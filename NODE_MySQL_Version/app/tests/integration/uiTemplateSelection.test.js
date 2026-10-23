'use strict';

const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const accountController = require('../../src/controllers/accountController');
const settingsController = require('../../src/controllers/settingsController');
const uiTemplateController = require('../../src/controllers/uiTemplateController');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const uiTemplateService = require('../../src/services/uiTemplateService');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const permissionRepository = require('../../src/repositories/permissionRepository');

/**
 * Port of UiTemplateSelectionTest.php. docs/schema.sql Section BQ — UI
 * template selection: per-user preference, company-wide default, and the
 * admin on/off switch per template, with the resolution order the owner
 * explicitly confirmed: user preference (if active) -> company default
 * (if active) -> hard-coded 'standard' (which can never itself be
 * deactivated).
 */
describe('UI template selection (docs/schema.sql Section BQ)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  beforeEach(async () => {
    // Every test starts from the real seeded state: standard+kanban both
    // active, default_ui_template = 'standard' — restore it explicitly in
    // case an earlier test in this run deactivated/changed one.
    await db.execute('UPDATE ui_templates SET is_active = 1');
    await db.execute("UPDATE company_settings SET setting_value = 'standard' WHERE setting_key = 'default_ui_template'");
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest UI Template Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-ui-template-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id };
  }

  function fakeRes() {
    const res = { renderedView: null, redirectedTo: null, statusCode: 200 };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, locals) => { res.renderedView = { view, locals }; };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; };
    return res;
  }

  function fakeReq(overrides = {}) {
    return { session: {}, body: {}, params: {}, ...overrides };
  }

  async function standardId() {
    return (await uiTemplateRepository.findByKey('standard')).id;
  }

  async function kanbanId() {
    return (await uiTemplateRepository.findByKey('kanban')).id;
  }

  // --- uiTemplateService.resolve() — the 4 fallback branches ---

  it('resolve() returns the user\'s active preference when set', async () => {
    expect(await uiTemplateService.resolve(await kanbanId())).toBe('kanban');
  });

  it('resolve() falls back to the company default when preference is null', async () => {
    expect(await uiTemplateService.resolve(null)).toBe('standard');

    await db.execute("UPDATE company_settings SET setting_value = 'kanban' WHERE setting_key = 'default_ui_template'");
    expect(await uiTemplateService.resolve(null)).toBe('kanban');
  });

  it('resolve() falls back to the company default when the preferred template is deactivated', async () => {
    const kbId = await kanbanId();
    await uiTemplateRepository.setActive(kbId, false);
    // Default is still 'standard' (active) — preference falls to it, not straight to the hardcoded fallback.
    expect(await uiTemplateService.resolve(kbId)).toBe('standard');
  });

  it('resolve() falls back to the hardcoded standard when both preference and default are deactivated', async () => {
    const kbId = await kanbanId();
    await uiTemplateRepository.setActive(kbId, false);
    await db.execute("UPDATE company_settings SET setting_value = 'kanban' WHERE setting_key = 'default_ui_template'");
    expect(await uiTemplateService.resolve(kbId)).toBe('standard');
    expect(await uiTemplateService.resolve(null)).toBe('standard');
  });

  it('the hardcoded fallback key is standard, which can never actually be deactivated', () => {
    expect(uiTemplateService.FALLBACK_TEMPLATE_KEY).toBe('standard');
  });

  // --- accountController.updateUiTemplate() — self-service preference ---

  it('updateUiTemplate saves an active preference', async () => {
    const user = await createTestUser();
    const kbId = await kanbanId();
    const req = fakeReq({ user: { id: user.id }, body: { ui_template_preference_id: String(kbId) } });
    const res = fakeRes();

    await accountController.updateUiTemplate(req, res);

    const flashMsgs = flash.pull(req);
    expect(flashMsgs[0].type).toBe('success');
    const row = await db.queryOne('SELECT ui_template_preference_id FROM users WHERE id = :id', { id: user.id });
    expect(Number(row.ui_template_preference_id)).toBe(kbId);
  });

  it('updateUiTemplate clears the preference on an empty submission', async () => {
    const user = await createTestUser();
    const kbId = await kanbanId();
    await db.execute('UPDATE users SET ui_template_preference_id = :tid WHERE id = :id', { tid: kbId, id: user.id });
    const req = fakeReq({ user: { id: user.id }, body: { ui_template_preference_id: '' } });

    await accountController.updateUiTemplate(req, fakeRes());

    const row = await db.queryOne('SELECT ui_template_preference_id FROM users WHERE id = :id', { id: user.id });
    expect(row.ui_template_preference_id).toBeNull();
  });

  it('updateUiTemplate rejects a deactivated template', async () => {
    const kbId = await kanbanId();
    await uiTemplateRepository.setActive(kbId, false);
    const user = await createTestUser();
    const req = fakeReq({ user: { id: user.id }, body: { ui_template_preference_id: String(kbId) } });

    await accountController.updateUiTemplate(req, fakeRes());

    const flashMsgs = flash.pull(req);
    expect(flashMsgs[0].type).toBe('error');
    const row = await db.queryOne('SELECT ui_template_preference_id FROM users WHERE id = :id', { id: user.id });
    expect(row.ui_template_preference_id).toBeNull();
  });

  // --- route gating: /ui-templates reuses manage_company_settings, the
  // same permission that already gates Settings/Watermarks/Holidays — any
  // user without it can set their OWN account preference (tested above)
  // but can never reach the admin on/off screen for everyone else's. ---

  it('Admin role has manage_company_settings needed for the admin screen', async () => {
    const user = await createTestUser('Admin');
    const permissions = await permissionRepository.effectivePermissions(user.id, user.roleId);
    expect(permissions.manage_company_settings).toBe(true);
  });

  it('an ungranted role lacks manage_company_settings so cannot reach the admin screen', async () => {
    const user = await createTestUser('Viewer / Auditor');
    const permissions = await permissionRepository.effectivePermissions(user.id, user.roleId);
    expect(!!permissions.manage_company_settings).toBe(false);
  });

  // --- uiTemplateController.toggleActive() — admin on/off switch ---

  it('toggleActive can deactivate kanban', async () => {
    const user = await createTestUser();
    const kbId = await kanbanId();
    const req = fakeReq({ user: { id: user.id }, params: { id: String(kbId) } });

    await uiTemplateController.toggleActive(req, fakeRes());

    const flashMsgs = flash.pull(req);
    expect(flashMsgs[0].type).toBe('success');
    const kanban = await uiTemplateRepository.findById(kbId);
    expect(Number(kanban.is_active)).toBe(0);
  });

  it('toggleActive refuses to deactivate standard', async () => {
    const user = await createTestUser();
    const stdId = await standardId();
    const req = fakeReq({ user: { id: user.id }, params: { id: String(stdId) } });

    await uiTemplateController.toggleActive(req, fakeRes());

    const flashMsgs = flash.pull(req);
    expect(flashMsgs[0].type).toBe('error');
    expect(flashMsgs[0].message).toMatch(/never be deactivated/);
    const standard = await uiTemplateRepository.findById(stdId);
    expect(Number(standard.is_active)).toBe(1);
  });

  it('toggleActive can reactivate a previously deactivated template', async () => {
    const kbId = await kanbanId();
    await uiTemplateRepository.setActive(kbId, false);
    const user = await createTestUser();
    const req = fakeReq({ user: { id: user.id }, params: { id: String(kbId) } });

    await uiTemplateController.toggleActive(req, fakeRes());

    const kanban = await uiTemplateRepository.findById(kbId);
    expect(Number(kanban.is_active)).toBe(1);
  });

  // --- settingsController.update() — default_ui_template validation ---

  it('settings update rejects an inactive template as the default', async () => {
    const kbId = await kanbanId();
    await uiTemplateRepository.setActive(kbId, false);
    const user = await createTestUser();
    const req = fakeReq({
      user: { id: user.id },
      body: { settings: { default_ui_template: 'kanban' }, reason: 'deactivated template test' },
    });

    await settingsController.update(req, fakeRes());

    const flashMsgs = flash.pull(req);
    expect(flashMsgs[0].type).toBe('error');
    expect(flashMsgs[0].message).toMatch(/default_ui_template/);
    expect(await companySettingsRepository.get('default_ui_template')).toBe('standard');
  });

  it('settings update accepts an active template as the default', async () => {
    const user = await createTestUser();
    const req = fakeReq({
      user: { id: user.id },
      body: { settings: { default_ui_template: 'kanban' }, reason: 'switching company default to kanban' },
    });

    await settingsController.update(req, fakeRes());

    const flashMsgs = flash.pull(req);
    expect(flashMsgs[0].type).toBe('success');
    expect(await companySettingsRepository.get('default_ui_template')).toBe('kanban');
  });

  it('settings update rejects an unknown template key as the default', async () => {
    const user = await createTestUser();
    const req = fakeReq({
      user: { id: user.id },
      body: { settings: { default_ui_template: 'not_a_real_template' }, reason: 'unknown template key test' },
    });

    await settingsController.update(req, fakeRes());

    const flashMsgs = flash.pull(req);
    expect(flashMsgs[0].type).toBe('error');
    expect(await companySettingsRepository.get('default_ui_template')).toBe('standard');
  });
});
