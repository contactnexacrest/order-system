'use strict';

/**
 * Owner decisions 11 Nov 2026 — D-310 (point 27): one Approvals page, the
 * sidebar "Approvals (n)", the dashboard tiles and the order page's "Waiting
 * for your approval" bar; D-312 (point 4 B): Admin › Setup checklist; D-313
 * (point 4): ready-to-use defaults and no company values in the starting data.
 * Parity: ApprovalsAndSetupD310Test.php.
 */
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const approvalsInbox = require('../../src/services/approvalsInbox');
const setupChecklist = require('../../src/services/setupChecklist');
const selfApprovalRules = require('../../src/services/selfApprovalRules');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const notificationRepository = require('../../src/repositories/notificationRepository');
const uiTemplateRepository = require('../../src/repositories/uiTemplateRepository');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

describe('D-310 / D-312 / D-313: Approvals page and Setup checklist', () => {
  const PASSWORD = 'JestApprovals!2026';

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const email = `jest-d310-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Approvals User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id, email };
  }

  async function permissionsOf(roleId) {
    const rows = await db.query('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r AND rp.is_enabled = 1', { r: roleId });
    return Object.fromEntries(rows.map((r) => [r.permission_key, true]));
  }

  async function reqFor(user) {
    return { user: { id: user.id, role_id: user.roleId }, permissions: await permissionsOf(user.roleId), session: {} };
  }

  async function itemsOf(user) {
    return Object.fromEntries((await approvalsInbox.items(await reqFor(user))).map((i) => [i.key, i]));
  }

  async function signedIn(user) {
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return agent;
  }

  /** A USD order with line 1 sent to the MD by `asker`. */
  async function lineWaitingForTheMd(asker) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [asker.id]);
    const r = await db.execute(
      `INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, price_set_by, price_approval_requested_at, price_approval_requested_by)
       VALUES (:o, 1, 'Granite slabs', 10, 'PCS', 40, 400, :u, NOW(), :u2)`, { o: orderId, u: asker.id, u2: asker.id });
    return [orderId, r.insertId];
  }

  it('writes "waiting since" plainly', () => {
    expect(approvalsInbox.sinceLabel('2026-11-11 10:42:00', '2026-11-11')).toBe('Today 10:42');
    expect(approvalsInbox.sinceLabel('2026-11-10 16:05:00', '2026-11-11')).toBe('Yesterday 16:05');
    expect(approvalsInbox.sinceLabel('2026-03-01 09:00:00', '2026-03-02')).toBe('Yesterday 09:00');
    expect(approvalsInbox.sinceLabel('2026-11-09 08:00:00', '2026-11-11')).toBe('9 Nov');
    expect(approvalsInbox.sinceLabel('2025-11-09', '2026-11-11')).toBe('9 Nov 2025');
    expect(approvalsInbox.when('2026-11-11 10:42:00')).toBe('11 Nov, 10:42');
  });

  it('a line price waits for the MD on every screen and the asker sees it waiting', async () => {
    const sales = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    const [orderId, lineId] = await lineWaitingForTheMd(sales);

    const item = (await itemsOf(md))[`price:${lineId}`];
    expect(item).toBeTruthy();
    expect(item.tab).toBe('prices');
    expect(item.url).toBe(`/orders/${orderId}/products/${lineId}/price-approval`);
    expect(item.button).toBe('Review and approve');
    expect(item.bar.startsWith('line 1 — USD 40.00 / PCS')).toBe(true);
    expect((await itemsOf(sales))[`price:${lineId}`]).toBeUndefined();
    const waiting = await approvalsInbox.waitingOnOthers(await reqFor(sales), orderId);
    expect((waiting[0] || '').startsWith('Line 1 — sent to the MD for approval on ')).toBe(true);
    expect(waiting[0].endsWith(' — waiting.')).toBe(true);

    const mdAgent = await signedIn(md);
    let page = (await mdAgent.get(`/orders/${orderId}`)).text;
    expect(page).toContain('<strong>Waiting for your approval:</strong> line 1 — USD 40.00 / PCS');
    expect(page).toContain(`href="/orders/${orderId}/products/${lineId}/price-approval">Review and approve line 1</a>`);
    expect(page.indexOf('id="approval-bar"')).toBeLessThan(page.indexOf('<h1>'));
    page = (await (await signedIn(sales)).get(`/orders/${orderId}`)).text;
    expect(page).not.toContain('id="approval-bar"');
    expect(page).toContain('Line 1 — sent to the MD for approval on ');

    page = (await mdAgent.get('/approvals')).text;
    expect(page).toContain('Approvals waiting for you (');
    expect(page).toContain(`id="approval-price-${lineId}"`);
    expect(page).toMatch(/href="\/approvals\?tab=prices">Prices [1-9]/);
    expect(page).toContain('href="/approvals" class="active"');
    page = (await mdAgent.get('/approvals?tab=emails')).text;
    expect(page).not.toContain(`id="approval-price-${lineId}"`);
    const dash = (await mdAgent.get('/')).text;
    expect(dash).toContain('Prices waiting for my approval');
    expect(dash).toMatch(/href="\/approvals">Open my approvals \([1-9][0-9]*\)<\/a>/);
  });

  it('the self-approval rules decide what is shown', async () => {
    const md = await createTestUser('Managing Director');
    const orderId = await createTestOrder(await createTestClient());
    const emailId = (await db.execute(
      `INSERT INTO email_log (order_id, template_key, recipient_email, subject, body_snapshot, status, requested_by)
       VALUES (:o, 'test', 'buyer@example.test', 'Proforma Invoice', 'Body', 'pending_approval', :u)`, { o: orderId, u: md.id })).insertId;
    const item = (await itemsOf(md))[`email:${emailId}`];
    expect(item).toBeTruthy();
    expect([item.tab, item.url, item.button]).toEqual(['emails', `/email-approvals#email-${emailId}`, 'Review e-mail']);
    const before = await selfApprovalRules.roleIds('email_send');
    try {
      await selfApprovalRules.set('email_send', []);
      expect((await itemsOf(md))[`email:${emailId}`]).toBeUndefined();
      expect((await itemsOf(await createTestUser('Executive Director')))[`email:${emailId}`]).toBeTruthy();
    } finally {
      await selfApprovalRules.set('email_send', before);
      await db.execute("UPDATE email_log SET status = 'cancelled' WHERE id = :id", { id: emailId });
    }
  });

  it('a Kanban user gets the same rows as a board', async () => {
    const sales = await createTestUser('Export Executive');
    const md = await createTestUser('Managing Director');
    const [orderId, lineId] = await lineWaitingForTheMd(sales);
    await userRepository.updateUiTemplatePreference(md.id, (await uiTemplateRepository.findByKey('kanban')).id);
    const agent = await signedIn(md);
    const board = (await agent.get('/approvals')).text;
    const list = (await agent.get('/approvals?view=list')).text;
    expect(board).toContain('Kanban Board');
    expect(board).toContain('Switch to list view');
    expect(list).toContain('Switch to board view');
    // UI-002: the list view's tab chips keep the list view (one &amp; in the HTML, never &amp;amp;).
    expect(list).toContain('href="/approvals?tab=prices&amp;view=list"');
    expect(list).not.toContain('&amp;amp;');
    for (const html of [board, list]) {
      expect(html).toContain(`href="/orders/${orderId}/products/${lineId}/price-approval"`);
      expect(html).toContain(`href="/orders/${orderId}"`);
      for (const label of Object.values(approvalsInbox.TABS)) {
        expect(html).toMatch(new RegExp(`class="filter-chip[^"]*" href="/approvals\\?tab=[a-z_]+[^"]*">${label} \\d+`));
      }
    }
  });

  it('approval e-mails link to the Approvals page', () => {
    expect(notificationRepository.emailBody('Order X needs your price approval.', 'staff_approvals').endsWith('/approvals')).toBe(true);
    expect(notificationRepository.emailBody('Order X needs your price approval.', 'staff_approvals')).toContain('\n\nOpen your approvals: ');
    expect(notificationRepository.emailBody('Order X: payment received.', 'staff_counterparty')).toBe('Order X: payment received.');
    expect(require('../../src/services/emailPolicy').kindForNotification('short_payment_approval')).toBe('staff_approvals');
  });

  it('the menu entry is for approvers only', async () => {
    expect(approvalsInbox.isApprover(await reqFor(await createTestUser('Viewer / Auditor')))).toBe(false);
    expect(approvalsInbox.isApprover(await reqFor(await createTestUser('Managing Director')))).toBe(true);
  });

  // ------------------------------------------------------------- D-312 / D-313

  it('the starting data has placeholders and the new defaults (both stacks)', () => {
    const root = path.join(__dirname, '../../../..');
    const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    for (const stack of ['PHP_MySQL_Version', 'NODE_MySQL_Version']) {
      const seed = fs.readFileSync(path.join(root, stack, 'docs/seed.sql'), 'utf8');
      const schema = fs.readFileSync(path.join(root, stack, 'docs/schema.sql'), 'utf8');
      for (const [key, values] of Object.entries(setupChecklist.PLACEHOLDERS)) {
        if (values[0] === '') continue;
        expect([stack, key, new RegExp(`\\('${key}',\\s+'${esc(values[0].replace(/'/g, "''"))}'`).test(seed)]).toEqual([stack, key, true]);
      }
      expect(seed).toContain("('password_expiry_days',  '0',");
      expect(seed).toContain("SELECT 'doc_cleanup_draft_days' AS k, '90' AS v,");
      expect(seed).toContain("UNION ALL SELECT 'doc_cleanup_superseded_days', '365',");
      expect(seed).toContain("UNION ALL SELECT 'doc_cleanup_cafin_mode', 'latest_only',");
      expect(seed).toContain("CAST(CONCAT(YEAR(CURDATE()) + 1, '-10-02') AS DATE), 'Gandhi Jayanti'");
      expect(schema).toContain("INSERT INTO test_mode_settings (id, is_enabled, test_email) VALUES (1, 0, 'info@email.com');");
      expect(schema).toContain('CREATE TABLE IF NOT EXISTS short_payment_requests');
      expect(schema).toContain('CREATE TABLE IF NOT EXISTS setup_checklist_ticks');
      expect(schema.slice(schema.indexOf('Owner decision D-310'))).not.toMatch(/(ADD|DROP|CHANGE|MODIFY) COLUMN IF (NOT )?EXISTS|CREATE INDEX IF NOT EXISTS/i);
    }
    expect(setupChecklist.PLACEHOLDERS.gstin[0].startsWith('29')).toBe(true);
  });

  it('placeholders are flagged Needed until the real values are entered', async () => {
    const admin = await createTestUser('Admin');
    const keys = [...Object.keys(setupChecklist.IDENTITY_KEYS), ...Object.keys(setupChecklist.BANK_KEYS), 'gstin', 'iec_pan'];
    const before = {};
    for (const k of keys) before[k] = await companySettingsRepository.get(k);
    try {
      await companySettingsRepository.set('bank_account_no', '000000000000');
      await companySettingsRepository.set('md_name', 'Managing Director');
      let items = Object.fromEntries((await setupChecklist.items(await reqFor(admin))).map((i) => [i.key, i]));
      expect(items.company.status).toBe('needed');
      expect(items.company.detail).toContain('MD name');
      expect(items.bank.status).toBe('needed');
      expect(items.bank.detail).toContain('Account number');
      expect(items.bank.url).toBe('/settings');
      const real = { legal_name: 'Example Exports Private Limited', registered_office: '1 Example Road, Example City – 100001, India',
        corporate_office: '2 Example Road, Example City – 100001, India', md_name: 'MD Full Name', director_name: 'Director Full Name',
        phone: '+91-1234567890', email: 'office@example.test', bank_name: 'Example Bank', bank_branch: 'Main Branch',
        bank_account_no: '123456789012', ifsc: 'EXMP0001234', swift_bic: 'EXMPINBB', bank_address: '3 Bank Street, Example City', gstin: '29AAAAA0000A1ZY', iec_pan: 'ABCDE1234F' };
      for (const [k, v] of Object.entries(real)) await companySettingsRepository.set(k, v);
      items = Object.fromEntries((await setupChecklist.items(await reqFor(admin))).map((i) => [i.key, i]));
      expect(items.company.status).toBe('done');
      expect(items.bank.status).toBe('needed');
      await companySettingsRepository.set('gstin', '29ABCDE1234F1ZW');
      items = Object.fromEntries((await setupChecklist.items(await reqFor(admin))).map((i) => [i.key, i]));
      expect(items.bank.status).toBe('done');
      expect(['optional', 'done']).toContain(items.zoho.status);
      expect(items.smtp.url).toBe('/settings#smtp-test');
      expect(items.backups.url).toBe('/admin/backups');
      expect(items.sessions.status).toBe('done');
    } finally {
      for (const [k, v] of Object.entries(before)) await companySettingsRepository.set(k, v);
    }
  });

  it('"Apply recommended" needs a reason and is audit-logged', async () => {
    const admin = await createTestUser('Admin');
    const before = await companySettingsRepository.get('password_expiry_days');
    try {
      await companySettingsRepository.set('password_expiry_days', '90');
      const diff = Object.fromEntries((await setupChecklist.differences()).map((d) => [d.key, d]));
      expect(diff.password_expiry_days).toEqual({ key: 'password_expiry_days', label: 'Password expiry (days, 0 = off)', current: '90', recommended: '0' });
      expect(await setupChecklist.applyRecommended(['password_expiry_days'], 'short', admin.id)).not.toBeNull();
      expect(await companySettingsRepository.get('password_expiry_days')).toBe('90');
      expect(await setupChecklist.applyRecommended(['password_expiry_days'], 'Setting up the new installation as recommended', admin.id)).toBeNull();
      expect(await companySettingsRepository.get('password_expiry_days')).toBe('0');
      const audit = await db.queryOne("SELECT old_value, new_value, reason FROM audit_log WHERE action_type = 'FIELD_EDIT' AND field_name = 'password_expiry_days' ORDER BY id DESC LIMIT 1");
      expect(audit).toEqual({ old_value: '90', new_value: '0', reason: 'Setting up the new installation as recommended' });
      expect(await setupChecklist.applyRecommended(['password_expiry_days'], 'Setting up the new installation as recommended', admin.id))
        .toBe('Nothing to change — these settings are already at the recommended values.');
    } finally {
      await companySettingsRepository.set('password_expiry_days', before);
    }
  });

  it('ticks, national holidays and the page', async () => {
    const admin = await createTestUser('Admin');
    const exec = await createTestUser('Export Executive');
    const execPerms = await permissionsOf(exec.roleId);
    expect(setupChecklist.mayTick('pricing_confirmed', execPerms)).toBe(false);
    expect(await setupChecklist.setTick('pricing_confirmed', true, null, exec.id, execPerms)).not.toBeNull();
    const adminReq = await reqFor(admin);
    const had = await setupChecklist.tick('tds_benefits_confirmed');
    try {
      expect(await setupChecklist.setTick('tds_benefits_confirmed', true, 'Confirmed with the CA by phone', admin.id, adminReq.permissions)).toBeNull();
      const items = Object.fromEntries((await setupChecklist.items(adminReq)).map((i) => [i.key, i]));
      expect(items.tds_benefits_confirmed.status).toBe('done');
      expect(items.tds_benefits_confirmed.detail).toContain('Confirmed with the CA by phone');
      expect(items.holidays.detail).toContain(`add the state and festival holidays for ${new Date().getFullYear()}`);
    } finally {
      if (had === null) await setupChecklist.setTick('tds_benefits_confirmed', false, null, admin.id, adminReq.permissions);
    }

    await db.execute("DELETE FROM company_holidays WHERE holiday_date BETWEEN '2041-01-01' AND '2042-12-31'");
    expect(await setupChecklist.addNationalHolidays(2041, admin.id)).toBe(6);
    expect(await setupChecklist.addNationalHolidays(2041, admin.id)).toBe(0);
    expect((await db.queryOne("SELECT description FROM company_holidays WHERE holiday_date = '2042-10-02'")).description).toBe('Gandhi Jayanti');
    await db.execute("DELETE FROM company_holidays WHERE holiday_date BETWEEN '2041-01-01' AND '2042-12-31'");

    const page = (await (await signedIn(admin)).get('/admin/setup-checklist')).text;
    expect(page).toContain('<h1>Setup checklist</h1>');
    expect(page).toContain('id="setup-lut_rcmc"');
    expect(page).toContain('id="setup-md_email"');
    expect(page).toContain('href="/admin/setup-checklist"');
  });
});
