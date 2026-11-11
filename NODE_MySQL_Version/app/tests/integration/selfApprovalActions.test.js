'use strict';

const crypto = require('crypto');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const purchasingController = require('../../src/controllers/purchasingController');
const amendmentRepository = require('../../src/repositories/amendmentRepository');
const orderCancellationRepository = require('../../src/repositories/orderCancellationRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const purchasingRepository = require('../../src/repositories/purchasingRepository');
const amendmentService = require('../../src/services/amendmentService');
const partPaymentService = require('../../src/services/partPaymentService');
const purchasingService = require('../../src/services/purchasingService');
const stageGateService = require('../../src/services/stageGateService');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of SelfApprovalActionsTest.php. QA Round 8, R8-N09: the four actions
 * that until now had their own hard-coded self-approval exemption — order
 * cancellation, own purchase award, amendment MD-approval and short-payment
 * clearing — follow Admin → Self-approval rules like the other three
 * (default Managing Director and Executive Director). Per action: an MD may
 * (and it is audited), an Admin and a System Administrator may not (even
 * holding the action's own permission), and a role may not once it is
 * unticked (a role of the test's own, so the shared MD/ED rules never change).
 */
describe('Self-approval rules on cancellation, purchase award, amendment and short payment (R8-N09)', () => {
  const customRoles = [];
  let md;
  let admin;
  let sysadmin;

  const roleId = async (name) => Number((await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: name })).id);
  // A user whose own (new) role is ticked for the rule, holding the action's permissions. Only that
  // role's row is added and removed, so the shared MD/ED rules are never touched (other test files
  // run in parallel against the same rules).
  async function tickedRoleUser(rule) {
    const name = `Self Test ${crypto.randomBytes(4).toString('hex')}`;
    const rid = (await db.execute("INSERT INTO roles (name, description) VALUES (:n, 'Jest self-approval role')", { n: name })).insertId;
    customRoles.push(rid);
    await db.execute('INSERT INTO self_approval_rules (rule_key, role_id) VALUES (:k, :r)', { k: rule, r: rid });
    return createTestUser(name, ['edit_locked_data', 'approve_purchases', 'approve_short_payment']);
  }
  const untick = async (rule, user) => db.execute('DELETE FROM self_approval_rules WHERE rule_key = :k AND role_id = :r', { k: rule, r: user.roleId });
  const auditCount = async (action, id) => Number((await db.queryOne('SELECT COUNT(*) AS n FROM audit_log WHERE action_type = :a AND entity_id = :id', { a: action, id })).n);

  async function createTestUser(roleName, extraPermissions = []) {
    const rid = await roleId(roleName);
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-selfapp-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: rid }
    );
    const id = result.insertId;
    for (const key of extraPermissions) {
      await db.execute("INSERT INTO user_permissions (user_id, permission_id, is_enabled, reason) SELECT :u, id, 1, 'Jest' FROM permissions WHERE permission_key = :k", { u: id, k: key });
    }
    const rows = await db.query('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r', { r: rid });
    const permissions = Object.fromEntries([...rows.map((r) => r.permission_key), ...extraPermissions].map((k) => [k, true]));
    return { id, roleId: rid, name: 'Jest Test User', permissions };
  }

  function fakeReq(user, params, body = {}) {
    return { user: { id: user.id, role_id: user.roleId, name: user.name }, permissions: { ...user.permissions, view_all_orders: true }, body, params, session: {} };
  }

  async function run(controller, action, user, params, body = {}) {
    const req = fakeReq(user, params, body);
    const res = { statusCode: 200, redirect: () => {}, renderView: () => {}, send: () => {} };
    res.status = (c) => { res.statusCode = c; return res; };
    await controller[action](req, res);
    const flashes = req.session._flash || [];
    return flashes[flashes.length - 1] || { type: null, message: '' };
  }

  beforeAll(async () => {
    md = await createTestUser('Managing Director');
    admin = await createTestUser('Admin');
    // The System Administrator is given each action's own permission, so only the self-approval rule can refuse them.
    sysadmin = await createTestUser('System Administrator', ['edit_locked_data', 'approve_purchases', 'approve_short_payment']);
  });

  afterAll(async () => {
    for (const rid of customRoles) await db.execute('DELETE FROM self_approval_rules WHERE role_id = :r', { r: rid });
  });

  afterAll(async () => {
    await db.pool.end();
  });

  // ---- order cancellation ----

  async function cancel(action, user) {
    const orderId = await createTestOrder(await createTestClient());
    const cancellationId = await orderCancellationRepository.create(orderId, 'Buyer withdrew the order in writing.', user.id);
    const flash = await run(ordersController, action, user, { id: String(orderId), cancellationId: String(cancellationId) },
      { refund_status: 'not_applicable', decision_notes: 'Buyer confirmed by e-mail.' });
    return [orderId, cancellationId, flash.message];
  }

  it('order cancellation self-approval follows the rule', async () => {
    let [orderId, id] = await cancel('approveCancellation', md);
    expect((await orderRepository.find(orderId)).status).toBe('cancelled'); // MD approves their own request
    expect(await auditCount('ORDER_CANCELLATION_SELF_APPROVED', id)).toBe(1);
    [orderId, id] = await cancel('rejectCancellation', md);
    expect((await orderCancellationRepository.find(id)).status).toBe('rejected');
    expect(await auditCount('ORDER_CANCELLATION_SELF_REJECTED', id)).toBe(1);

    for (const user of [admin, sysadmin]) {
      let message;
      [orderId, id, message] = await cancel('approveCancellation', user);
      expect(message).toBe('You requested this cancellation — a different privileged person must approve it.');
      expect((await orderRepository.find(orderId)).status).toBe('active');
      [, id, message] = await cancel('rejectCancellation', user);
      expect(message).toBe('You requested this cancellation — a different privileged person must decide on it.');
      expect((await orderCancellationRepository.find(id)).status).toBe('pending');
    }

    const custom = await tickedRoleUser('cancellation');
    [orderId] = await cancel('approveCancellation', custom);
    expect((await orderRepository.find(orderId)).status).toBe('cancelled'); // any role ticked on the page qualifies
    await untick('cancellation', custom);
    const [orderId2, , message] = await cancel('approveCancellation', custom);
    expect(message).toBe('You requested this cancellation — a different privileged person must approve it.'); // once unticked
    expect((await orderRepository.find(orderId2)).status).toBe('active');
  });

  // ---- own purchase award ----

  it('own purchase award self-approval follows the rule', async () => {
    for (const [who, user] of [['MD', md], ['Admin', admin], ['System Administrator', sysadmin]]) {
      const refusal = await purchasingService.approvalRefusal(fakeReq(user, {}), { awarded_by: user.id }, 0);
      expect([who, refusal]).toEqual([who, who === 'MD' ? null : 'You awarded this purchase, so someone else must approve it.']);
    }

    // Through the page: an award waiting for approval, made by the MD, approved by the MD.
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    const supplierId = (await db.execute('INSERT INTO suppliers (supplier_legal_name, is_active) VALUES (:n, 1)', { n: `Quarry Self ${Math.random().toString(16).slice(2, 8)}` })).insertId;
    const d = new Date(`${todayIst()}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + 20);
    await run(purchasingController, 'raise', admin, { id: String(orderId) }, { description: 'Black granite slabs 2cm', quantity: '120', unit: 'sqm', needed_by: d.toISOString().slice(0, 10) });
    const id = Number((await db.queryOne('SELECT MAX(id) AS id FROM purchase_requirements WHERE order_id = :o', { o: orderId })).id);
    await run(purchasingController, 'addQuote', admin, { id: String(id) }, { supplier_id: String(supplierId), unit_price_inr: '10', gst_percent: '0', lead_time_days: '14' });
    const quote = (await purchasingRepository.quotes(id))[0];
    await run(purchasingController, 'award', admin, { id: String(id) }, { quote_id: String(quote.id) });
    // Put it back to "waiting for approval", as if the MD had awarded it above a limit.
    await db.execute("UPDATE purchase_requirements SET status = 'awarded', approved_by = NULL, approved_at = NULL, awarded_by = :u WHERE id = :id", { u: md.id, id });

    const custom = await tickedRoleUser('purchase_award');
    expect(await purchasingService.approvalRefusal(fakeReq(custom, {}), { awarded_by: custom.id }, 0)).toBeNull(); // any role ticked on the page qualifies
    await untick('purchase_award', custom);
    expect(await purchasingService.approvalRefusal(fakeReq(custom, {}), { awarded_by: custom.id }, 0)).toBe('You awarded this purchase, so someone else must approve it.'); // once unticked
    expect((await run(purchasingController, 'approve', md, { id: String(id) })).message).toMatch(new RegExp(`^Purchase PR-${id} approved`));
    expect(await auditCount('PURCHASE_AWARD_SELF_APPROVED', id)).toBe(1);
  });

  // ---- amendment MD-approval ----

  const amendment = async (createdBy) => amendmentRepository.create(`AMD-SELF-${Math.random().toString(16).slice(2, 10)}`, await createTestOrder(await createTestClient()),
    'Jest self-approval amendment', 'importer', { note: 'snapshot' }, null, null, null, null, null, null, null, createdBy);

  it('amendment self-approval follows the rule', async () => {
    let id = await amendment(md.id);
    await amendmentService.approveByMd(id, md.id);
    expect((await amendmentRepository.find(id)).status).toBe('md_approved');
    expect(await auditCount('AMENDMENT_SELF_APPROVED', id)).toBe(1);

    const custom = await tickedRoleUser('amendment');
    id = await amendment(custom.id);
    await amendmentService.approveByMd(id, custom.id);
    expect((await amendmentRepository.find(id)).status).toBe('md_approved'); // any role ticked on the page qualifies
    await untick('amendment', custom);
    for (const user of [admin, sysadmin, custom]) {
      id = await amendment(user.id);
      await expect(amendmentService.approveByMd(id, user.id)).rejects.toThrow('You requested this amendment — a different privileged user must MD-approve it.');
      expect((await amendmentRepository.find(id)).status).toBe('pending');
    }
  });

  // ---- short-payment clearing ----

  /** A USD order with its advance (USD 1,800) due and USD 1,500 received, recorded by `recordedBy`. */
  async function shortAdvance(recordedBy) {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('UPDATE orders SET advance_pct = 40 WHERE id = :o', { o: orderId });
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    const type = await db.queryOne("SELECT id FROM document_types WHERE code = 'PI'");
    await db.execute("INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, :r, 'sent')", { o: orderId, t: type.id, r: `JEST-SA-${Math.random().toString(16).slice(2, 10)}` }); // issued (D-280: Testing mode is off when installed, so a buyer document counts once sent)
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await partPaymentService.addLegReceipt(orderId, 'advance', 1500, '2026-10-20', 84, `UTR${Math.floor(10000 + Math.random() * 89999)}`, recordedBy.id);
    return orderId;
  }

  it('short-payment clearing by whoever recorded the receipts follows the rule', async () => {
    const refused = 'You recorded this payment\'s receipts, so a different manager with "Approve short payment" must clear it short. It now waits in Approvals for them.';
    const reason = 'Buyer confirmed the rest comes with the balance.';
    for (const user of [admin, sysadmin]) {
      const orderId = await shortAdvance(user);
      expect((await partPaymentService.clearCheck(orderId, 'advance', null, true, reason, user.id)).error).toBe(refused);
      expect(await partPaymentService.clearCheck(orderId, 'advance', null, true, reason, md.id)).toHaveProperty('short'); // a different manager may clear it
    }

    const custom = await tickedRoleUser('short_payment');
    const customOrder = await shortAdvance(custom);
    expect(await partPaymentService.clearCheck(customOrder, 'advance', null, true, reason, custom.id)).toHaveProperty('short'); // any role ticked on the page qualifies
    await untick('short_payment', custom);
    expect((await partPaymentService.clearCheck(customOrder, 'advance', null, true, reason, custom.id)).error).toBe(refused); // once unticked

    const orderId = await shortAdvance(md);

    const ok = await run(ordersController, 'clearAdvancePayment', md, { id: String(orderId) }, { advance_cleared_at: '2026-10-21', short_reason: reason });
    expect(ok.message).toContain('Short by USD 300.00: approved by Jest Test User — carried into the balance.');
    expect(await auditCount('SHORT_PAYMENT_SELF_APPROVED', orderId)).toBe(1);
  });
});
