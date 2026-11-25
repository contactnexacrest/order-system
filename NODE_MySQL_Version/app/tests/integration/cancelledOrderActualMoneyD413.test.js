'use strict';

// Owner decision D-413 (25 Nov 2026, confirmed twice): a cancelled or lost order counts only the money that
// actually moved — what the buyer paid (less a completed refund), what was paid to the supplier, real bills
// and expenses. No balance still to come, no full supplier order, no unbilled freight, no estimate.
// PHP twin: CancelledOrderActualMoneyD413Test.php.

const bcrypt = require('bcrypt');
const crypto = require('crypto');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

describe('D-413 cancelled or lost orders count only money that moved', () => {
  const PASSWORD = 'JestD413Pass!1';
  let userId;
  let email;

  beforeAll(async () => {
    const role = await db.queryOne("SELECT id FROM roles WHERE name = 'Admin'");
    email = `jest-d413-${crypto.randomBytes(4).toString('hex')}@nexacrest.test`;
    userId = (await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled, is_super_admin)
       VALUES ('Jest D413 User', :email, NULL, :hash, :role_id, 1, 0, 0, 1)`,
      { email, hash: await bcrypt.hash(PASSWORD, 4), role_id: role.id }
    )).insertId;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  /** USD CIF order of 10,000: advance 3,000 received at 84 (₹252,000 credited), supplier PO ₹600,000 with ₹150,000 paid, freight 1,000 confirmed. */
  async function order(status) {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    await orderProductRepository.add(orderId, 1, 'Test granite', null, null, '10', false, 'PCS', '1000');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 3000, '2026-11-01');
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 84, userId);
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 252000, userId);
    await orderPaymentStatusRepository.setBalanceAmount(orderId, 7000, null);
    await orderFreightRepository.upsert(orderId, { confirmed_freight_rate: '1000', insurance_amount: '50' });
    const supplierId = (await db.execute("INSERT INTO suppliers (supplier_legal_name) VALUES ('TEST-Supplier D413')")).insertId;
    await db.execute(
      `INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference, total_payable_inr, advance_paid_amount_inr)
       VALUES (:o, :s, 'TEST-Supplier D413', 'TEST-SPO-D413', 600000, 150000)`, { o: orderId, s: supplierId });
    await db.execute('UPDATE orders SET status = :s WHERE id = :id', { s: status, id: orderId });
    return orderId;
  }

  async function refund(orderId, amount, refundStatus) {
    await db.execute(
      `INSERT INTO order_cancellations (order_id, reason, requested_by, status, refund_amount, refund_status)
       VALUES (:o, 'TEST buyer withdrew', :u, 'approved', :a, :r)`, { o: orderId, u: userId, a: amount, r: refundStatus });
  }

  it('counts only money that moved on a cancelled order', async () => {
    const p = await orderProfitabilityService.computeForOrder(await order('cancelled'));

    expect(p.actual_money_only).toBe(true);
    expect(p.revenue_is_estimated).toBe(false);
    expect(p.revenue_inr).toBe(252000);
    expect(p.supplier_cost_inr).toBe(150000);
    expect(p.freight_cost_inr).toBe(0);
    expect(p.insurance_cost_inr).toBe(0);
  });

  it('takes a completed refund off at the advance rate', async () => {
    const orderId = await order('cancelled');
    await refund(orderId, 1000, 'completed');

    expect((await orderProfitabilityService.computeForOrder(orderId)).revenue_inr).toBe(168000);
  });

  it('does not take off a refund not yet paid', async () => {
    const orderId = await order('cancelled');
    await refund(orderId, 1000, 'pending');

    expect((await orderProfitabilityService.computeForOrder(orderId)).revenue_inr).toBe(252000);
  });

  it('counts a received leg without rupees entered at its rate', async () => {
    const orderId = await order('lost');
    await orderPaymentStatusRepository.clearAdvanceInrActual(orderId);

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.actual_money_only).toBe(true);
    expect(p.revenue_inr).toBe(252000);
  });

  it('still shows the estimate on an open order', async () => {
    const p = await orderProfitabilityService.computeForOrder(await order('active'));

    expect(p.actual_money_only).toBe(false);
    expect(p.supplier_cost_inr).toBe(600000);
    expect(p.freight_cost_inr).toBe(84000);
  });

  it('tags it on the profitability report (D-422)', async () => {
    const cancelled = await order('cancelled');
    const lost = await order('lost');
    const open = await order('active');
    const { rows } = await require('../../src/repositories/reportRepository').orderProfitabilityReport(null, null);
    const byId = Object.fromEntries(rows.map((r) => [Number(r.order_id), r]));
    expect(byId[cancelled].actual_money_only).toBe(true);
    expect(byId[cancelled].order_status).toBe('cancelled');
    expect(byId[lost].order_status).toBe('lost');
    expect(byId[open].actual_money_only).toBe(false);
    const view = require('fs').readFileSync(require('path').join(__dirname, '../../views/reports/profitability.njk'), 'utf8');
    expect(view).toContain('data-actual-money-tag');
    expect(view).toContain(' — actual money only');
    expect(view).toContain('data-actual-money-legend');
  });

  it('explains it on the order page', async () => {
    const orderId = await order('cancelled');
    const agent = request.agent(app);
    const csrf = (await agent.get('/login')).text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: PASSWORD });

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.status).toBe(200);
    expect(res.text).toContain('data-actual-money-only');
    expect(res.text).toContain('only money that actually moved is counted');
  });
});
