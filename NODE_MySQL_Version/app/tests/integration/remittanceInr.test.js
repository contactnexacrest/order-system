'use strict';

const crypto = require('crypto');
const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const caController = require('../../src/controllers/caController');
const caRepository = require('../../src/repositories/caRepository');
const ordersController = require('../../src/controllers/ordersController');
const paymentMilestoneController = require('../../src/controllers/paymentMilestoneController');
const orderCostEntryRepository = require('../../src/repositories/orderCostEntryRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const partPaymentService = require('../../src/services/partPaymentService');
const remittanceDifference = require('../../src/services/remittanceDifference');
const remittanceInr = require('../../src/services/remittanceInr');
const stageGateService = require('../../src/services/stageGateService');
const zohoBooksService = require('../../src/services/zohoBooksService');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of RemittanceInrTest.php. Owner decision D-244 (4 Nov 2026, "C
 * yes"): buyer remittances are recorded with the INR the bank credited; the
 * rate is worked out from it. The bank's rate on the advice is optional and,
 * when it is more than ₹1 away from the INR, the person says why (bank
 * charges → an order cost; a typo → nothing saved; another reason → kept on
 * the receipt). "INR not known yet" keeps the typed rate, and the CA
 * register shows an estimate.
 */
describe('D-244: INR credited on buyer remittances', () => {
  let admin;
  let shortReason;

  afterAll(async () => {
    await db.pool.end();
  });

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-inr-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    const id = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest INR User', :email, NULL, :hash, :role_id, 1, 0, 0, 1)`,
      { email, hash: await bcrypt.hash('JestInr5!', 10), role_id: role.id }
    )).insertId;
    admin = { id, roleId: role.id, name: 'Jest INR User', email };
    shortReason = String(await remittanceDifference.reasonIdFor('short'));
  });

  async function permissionsOf(roleId) {
    const rows = await db.query('SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r', { r: roleId });
    return Object.fromEntries(rows.map((r) => [r.permission_key, true]));
  }

  async function call(action, orderId, body) {
    const req = { user: { id: admin.id, role_id: admin.roleId, name: admin.name }, permissions: await permissionsOf(admin.roleId), body, params: { id: String(orderId) }, session: {} };
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
    await db.execute("INSERT INTO documents (order_id, document_type_id, document_reference, status) VALUES (:o, :t, :r, 'approved')", { o: orderId, t: type.id, r: `JEST-INR-${crypto.randomBytes(4).toString('hex')}` });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    return orderId;
  }

  const advance = (amount, extra = {}) => ({ advance_amount: amount, advance_received_at: '2026-10-20', advance_bank_reference: 'UTR440001', ...extra });
  const receipts = (orderId) => partPaymentService.receipts(orderId, 'advance');
  async function registerRow(orderId) {
    const row = (await caRepository.settlementRegister()).find((r) => Number(r.order_id) === Number(orderId) && r.leg === 'advance');
    expect(row).toBeDefined();
    return row;
  }

  it('the INR credited sets the rate and the INR actual, and the register shows it', async () => {
    const orderId = await orderAtAdvance();
    const r = await call('recordAdvancePayment', orderId, advance('1800', { advance_inr_credited: '149850.00' }));
    expect(r.type).toBe('success');
    expect(r.message).toContain('Received USD 1,800.00; ₹149,850.00 credited by the bank — exchange rate worked out ₹83.2500.');

    const receipt = (await receipts(orderId))[0];
    expect([receipt.inr_credited, receipt.exchange_rate, receipt.bank_rate, receipt.rate_gap_inr, receipt.rate_gap_choice]).toEqual(['149850.00', '83.2500', null, null, null]);
    const ops = await orderPaymentStatusRepository.find(orderId);
    expect([ops.advance_exchange_rate, ops.advance_inr_actual]).toEqual(['83.2500', '149850.00']);
    expect(ops.advance_inr_actual_recorded_at).not.toBeNull();
    expect(Number(ops.advance_inr_actual_recorded_by)).toBe(admin.id);

    await call('clearAdvancePayment', orderId, { advance_cleared_at: '2026-10-21' });
    const row = await registerRow(orderId);
    expect([row.inr_actual, row.inr_estimated]).toEqual([149850, null]);
    const csv = caController.settlementRegisterCsvRows([row]);
    expect([csv.rows[0]['INR Actual'], csv.rows[0]['INR Estimated (at recorded rate)']]).toEqual([149850, null]);
  });

  it('a gap up to ₹1 saves without a question', async () => {
    const orderId = await orderAtAdvance();
    const r = await call('recordAdvancePayment', orderId, advance('1800', { advance_inr_credited: '149850.90', advance_bank_rate: '83.25' }));
    expect(r.type).toBe('success');
    const receipt = (await receipts(orderId))[0];
    expect([receipt.inr_credited, receipt.bank_rate, receipt.rate_gap_inr, receipt.rate_gap_choice]).toEqual(['149850.90', '83.2500', '-0.90', null]);
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);
  });

  it('a gap above ₹1 needs an answer', async () => {
    const orderId = await orderAtAdvance();
    const r = await call('recordAdvancePayment', orderId, advance('1800', { advance_inr_credited: '149348.00', advance_bank_rate: '83.25' }));
    expect(r.type).toBe('error');
    expect(r.message).toBe("At the bank's rate of ₹83.2500, USD 1,800.00 is ₹149,850.00, but ₹149,348.00 was credited — ₹502.00 less. Say why: the bank deducted its charges, a figure was typed wrong, or another reason.");
    expect(await receipts(orderId)).toEqual([]);
  });

  it('"the bank deducted its charges" books the gap as Bank Charges', async () => {
    const orderId = await orderAtAdvance();
    const order = await orderRepository.find(orderId);
    const r = await call('recordAdvancePayment', orderId, advance('1800', { advance_inr_credited: '149348.00', advance_bank_rate: '83.25', rate_gap_choice: 'bank_charges' }));
    expect(r.type).toBe('success');
    expect(r.message).toContain('₹502.00 the bank kept (at its rate of ₹83.2500) is recorded as an order cost "Bank Charges".');

    const receipt = (await receipts(orderId))[0];
    expect([receipt.inr_credited, receipt.bank_rate, receipt.rate_gap_inr, receipt.rate_gap_choice, receipt.exchange_rate]).toEqual(['149348.00', '83.2500', '502.00', 'bank_charges', '83.2500']);
    const costs = await orderCostEntryRepository.forOrder(orderId);
    expect(costs.length).toBe(1);
    const expected = `Bank charges deducted in rupees from ${order.company_legal_name}'s advance remittance for order ${order.buyer_inquiry_ref || order.order_reference}`
      + ": USD 1,800.00 at the bank's rate ₹83.2500 = ₹149,850.00, credited ₹149,348.00, charges ₹502.00 · bank ref UTR440001";
    expect([costs[0].category, costs[0].amount_inr, costs[0].description, costs[0].paid_from, String(costs[0].incurred_at).slice(0, 10)]).toEqual(['bank_charges', '502.00', expected, 'company_account', '2026-10-20']);
    expect(Number(receipt.rate_gap_cost_id)).toBe(Number(costs[0].id));

    expect((await orderPaymentStatusRepository.find(orderId)).advance_inr_actual).toBe('149348.00');
    expect(await remittanceDifference.chargesInr(orderId, 'advance')).toBe(502);
    expect(Math.round((await orderProfitabilityService.computeForOrder(orderId)).revenue_inr * 100) / 100).toBe(149850);
    const payload = zohoBooksService.paymentPayload({ depositAccountId: 'ACC1' }, 'C1', 'USD', 1800, 149348, '2026-10-21', 'REF-advance', await remittanceDifference.chargesInr(orderId, 'advance'), 'desc');
    expect([payload.amount, payload.exchange_rate, payload.bank_charges]).toEqual([1800, 83.25, 6.03]);

    // Owner 4 Nov 2026: the register labels the ₹502 as bank charges, not as a forex loss.
    await call('clearAdvancePayment', orderId, { advance_cleared_at: '2026-10-21' });
    const row = await registerRow(orderId);
    expect([Number(row.forex_gain_loss), row.bank_charges_inr]).toEqual([0, 502]);
    expect(caController.settlementRegisterCsvRows([row]).rows[0]['Bank Charges (deducted from credit)']).toBe(502);
  });

  it('a typo blocks the save', async () => {
    const orderId = await orderAtAdvance();
    const r = await call('recordAdvancePayment', orderId, advance('1800', { advance_inr_credited: '14985.00', advance_bank_rate: '83.25', rate_gap_choice: 'typo' }));
    expect(r.type).toBe('error');
    expect(r.message).toContain("You said a figure was typed wrong: correct the amount, the INR credited or the bank's rate so they agree (within ₹1), then save again.");
    expect(await receipts(orderId)).toEqual([]);
    expect((await orderPaymentStatusRepository.find(orderId)).advance_amount).toBeNull();
  });

  it('another reason is saved on the receipt', async () => {
    const orderId = await orderAtAdvance();
    const short = await call('recordAdvancePayment', orderId, advance('1800', { advance_inr_credited: '150300.00', advance_bank_rate: '83.25', rate_gap_choice: 'other', rate_gap_reason: 'Bank' }));
    expect([short.type, short.message]).toEqual(['error', "Type the reason the INR credited differs from the bank's rate (at least 10 characters)."]);

    const r = await call('recordAdvancePayment', orderId, advance('1800', { advance_inr_credited: '150300.00', advance_bank_rate: '83.25', rate_gap_choice: 'other', rate_gap_reason: "Bank applied the next day's card rate" }));
    expect(r.type).toBe('success');
    const receipt = (await receipts(orderId))[0];
    expect([receipt.inr_credited, receipt.rate_gap_inr, receipt.rate_gap_choice, receipt.rate_gap_reason, receipt.exchange_rate])
      .toEqual(['150300.00', '-450.00', 'other', "Bank applied the next day's card rate", '83.5000']);
    expect(await orderCostEntryRepository.forOrder(orderId)).toEqual([]);

    const orderId2 = await orderAtAdvance();
    const r2 = await call('recordAdvancePayment', orderId2, advance('1800', { advance_inr_credited: '150300.00', advance_bank_rate: '83.25', rate_gap_choice: 'bank_charges' }));
    expect(r2.type).toBe('error');
    expect(r2.message).toContain('so it cannot be bank charges');
  });

  it('"INR not known yet" keeps the typed rate and the register shows an estimate', async () => {
    const orderId = await orderAtAdvance();
    const missing = await call('recordAdvancePayment', orderId, advance('1800'));
    expect(missing.type).toBe('error');
    expect(missing.message.startsWith('Enter the INR credited by the bank (₹)')).toBe(true);

    const r = await call('recordAdvancePayment', orderId, advance('1800', { advance_inr_unknown: '1', advance_exchange_rate: '83.40' }));
    expect(r.type).toBe('success');
    expect(r.message).toContain('Received USD 1,800.00 at the exchange rate of ₹83.4000 (≈ ₹150,120.00, estimated until the INR credited is known).');
    expect((await receipts(orderId))[0].inr_credited).toBeNull();
    expect((await orderPaymentStatusRepository.find(orderId)).advance_inr_actual).toBeNull();

    await call('clearAdvancePayment', orderId, { advance_cleared_at: '2026-10-21' });
    const row = await registerRow(orderId);
    expect(row.inr_actual).toBeNull();
    expect(Math.round(row.inr_estimated * 100) / 100).toBe(150120);
    expect(caController.settlementRegisterCsvRows([row]).rows[0]['INR Estimated (at recorded rate)']).toBe(150120);

    const agent = request.agent(app);
    const csrf = (html) => (html.match(/name="_csrf"\s+value="([^"]+)"/) || [])[1];
    const loginToken = csrf((await agent.get('/login')).text);
    const login = await agent.post('/login').type('form').send({ _csrf: loginToken, email: admin.email, password: 'JestInr5!' });
    expect(login.headers.location).not.toBe('/login');
    const page = (await agent.get('/ca')).text;
    expect(page).toMatch(/&#8377;150,120\.00\s*<div class="muted small">estimated at recorded rate/);

    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 150000, admin.id);
    const after = await registerRow(orderId);
    expect([after.inr_actual, after.inr_estimated]).toEqual([150000, null]);
  });

  it("a leg's receipts add up, and a leg with a receipt without INR is not known", async () => {
    const orderId = await orderAtAdvance();
    const first = await call('recordAdvancePayment', orderId, advance('1000', { advance_inr_credited: '83250.00', difference_reason_choice: shortReason }));
    expect(first.type).toBe('success');
    const second = await call('recordAdvancePayment', orderId, advance('800', { advance_received_at: '2026-10-22', advance_inr_credited: '67000.00' }));
    expect(second.type).toBe('success');
    const ops = await orderPaymentStatusRepository.find(orderId);
    expect([ops.advance_amount, ops.advance_inr_actual]).toEqual(['1800.00', '150250.00']);
    expect(ops.advance_exchange_rate).toBe('83.4722');

    const again = await call('recordAdvancePayment', orderId, advance('800', { advance_received_at: '2026-10-22', advance_inr_credited: '67000.00' }));
    expect(again.type).toBe('warning');
    expect((await receipts(orderId)).length).toBe(2);

    const orderId2 = await orderAtAdvance();
    await call('recordAdvancePayment', orderId2, advance('1000', { advance_inr_credited: '83250.00', difference_reason_choice: shortReason }));
    await call('recordAdvancePayment', orderId2, advance('800', { advance_received_at: '2026-10-22', advance_inr_unknown: '1', advance_exchange_rate: '83.5' }));
    expect((await orderPaymentStatusRepository.find(orderId2)).advance_inr_actual).toBeNull();
  });

  it('a milestone takes the INR credited too', async () => {
    const orderId = await orderAtAdvance();
    await db.execute("INSERT INTO order_payment_milestones (order_id, seq, pct, due_text) VALUES (:o, 1, 20, 'on dispatch')", { o: orderId });
    const m = await db.queryOne('SELECT * FROM order_payment_milestones WHERE order_id = :o', { o: orderId });
    const req = { user: { id: admin.id, role_id: admin.roleId, name: admin.name }, permissions: await permissionsOf(admin.roleId),
      body: { amount: '900', inr_credited: '75150.00', bank_rate: '83.5', bank_reference: 'UTR440009', received_on: todayIst() }, params: { id: String(orderId), milestoneId: String(m.id) }, session: {} };
    const res = { redirect: () => {}, renderView: () => {}, status: () => res, send: () => {} };
    await paymentMilestoneController.record(req, res);
    const f = (req.session._flash || []).pop();
    expect(f.type).toBe('success');
    expect((await db.queryOne('SELECT exchange_rate FROM order_payment_milestones WHERE id = :id', { id: m.id })).exchange_rate).toBe('83.5000');
    const receipt = (await partPaymentService.receipts(orderId, 'milestone', Number(m.id)))[0];
    expect([receipt.inr_credited, receipt.bank_rate, receipt.rate_gap_inr]).toEqual(['75150.00', '83.5000', '0.00']);
  });

  it('keeps the rules in one place', () => {
    expect(remittanceInr.TOLERANCE_INR).toBe(1);
    const ok = remittanceInr.fromPost({ p_inr_credited: '8400', p_bank_rate: '84.01' }, 'p_', 100, 'USD');
    expect([ok.inr, ok.rate, ok.bank_rate, ok.gap, ok.choice]).toEqual([8400, 84, 84.01, 1, null]);
    expect(remittanceInr.fromPost({ p_inr_credited: '8400', p_bank_rate: '84.02' }, 'p_', 100, 'USD').error).toBeDefined();
    expect(remittanceInr.fromPost({ p_inr_unknown: '1', p_exchange_rate: '83' }, 'p_', 100, 'USD').rate).toBe(83);
    expect(remittanceInr.legInrActual([{ inr_credited: '10' }, { inr_credited: null }])).toBeNull();
    expect(remittanceInr.legInrActual([{ inr_credited: '10.25' }, { inr_credited: '20.25' }])).toBe(30.5);
  });
});
