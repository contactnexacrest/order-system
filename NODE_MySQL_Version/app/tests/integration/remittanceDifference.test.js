'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const partPaymentService = require('../../src/services/partPaymentService');
const paymentDescription = require('../../src/services/paymentDescription');
const remittanceDifference = require('../../src/services/remittanceDifference');
const stageGateService = require('../../src/services/stageGateService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of RemittanceDifferenceTest.php. Owner decision (2 Nov 2026, "18
 * yes"): a buyer remittance that differs from what is still due says why —
 * bank charges deducted by the banks (the leg counts as paid in full, the
 * charges are an order cost with a D-212 description and go to Zoho Books
 * with the payment), the buyer paid less, or the buyer paid more.
 */
describe('Remittance difference: bank charges, short, excess', () => {
  // Follow-up (2 Nov 2026): the reason is a list entry — the first of each treatment.
  const RID = {};
  beforeAll(async () => {
    const rd = require('../../src/services/remittanceDifference');
    for (const t of ['bank_charges', 'short', 'excess']) RID[t] = String(await rd.reasonIdFor(t));
  });
  let admin;
  let accounts;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Remit User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-remit-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id, name: 'Jest Remit User' };
  }

  async function permissionsOf(roleId) {
    const rows = await db.query('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r', { r: roleId });
    return Object.fromEntries(rows.map((r) => [r.permission_key, true]));
  }

  async function call(action, orderId, body, user = admin) {
    const req = { user: { id: user.id, role_id: user.roleId, name: user.name }, permissions: await permissionsOf(user.roleId), body, params: { id: String(orderId) }, session: {} };
    const res = { redirect: () => {}, renderView: () => {}, status: () => res, send: () => {} };
    await ordersController[action](req, res);
    const flashes = req.session._flash || [];
    return flashes[flashes.length - 1] || { type: '', message: '' };
  }

  /** USD order, products USD 4,500, advance 40% = USD 1,800 due, PI approved, at Stage 3. */
  async function orderAtAdvance() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('UPDATE orders SET advance_pct = 40 WHERE id = :o', { o: orderId });
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    const type = await db.queryOne("SELECT id FROM document_types WHERE code = 'PI'");
    await db.execute("INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, :r, 'approved')", { o: orderId, t: type.id, r: `JEST-RD-${Math.random().toString(16).slice(2, 10)}` });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    return orderId;
  }

  const advance = (amount, extra = {}) => ({ advance_amount: amount, advance_received_at: '2026-10-20', advance_exchange_rate: '84', advance_bank_reference: 'UTR778801', ...extra });

  beforeAll(async () => {
    admin = await createTestUser('Admin');
    accounts = await createTestUser('Accounts Executive');
  });

  it('applies the rules behind each reason', () => {
    const ev = (...a) => remittanceDifference.evaluate(...a);
    expect(ev('Advance', 'USD', 1800, 0, 1795, null, null, 3)).toMatchObject({ reason: null, charges: 0 });
    expect(ev('Advance', 'USD', 1800, 0, 1500, null, null, 3).error).toBe('The advance still due is USD 1,800.00. You entered USD 1,500.00 — a difference of USD 300.00. Choose why it is different in the Reason list and save again.');
    expect(ev('Advance', 'USD', 1800, 0, 1780, 'bank_charges', null, 3).charges).toBe(20);
    expect(ev('Advance', 'USD', 1800, 0, 1780, 'bank_charges', 15, 3).charges).toBe(15);
    expect(ev('Advance', 'USD', 1800, 0, 1780, 'bank_charges', 25, 3).error).toBe('Bank charges of USD 25.00 are more than the difference of USD 20.00 between the advance still due and what arrived.');
    expect(ev('Advance', 'USD', 1800, 0, 1700, 'bank_charges', null, 3).error).toBe('Bank charges of USD 100.00 are more than 3% of the advance due (USD 54.00). If the buyer paid less, choose a "Buyer paid less" reason instead.');
    expect(ev('Advance', 'USD', 1800, 0, 1900, 'short', null, 3).error).toBe('Nothing is short: USD 1,900.00 covers the advance still due (USD 1,800.00).');
    expect(ev('Advance', 'USD', 1800, 0, 1900, 'excess', null, 3).excess).toBe(100);
    expect(ev('Advance', 'USD', 1800, 1000, 900, null, null, 3).error).toBe('The advance still due is USD 800.00. You entered USD 900.00 — a difference of USD 100.00. Choose why it is different in the Reason list and save again.');
    expect(ev('Advance', 'USD', 1800, 1000, 500, null, null, 3).error).toBeUndefined();
  });

  it('bank charges count the advance as paid in full and become an order cost', async () => {
    const orderId = await orderAtAdvance();
    const order = await orderRepository.find(orderId);
    const r = await call('recordAdvancePayment', orderId, advance('1780', { difference_reason_choice: RID.bank_charges, bank_charges: '' }), accounts);
    expect(r.type).toBe('success');
    expect(r.message).toContain('Bank charges USD 20.00 = ₹1,680.00 recorded as an order cost — the advance counts as received in full.');

    expect((await orderPaymentStatusRepository.find(orderId)).advance_amount).toBe('1800.00');
    const s = await partPaymentService.summary(orderId, 'advance');
    expect([s.received, s.owed]).toEqual([1800, 0]);
    const receipt = s.receipts[0];
    expect([receipt.amount, receipt.difference_reason, receipt.bank_charges, receipt.bank_charges_inr]).toEqual(['1780.00', 'bank_charges', '20.00', '1680.00']);
    expect([Number(receipt.difference_reason_id), receipt.difference_reason_text, Number(receipt.difference_reason_custom)])
      .toEqual([Number(RID.bank_charges), 'Intermediary / correspondent bank charges deducted', 0]);

    const costs = await orderCostEntryRepository.forOrder(orderId);
    expect(costs.length).toBe(1);
    const expected = `Bank charges deducted from ${order.company_legal_name}'s advance remittance for order ${order.buyer_inquiry_ref || order.order_reference}`
      + ': received USD 1,780.00 of USD 1,800.00 due, charges USD 20.00 (₹1,680.00) · bank ref UTR778801 · Reason: Intermediary / correspondent bank charges deducted';
    expect([costs[0].category, costs[0].amount_inr, costs[0].description, costs[0].paid_from]).toEqual(['bank_charges', '1680.00', expected, 'company_account']);
    expect(Number(receipt.bank_charges_cost_id)).toBe(Number(costs[0].id));
    const audit = await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'REMITTANCE_BANK_CHARGES_RECORDED' AND entity_id = :r", { r: receipt.id });
    expect(Number(audit.n)).toBe(1);

    const clear = await call('clearAdvancePayment', orderId, { advance_cleared_at: '2026-10-21' }, accounts);
    expect(clear.type).not.toBe('error');
    expect((await orderPaymentStatusRepository.find(orderId)).advance_cleared_at).not.toBeNull();

    expect(await remittanceDifference.chargesInr(orderId, 'advance')).toBe(1680);
    expect(await paymentDescription.buyerPayment(orderId, 'advance', null)).toContain('USD 1,800.00 at ₹84.00 · ₹1,680.00 bank charges deducted by the banks');
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 149520, admin.id);
    expect((await orderProfitabilityService.computeForOrder(orderId)).revenue_inr).toBe(151200);
  });

  it('the Zoho payment settles the invoice in full', () => {
    const payload = zohoBooksService.paymentPayload({ depositAccountId: 'ACC1' }, 'C1', 'USD', 1800, 149520, '2026-10-21', 'REF-advance', 1680, 'desc');
    expect([payload.amount, payload.exchange_rate, payload.bank_charges]).toEqual([1800, 84, 20]);
  });

  it('records short and excess as they say and refuses charges above the limit', async () => {
    const orderId = await orderAtAdvance();
    const refused = await call('recordAdvancePayment', orderId, advance('1700', { difference_reason_choice: RID.bank_charges }));
    expect(refused.message).toBe('Bank charges of USD 100.00 are more than 3% of the advance due (USD 54.00). If the buyer paid less, choose a "Buyer paid less" reason instead.');
    expect((await orderPaymentStatusRepository.find(orderId)).advance_amount).toBeNull();

    const short = await call('recordAdvancePayment', orderId, advance('1700', { difference_reason_choice: RID.short }));
    expect(short.message).toContain('Short by USD 100.00 — record the rest when it arrives, or a manager clears the advance short and the difference is carried into the balance.');
    expect((await partPaymentService.summary(orderId, 'advance')).owed).toBe(100);
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);

    const excess = await call('recordAdvancePayment', orderId, advance('300', { advance_received_at: '2026-10-22', difference_reason_choice: RID.excess }));
    expect(excess.message).toContain('Excess USD 200.00 — the balance due goes down by the same amount.');
    const rows = await db.query(
      "SELECT action_type FROM audit_log WHERE entity_type = 'order_payment_receipts' AND action_type LIKE 'REMITTANCE_%' AND entity_id IN (SELECT id FROM order_payment_receipts WHERE order_id = :o) ORDER BY id",
      { o: orderId }
    );
    expect(rows.map((x) => x.action_type)).toEqual(['REMITTANCE_SHORT_RECORDED', 'REMITTANCE_EXCESS_RECORDED']);
  });

  it('the order page asks why on every buyer remittance form', () => {
    const fs = require('fs');
    const path = require('path');
    const view = fs.readFileSync(path.join(__dirname, '../../views/orders/show.njk'), 'utf8');
    const partial = fs.readFileSync(path.join(__dirname, '../../views/orders/_remittance_difference.njk'), 'utf8');
    expect(view).not.toContain('confirm_amount_differs');
    expect((view.match(/orders\/_remittance_difference\.njk/g) || []).length).toBe(4);
    expect(partial).toContain('Why is it different?');
    expect(partial).toContain('name="difference_reason_choice"');
    expect(partial).toContain('name="bank_charges" data-remit-charges');
  });
});
