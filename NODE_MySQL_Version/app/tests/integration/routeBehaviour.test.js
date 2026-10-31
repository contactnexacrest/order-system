'use strict';

// Approving a document's last review renders its PDF — puppeteer is stubbed under Jest.
jest.mock('../../src/services/pdfRenderService', () => ({
  renderPdfFromHtml: jest.fn().mockResolvedValue(Buffer.from('%PDF-fake')),
}));

const os = require('os');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder, createTestFile, createTestDocument } = require('../support/fixtures');

/**
 * Port of RouteBehaviourTest.php. QA Round 6, Batch 5 (R5-14): QA found 124
 * routes that no PHPUnit, Jest or e2e test exercised. routeMatrix.test.js
 * already proves every one of them refuses a user without the permission;
 * this drives each of them through the real app as a user who holds the
 * permission and checks what the route actually does — the record changed,
 * the audit row written, the page shown — and that a missing id is answered
 * as not found with nothing written.
 */
describe('Route behaviour (QA Round 6, R5-14)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c63f80f00000101000518d84e0000000049454e44ae426082', 'hex');
  const csrfOf = (html) => (html.match(/name="_csrf"\s+value="([^"]+)"/) || [])[1];
  const value = async (sql, params = {}) => {
    const row = await db.queryOne(sql, params);
    return row ? Object.values(row)[0] : null;
  };
  const insert = async (table, row) => (await db.execute(
    `INSERT INTO ${table} (${Object.keys(row).join(', ')}) VALUES (${Object.keys(row).map((k) => `:${k}`).join(', ')})`, row
  )).insertId;
  const audits = async (action, entityId = null) => Number(await value(
    `SELECT COUNT(*) AS n FROM audit_log WHERE action_type = :action${entityId === null ? '' : ' AND entity_id = :id'}`, { action, id: entityId }
  ));
  const tmpFile = (ext, bytes) => {
    const file = path.join(os.tmpdir(), `route-behaviour-${crypto.randomBytes(4).toString('hex')}.${ext}`);
    fs.writeFileSync(file, bytes);
    return file;
  };
  const binary = (res, cb) => {
    const chunks = [];
    res.on('data', (c) => chunks.push(c));
    res.on('end', () => cb(null, Buffer.concat(chunks)));
  };

  async function staffUser(roleName, superAdmin = false) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-route-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Route Holder', :email, NULL, :hash, :role_id, 1, 0, 0, :sa)`,
      { email, hash: await bcrypt.hash('JestRoute5!', 10), role_id: role.id, sa: superAdmin ? 1 : 0 }
    )).insertId;
    return { id, email };
  }

  /** A signed-in agent with call() → { status, body, type, flash }. */
  async function signIn(user) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    const login = await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: 'JestRoute5!' });
    expect(login.headers.location).not.toBe('/login');
    agent.userId = user.id;
    agent.csrf = csrfOf((await agent.get('/account')).text) || csrfOf((await agent.get('/')).text);
    agent.call = async (method, url, fields = {}) => {
      const res = method === 'GET'
        ? await agent.get(url)
        : await agent.post(url).type('form').send({ _csrf: agent.csrf, ...fields });
      let type = null;
      let flash = null;
      if (res.status === 302 && res.headers.location && !/^https?:/.test(res.headers.location)) {
        const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
        if (m) [type, flash] = [m[1], m[2].replace(/<[^>]+>/g, '').replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&').trim()];
      }
      return { status: res.status, body: res.text || '', type, flash };
    };
    return agent;
  }

  let holderUser;
  let holder;
  beforeAll(async () => {
    // A Super Admin holds every permission, so one user reaches every route below.
    holderUser = await staffUser('Admin', true);
  });
  beforeEach(async () => {
    holder = await signIn(holderUser);
  });

  const expectPage = (r, marker, route) => {
    expect([route, r.status]).toEqual([route, 200]);
    expect([route, r.body.includes(marker)]).toEqual([route, true]);
  };
  const expectDone = (r, route, flashContains = '') => {
    expect([route, r.status < 400, r.type, r.flash]).toEqual([route, true, 'success', expect.stringContaining(flashContains)]);
  };

  // ---------------------------------------------------------------- ports, routes, presets

  it('ports and port routes', async () => {
    const port = await insert('ports', { name: 'Route Test Port', city: 'Testcity', country: 'India', port_role: 'loading', is_active: 1 });
    expectPage(await holder.call('GET', '/ports/create'), 'name="port_role"', 'GET /ports/create');
    expectPage(await holder.call('GET', `/ports/${port}/edit`), 'Route Test Port', 'GET /ports/:id/edit');

    expectDone(await holder.call('POST', `/ports/${port}/update`, { name: 'Route Test Port B', city: 'Testcity', country: 'India', port_role: 'loading' }), 'POST /ports/:id/update');
    expect(await value('SELECT name FROM ports WHERE id = :id', { id: port })).toBe('Route Test Port B');
    expect(await audits('PORT_UPDATED', port)).toBe(1);

    expectDone(await holder.call('POST', `/ports/${port}/toggle`), 'POST /ports/:id/toggle');
    expect(Number(await value('SELECT is_active FROM ports WHERE id = :id', { id: port }))).toBe(0);
    expect(await audits('PORT_DEACTIVATED', port)).toBe(1);
    await holder.call('POST', `/ports/${port}/toggle`);

    const defaults = (await db.query('SELECT id FROM ports WHERE is_default = 1')).map((r) => r.id);
    expectDone(await holder.call('POST', `/ports/${port}/set-default`), 'POST /ports/:id/set-default');
    expect(Number(await value('SELECT is_default FROM ports WHERE id = :id', { id: port }))).toBe(1);
    expect(await audits('PORT_DEFAULT_CHANGED', port)).toBe(1);
    // Other test files create orders with the seeded default ports.
    await db.execute('UPDATE ports SET is_default = 0');
    if (defaults.length) await db.execute(`UPDATE ports SET is_default = 1 WHERE id IN (${defaults.map(Number).join(',')})`);

    const discharge = await insert('ports', { name: 'Route Test Discharge', port_role: 'discharge', is_active: 1 });
    const route = await insert('port_routes', { port_of_loading_id: port, port_of_discharge_id: discharge, sailing_days_min: 10, sailing_days_max: 14 });
    expectPage(await holder.call('GET', '/port-routes/create'), 'name="port_of_loading_id"', 'GET /port-routes/create');
    expectPage(await holder.call('GET', `/port-routes/${route}/edit`), 'Route Test Discharge', 'GET /port-routes/:id/edit');
    expectDone(await holder.call('POST', `/port-routes/${route}/update`, { port_of_loading_id: port, port_of_discharge_id: discharge, sailing_days_min: '12', sailing_days_max: '16' }), 'POST /port-routes/:id/update');
    expect(Number(await value('SELECT sailing_days_max FROM port_routes WHERE id = :id', { id: route }))).toBe(16);
    expect(await audits('PORT_ROUTE_UPDATED', route)).toBe(1);
  });

  it('payment presets', async () => {
    const currency = await value("SELECT id FROM currencies WHERE code = 'USD'");
    const form = (name) => ({
      preset_name: name, advance_trigger_text: 'On order confirmation', payment_method: 'CASH_ADVANCE',
      balance_trigger_option: 'A_BEFORE_SHIPMENT', balance_trigger_wording: 'Balance {days} days before shipment',
      advance_pct: '30', balance_pct: '70', balance_days: '7', currency_id: String(currency),
    });
    expectPage(await holder.call('GET', '/payment-presets'), 'Standard', 'GET /payment-presets');
    expectPage(await holder.call('GET', '/payment-presets/create'), 'name="preset_name"', 'GET /payment-presets/create');

    expectDone(await holder.call('POST', '/payment-presets', form('Route Test Preset')), 'POST /payment-presets', '30% advance');
    const id = await value("SELECT id FROM payment_presets WHERE preset_name = 'Route Test Preset'");
    expect(id).toBeGreaterThan(0);
    expect(await audits('PAYMENT_PRESET_ADDED', id)).toBe(1);

    expectPage(await holder.call('GET', `/payment-presets/${id}/edit`), 'Route Test Preset', 'GET /payment-presets/:id/edit');
    expectDone(await holder.call('POST', `/payment-presets/${id}/update`, form('Route Test Preset B')), 'POST /payment-presets/:id/update');
    expect(await value('SELECT preset_name FROM payment_presets WHERE id = :id', { id })).toBe('Route Test Preset B');
    expect(await audits('PAYMENT_PRESET_UPDATED', id)).toBe(1);

    expectDone(await holder.call('POST', `/payment-presets/${id}/toggle`), 'POST /payment-presets/:id/toggle');
    expect(Number(await value('SELECT is_active FROM payment_presets WHERE id = :id', { id }))).toBe(0);
    expect(await audits('PAYMENT_PRESET_DEACTIVATED', id)).toBe(1);
  });

  // ---------------------------------------------------------------- directories and lists

  it('logistics partners, compliance task types and holidays', async () => {
    const partner = await insert('logistics_partners', { partner_name: 'Route Test Freight Co', service_type: 'freight_forwarder', is_active: 1 });
    expectPage(await holder.call('GET', `/logistics-partners/${partner}/edit`), 'Route Test Freight Co', 'GET /logistics-partners/:id/edit');
    expectDone(await holder.call('POST', `/logistics-partners/${partner}/toggle`), 'POST /logistics-partners/:id/toggle');
    expect(Number(await value('SELECT is_active FROM logistics_partners WHERE id = :id', { id: partner }))).toBe(0);
    expect(await audits('LOGISTICS_PARTNER_DEACTIVATED', partner)).toBe(1);
    expectDone(await holder.call('POST', `/logistics-partners/${partner}/delete`), 'POST /logistics-partners/:id/delete');
    expect(await value('SELECT id FROM logistics_partners WHERE id = :id', { id: partner })).toBeNull();
    expect(await audits('LOGISTICS_PARTNER_DELETED', partner)).toBe(1);

    const task = await insert('compliance_task_types', { name: 'Route Test Task', is_active: 1 });
    expectPage(await holder.call('GET', '/compliance-task-types/create'), 'name="name"', 'GET /compliance-task-types/create');
    expectPage(await holder.call('GET', `/compliance-task-types/${task}/edit`), 'Route Test Task', 'GET /compliance-task-types/:id/edit');
    expectDone(await holder.call('POST', `/compliance-task-types/${task}/update`, { name: 'Route Test Task B' }), 'POST /compliance-task-types/:id/update');
    expect(await value('SELECT name FROM compliance_task_types WHERE id = :id', { id: task })).toBe('Route Test Task B');
    expect(await audits('COMPLIANCE_TASK_TYPE_UPDATED', task)).toBe(1);
    expectDone(await holder.call('POST', `/compliance-task-types/${task}/delete`), 'POST /compliance-task-types/:id/delete');
    expect(await value('SELECT id FROM compliance_task_types WHERE id = :id', { id: task })).toBeNull();
    expect(await audits('COMPLIANCE_TASK_TYPE_DELETED', task)).toBe(1);

    const holiday = await insert('company_holidays', { holiday_date: '2031-01-26', description: 'Route Test Day' });
    expectDone(await holder.call('POST', `/holidays/${holiday}/update`, { holiday_date: '2031-01-27', description: 'Route Test Day B' }), 'POST /holidays/:id/update');
    expect(await value("SELECT DATE_FORMAT(holiday_date, '%Y-%m-%d') AS d FROM company_holidays WHERE id = :id", { id: holiday })).toBe('2031-01-27');
    expectDone(await holder.call('POST', `/holidays/${holiday}/delete`), 'POST /holidays/:id/delete');
    expect(await value('SELECT id FROM company_holidays WHERE id = :id', { id: holiday })).toBeNull();
    expect(await audits('HOLIDAY_REMOVED', holiday)).toBe(1);
  });

  it('HS codes', async () => {
    const code = await insert('hs_codes', { code: '99887766', description: 'Route test goods', is_active: 1 });
    expectDone(await holder.call('POST', `/hs-codes/${code}/toggle`), 'POST /hs-codes/:id/toggle');
    expect(Number(await value('SELECT is_active FROM hs_codes WHERE id = :id', { id: code }))).toBe(0);
    expect(await audits('HS_CODE_TOGGLED', code)).toBe(1);
    expectDone(await holder.call('POST', `/hs-codes/${code}/delete`), 'POST /hs-codes/:id/delete');
    expect(await value('SELECT id FROM hs_codes WHERE id = :id', { id: code })).toBeNull();
    expect(await audits('HS_CODE_DELETED', code)).toBe(1);

    const bulkBefore = await audits('HS_CODE_BULK_IMPORTED');
    await holder.call('POST', '/hs-codes/bulk-import', { bulk_codes: '99887711, Route bulk one\n99887712, Route bulk two' });
    expect(Number(await value("SELECT COUNT(*) AS n FROM hs_codes WHERE code IN ('99887711', '99887712')"))).toBe(2);
    expect(await audits('HS_CODE_BULK_IMPORTED')).toBe(bulkBefore + 1);

    const guideBefore = await audits('HS_CODE_GUIDE_IMPORTED');
    await holder.call('POST', '/hs-codes/product-guide', { bulk_guide: 'Route guide product\t99887711\ta note' });
    const guide = await value("SELECT id FROM hs_code_product_examples WHERE product_description = 'Route guide product'");
    expect(guide).toBeGreaterThan(0);
    expect(await audits('HS_CODE_GUIDE_IMPORTED')).toBe(guideBefore + 1);
    expectDone(await holder.call('POST', `/hs-codes/product-guide/${guide}/delete`), 'POST /hs-codes/product-guide/:id/delete');
    expect(await value('SELECT id FROM hs_code_product_examples WHERE id = :id', { id: guide })).toBeNull();
    expect(await audits('HS_CODE_GUIDE_DELETED', guide)).toBe(1);
  });

  it('reference library', async () => {
    const category = await insert('reference_library_categories', { name: 'Route Test Category' });
    expectDone(await holder.call('POST', `/reference-docs/categories/${category}/update`, { name: 'Route Test Category B', required_permission: '' }), 'POST /reference-docs/categories/:id/update');
    expect(await value('SELECT name FROM reference_library_categories WHERE id = :id', { id: category })).toBe('Route Test Category B');
    expect(await audits('REFERENCE_LIBRARY_CATEGORY_UPDATED', category)).toBe(1);
    expectDone(await holder.call('POST', `/reference-docs/categories/${category}/delete`), 'POST /reference-docs/categories/:id/delete');
    expect(await value('SELECT id FROM reference_library_categories WHERE id = :id', { id: category })).toBeNull();
    expect(await audits('REFERENCE_LIBRARY_CATEGORY_DELETED', category)).toBe(1);

    const doc = await insert('reference_library_documents', { title: 'Route Test Reference', content: 'Body text' });
    expectPage(await holder.call('GET', '/reference-docs/custom/create'), 'name="title"', 'GET /reference-docs/custom/create');
    expectPage(await holder.call('GET', `/reference-docs/custom/${doc}/edit`), 'Route Test Reference', 'GET /reference-docs/custom/:id/edit');
    expectDone(await holder.call('POST', `/reference-docs/custom/${doc}/delete`), 'POST /reference-docs/custom/:id/delete');
    expect(await value('SELECT id FROM reference_library_documents WHERE id = :id', { id: doc })).toBeNull();
    expect(await audits('REFERENCE_LIBRARY_DOC_DELETED', doc)).toBe(1);
  });

  // ---------------------------------------------------------------- users, roles, permissions

  it('users', async () => {
    const role = await value("SELECT id FROM roles WHERE name = 'Logistics Executive'");
    const email = `route-test-person-${crypto.randomBytes(3).toString('hex')}@nexacrest.test`;
    expectDone(await holder.call('POST', '/users/create', { name: 'Route Test Person', email, phone: '', role_id: String(role) }), 'POST /users/create', 'temporary password');
    const user = await value('SELECT id FROM users WHERE email = :email', { email });
    expect(await audits('USER_CREATED', user)).toBe(1);

    expectPage(await holder.call('GET', `/users/${user}/edit`), email, 'GET /users/:id/edit');
    expectDone(await holder.call('POST', `/users/${user}/update`, { name: 'Route Test Person B', email, phone: '', role_id: String(role) }), 'POST /users/:id/update');
    expect(await value('SELECT name FROM users WHERE id = :id', { id: user })).toBe('Route Test Person B');

    expectDone(await holder.call('POST', `/users/${user}/toggle-active`), 'POST /users/:id/toggle-active');
    expect(Number(await value('SELECT is_active FROM users WHERE id = :id', { id: user }))).toBe(0);

    await db.execute('UPDATE users SET is_archived = 1 WHERE id = :id', { id: user });
    expectDone(await holder.call('POST', `/users/${user}/unarchive`), 'POST /users/:id/unarchive');
    expect(Number(await value('SELECT is_archived FROM users WHERE id = :id', { id: user }))).toBe(0);
    expect(await audits('USER_UNARCHIVED', user)).toBe(1);

    expect((await holder.call('POST', `/users/${holder.userId}/toggle-active`)).flash).toBe("You can't deactivate your own account.");
  });

  it('roles and permissions', async () => {
    const role = await insert('roles', { name: `Route Test Role ${crypto.randomBytes(3).toString('hex')}`, description: 'Test only', is_system_role: 0 });
    expectPage(await holder.call('GET', `/admin/roles/${role}/edit`), 'Route Test Role', 'GET /admin/roles/:id/edit');
    expectDone(await holder.call('POST', `/admin/roles/${role}/delete`), 'POST /admin/roles/:id/delete');
    expect(await value('SELECT id FROM roles WHERE id = :id', { id: role })).toBeNull();
    expect(await audits('ROLE_DELETED', role)).toBe(1);

    const permission = await insert('permissions', { permission_key: `route_test_${crypto.randomBytes(3).toString('hex')}`, name: 'Route Test Permission', is_system_permission: 0 });
    expectPage(await holder.call('GET', `/admin/permission-definitions/${permission}/edit`), 'Route Test Permission', 'GET /admin/permission-definitions/:id/edit');
    expectDone(await holder.call('POST', `/admin/permission-definitions/${permission}/delete`), 'POST /admin/permission-definitions/:id/delete');
    expect(await value('SELECT id FROM permissions WHERE id = :id', { id: permission })).toBeNull();
    expect(await audits('PERMISSION_DELETED', permission)).toBe(1);

    const other = await staffUser('Logistics Executive');
    const permId = await value("SELECT id FROM permissions WHERE permission_key = 'view_reports'");
    const override = await insert('user_permissions', { user_id: other.id, permission_id: permId, is_enabled: 1 });
    expectDone(await holder.call('POST', `/admin/permissions/${override}/remove`), 'POST /admin/permissions/:id/remove');
    expect(await value('SELECT id FROM user_permissions WHERE id = :id', { id: override })).toBeNull();
    expect(await audits('USER_PERMISSION_REVOKED', override)).toBe(1);
  });

  it('field protection requests and the admin overrides', async () => {
    const preset = await insert('payment_presets', {
      preset_name: `Route Protected Preset ${crypto.randomBytes(3).toString('hex')}`, advance_pct: 30, advance_trigger_text: 'On order confirmation', payment_method: 'CASH_ADVANCE',
      balance_pct: 70, balance_trigger_option: 'A_BEFORE_SHIPMENT', balance_days: 7, balance_trigger_wording: 'Balance {days} days before shipment',
      currency_id: await value("SELECT id FROM currencies WHERE code = 'USD'"), is_active: 1, is_protected: 1,
    });
    const requestUnlock = async (agent) => {
      const before = Number(await value('SELECT COALESCE(MAX(id), 0) AS m FROM field_protection_requests'));
      expectDone(await agent.call('POST', '/admin/field-protection/request', { table_name: 'payment_presets', record_id: String(preset), record_label: 'Route test preset', action: 'unlock', reason: 'Route behaviour test of the protection request' }), 'POST /admin/field-protection/request');
      return value('SELECT id FROM field_protection_requests WHERE id > :b AND record_id = :r', { b: before, r: preset });
    };
    const first = await requestUnlock(holder);
    expect(await value('SELECT status FROM field_protection_requests WHERE id = :id', { id: first })).toBe('pending');
    expect(await audits('PROTECTION_FLAG_REQUESTED', preset)).toBe(1);

    // A different privileged user resolves it (maker-checker).
    const checker = await signIn(await staffUser('Admin', true));
    expectDone(await checker.call('POST', `/admin/field-protection/${first}/reject`, { resolved_reason: 'Not needed for this route test' }), 'POST /admin/field-protection/:id/reject');
    expect(await value('SELECT status FROM field_protection_requests WHERE id = :id', { id: first })).toBe('rejected');

    const second = await requestUnlock(holder);
    expectDone(await checker.call('POST', `/admin/field-protection/${second}/approve`, { resolved_reason: 'Approved for this route test' }), 'POST /admin/field-protection/:id/approve');
    expect(await value('SELECT status FROM field_protection_requests WHERE id = :id', { id: second })).toBe('approved');
    expect(Number(await value('SELECT is_protected FROM payment_presets WHERE id = :id', { id: preset }))).toBe(0);

    // Overrides: one document type's minimum reviewers, the now-unprotected preset's advance text.
    const type = await db.queryOne("SELECT id, ref_format, min_reviewers_default FROM document_types WHERE code = 'ANNEXA'");
    const newMin = Number(type.min_reviewers_default) + 1;
    expectDone(await holder.call('POST', '/admin/overrides/document-types', { reason: 'Route behaviour test override', [`ref_format[${type.id}]`]: type.ref_format, [`min_reviewers[${type.id}]`]: String(newMin) }), 'POST /admin/overrides/document-types', '1 document type');
    expect(Number(await value('SELECT min_reviewers_default FROM document_types WHERE id = :id', { id: type.id }))).toBe(newMin);
    await db.execute('UPDATE document_types SET min_reviewers_default = :m WHERE id = :id', { m: type.min_reviewers_default, id: type.id });

    const open = await db.queryOne('SELECT * FROM payment_presets WHERE id = :id', { id: preset });
    expectDone(await holder.call('POST', '/admin/overrides/payment-presets', {
      reason: 'Route behaviour test override',
      [`advance_pct[${preset}]`]: String(Number(open.advance_pct)), [`balance_pct[${preset}]`]: String(Number(open.balance_pct)),
      [`advance_trigger_text[${preset}]`]: 'Route test advance wording', [`balance_trigger_option[${preset}]`]: open.balance_trigger_option,
      [`balance_days[${preset}]`]: String(open.balance_days),
    }), 'POST /admin/overrides/payment-presets', '1 payment preset');
    expect(await value('SELECT advance_trigger_text FROM payment_presets WHERE id = :id', { id: preset })).toBe('Route test advance wording');
  });

  // ---------------------------------------------------------------- company settings pages

  it('settings pages and signatories', async () => {
    expectPage(await holder.call('GET', '/ui-templates'), '<form', 'GET /ui-templates');
    expectPage(await holder.call('GET', '/watermarks'), '<form', 'GET /watermarks');
    const template = await value('SELECT id FROM email_templates ORDER BY id LIMIT 1');
    expectPage(await holder.call('GET', `/email-templates/${template}/edit`), 'name="subject"', 'GET /email-templates/:id/edit');

    const designation = await value('SELECT id FROM designations ORDER BY id LIMIT 1');
    const person = (await staffUser('Admin')).id;
    expectDone(await holder.call('POST', `/signatories/users/${person}/eligibility`, { eligible: '1', designation_id: String(designation) }), 'POST /signatories/users/:id/eligibility');
    expect(Number(await value('SELECT is_signatory_eligible FROM users WHERE id = :id', { id: person }))).toBe(1);
    const previousDefault = await value('SELECT user_id FROM company_default_signatory WHERE id = 1');
    expectDone(await holder.call('POST', '/signatories/global-default', { user_id: String(person) }), 'POST /signatories/global-default');
    expect(await value('SELECT user_id FROM company_default_signatory WHERE id = 1')).toBe(person);
    // Other test files generate documents signed by the seeded default.
    if (previousDefault) await db.execute('UPDATE company_default_signatory SET user_id = :u WHERE id = 1', { u: previousDefault });

    const asset = await insert('user_signature_assets', { user_id: person, asset_kind: 'signature', label: 'Route test signature', server_path: tmpFile('png', PNG), mime_type: 'image/png', is_active: 1 });
    const preview = await holder.get(`/signatories/user-assets/${asset}/preview`).buffer(true).parse(binary);
    expect(preview.status).toBe(200);
    expect(Buffer.compare(preview.body, PNG)).toBe(0);

    const old = await insert('assets', { asset_type: 'logo', name: 'Route test old logo', server_path: tmpFile('png', PNG), mime_type: 'image/png', is_active: 0 });
    expectDone(await holder.call('POST', `/company-assets/${old}/delete`), 'POST /company-assets/:id/delete');
    expect(await value('SELECT id FROM assets WHERE id = :id', { id: old })).toBeNull();
    expect(await audits('ASSET_DELETED', old)).toBe(1);
  });

  // The /sample-data routes are checked in sampleDataLoadClear.test.js: Jest runs
  // test files side by side, and loading or clearing the sample set here would
  // race that file's own load/clear checks.
  it('test mode and the Super Admin flag', async () => {
    expectPage(await holder.call('GET', '/test-mode'), 'Test Mode', 'GET /test-mode');

    const target = (await staffUser('Admin')).id;
    expectDone(await holder.call('POST', '/super-admin/set-permanent', { target_id: String(target), is_super_admin: '1', reason: 'Route behaviour test promotion' }), 'POST /super-admin/set-permanent', 'promoted');
    expect(Number(await value('SELECT is_super_admin FROM users WHERE id = :id', { id: target }))).toBe(1);
  });

  // ---------------------------------------------------------------- product catalogue

  it('product catalogue', async () => {
    const hs = await value('SELECT code FROM hs_codes WHERE is_active = 1 ORDER BY id LIMIT 1');
    const name = `Route Test Granite ${crypto.randomBytes(3).toString('hex')}`;
    const form = (n) => ({ name: n, specifications: '1200 x 600 mm', hs_code: hs, origin: 'India', is_active: '1' });
    expectDone(await holder.call('POST', '/products/create', form(name)), 'POST /products/create');
    const product = await value('SELECT id FROM catalog_products WHERE name = :n', { n: name });
    expect(await audits('PRODUCT_CREATED', product)).toBe(1);

    expectPage(await holder.call('GET', `/products/${product}`), name, 'GET /products/:id');
    expectPage(await holder.call('GET', `/products/${product}/edit`), name, 'GET /products/:id/edit');
    expectDone(await holder.call('POST', `/products/${product}/update`, form(`${name} B`)), 'POST /products/:id/update');
    expect(await value('SELECT name FROM catalog_products WHERE id = :id', { id: product })).toBe(`${name} B`);

    expectDone(await holder.call('POST', `/products/${product}/misc-charges/add`, { label: 'Route crating', amount: '250' }), 'POST /products/:id/misc-charges/add');
    const charge = await value('SELECT id FROM catalog_product_misc_charges WHERE product_id = :p', { p: product });
    expectDone(await holder.call('POST', `/products/misc-charges/${charge}/delete`), 'POST /products/misc-charges/:id/delete');
    expect(await value('SELECT id FROM catalog_product_misc_charges WHERE id = :id', { id: charge })).toBeNull();
    expect(await audits('PRODUCT_MISC_CHARGE_DELETED', charge)).toBe(1);

    const supplier = { supplier_name: 'Route Quarry One', fob_source: 'direct', fob_value: '40' };
    expectDone(await holder.call('POST', `/products/${product}/suppliers/add`, supplier), 'POST /products/:id/suppliers/add');
    expectDone(await holder.call('POST', `/products/${product}/suppliers/add`, { ...supplier, supplier_name: 'Route Quarry Two' }), 'second supplier');
    const [one, two] = (await db.query('SELECT id FROM catalog_product_suppliers WHERE product_id = :p ORDER BY id', { p: product })).map((r) => r.id);
    expectDone(await holder.call('POST', `/products/suppliers/${two}/update`, { ...supplier, supplier_name: 'Route Quarry Two B' }), 'POST /products/suppliers/:id/update');
    expect(await value('SELECT supplier_name FROM catalog_product_suppliers WHERE id = :id', { id: two })).toBe('Route Quarry Two B');
    expectDone(await holder.call('POST', `/products/suppliers/${two}/set-primary`), 'POST /products/suppliers/:id/set-primary');
    expect(Number(await value('SELECT is_primary FROM catalog_product_suppliers WHERE id = :id', { id: two }))).toBe(1);
    expect(Number(await value('SELECT is_primary FROM catalog_product_suppliers WHERE id = :id', { id: one }))).toBe(0);
    expectDone(await holder.call('POST', `/products/suppliers/${one}/delete`), 'POST /products/suppliers/:id/delete');
    expect(await value('SELECT id FROM catalog_product_suppliers WHERE id = :id', { id: one })).toBeNull();

    // An upload with no file is refused and nothing is stored; a real image is stored and served.
    const none = await holder.call('POST', `/products/${product}/images/upload`);
    expect(none.flash).toBe('No file was uploaded, or the upload failed.');
    expect(Number(await value('SELECT COUNT(*) AS n FROM catalog_product_images WHERE product_id = :p', { p: product }))).toBe(0);
    const up = await holder.post(`/products/${product}/images/upload`).field('_csrf', holder.csrf).attach('image', PNG, { filename: 'route.png', contentType: 'image/png' });
    expect(up.status).toBe(302);
    const image = await value('SELECT id FROM catalog_product_images WHERE product_id = :p', { p: product });
    expect(image).toBeGreaterThan(0);
    const view = await holder.get(`/products/images/${image}/view`).buffer(true).parse(binary);
    expect(Buffer.compare(view.body, PNG)).toBe(0);
    expectDone(await holder.call('POST', `/products/images/${image}/delete`), 'POST /products/images/:id/delete');
    expect(await value('SELECT id FROM catalog_product_images WHERE id = :id', { id: image })).toBeNull();

    expectDone(await holder.call('POST', `/products/${product}/delete`), 'POST /products/:id/delete');
    expect(await audits('PRODUCT_DELETED', product)).toBe(1);
  });

  // ---------------------------------------------------------------- clients, suppliers, associates

  it('clients, suppliers and associates', async () => {
    const client = await createTestClient();
    const company = await value('SELECT company_legal_name FROM clients WHERE id = :id', { id: client });
    expectPage(await holder.call('GET', `/clients/${client}/edit`), company, 'GET /clients/:id/edit');
    const number = `RT-OVR-${crypto.randomBytes(3).toString('hex').toUpperCase()}`;
    expectDone(await holder.call('POST', `/clients/${client}/override-unique-number`, { client_unique_number: number, reason: 'Route behaviour test override' }), 'POST /clients/:id/override-unique-number', number);
    expect(await value('SELECT client_unique_number FROM clients WHERE id = :id', { id: client })).toBe(number);

    const inactive = await createTestClient();
    await db.execute("UPDATE clients SET company_legal_name = 'Route Dormant Buyer', is_active = 0 WHERE id = :id", { id: inactive });
    expectPage(await holder.call('GET', '/clients/inactive'), 'Route Dormant Buyer', 'GET /clients/inactive');

    const supplier = await insert('suppliers', { supplier_legal_name: 'Route Test Supplier' });
    expectDone(await holder.call('POST', `/suppliers/${supplier}/details-link`), 'POST /suppliers/:id/details-link', 'Vendor Details Link');
    expect(await audits('VENDOR_DETAILS_LINK_GENERATED', supplier)).toBe(1);

    const associate = await insert('associates', { name: 'Route Test Associate', status: 'active' });
    expectDone(await holder.call('POST', `/associates/${associate}/clients`, { client_id: String(client), registered_on: todayIst() }), 'POST /associates/:id/clients');
    expect(await value('SELECT associate_id FROM clients WHERE id = :id', { id: client })).toBe(associate);
    expectDone(await holder.call('POST', `/associates/${associate}/clients/${client}/unlink`), 'POST /associates/:id/clients/:clientId/unlink');
    expect(await value('SELECT associate_id FROM clients WHERE id = :id', { id: client })).toBeNull();
    expect(await audits('ASSOCIATE_CLIENT_UNLINKED', client)).toBe(1);
  });

  // ---------------------------------------------------------------- orders

  it('order payment records and order settings', async () => {
    const order = await createTestOrder(await createTestClient());
    expectDone(await holder.call('POST', `/orders/${order}/dispute-visibility`, { dispute_button_visible_to_client: '1' }), 'POST /orders/:id/dispute-visibility');
    expect(Number(await value('SELECT dispute_button_visible_to_client FROM orders WHERE id = :id', { id: order }))).toBe(1);

    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: order });
    await db.execute(`UPDATE order_payment_status SET advance_amount = 1000, advance_remittance_received_at = NOW(), advance_inr_actual = 83000,
                      balance_amount = 2000, balance_remittance_received_at = NOW(), balance_inr_actual = 166000,
                      freight_amount = 300, freight_remittance_received_at = NOW() WHERE order_id = :o`, { o: order });

    expectDone(await holder.call('POST', `/orders/${order}/payment/balance/firc`, { balance_firc_reference: 'RT-FIRC-B', balance_firc_received_at: todayIst() }), 'POST /orders/:id/payment/balance/firc');
    expect(await value('SELECT balance_firc_reference FROM order_payment_status WHERE order_id = :o', { o: order })).toBe('RT-FIRC-B');
    expectDone(await holder.call('POST', `/orders/${order}/payment/freight/firc`, { freight_firc_reference: 'RT-FIRC-F', freight_firc_received_at: todayIst() }), 'POST /orders/:id/payment/freight/firc');
    expect(await value('SELECT freight_firc_reference FROM order_payment_status WHERE order_id = :o', { o: order })).toBe('RT-FIRC-F');

    expectDone(await holder.call('POST', `/orders/${order}/payment/freight/inr-actual`, { freight_inr_actual: '24900' }), 'POST /orders/:id/payment/freight/inr-actual');
    expect(Number(await value('SELECT freight_inr_actual FROM order_payment_status WHERE order_id = :o', { o: order }))).toBe(24900);

    for (const leg of ['advance', 'balance', 'freight']) {
      expectDone(await holder.call('POST', `/orders/${order}/payment/${leg}/inr-actual/delete`), `POST /orders/:id/payment/${leg}/inr-actual/delete`);
      expect([leg, await value(`SELECT ${leg}_inr_actual FROM order_payment_status WHERE order_id = :o`, { o: order })]).toEqual([leg, null]);
    }
    expect(await audits('CA_INR_ACTUAL_DELETED', order)).toBe(3);

    expectPage(await holder.call('GET', `/orders/${order}/zoho-check`), 'Zoho', 'GET /orders/:id/zoho-check');

    const archived = await createTestOrder(await createTestClient());
    const reference = `RT-ARCHIVED-${crypto.randomBytes(3).toString('hex')}`;
    await db.execute('UPDATE orders SET is_archived = 1, order_reference = :r WHERE id = :id', { r: reference, id: archived });
    expectPage(await holder.call('GET', '/orders/archived'), reference, 'GET /orders/archived');
  });

  it('order product lines and skipping the PI form', async () => {
    const order = await createTestOrder(await createTestClient());
    const hs = await value('SELECT code FROM hs_codes WHERE is_active = 1 ORDER BY id LIMIT 1');
    const line = await insert('order_products', { order_id: order, line_no: 1, description: 'Route line', quantity: 10, unit: 'pcs', unit_price: 12, hs_code: hs });

    expectDone(await holder.call('POST', `/orders/${order}/products/${line}`, { description: 'Route edited line', hs_code: hs, quantity: '12', unit: 'pcs', unit_price: '15', reason: 'Route behaviour test edit' }), 'POST /orders/:id/products/:productId');
    expect(await value('SELECT description FROM order_products WHERE id = :id', { id: line })).toBe('Route edited line');

    expectDone(await holder.call('POST', `/orders/${order}/products/${line}/duplicate`, { reason: 'Route behaviour test copy' }), 'POST /orders/:id/products/:productId/duplicate');
    expect(Number(await value("SELECT COUNT(*) AS n FROM order_products WHERE order_id = :o AND description LIKE 'Route edited line%'", { o: order }))).toBe(2);

    expectDone(await holder.call('POST', `/orders/${order}/products/${line}/delete`, { reason: 'Route behaviour test removal' }), 'POST /orders/:id/products/:productId/delete');
    expect(Number(await value('SELECT is_active FROM order_products WHERE id = :id', { id: line }))).toBe(0);

    expect((await holder.call('POST', `/orders/${order}/pi-form/skip`, { skip_reason: '' })).type).toBe('error');
    expect(await value('SELECT pi_form_skipped_at FROM orders WHERE id = :id', { id: order })).toBeNull();
    expectDone(await holder.call('POST', `/orders/${order}/pi-form/skip`, { skip_reason: 'Buyer sent all PI details by e-mail already' }), 'POST /orders/:id/pi-form/skip');
    expect(await value('SELECT pi_form_skipped_at FROM orders WHERE id = :id', { id: order })).not.toBeNull();
    expect(await audits('PI_FORM_SKIPPED', order)).toBe(1);
  });

  it('comment attachment download, vendor bill removal and reorder approval', async () => {
    const client = await createTestClient();
    const order = await createTestOrder(client);

    const comment = await insert('order_comments', { order_id: order, author_type: 'client', author_client_id: client, body: 'See attached' });
    const file = await createTestFile(order, client);
    await db.execute('UPDATE file_store SET server_path = :p, file_size_bytes = 19 WHERE id = :id', { p: tmpFile('pdf', '%PDF-1.4 route test'), id: file });
    await insert('order_comment_attachments', { comment_id: comment, file_store_id: file });
    const download = await holder.get(`/orders/${order}/comment-attachments/${file}/download`).buffer(true).parse(binary);
    expect(download.status).toBe(200);
    expect(download.body.toString()).toBe('%PDF-1.4 route test');

    const bill = await insert('order_vendor_bills', { order_id: order, vendor_kind: 'logistics', bill_for: 'freight', bill_no: 'RT-BILL-1', bill_date: todayIst(), taxable_inr: 1000, total_inr: 1000, created_by: holder.userId });
    const removed = await holder.call('POST', `/orders/${order}/vendor-bills/${bill}/delete`);
    expect(removed.status).toBeLessThan(400);
    expect(await value('SELECT id FROM order_vendor_bills WHERE id = :id', { id: bill })).toBeNull();
    expect(await audits('VENDOR_BILL_REMOVED', bill)).toBe(1);

    const request_ = await insert('order_reorder_requests', { client_id: client, source_order_id: order, status: 'pending' });
    const hs = await value('SELECT code FROM hs_codes WHERE is_active = 1 ORDER BY id LIMIT 1');
    expectDone(await holder.call('POST', `/reorder-requests/${request_}/approve`, { 'description[0]': 'Route reorder line', 'hs_code[0]': hs, 'quantity[0]': '5', 'unit[0]': 'pcs', 'unit_price[0]': '20' }), 'POST /reorder-requests/:id/approve', 'new order created');
    const newOrder = await value('SELECT new_order_id FROM order_reorder_requests WHERE id = :id', { id: request_ });
    expect(newOrder).toBeGreaterThan(0);
    expect(await value('SELECT description FROM order_products WHERE order_id = :o', { o: newOrder })).toBe('Route reorder line');
  });

  it("the supplier's Purchase Order download link", async () => {
    const order = await createTestOrder(await createTestClient());
    const token = crypto.randomBytes(16).toString('hex');
    const file = await createTestFile(order, null);
    await db.execute('UPDATE file_store SET server_path = :p, file_size_bytes = 20 WHERE id = :id', { p: tmpFile('pdf', '%PDF-1.4 supplier po'), id: file });
    const supplier = await insert('suppliers', { supplier_legal_name: 'Route PO Supplier' });
    await insert('order_supplier_po', {
      order_id: order, supplier_id: supplier, supplier_legal_name: 'Route PO Supplier', supplier_po_reference: 'RT-SPO-1',
      supplier_action_token_hash: crypto.createHash('sha256').update(token).digest('hex'),
      supplier_action_token_expires_at: new Date(Date.now() + 86400000),
    });
    const doc = await createTestDocument(order, 'SUPPO', 'sent');
    await db.execute('UPDATE documents SET pdf_file_id = :f WHERE id = :d', { f: file, d: doc });

    const res = await request(app).get(`/supplier-action/${token}/po-document`).buffer(true).parse(binary);
    expect(res.status).toBe(200);
    expect(res.body.toString()).toBe('%PDF-1.4 supplier po');
    const unknown = await request(app).get(`/supplier-action/${'0'.repeat(32)}/po-document`);
    expect(unknown.text).not.toContain('%PDF');
    expect(unknown.text.toLowerCase()).toContain('expired');
  });

  // ---------------------------------------------------------------- documents, reviews, amendments, e-mail

  it('reviews and cross-verification', async () => {
    const order = await createTestOrder(await createTestClient());
    const doc = await createTestDocument(order, 'QT', 'draft');
    const reviewerUser = await staffUser('Managing Director');
    expectDone(await holder.call('POST', `/documents/${doc}/reviewers`, { 'reviewer_ids[]': String(reviewerUser.id) }), 'POST /documents/:id/reviewers', 'in review');
    const review = await value('SELECT id FROM document_reviews WHERE document_id = :d AND reviewer_id = :r', { d: doc, r: reviewerUser.id });
    expect(review).toBeGreaterThan(0);

    const reviewer = await signIn(reviewerUser);
    expectDone(await reviewer.call('POST', `/reviews/${review}/approve`, { comments: 'Looks right' }), 'POST /reviews/:id/approve');
    expect(await value('SELECT status FROM document_reviews WHERE id = :id', { id: review })).toBe('approved');

    expectDone(await holder.call('POST', `/documents/${doc}/cross-verify`, { result: 'pass', comments: 'Figures match' }), 'POST /documents/:id/cross-verify');
    expect(await value('SELECT result FROM document_cross_verifications WHERE document_id = :d', { d: doc })).toBe('pass');
  });

  it('amendment reference override and signed copy', async () => {
    const order = await createTestOrder(await createTestClient());
    const reference = `RT-AMD-${crypto.randomBytes(3).toString('hex')}`;
    const amendment = await insert('amendments', { amendment_reference: reference, order_id: order, reason: 'Route test', requested_by: 'exporter', original_terms_snapshot: '{}', status: 'md_approved' });
    expectDone(await holder.call('POST', `/amendments/${amendment}/override-reference`, { amendment_reference: `${reference}-B`, reason: 'Route behaviour test override' }), 'POST /amendments/:id/override-reference', `${reference}-B`);
    expect(await value('SELECT amendment_reference FROM amendments WHERE id = :id', { id: amendment })).toBe(`${reference}-B`);

    // With no file attached the signed copy is refused and the amendment stays as it was.
    const r = await holder.call('POST', `/amendments/${amendment}/signed-copy`);
    expect(r.type).toBe('error');
    expect(await value('SELECT status FROM amendments WHERE id = :id', { id: amendment })).toBe('md_approved');
    expect(await value('SELECT signed_copy_file_id FROM amendments WHERE id = :id', { id: amendment })).toBeNull();
  });

  it('queued e-mails', async () => {
    const order = await createTestOrder(await createTestClient());
    const requester = (await staffUser('Export Executive')).id;
    const queue = (status, by) => insert('email_log', { order_id: order, recipient_email: 'buyer@route.test', subject: 'Route test', body_snapshot: 'Hello', status, requested_by: by });

    const toReject = await queue('pending_approval', requester);
    expectDone(await holder.call('POST', `/email-log/${toReject}/reject`, { reason: 'Wrong attachment for this buyer' }), 'POST /email-log/:id/reject');
    expect(await value('SELECT status FROM email_log WHERE id = :id', { id: toReject })).toBe('rejected');
    expect(await audits('EMAIL_SEND_REJECTED', toReject)).toBe(1);

    const toCancel = await queue('pending_approval', holder.userId);
    expectDone(await holder.call('POST', `/email-log/${toCancel}/cancel`, { order_id: String(order), reason: 'Buyer asked us to wait a day' }), 'POST /email-log/:id/cancel');
    expect(await value('SELECT status FROM email_log WHERE id = :id', { id: toCancel })).toBe('cancelled');

    // Send now: mail sending is off in the test database, so the attempt is made and reported.
    const toSend = await queue('approved', requester);
    const r = await holder.call('POST', `/email-log/${toSend}/send-now`);
    expect(r.flash).not.toBeNull();
    expect(await audits('EMAIL_SEND_FORCED_NOW', toSend)).toBe(1);
    expect(await value('SELECT status FROM email_log WHERE id = :id', { id: toSend })).not.toBe('approved');
  });

  // ---------------------------------------------------------------- CA module and incentives

  it('CA bank lines, expenses and export benefits', async () => {
    const expense = await insert('ca_expenses', { zoho_expense_id: `RT-EXP-${crypto.randomBytes(3).toString('hex')}`, category: 'Freight', amount: 1180, expense_date: todayIst() });
    const line = await insert('ca_bank_statement_lines', { line_hash: crypto.randomBytes(16).toString('hex'), transaction_date: todayIst(), description: 'Route test debit', debit_amount: 1180 });

    expectDone(await holder.call('POST', `/ca/bank-statement/${line}/match-expense`, { expense_id: String(expense) }), 'POST /ca/bank-statement/:id/match-expense');
    expect(await value('SELECT matched_expense_id FROM ca_bank_statement_lines WHERE id = :id', { id: line })).toBe(expense);
    expectDone(await holder.call('POST', `/ca/bank-statement/${line}/unmatch`), 'POST /ca/bank-statement/:id/unmatch');
    expect(await value('SELECT matched_expense_id FROM ca_bank_statement_lines WHERE id = :id', { id: line })).toBeNull();

    expectDone(await holder.call('POST', `/ca/expenses/${expense}/tds`, { is_tds_applicable: '1', tds_amount: '18' }), 'POST /ca/expenses/:id/tds');
    expect(Number(await value('SELECT tds_amount FROM ca_expenses WHERE id = :id', { id: expense }))).toBe(18);

    const claim = await insert('ca_export_benefits', { scheme_name: 'RoDTEP', claimed_amount: 5000, claimed_at: todayIst(), recorded_by: holder.userId });
    expectDone(await holder.call('POST', `/ca/export-benefits/${claim}/mark-received`, { received_amount: '4900', received_at: todayIst(), received_reference: 'ESCRIP-0002' }), 'POST /ca/export-benefits/:id/mark-received');
    expect(Number(await value('SELECT received_amount FROM ca_export_benefits WHERE id = :id', { id: claim }))).toBe(4900);
    expect(await audits('CA_EXPORT_BENEFIT_RECEIVED', claim)).toBe(1);
  });

  it('incentive payouts paid', async () => {
    const order = await createTestOrder(await createTestClient());
    const month = todayIst().slice(0, 7);
    const payout = () => insert('incentive_payouts', { order_id: order, payee_kind: 'staff', user_id: holder.userId, role: 'sales', amount_inr: 5000, incentive_month: month, status: 'due' });

    const one = await payout();
    expectDone(await holder.call('POST', `/incentives/payouts/${one}/paid`, { month, paid_on: todayIst(), paid_inr: '5000', tds_inr: '500', paid_via: 'salary', paid_reference: 'Payroll TEST' }), 'POST /incentives/payouts/:payoutId/paid');
    expect(await value('SELECT status FROM incentive_payouts WHERE id = :id', { id: one })).toBe('paid');
    expect(Number(await value('SELECT tds_inr FROM incentive_payouts WHERE id = :id', { id: one }))).toBe(500);

    const [a, b] = [await payout(), await payout()];
    expectDone(await holder.call('POST', '/incentives/payouts/paid-all', { month, paid_on: todayIst(), 'payout_ids[]': [String(a), String(b)], paid_via: 'salary', paid_reference: 'Payroll TEST' }), 'POST /incentives/payouts/paid-all', '2 payments recorded');
    expect(Number(await value("SELECT COUNT(*) AS n FROM incentive_payouts WHERE id IN (:a, :b) AND status = 'paid'", { a, b }))).toBe(2);
  });

  // ---------------------------------------------------------------- reports

  it('report and CA pages open', async () => {
    await createTestOrder(await createTestClient());
    const pages = [
      '/reports/ageing', '/reports/aggregate', '/reports/amendments', '/reports/conversion', '/reports/disputes',
      '/reports/order-profitability', '/reports/payments', '/reports/performance', '/reports/products', '/reports/queues',
      '/reports/staff', '/reports/suppliers', '/reports/trends',
      '/ca/bank-statement', '/ca/export-benefits', '/ca/fy-locks', '/ca/reconciliation', '/ca/reports', '/ca/tds-summary',
      '/email-approvals', '/admin/permissions',
    ];
    for (const page of pages) {
      const r = await holder.call('GET', page);
      expect([page, r.status, r.body.includes('<h1')]).toEqual([page, 200, true]);
    }

    const scheme = await value("SELECT option_value FROM dropdown_options WHERE list_key = 'export_benefit_scheme' ORDER BY id LIMIT 1");
    const reference = `RT-CLAIM-${crypto.randomBytes(3).toString('hex')}`;
    expectDone(await holder.call('POST', '/ca/export-benefits', { scheme_name: scheme, claimed_amount: '1250', claimed_at: todayIst(), reference_number: reference }), 'POST /ca/export-benefits', 'claim recorded');
    const claim = await value('SELECT id FROM ca_export_benefits WHERE reference_number = :r', { r: reference });
    expect(await audits('CA_EXPORT_BENEFIT_RECORDED', claim)).toBe(1);
  });

  it('associate and partner edits, the balance exchange rate and a review rejection', async () => {
    const associate = await insert('associates', { name: 'Route Test Associate', based_in: 'india', currency_code: 'INR', status: 'active' });
    const rates = { rate_1_5: '5', rate_6_10: '5', rate_11_20: '4', rate_21_40: '3', rate_41_plus: '2' };
    expectDone(await holder.call('POST', `/associates/${associate}/update`, { name: 'Route Test Associate B', based_in: 'india', currency_code: 'INR', status: 'active', ...rates }), 'POST /associates/:id/update');
    expect(await value('SELECT name FROM associates WHERE id = :id', { id: associate })).toBe('Route Test Associate B');
    expect(await audits('ASSOCIATE_UPDATED', associate)).toBe(1);

    const partner = await insert('logistics_partners', { partner_name: 'Route Test CHA', service_type: 'cha', is_active: 1 });
    expectDone(await holder.call('POST', `/logistics-partners/${partner}/update`, { partner_name: 'Route Test CHA B', service_type: 'cha' }), 'POST /logistics-partners/:id/update');
    expect(await value('SELECT partner_name FROM logistics_partners WHERE id = :id', { id: partner })).toBe('Route Test CHA B');
    expect(await audits('LOGISTICS_PARTNER_UPDATED', partner)).toBe(1);
    expectDone(await holder.call('POST', `/logistics-partners/${partner}/details-link`), 'POST /logistics-partners/:id/details-link', 'Vendor Details Link');
    expect(await audits('VENDOR_DETAILS_LINK_GENERATED', partner)).toBe(1);

    const order = await createTestOrder(await createTestClient());
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: order });
    await db.execute('UPDATE order_payment_status SET balance_amount = 2000, balance_remittance_received_at = NOW() WHERE order_id = :o', { o: order });
    expectDone(await holder.call('POST', `/orders/${order}/payment/balance/exchange-rate`, { balance_exchange_rate: '83.25' }), 'POST /orders/:id/payment/balance/exchange-rate');
    expect(Number(await value('SELECT balance_exchange_rate FROM order_payment_status WHERE order_id = :o', { o: order }))).toBe(83.25);
    expect(await audits('CA_EXCHANGE_RATE_RECORDED', order)).toBe(1);

    const doc = await createTestDocument(order, 'QT', 'draft');
    const reviewerUser = await staffUser('Managing Director');
    expectDone(await holder.call('POST', `/documents/${doc}/reviewers`, { 'reviewer_ids[]': String(reviewerUser.id) }), 'assign a reviewer');
    const review = await value('SELECT id FROM document_reviews WHERE document_id = :d AND reviewer_id = :r', { d: doc, r: reviewerUser.id });
    const reviewer = await signIn(reviewerUser);
    expectDone(await reviewer.call('POST', `/reviews/${review}/reject`, { comments: 'The freight figure is wrong' }), 'POST /reviews/:id/reject', 'sent back');
    expect(await value('SELECT status FROM document_reviews WHERE id = :id', { id: review })).toBe('rejected');
  });

  // ---------------------------------------------------------------- missing ids

  /** A complete associate form, so the missing record is what gets refused, not a blank field. */
  const VALID_ASSOCIATE = { name: 'Route Ghost Associate', based_in: 'india', currency_code: 'INR', status: 'active', rate_1_5: '5', rate_6_10: '5', rate_11_20: '4', rate_21_40: '3', rate_41_plus: '2' };

  /** The routes QA listed (plus five found since) whose path carries a record id. */
  const ID_ROUTES = [
    ['POST', '/admin/field-protection/:id/approve'], ['POST', '/admin/field-protection/:id/reject'],
    ['POST', '/admin/permission-definitions/:id/delete'], ['GET', '/admin/permission-definitions/:id/edit'],
    ['POST', '/admin/permissions/:id/remove'], ['POST', '/admin/roles/:id/delete'], ['GET', '/admin/roles/:id/edit'],
    ['POST', '/amendments/:id/override-reference'], ['POST', '/amendments/:id/signed-copy'],
    ['POST', '/associates/:id/clients'], ['POST', '/associates/:id/clients/:id/unlink'], ['POST', '/associates/:id/update', VALID_ASSOCIATE],
    ['POST', '/ca/bank-statement/:id/match-expense'], ['POST', '/ca/bank-statement/:id/unmatch'],
    ['POST', '/ca/expenses/:id/tds'], ['POST', '/ca/export-benefits/:id/mark-received'],
    ['GET', '/clients/:id/edit'], ['POST', '/clients/:id/override-unique-number'],
    ['POST', '/company-assets/:id/delete'],
    ['POST', '/compliance-task-types/:id/delete'], ['GET', '/compliance-task-types/:id/edit'], ['POST', '/compliance-task-types/:id/update'],
    ['POST', '/documents/:id/cross-verify'], ['POST', '/documents/:id/reviewers'],
    ['POST', '/email-log/:id/cancel'], ['POST', '/email-log/:id/reject'], ['POST', '/email-log/:id/send-now'],
    ['GET', '/email-templates/:id/edit'],
    ['POST', '/holidays/:id/delete'], ['POST', '/holidays/:id/update'],
    ['POST', '/hs-codes/:id/delete'], ['POST', '/hs-codes/:id/toggle'], ['POST', '/hs-codes/product-guide/:id/delete'],
    ['POST', '/incentives/payouts/:id/paid'],
    ['POST', '/logistics-partners/:id/delete'], ['GET', '/logistics-partners/:id/edit'], ['POST', '/logistics-partners/:id/toggle'],
    ['POST', '/logistics-partners/:id/update'], ['POST', '/logistics-partners/:id/details-link'],
    ['GET', '/orders/:id/comment-attachments/:id/download'], ['POST', '/orders/:id/dispute-visibility'],
    ['POST', '/orders/:id/payment/advance/inr-actual/delete'], ['POST', '/orders/:id/payment/balance/firc'], ['POST', '/orders/:id/payment/balance/exchange-rate'],
    ['POST', '/orders/:id/payment/balance/inr-actual/delete'], ['POST', '/orders/:id/payment/freight/firc'],
    ['POST', '/orders/:id/payment/freight/inr-actual'], ['POST', '/orders/:id/payment/freight/inr-actual/delete'],
    ['POST', '/orders/:id/pi-form/skip'], ['POST', '/orders/:id/products/:id'], ['POST', '/orders/:id/products/:id/delete'],
    ['POST', '/orders/:id/products/:id/duplicate'], ['POST', '/orders/:id/vendor-bills/:id/delete'], ['GET', '/orders/:id/zoho-check'],
    ['GET', '/payment-presets/:id/edit'], ['POST', '/payment-presets/:id/toggle'], ['POST', '/payment-presets/:id/update'],
    ['GET', '/port-routes/:id/edit'], ['POST', '/port-routes/:id/update'],
    ['GET', '/ports/:id/edit'], ['POST', '/ports/:id/set-default'], ['POST', '/ports/:id/toggle'], ['POST', '/ports/:id/update'],
    ['GET', '/products/:id'], ['POST', '/products/:id/delete'], ['GET', '/products/:id/edit'], ['POST', '/products/:id/images/upload'],
    ['POST', '/products/:id/misc-charges/add'], ['POST', '/products/:id/suppliers/add'], ['POST', '/products/:id/update'],
    ['POST', '/products/images/:id/delete'], ['GET', '/products/images/:id/view'], ['POST', '/products/misc-charges/:id/delete'],
    ['POST', '/products/suppliers/:id/delete'], ['POST', '/products/suppliers/:id/set-primary'], ['POST', '/products/suppliers/:id/update'],
    ['POST', '/reference-docs/categories/:id/delete'], ['POST', '/reference-docs/categories/:id/update'],
    ['POST', '/reference-docs/custom/:id/delete'], ['GET', '/reference-docs/custom/:id/edit'],
    ['POST', '/reorder-requests/:id/approve'], ['POST', '/reviews/:id/approve'], ['POST', '/reviews/:id/reject'],
    ['GET', '/signatories/user-assets/:id/preview'], ['POST', '/signatories/users/:id/eligibility'],
    ['POST', '/suppliers/:id/details-link'],
    ['GET', '/users/:id/edit'], ['POST', '/users/:id/toggle-active'], ['POST', '/users/:id/unarchive'], ['POST', '/users/:id/update'],
  ];

  it('every route answers a missing id as not found and writes nothing', async () => {
    // Other test files run alongside this one, so the check is on audit rows for the missing id itself.
    const auditBefore = Number(await value('SELECT COUNT(*) AS n FROM audit_log WHERE entity_id = 999999999'));
    const wrong = [];
    for (const [method, pattern, fields = {}] of ID_ROUTES) {
      const r = await holder.call(method, pattern.replace(/:id/g, '999999999'), { reason: 'Route behaviour test of a missing record', ...fields });
      const notFound = r.status === 404 || ['error', 'warning'].includes(r.type);
      if (!notFound || r.status >= 500) wrong.push(`${method} ${pattern} → ${r.status} ${r.type || ''} ${r.flash || r.body.replace(/<[^>]+>/g, ' ').trim().slice(0, 80)}`);
    }
    expect(wrong).toEqual([]);
    expect(ID_ROUTES.length).toBe(90);
    expect(Number(await value('SELECT COUNT(*) AS n FROM audit_log WHERE entity_id = 999999999'))).toBe(auditBefore);
  }, 120000);
});
