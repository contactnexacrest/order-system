'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const paymentMilestoneController = require('../../src/controllers/paymentMilestoneController');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const paymentMilestoneRepository = require('../../src/repositories/paymentMilestoneRepository');
const partPaymentService = require('../../src/services/partPaymentService');
const paymentDescription = require('../../src/services/paymentDescription');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of GapPartPaymentsTest.php. Gap list G-5 (owner, 1 Nov 2026): a
 * buyer payment may arrive in parts — each receipt is kept, the leg keeps
 * the total, the page shows what is still owed, and a leg is cleared short
 * only by a manager with "Approve short payment" and a written reason,
 * which is recorded.
 */
describe('Gap list G-5: part payments and short payments', () => {
  let admin;
  let md;
  let accounts;

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Part Pay User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-partpay-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return { id: result.insertId, roleId: role.id, name: 'Jest Part Pay User' };
  }

  async function createTestDocument(orderId, typeCode, status) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    await db.execute('INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, :r, :s)',
      { o: orderId, t: type.id, r: `JEST-PP-${Math.random().toString(16).slice(2, 10)}`, s: status });
  }

  async function permissionsOf(roleId) {
    const rows = await db.query('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r', { r: roleId });
    return Object.fromEntries(rows.map((r) => [r.permission_key, true]));
  }

  /** Runs one action; returns { type, message } of the flash. */
  async function call(action, orderId, body = {}, user = admin, params = {}) {
    const req = { user: { id: user.id, role_id: user.roleId, name: user.name }, permissions: await permissionsOf(user.roleId), body, params: { id: String(orderId), ...params }, session: {} };
    const res = { redirect: () => {}, renderView: () => {}, status: () => res, send: () => {} };
    const controller = action === 'record' || action === 'clear' ? paymentMilestoneController : ordersController;
    await controller[action](req, res);
    return (req.session._flash || [])[0] || { type: '', message: '' };
  }

  /** A USD order at Stage 3 with its PI approved: products USD 4,500, advance 40% = USD 1,800 due. */
  async function orderAtAdvance() {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)", { o: orderId });
    await db.execute('UPDATE orders SET advance_pct = 40 WHERE id = :o', { o: orderId });
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await createTestDocument(orderId, 'PI', 'approved');
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    return orderId;
  }

  const receipt = (amount, date, rate, ref, differs = true) => ({ advance_amount: amount, advance_received_at: date, advance_exchange_rate: rate, advance_bank_reference: ref, ...(differs ? { confirm_amount_differs: '1' } : {}) });

  beforeAll(async () => {
    admin = await createTestUser('Admin');
    md = await createTestUser('Managing Director');
    accounts = await createTestUser('Accounts Executive');
  });

  it('two receipts add up and the leg keeps the total', async () => {
    const orderId = await orderAtAdvance();
    const first = await call('recordAdvancePayment', orderId, receipt('1000', '2026-10-20', '84', 'UTR0001'));
    expect(first.type).toBe('success');
    const again = await call('recordAdvancePayment', orderId, receipt('1000', '2026-10-20', '84', 'UTR0001'));
    expect(again.message).toBe('Advance remittance already recorded with these details — nothing changed.'); // a second click records nothing more
    const second = await call('recordAdvancePayment', orderId, receipt('800', '2026-10-24', '85', 'UTR0002', false));
    expect(second.type).toBe('success'); // a further receipt needs no "differs" tick

    const ops = await orderPaymentStatusRepository.find(orderId);
    const day = await db.queryOne("SELECT DATE_FORMAT(advance_remittance_received_at, '%Y-%m-%d') AS d FROM order_payment_status WHERE order_id = :o", { o: orderId });
    expect([String(ops.advance_amount), day.d, String(ops.advance_exchange_rate), ops.advance_bank_reference]).toEqual(['1800.00', '2026-10-24', '84.4444', 'UTR0001, UTR0002']);
    const s = await partPaymentService.summary(orderId, 'advance');
    expect([s.expected, s.received, s.owed, s.receipts.length]).toEqual([1800, 1800, 0, 2]);
    expect(await paymentDescription.buyerPayment(orderId, 'advance', null)).toContain('bank ref UTR0001, UTR0002'); // D-212
  });

  it('a short leg is cleared only by a manager with a reason', async () => {
    const orderId = await orderAtAdvance();
    await call('recordAdvancePayment', orderId, receipt('1500', '2026-10-20', '84', 'UTR0101'));
    expect((await partPaymentService.summary(orderId, 'advance')).owed).toBe(300);

    const refused = await call('clearAdvancePayment', orderId, { advance_cleared_at: '2026-10-21' }, accounts);
    expect(refused.message).toBe('Advance received so far: USD 1,500.00 of USD 1,800.00 due — USD 300.00 is still owed. Record the rest when it arrives, or ask a manager with "Approve short payment" to clear it with a written reason.');
    expect((await orderPaymentStatusRepository.find(orderId)).advance_cleared_at).toBeNull();

    const noReason = await call('clearAdvancePayment', orderId, { advance_cleared_at: '2026-10-21', short_reason: 'short' }, md);
    expect(noReason.message).toBe('USD 300.00 of the advance is still owed. To clear it short, give the reason (10 to 500 characters) — it is recorded and shown on the order.');
    const ok = await call('clearAdvancePayment', orderId, { advance_cleared_at: '2026-10-21', short_reason: 'Buyer confirmed the rest comes with the balance.' }, md);
    expect(ok.message).toContain('Short by USD 300.00: approved by Jest Part Pay User — carried into the balance.');
    const row = await db.queryOne('SELECT * FROM order_payment_shortfalls WHERE order_id = :o', { o: orderId });
    expect([row.leg, String(row.expected_amount), String(row.received_amount), String(row.shortfall_amount), row.treatment]).toEqual(['advance', '1800.00', '1500.00', '300.00', 'carried_to_balance']);
    expect((await db.queryOne("SELECT COUNT(*) AS n FROM audit_log WHERE action_type = 'PAYMENT_SHORTFALL_APPROVED' AND entity_id = :o", { o: orderId })).n).toBe(1);
    expect(await partPaymentService.expected(orderId, 'balance')).toBe(3000); // the advance shortfall is part of the balance

    const after = await call('recordAdvancePayment', orderId, receipt('300', '2026-10-25', '84', 'UTR0102'));
    expect(after.message).toBe('The advance is already cleared — no more receipts can be added to it.');
  });

  it('an excess is shown and does not block', async () => {
    const orderId = await orderAtAdvance();
    await call('recordAdvancePayment', orderId, receipt('2000', '2026-10-20', '84', 'UTR0201'));
    expect((await partPaymentService.summary(orderId, 'advance')).owed).toBe(-200);
    const cleared = await call('clearAdvancePayment', orderId, { advance_cleared_at: '2026-10-21' }, accounts);
    expect(cleared.message.startsWith('Advance payment cleared.')).toBe(true);
    const req = { user: { id: admin.id, role_id: admin.roleId, name: admin.name }, permissions: await permissionsOf(admin.roleId), params: { id: String(orderId) }, session: {}, query: {} };
    const res = { redirect: () => {}, renderView: (view, data) => { res.data = data; } };
    await ordersController.show(req, res);
    expect(res.data.partPayments.advance.owed).toBe(-200); // the page shows "paid USD 200.00 more than due"
    expect(res.data.canApproveShort).toBe(true);
  });

  it('a milestone paid short is written off with the manager\'s reason', async () => {
    const orderId = await orderAtAdvance();
    await paymentMilestoneRepository.replaceSchedule(orderId, [{ pct: 20, due_text: 'when production starts' }], 40);
    const m = (await paymentMilestoneRepository.forOrder(orderId))[0];
    const rec = await call('record', orderId, { amount: '700', exchange_rate: '84', bank_reference: 'UTR0301', received_on: '2026-10-22', confirm_amount_differs: '1' }, admin, { milestoneId: String(m.id) });
    expect(rec.type).toBe('success');
    expect((await partPaymentService.receipts(orderId, 'milestone', Number(m.id))).length).toBe(1); // in the history

    const refused = await call('clear', orderId, { cleared_on: '2026-10-23' }, accounts, { milestoneId: String(m.id) });
    expect(refused.message).toBe('Milestone 2 received so far: USD 700.00 of USD 900.00 due — USD 200.00 is still owed. Record the rest when it arrives, or ask a manager with "Approve short payment" to clear it with a written reason.');
    const ok = await call('clear', orderId, { cleared_on: '2026-10-23', short_reason: 'Bank charges taken by the buyer\'s bank, agreed.' }, md, { milestoneId: String(m.id) });
    expect(ok.message).toBe('Milestone 2 cleared. It goes to Zoho Books with the next revenue sync. Short by USD 200.00: approved by Jest Part Pay User — written off.');
    expect((await db.queryOne("SELECT treatment FROM order_payment_shortfalls WHERE order_id = :o AND leg = 'milestone'", { o: orderId })).treatment).toBe('written_off');
  });

  it('a single receipt leaves the leg exactly as before', async () => {
    // Zoho, CAFIN, reports and documents read the leg's columns.
    const orderId = await orderAtAdvance();
    await call('recordAdvancePayment', orderId, receipt('1800', '2026-10-20', '83.25', 'HDFCN26304123456', false));
    const ops = await orderPaymentStatusRepository.find(orderId);
    const day = await db.queryOne("SELECT DATE_FORMAT(advance_remittance_received_at, '%Y-%m-%d') AS d FROM order_payment_status WHERE order_id = :o", { o: orderId });
    expect([String(ops.advance_amount), day.d, String(ops.advance_exchange_rate), ops.advance_bank_reference]).toEqual(['1800.00', '2026-10-20', '83.2500', 'HDFCN26304123456']);
    const description = await paymentDescription.buyerPayment(orderId, 'advance', null);
    expect(description).toContain(' · USD 1,800.00 at ₹83.25 · ');
    expect(description.endsWith(' · bank ref HDFCN26304123456')).toBe(true);
  });

  it('the migration back-fills one receipt per recorded leg', async () => {
    const orderId = await orderAtAdvance();
    await db.execute("UPDATE order_payment_status SET advance_amount = 1800, advance_remittance_received_at = '2026-10-01 10:00:00', advance_exchange_rate = 83.5, advance_bank_reference = 'OLD0001' WHERE order_id = :o", { o: orderId });
    const sql = fs.readFileSync(path.join(__dirname, '../../../../docs/migrations/2026-11-02d-gap-part-payments.sql'), 'utf8');
    const backfill = sql.slice(sql.indexOf('-- Existing orders'));
    for (const statement of backfill.split(';').map((x) => x.trim()).filter((x) => x.includes('INSERT'))) {
      await db.pool.query(statement.replace(/^--.*$/gm, ''));
      await db.pool.query(statement.replace(/^--.*$/gm, '')); // safe to run twice
    }
    const rows = await partPaymentService.receipts(orderId, 'advance');
    expect(rows.length).toBe(1);
    expect([String(rows[0].amount), rows[0].received_on, String(rows[0].exchange_rate), rows[0].bank_reference]).toEqual(['1800.00', '2026-10-01', '83.5000', 'OLD0001']);
  });
});
