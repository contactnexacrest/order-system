'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const incentiveRepository = require('../../src/repositories/incentiveRepository');
const paymentDescription = require('../../src/services/paymentDescription');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of PaymentDescriptionRuleTest.php. Owner rule D-212 (2026-10-31):
 * every money record has a correct description — so the forms that record
 * money refuse to save without the bank / payment reference it is built
 * from. Batch 1: buyer remittances, incentive payouts, staff-added costs,
 * export benefits received and cancellation refunds. (The LC payment and
 * the Zoho Books descriptions are checked in zohoBooksStage2.test.js and
 * zohoBooksStage4.test.js.)
 */
describe('Rule D-212 — money records need the reference their description is built from', () => {
  let admin;
  let adminId;

  afterAll(async () => {
    await db.pool.end();
  });

  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  beforeEach(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    const email = `jest-d212-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash('JestRuleD212!', 10), role_id: role.id }
    );
    adminId = result.insertId;
    admin = request.agent(app);
    const csrf = csrfOf((await admin.get('/login')).text);
    await admin.post('/login').type('form').send({ _csrf: csrf, email, password: 'JestRuleD212!' });
  });

  async function post(path, fields, page = '/') {
    const csrf = csrfOf((await admin.get(page)).text);
    return admin.post(path).type('form').send({ _csrf: csrf, ...fields });
  }

  async function flashAfter(res) {
    const m = (await admin.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim() } : { type: null, message: '' };
  }

  const value = async (sql, params = {}) => {
    const row = await db.queryOne(sql, params);
    return row ? Object.values(row)[0] : null;
  };

  it('a buyer remittance needs its bank reference', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slab', 100, 'SQM', 40, 4000)", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    const fields = { advance_amount: '1600', advance_received_at: todayIst(), advance_exchange_rate: '84.10' };
    const page = `/orders/${orderId}`;

    expect((await flashAfter(await post(`/orders/${orderId}/payment/advance`, fields, page))).message).toContain('bank reference is required');
    expect(await value('SELECT advance_remittance_received_at FROM order_payment_status WHERE order_id = :o', { o: orderId })).toBeNull(); // nothing recorded without it

    expect((await flashAfter(await post(`/orders/${orderId}/payment/advance`, { ...fields, advance_bank_reference: 'HDFCN26304123456' }, page))).type).toBe('success');
    expect(await value('SELECT advance_bank_reference FROM order_payment_status WHERE order_id = :o', { o: orderId })).toBe('HDFCN26304123456');
    const ref = await value('SELECT buyer_inquiry_ref FROM orders WHERE id = :o', { o: orderId });
    expect(await paymentDescription.buyerPayment(orderId, 'advance'))
      .toBe(`Advance received from Jest Test Buyer Ltd for order ${ref} · USD 1,600.00 at ₹84.10 · bank ref HDFCN26304123456`);
  });

  it('a payout needs how it was paid and its reference', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const month = todayIst().slice(0, 7);
    await db.execute(
      "INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by, incentive_kind) VALUES (:o, 'commission', 'Team incentive — test month', 5000, CURDATE(), :u, 'team')",
      { o: orderId, u: adminId }
    );
    const payout = (await db.execute(
      "INSERT INTO incentive_payouts (order_id, payee_kind, user_id, role, amount_inr, incentive_month, status) VALUES (:o, 'staff', :u, 'sales', 5000, :m, 'due')",
      { o: orderId, u: adminId, m: month }
    )).insertId;
    const fields = { month, paid_on: todayIst(), paid_inr: '5000' };
    const pay = async (body) => flashAfter(await post(`/incentives/payouts/${payout}/paid`, body, `/incentives/payouts?month=${month}`));

    expect((await pay({ ...fields, paid_reference: 'Payroll Nov-2026' })).message).toContain('Choose how it was paid');
    expect((await pay({ ...fields, paid_via: 'salary' })).message).toContain('payment reference is required');
    expect(await value('SELECT status FROM incentive_payouts WHERE id = :id', { id: payout })).toBe('due');

    expect((await pay({ ...fields, paid_via: 'salary', paid_reference: 'Payroll Nov-2026' })).type).toBe('success');
    expect(await db.queryOne('SELECT paid_via, paid_reference FROM incentive_payouts WHERE id = :id', { id: payout })).toEqual({ paid_via: 'salary', paid_reference: 'Payroll Nov-2026' });
    // the cost line (and so the Zoho expense) says how it was paid
    expect(await value("SELECT description FROM order_cost_entries WHERE order_id = :o AND incentive_kind = 'team'", { o: orderId }))
      .toBe('Team incentive — test month · paid with the salary (payroll), ref Payroll Nov-2026');
    expect(Object.keys(incentiveRepository.PAID_VIA)).toEqual(['salary', 'bank_transfer', 'cheque']);
  });

  it('a cost needs a description', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const fields = { category: 'commission', amount_inr: '9000', incurred_at: todayIst(), paid_from: 'company_account', description: 'Agent' };
    const page = `/orders/${orderId}`;
    expect((await flashAfter(await post(`/orders/${orderId}/cost-entries`, fields, page))).message).toContain('who was paid and for what');
    expect(Number(await value('SELECT COUNT(*) AS n FROM order_cost_entries WHERE order_id = :o', { o: orderId }))).toBe(0);

    await post(`/orders/${orderId}/cost-entries`, { ...fields, description: 'Agent commission — Ravi Traders, invoice RT/45' }, page);
    expect(await value('SELECT description FROM order_cost_entries WHERE order_id = :o', { o: orderId })).toBe('Agent commission — Ravi Traders, invoice RT/45');
  });

  it('an export benefit received says how it was credited', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const claim = await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB 4417893', 2350, todayIst(), 'INR', null, adminId);

    const fields = { received_amount: '2350', received_at: todayIst() };
    expect((await flashAfter(await post(`/ca/export-benefits/${claim}/mark-received`, fields, '/ca/export-benefits'))).message)
      .toContain('How it was credited (bank UTR / e-scrip no.) is required');
    expect(await value('SELECT received_amount FROM ca_export_benefits WHERE id = :id', { id: claim })).toBeNull();

    await post(`/ca/export-benefits/${claim}/mark-received`, { ...fields, received_reference: '2610000123456' }, '/ca/export-benefits');
    expect(await db.queryOne('SELECT received_amount, received_reference FROM ca_export_benefits WHERE id = :id', { id: claim }))
      .toEqual({ received_amount: '2350.00', received_reference: '2610000123456' });

    // Step 13g's table: a row with a received amount needs its credit reference too.
    await db.execute("UPDATE orders SET status = 'complete' WHERE id = :o", { o: orderId });
    await db.execute('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)', { o: orderId });
    await db.execute('UPDATE order_payment_status SET balance_remittance_received_at = NOW() WHERE order_id = :o', { o: orderId });
    const res = await post(`/orders/${orderId}/export-benefits/save-all`, {
      'benefits[0][scheme_name]': 'Duty Drawback', 'benefits[0][claimed_amount]': '1800', 'benefits[0][claimed_at]': todayIst(),
      'benefits[0][received_amount]': '1785', 'benefits[0][received_at]': todayIst(),
    }, `/orders/${orderId}`);
    expect((await flashAfter(res)).message).toContain('Duty Drawback: How it was credited');
    expect(Number(await value("SELECT COUNT(*) AS n FROM ca_export_benefits WHERE order_id = :o AND scheme_name = 'Duty Drawback'", { o: orderId }))).toBe(0); // nothing saved
  });

  it('a refund needs its date and bank reference', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const cancellation = (await db.execute(
      "INSERT INTO order_cancellations (order_id, reason, requested_by, status, decided_by, decided_at, refund_amount, refund_status) VALUES (:o, 'Buyer withdrew the order', :u, 'approved', :u2, NOW(), 3200, 'pending')",
      { o: orderId, u: adminId, u2: adminId }
    )).insertId;
    const complete = async (body) => flashAfter(await post(`/orders/${orderId}/cancellation/${cancellation}/complete-refund`, body, `/orders/${orderId}`));

    expect((await complete({ refund_reference: 'HDFCO26311000777' })).message).toContain('date the refund was paid');
    expect((await complete({ refund_paid_at: todayIst() })).message).toContain('bank reference of the refund is required');
    expect(await value('SELECT refund_status FROM order_cancellations WHERE id = :id', { id: cancellation })).toBe('pending');

    expect((await complete({ refund_paid_at: todayIst(), refund_reference: 'HDFCO26311000777' })).type).toBe('success');
    expect(await db.queryOne("SELECT refund_status, DATE_FORMAT(refund_paid_at, '%Y-%m-%d') AS paid_at, refund_reference FROM order_cancellations WHERE id = :id", { id: cancellation }))
      .toEqual({ refund_status: 'completed', paid_at: todayIst(), refund_reference: 'HDFCO26311000777' });
  });

  // Batch 2 (A13): an expense typed in Zoho Books without a description is flagged, so the CA fixes it there.
  it('flags a Zoho expense without a description', async () => {
    const tag = Math.random().toString(16).slice(2, 8);
    await db.execute(
      `INSERT INTO ca_expenses (zoho_expense_id, category, description, vendor_name, amount, currency_code, expense_date)
       VALUES (:a, 'Courier', '', 'Blue Dart', 850, 'INR', CURDATE()), (:b, 'Courier', :desc, 'DHL', 2100, 'INR', CURDATE())`,
      { a: `TEST-ZX-${tag}-1`, b: `TEST-ZX-${tag}-2`, desc: `Courier of BL copies to the buyer ${tag}` }
    );
    const html = (await admin.get('/ca/expenses')).text;
    expect(html).toContain('>No description in Zoho</span>');
    expect(html).toContain(`Courier of BL copies to the buyer ${tag}`);
  });

  // Batch 2: the descriptions built for the inspection fee, the LC loan interest and the travel cost.
  it('builds the cost descriptions', () => {
    expect(paymentDescription.inspectionFee(' SGS India Pvt Ltd ', 'SGS/MUM/26/0457')).toBe('Pre-shipment inspection fee — SGS India Pvt Ltd, invoice SGS/MUM/26/0457');
    expect(paymentDescription.lcLoanInterest('LC-778', 250000, 9.5)).toBe('Bank loan interest (LC order) · LC LC-778 · loan ₹250,000.00 at 9.5%');
    expect(paymentDescription.lcLoanInterest(null, null, null)).toBe('Bank loan interest (LC order)');
    expect(paymentDescription.travelExpenses(175, 5, 'USD', 8000, 84.1)).toBe('Travel expenses (factory visits) — 175 km to the factory, 5% of the order value USD 8,000.00 at ₹84.10');
    expect(paymentDescription.travelExpenses(null, 7.5, 'INR', 500000, 1)).toBe('Travel expenses (factory visits) — 7.5% of the order value INR 500,000.00');
  });
});
