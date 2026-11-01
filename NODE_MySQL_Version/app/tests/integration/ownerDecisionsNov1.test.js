'use strict';

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OwnerDecisionsNov1Test.php. Owner decisions of 1 Nov 2026
 * ("A all yes, B all yes, C all as recommended"), driven through the real
 * app as the person concerned.
 */
describe('Owner decisions of 1 Nov 2026', () => {
  const PASSWORD = 'JestOwnerNov1!';
  const csrfOf = (html) => (html.match(/name="_csrf"\s+value="([^"]+)"/) || [])[1];

  afterAll(async () => {
    await db.pool.end();
  });

  async function staff(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-own-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Owner Decisions', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    const agent = request.agent(app);
    const loginCsrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: loginCsrf, email, password: PASSWORD });
    agent.userId = id;
    agent.email = email;
    agent.csrf = csrfOf((await agent.get('/account')).text);
    return agent;
  }

  async function post(agent, path, fields) {
    const res = await agent.post(path).type('form').send({ _csrf: agent.csrf, ...fields });
    if (res.status !== 302) return { status: res.status, type: null, message: '' };
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { status: 302, type: m[1], message: m[2].trim().replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&') } : { status: 302, type: null, message: '' };
  }

  const roleId = async (name) => Number((await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: name })).id);
  const permissionId = async (key) => Number((await db.queryOne('SELECT id FROM permissions WHERE permission_key = :k', { k: key })).id);
  const rolePermissionIds = async (id) => (await db.query('SELECT permission_id FROM role_permissions WHERE role_id = :r', { r: id })).map((r) => Number(r.permission_id));
  const sorted = (a) => [...a].sort((x, y) => x - y);

  it('A-8: System Administrator cannot download the whole database', async () => {
    expect((await (await staff('System Administrator')).get('/admin/data-export')).status).toBe(403);
    expect((await (await staff('Managing Director')).get('/admin/data-export')).status).toBe(200);
  });

  it('A-8: role permissions cannot be raised beyond the editor\'s own', async () => {
    const sysAdmin = await staff('System Administrator');
    const own = await roleId('System Administrator');
    const before = await rolePermissionIds(own);
    expect((await post(sysAdmin, `/admin/roles/${own}/permissions`, { permission_ids: [...before, await permissionId('view_purchase_prices')].map(String) })).message)
      .toBe('You cannot change the permissions of "System Administrator": it is a role you work under. Ask another administrator.');
    expect(sorted(await rolePermissionIds(own))).toEqual(sorted(before));

    const other = await roleId('Viewer / Auditor');
    const otherBefore = await rolePermissionIds(other);
    const r = await post(sysAdmin, `/admin/roles/${other}/permissions`, { permission_ids: [...otherBefore, await permissionId('view_cafin')].map(String) });
    expect(r.message.startsWith('You cannot give the "')).toBe(true);
    expect(r.message.endsWith('permission: you do not hold it yourself. Ask someone who holds it. Nothing was changed.')).toBe(true);
    expect(sorted(await rolePermissionIds(other))).toEqual(sorted(otherBefore));

    expect((await post(sysAdmin, `/admin/roles/${other}/permissions`, { permission_ids: [...otherBefore, await permissionId('manage_ports')].map(String) })).type).toBe('success');
    expect(await rolePermissionIds(other)).toContain(await permissionId('manage_ports'));

    const admin = await staff('Admin');
    expect((await post(admin, `/admin/roles/${other}/permissions`, { permission_ids: [...otherBefore, await permissionId('view_cafin')].map(String) })).type).toBe('success');
    const adminRole = await roleId('Admin');
    expect((await post(admin, `/admin/roles/${adminRole}/permissions`, { permission_ids: (await rolePermissionIds(adminRole)).map(String) })).message).toContain('it is a role you work under');
    // Leave Viewer / Auditor as the seed has it: other test files check who holds view_cafin.
    expect((await post(admin, `/admin/roles/${other}/permissions`, { permission_ids: otherBefore.map(String) })).type).toBe('success');
  });

  it('A-8: personal exceptions follow the same rule', async () => {
    const sysAdmin = await staff('System Administrator');
    const colleague = await staff('Export Executive');
    const base = { reason: 'Covering for a colleague this month', expires_at: '' };
    expect((await post(sysAdmin, '/admin/permissions/grant', { user_id: String(sysAdmin.userId), permission_id: String(await permissionId('manage_ports')), ...base })).message)
      .toBe('You cannot give yourself an extra permission. Ask another administrator.');
    expect((await post(sysAdmin, '/admin/permissions/grant', { user_id: String(colleague.userId), permission_id: String(await permissionId('view_purchase_prices')), ...base })).message)
      .toContain('you do not hold it yourself');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM user_permissions WHERE user_id IN (:a, :b)', { a: sysAdmin.userId, b: colleague.userId })).n)).toBe(0);
    expect((await post(sysAdmin, '/admin/permissions/grant', { user_id: String(colleague.userId), permission_id: String(await permissionId('manage_ports')), ...base })).type).toBe('success');
  });

  it('A-8: roles and memberships cannot lift anyone above the editor', async () => {
    const sysAdmin = await staff('System Administrator');
    expect((await post(sysAdmin, `/users/${sysAdmin.userId}/update`, { name: 'Jest Owner Decisions', email: sysAdmin.email, phone: '', role_id: String(await roleId('Admin')) })).message)
      .toBe('You cannot change your own role. Ask another administrator.');

    const colleague = await staff('Viewer / Auditor');
    expect((await post(sysAdmin, `/users/${colleague.userId}/update`, { name: 'Jest Owner Decisions', email: colleague.email, phone: '', role_id: String(await roleId('Finance Director')) })).message)
      .toContain('you do not hold it yourself. Ask someone who holds it. The role was not changed.');
    expect(Number((await db.queryOne('SELECT role_id FROM users WHERE id = :id', { id: colleague.userId })).role_id)).toBe(await roleId('Viewer / Auditor'));

    const financeDirector = Number((await db.queryOne("SELECT id FROM department_designations WHERE title = 'Finance Director'")).id);
    expect((await post(sysAdmin, `/users/${sysAdmin.userId}/memberships`, { designation_id: String(financeDirector), starts_on: todayIst() })).message)
      .toBe('You cannot give yourself a department membership. Ask another administrator.');
    expect((await post(sysAdmin, `/users/${colleague.userId}/memberships`, { designation_id: String(financeDirector), starts_on: todayIst() })).message)
      .toContain('you do not hold it yourself');
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM user_department_memberships WHERE user_id IN (:a, :b)', { a: sysAdmin.userId, b: colleague.userId })).n)).toBe(0);

    expect((await post(await staff('Managing Director'), `/users/${colleague.userId}/memberships`, { designation_id: String(financeDirector), starts_on: todayIst() })).type).toBe('success');
  });

  it('A-7 / A-9: each report needs only the money it shows', async () => {
    const logistics = await staff('Logistics Manager');
    expect((await logistics.get('/reports/freight-cost')).status).toBe(200);
    expect((await logistics.get('/reports/suppliers')).status).toBe(403);
    expect((await logistics.get('/')).text).toContain('href="/reports/freight-cost"');

    const sales = await staff('Export Executive');
    expect((await sales.get('/reports/suppliers')).status).toBe(403);
    expect((await sales.get('/reports')).text).not.toContain('href="/reports/suppliers"');

    expect((await (await staff('Procurement Executive')).get('/reports/suppliers')).status).toBe(200);
    expect((await (await staff('Finance Manager')).get('/reports/suppliers')).status).toBe(200);
  });

  it('C-8: Procurement Executive manages suppliers, Sales does not; System Administrator has no data export', async () => {
    const has = async (role, key) => Number((await db.queryOne(
      `SELECT COUNT(*) AS n FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
        WHERE r.name = :r AND p.permission_key = :k`, { r: role, k: key })).n) === 1;
    expect(await has('Procurement Executive', 'manage_suppliers')).toBe(true);
    expect(await has('Export Executive', 'manage_suppliers')).toBe(false);
    expect(await has('System Administrator', 'data_export_run')).toBe(false);
  });

  it('C-4: zero advance only on a Letter of Credit preset', async () => {
    const admin = await staff('Admin');
    const usd = Number((await db.queryOne("SELECT id FROM currencies WHERE code = 'USD'")).id);
    const name = `TEST-Zero ${crypto.randomBytes(3).toString('hex')}`;
    const preset = { preset_name: name, advance_trigger_text: 'against Proforma Invoice', payment_method: 'CASH_ADVANCE', advance_pct: '0', balance_pct: '100',
      balance_trigger_option: 'A_BEFORE_SHIPMENT', balance_days: '3', balance_trigger_wording: '', currency_id: String(usd) };
    expect((await post(admin, '/payment-presets', preset)).message).toBe('Advance % is 0: tick "May run without an advance (0%)" (Letter of Credit presets only), or enter the advance %.');
    expect((await post(admin, '/payment-presets', { ...preset, allow_zero_advance: '1' })).message)
      .toBe('Only a Letter of Credit preset may run without an advance — untick "May run without an advance (0%)" or choose Letter of Credit.');
    expect((await post(admin, '/payment-presets', { ...preset, allow_zero_advance: '1', payment_method: 'LC' })).type).toBe('success');
    expect(Number((await db.queryOne('SELECT allow_zero_advance FROM payment_presets WHERE preset_name = :n', { n: name })).allow_zero_advance)).toBe(1);
    expect(Number((await db.queryOne("SELECT allow_zero_advance FROM payment_presets WHERE preset_name = 'Irrevocable Letter of Credit at Sight'")).allow_zero_advance)).toBe(1);

    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer');
    expect((await post(admin, `/orders/${orderId}/amendments`, { reason: 'Buyer asked to drop the advance', requested_by: 'importer', amended_advance_pct: '0' })).message)
      .toBe('Amended advance %: 0% is allowed only on a Letter of Credit order. Enter the advance %.');
  });

  it('C-14: the Zoho secrets never reach the page or the audit log', async () => {
    await db.execute("UPDATE company_settings SET setting_value = 'super-secret-value-WXYZ' WHERE setting_key = 'zoho_books_client_secret'");
    const md = await staff('Managing Director');
    const page = (await md.get('/settings')).text;
    expect(page).not.toContain('super-secret-value');
    expect(page).toContain('•••• stored, ends in WXYZ');

    await post(md, '/settings/update', { settings: { zoho_books_client_secret: '' }, reason: 'Checking the secret stays as it is' });
    expect((await db.queryOne("SELECT setting_value FROM company_settings WHERE setting_key = 'zoho_books_client_secret'")).setting_value).toBe('super-secret-value-WXYZ');

    const r = await post(md, '/settings/update', { settings: { zoho_books_client_secret: 'brand-new-secret-ABCD' }, unlocked: { zoho_books_client_secret: '1' }, reason: 'Zoho secret rotated today' });
    expect(r.type).toBe('success');
    expect((await db.queryOne("SELECT setting_value FROM company_settings WHERE setting_key = 'zoho_books_client_secret'")).setting_value).toBe('brand-new-secret-ABCD');
    const audit = await db.queryOne("SELECT old_value, new_value FROM audit_log WHERE action_type = 'FIELD_EDIT' AND field_name = 'zoho_books_client_secret' ORDER BY id DESC LIMIT 1");
    expect(audit).toEqual({ old_value: '(secret, ends in WXYZ)', new_value: '(secret, ends in ABCD)' });
    expect((await md.get('/audit-log?entity_type=company_settings')).text).not.toContain('brand-new-secret');
  });

  it('C-7a: every order has an assignee and always keeps at least one', async () => {
    const orderDuplicationService = require('../../src/services/orderDuplicationService');
    const orderAssignmentService = require('../../src/services/orderAssignmentService');
    const orderAssigneeRepository = require('../../src/repositories/orderAssigneeRepository');
    const creator = await staff('Export Executive');
    const orderId = await orderDuplicationService.duplicate(await createTestOrder(await createTestClient()), creator.userId);
    const ids = async () => (await orderAssigneeRepository.forOrder(orderId)).map((a) => Number(a.user_id));
    expect(await ids()).toEqual([creator.userId]);

    const md = await staff('Managing Director');
    await expect(orderAssignmentService.removeAssignee(orderId, creator.userId, md.userId))
      .rejects.toThrow('An order always keeps at least one assignee — add the person taking over first, then remove this one.');
    await orderAssignmentService.addAssignee(orderId, md.userId, md.userId);
    await orderAssignmentService.removeAssignee(orderId, creator.userId, md.userId);
    expect(await ids()).toEqual([md.userId]);
  });

  it('C-7b: the daily digest lists what waits on each person, once a day', async () => {
    const dailyDigestService = require('../../src/services/dailyDigestService');
    const purchasingRepository = require('../../src/repositories/purchasingRepository');
    const departmentTaskRepository = require('../../src/repositories/departmentTaskRepository');
    const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
    const orderRepository = require('../../src/repositories/orderRepository');
    const person = await staff('Procurement Manager');
    const idle = await staff('Viewer / Auditor');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [person.userId]);
    const order = await orderRepository.find(orderId);
    await purchasingRepository.create(orderId, null, 'Granite slabs', null, 10, 'sqm', todayIst(), person.userId, null);
    const proc = Number((await db.queryOne("SELECT id FROM departments WHERE code = 'PROC'")).id);
    await departmentTaskRepository.setOwner(orderId, proc, person.userId, person.userId);
    const docType = await db.queryOne("SELECT id, name FROM document_types WHERE code = 'QT'");
    const docId = (await db.execute("INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, 'TEST-QT-DIGEST-1', 'draft')", { o: orderId, t: docType.id })).insertId;
    await documentReviewRepository.assign(docId, person.userId);

    const items = await dailyDigestService.itemsFor(person.userId);
    expect(items).toContainEqual({ kind: 'review', text: `TEST-QT-DIGEST-1 (${docType.name}) — waiting for your review` });
    expect(items.filter((i) => i.kind === 'task' && i.text.startsWith(`${order.order_reference} — `))).toHaveLength(1);
    expect(await dailyDigestService.itemsFor(idle.userId)).toEqual([]);

    const mail = dailyDigestService.compose('Asha', [{ kind: 'task', text: 'A' }, { kind: 'review', text: 'B' }], '2026-11-02');
    expect(mail.subject).toBe('NexaCrest — your day 2 Nov 2026: 1 task, 1 review');
    expect(mail.body).toBe('Good morning Asha,\n\nThis is what waits on you today.\n\nTasks (My tasks):\n- A\n\nDocuments to review (My Reviews):\n- B\n\nOpen the app to act on them. You get this e-mail once a day, only when something waits on you.\n');

    const date = `2099-01-0${1 + Math.floor(Math.random() * 9)}`;
    dailyDigestService.setSender(async () => true);
    try {
      await dailyDigestService.run(date);
      await dailyDigestService.run(date);
    } finally {
      dailyDigestService.setSender(null);
    }
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM daily_digest_log WHERE user_id = :u AND digest_date = :d', { u: person.userId, d: date })).n)).toBe(1);
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM daily_digest_log WHERE user_id = :u', { u: idle.userId })).n)).toBe(0);
  });
});
