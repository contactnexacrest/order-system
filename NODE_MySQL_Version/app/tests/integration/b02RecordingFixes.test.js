'use strict';

const fs = require('fs');
const path = require('path');
const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const documentController = require('../../src/controllers/documentController');
const reportRepository = require('../../src/repositories/reportRepository');
const paymentTermsApprovalService = require('../../src/services/paymentTermsApprovalService');
const { createTestClient, createTestOrder, createTestClientLogin, createTestDocument } = require('../support/fixtures');

/**
 * Port of B02RecordingFixesTest.php. Owner decision D-269 (8 Nov 2026): five
 * fixes found while recording the B02 tutorial.
 *  1. Payment-terms approval: the wording names who can approve, from the
 *     "Approve payment terms" permission — not "MD/ED".
 *  2. Buyer portal: the Buyer PO "upload any time" intro is not shown when
 *     upload is closed on a cancelled or lost order.
 *  3. Staff Productivity: deactivated users are left out; the date range
 *     applies to both counts and the form says so.
 *  4. Management cockpit: every active department has a row, with zeros.
 *  5. Email Send Approvals: the reason boxes show the 10-character rule only
 *     as the validation error, not as a hint before anything is typed.
 */
describe('D-269 fixes found while recording B02', () => {
  const PASSWORD = 'JestB02Fixes!1';
  const SEEDED_APPROVERS = 'Admin, Managing Director, Executive Director, Sales/Export Manager, Sales Director, Finance Director';
  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  afterAll(async () => {
    await db.pool.end();
  });

  async function createUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-b02-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
    return { id, email };
  }

  async function signIn(user) {
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email: user.email, password: PASSWORD });
    return agent;
  }

  // ---- 1. Payment-terms approval wording ------------------------------------------------------

  it('takes the approver roles from the permission', async () => {
    expect((await paymentTermsApprovalService.approverRoleNames()).join(', ')).toBe(SEEDED_APPROVERS);
    expect(await paymentTermsApprovalService.whoCanApprove()).toBe(`someone who can approve payment terms (${SEEDED_APPROVERS})`);
  });

  it('names who can approve on the order page instead of MD/ED', async () => {
    const exec = await createUser('Export Executive');
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Established Buyer — Post-BL', 'USD', null, null, exec.id);
    let html = (await (await signIn(exec)).get(`/orders/${orderId}`)).text;

    expect(html).not.toContain('MD/ED approval');
    expect(html).toContain('<strong>Payment terms need approval first</strong>');
    expect(html).toContain(`The Quotation can't be generated or sent until they are approved. Waiting for approval by someone who can approve payment terms (${SEEDED_APPROVERS}).`);

    // An approver sees the button, not the "Waiting for" line.
    html = (await (await signIn(await createUser('Sales Director'))).get(`/orders/${orderId}`)).text;
    expect(html).toContain('Approve payment terms</button>');
    expect(html).not.toContain('Waiting for approval by');
  });

  it('names who can approve in the refusal message', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Established Buyer — Post-BL');
    const res = { redirect() {}, status() { return res; }, send() {} };
    const req = { params: { id: String(orderId) }, body: { document_type: 'QT' }, user: { id: (await createUser('Export Executive')).id }, permissions: { generate_documents: true, view_selling_prices: true }, session: {} };
    await documentController.generate(req, res);

    expect(flash.pull(req)[0].message).toBe(`The payment terms on this order ("Established Buyer — Post-BL") need approval before the Quotation can be generated or sent — approve them in Step 1, or ask someone who can approve payment terms (${SEEDED_APPROVERS}).`);
  });

  it('names a role given the permission at once', async () => {
    const name = `Jest Approver ${crypto.randomBytes(3).toString('hex')}`;
    const roleId = (await db.execute('INSERT INTO roles (name, description, is_system_role) VALUES (:n, NULL, 0)', { n: name })).insertId;
    try {
      expect(await paymentTermsApprovalService.approverRoleNames()).not.toContain(name);
      await db.execute(`INSERT INTO role_permissions (role_id, permission_id, is_enabled)
                        SELECT :r, id, 1 FROM permissions WHERE permission_key = 'approve_payment_terms'`, { r: roleId });
      expect((await paymentTermsApprovalService.approverRoleNames()).join(', ')).toBe(`${SEEDED_APPROVERS}, ${name}`);
      // A role with the permission switched off is not named.
      await db.execute('UPDATE role_permissions SET is_enabled = 0 WHERE role_id = :r', { r: roleId });
      expect(await paymentTermsApprovalService.approverRoleNames()).not.toContain(name);
    } finally {
      await db.execute('DELETE FROM role_permissions WHERE role_id = :r', { r: roleId });
      await db.execute('DELETE FROM roles WHERE id = :r', { r: roleId });
    }
  });

  // ---- 2. Buyer portal: the Buyer PO intro ----------------------------------------------------

  const BUYER_PO_INTRO = "After you sign your purchase order, upload a scan of it here. You can upload a corrected copy later. We keep every copy you upload.";

  async function portalPage(status) {
    const email = `jest-b02-${crypto.randomBytes(4).toString('hex')}@buyer.test`;
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, PASSWORD);
    const orderId = await createTestOrder(clientId);
    await db.execute('UPDATE orders SET status = :s WHERE id = :id', { s: status, id: orderId });
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/client/login')).text);
    await agent.post('/client/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });
    return (await agent.get(`/client/orders/${orderId}`)).text;
  }

  it('shows the Buyer PO intro while upload is open', async () => {
    const html = await portalPage('active');
    expect(html).toContain(BUYER_PO_INTRO); // literal template text, not escaped
    expect(html).toContain('/buyer-po" enctype="multipart/form-data"');
  });

  it.each(['cancelled', 'lost'])('hides the Buyer PO intro on a %s order', async (status) => {
    const html = await portalPage(status);
    expect(html).not.toContain('upload a scan of it here');
    // D-330: buyer words — never the raw status ("lost").
    const word = status === 'lost' ? 'This order did not go ahead' : 'This order was cancelled';
    expect(html).toContain(`${word}, so you can no longer upload a purchase order.`);
  });

  // ---- 3. Staff Productivity ------------------------------------------------------------------

  async function staffUserWithActivity(active) {
    const user = await createUser('Export Executive');
    const name = `Jest Staff ${crypto.randomBytes(4).toString('hex')}`;
    await db.execute('UPDATE users SET name = :n, is_active = :a WHERE id = :id', { n: name, a: active ? 1 : 0, id: user.id });
    const doc = await createTestDocument(await createTestOrder(await createTestClient()), 'QT', 'approved');
    await db.execute("UPDATE documents SET generated_by = :u, generated_at = '2026-01-15 11:00:00' WHERE id = :id", { u: user.id, id: doc });
    for (const at of ['2025-06-10 10:00:00', '2026-01-15 10:00:00']) {
      await db.execute("INSERT INTO audit_log (user_id, action_type, entity_type, entity_id, created_at) VALUES (:u, 'JEST_STAFF_REPORT', 'users', :u2, :at)", { u: user.id, u2: user.id, at });
    }
    return { id: user.id, name };
  }

  const staffRow = (rows, userId) => rows.find((r) => Number(r.user_id) === Number(userId)) || null;

  it('leaves deactivated users out of Staff Productivity', async () => {
    const active = await staffUserWithActivity(true);
    const inactive = await staffUserWithActivity(false);

    const rows = await reportRepository.staffProductivity(null, null);
    expect(staffRow(rows, active.id)).not.toBeNull();
    expect(staffRow(rows, inactive.id)).toBeNull(); // a deactivated user is not listed

    const html = (await (await signIn(await createUser('Admin'))).get('/reports/staff')).text;
    expect(html).not.toContain(inactive.name);
  });

  it('counts audit actions in the date range only', async () => {
    const user = await staffUserWithActivity(true);

    const all = staffRow(await reportRepository.staffProductivity(null, null), user.id);
    expect(all.audit_actions).toBe(2);
    expect(all.documents_total).toBe(1);

    const jan = staffRow(await reportRepository.staffProductivity('2026-01-01', '2026-01-31'), user.id);
    expect(jan.audit_actions).toBe(1); // only the 15 Jan 2026 action
    expect(jan.documents_total).toBe(1);

    const june = staffRow(await reportRepository.staffProductivity('2025-06-01', '2025-06-30'), user.id);
    expect(june.audit_actions).toBe(1); // only the June 2025 action
    expect(june.documents_total).toBe(0);
  });

  it('says on the form that the dates apply to both counts', async () => {
    const html = (await (await signIn(await createUser('Admin'))).get('/reports/staff')).text;
    expect(html).not.toContain('(documents generated)');
    expect(html).toContain('<label>Date From<input type="date" name="date_from"');
    expect(html).toContain('<label>Date To<input type="date" name="date_to"');
    expect(html).toContain('The dates apply to both counts. Deactivated users are not listed.');
  });

  // ---- 4. Management cockpit ------------------------------------------------------------------

  it('lists every active department on the cockpit, with zeros', async () => {
    const tag = crypto.randomBytes(3).toString('hex').toUpperCase();
    const insert = (code, name, color, active) => db.execute(
      'INSERT INTO departments (code, name, color, sort_order, is_active) VALUES (:c, :n, :col, 999, :a)', { c: code, n: name, col: color, a: active }
    );
    const quiet = (await insert(`Q${tag}`, `Quiet Dept ${tag}`, '#123456', 1)).insertId;
    const closed = (await insert(`X${tag}`, `Closed Dept ${tag}`, '#654321', 0)).insertId;
    try {
      const html = (await (await signIn(await createUser('Admin'))).get('/cockpit')).text;
      expect(html).toMatch(new RegExp(`<span class="dept-tag" style="--dept-color:#123456">Quiet Dept ${tag}</span></td>\\s*<td>0</td>\\s*<td>0</td>\\s*<td>0</td>\\s*<td>0</td>`));
      expect(html).not.toContain(`Closed Dept ${tag}`); // an inactive department has no row
    } finally {
      await db.execute('DELETE FROM departments WHERE id IN (:a, :b)', { a: quiet, b: closed });
    }
  });

  // ---- 5. Email Send Approvals: the reason boxes ----------------------------------------------

  it('shows the length rule on the approval reason boxes only as the error', () => {
    const appDir = path.join(__dirname, '..', '..');
    const view = fs.readFileSync(path.join(appDir, 'views', 'email', 'approvals.njk'), 'utf8');
    expect(view.split('data-reason-hint="on-error"').length - 1).toBe(2); // the Reject and Cancel reason boxes
    expect(view).toContain('<input type="text" name="reason" placeholder="Cancel reason (mandatory)" required minlength="10" data-reason-hint="on-error"');
    expect(view).toContain('<input type="text" name="reason" placeholder="Reason (mandatory)" required minlength="10" data-reason-hint="on-error"');

    const js = fs.readFileSync(path.join(appDir, 'public', 'js', 'app.js'), 'utf8');
    expect(js).toContain("var hintUpFront = field.dataset.reasonHint !== 'on-error';");
    expect(js).toContain("existingError.textContent = 'Reason must be at least ' + MIN_LENGTH + ' characters (' + val.length + ' so far).';");
  });
});
