'use strict';

const db = require('../../src/config/db');
const emailTemplateController = require('../../src/controllers/emailTemplateController');
const emailTemplateRepository = require('../../src/repositories/emailTemplateRepository');
const uiTemplateController = require('../../src/controllers/uiTemplateController');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const accountController = require('../../src/controllers/accountController');
const signatoryController = require('../../src/controllers/signatoryController');
const signatoryRepository = require('../../src/repositories/signatoryRepository');
const testModeController = require('../../src/controllers/testModeController');
const testModeService = require('../../src/services/testModeService');

/**
 * Port of N08AuditLogCoverageClusterATest.php. External QA report N-08,
 * Cluster A: Settings/Account/Signatories/Test-Mode/Email-template/
 * UI-template routes never called auditLogRepository.log(), so none of
 * them ever notified anyone either. Covers the 17 of Appendix 1's 19
 * Cluster-A actions reachable without a live SMTP/Zoho connection
 * (testZohoEmail/testSmtpEmail both throw before reaching the log call
 * without real mail credentials — verified by code review instead).
 * uploadUserAsset IS covered here (unlike its PHP mirror) since multer's
 * in-memory req.file has no real-HTTP-upload restriction to work around.
 */
describe('Audit log coverage, N-08 Cluster A', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest N08 Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-n08a-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function lastAuditLogAction() {
    return db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
  }

  function fakeReq(userId, body = {}, extraParams = {}, file = undefined) {
    return { params: extraParams, body, user: { id: userId }, file, session: {} };
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.status = () => res;
    res.send = () => {};
    return res;
  }

  it('email template store is logged', async () => {
    const userId = await createTestUser();
    await emailTemplateController.store(fakeReq(userId, { template_key: 'n08_test_key', subject: 'Subject', body: 'Body text' }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('EMAIL_TEMPLATE_CREATED');
  });

  it('email template update is logged', async () => {
    const userId = await createTestUser();
    const id = await emailTemplateRepository.create('n08_update_key', 'Old subject', 'Body', null, userId);
    await emailTemplateController.update(fakeReq(userId, { subject: 'New subject', body: 'Body' }, { id: String(id) }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('EMAIL_TEMPLATE_UPDATED');
  });

  it('email template toggleActive is logged', async () => {
    const userId = await createTestUser();
    const id = await emailTemplateRepository.create('n08_toggle_key', 'Subject', 'Body', null, userId);
    await emailTemplateController.toggleActive(fakeReq(userId, {}, { id: String(id) }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('EMAIL_TEMPLATE_TOGGLED_ACTIVE');
  });

  it('ui template toggleActive is logged', async () => {
    const userId = await createTestUser();
    const kanban = await uiTemplateRepository.findByKey('kanban');
    await uiTemplateController.toggleActive(fakeReq(userId, {}, { id: String(kanban.id) }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('UI_TEMPLATE_TOGGLED_ACTIVE');
    await uiTemplateRepository.setActive(kanban.id, true);
  });

  it('account updateSignature is logged', async () => {
    const userId = await createTestUser();
    await accountController.updateSignature(fakeReq(userId, { email_signature: 'Regards, Test' }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('ACCOUNT_SIGNATURE_UPDATED');
  });

  it('account updateNotificationPreference is logged', async () => {
    const userId = await createTestUser();
    await accountController.updateNotificationPreference(fakeReq(userId, { email_action_notifications_enabled: '1' }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('ACCOUNT_NOTIFICATION_PREFERENCE_UPDATED');
  });

  it('account updateUiTemplate is logged', async () => {
    const userId = await createTestUser();
    await accountController.updateUiTemplate(fakeReq(userId, { ui_template_preference_id: '' }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('ACCOUNT_UI_TEMPLATE_PREFERENCE_UPDATED');
  });

  it('signatory createDesignation is logged', async () => {
    const userId = await createTestUser();
    await signatoryController.createDesignation(fakeReq(userId, { title: 'N08 Test Designation' }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('SIGNATORY_DESIGNATION_CREATED');
  });

  it('signatory toggleDesignation is logged', async () => {
    const userId = await createTestUser();
    const id = await signatoryRepository.createDesignation('N08 Toggle Designation', userId);
    await signatoryController.toggleDesignation(fakeReq(userId, {}, { id: String(id) }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('SIGNATORY_DESIGNATION_TOGGLED');
  });

  it('signatory updateDesignation is logged', async () => {
    const userId = await createTestUser();
    const id = await signatoryRepository.createDesignation('N08 Rename Designation', userId);
    await signatoryController.updateDesignation(fakeReq(userId, { title: 'N08 Renamed Designation' }, { id: String(id) }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('SIGNATORY_DESIGNATION_RENAMED');
  });

  it('signatory deleteDesignation is logged', async () => {
    const userId = await createTestUser();
    const id = await signatoryRepository.createDesignation('N08 Delete Designation', userId);
    await signatoryController.deleteDesignation(fakeReq(userId, {}, { id: String(id) }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('SIGNATORY_DESIGNATION_DELETED');
  });

  it('signatory uploadUserAsset is logged', async () => {
    const userId = await createTestUser();
    const req = fakeReq(userId, { asset_kind: 'signature', label: 'Default' }, { id: String(userId) }, {
      buffer: Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
      size: 8,
    });
    await signatoryController.uploadUserAsset(req, fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('SIGNATORY_USER_ASSET_UPLOADED');
  });

  it('signatory deactivateUserAsset is logged', async () => {
    const userId = await createTestUser();
    const assetId = await signatoryRepository.addUserAsset(userId, 'signature', 'Default', '/tmp/n08-fake-asset.png', 'image/png', userId);
    await signatoryController.deactivateUserAsset(fakeReq(userId, {}, { id: String(assetId) }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('SIGNATORY_USER_ASSET_DEACTIVATED');
  });

  it('test mode enable is logged', async () => {
    const userId = await createTestUser();
    await testModeController.enable(fakeReq(userId), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('TEST_MODE_ENABLED');
    await testModeService.disable();
  });

  it('test mode disable is logged', async () => {
    const userId = await createTestUser();
    await testModeService.enable(userId);
    await testModeController.disable(fakeReq(userId), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('TEST_MODE_DISABLED');
  });

  it('test mode updateTestEmail is logged', async () => {
    const userId = await createTestUser();
    await testModeController.updateTestEmail(fakeReq(userId, { test_email: 'n08-test@nexacrest.test' }), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('TEST_MODE_TEST_EMAIL_UPDATED');
  });

  it('test mode deleteTestData is logged', async () => {
    const userId = await createTestUser();
    await testModeController.deleteTestData(fakeReq(userId), fakeRes());
    expect((await lastAuditLogAction()).action_type).toBe('TEST_MODE_TEST_DATA_DELETED');
  });
});
