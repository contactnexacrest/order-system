'use strict';

const bcrypt = require('bcrypt');
const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const fieldValidator = require('../../src/helpers/fieldValidator');
const orderLineValidator = require('../../src/helpers/orderLineValidator');
const orderAccessService = require('../../src/services/orderAccessService');
const incentiveService = require('../../src/services/incentiveService');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');

/**
 * Port of QaRound6InputTest.php. QA Round 6, Batch 2 (owner approved the
 * plan 2026-10-30): bad ids and records that do not exist
 * (R6-N04/N05/N06/N07), incentive payouts (R6-N10, R6-N29), product line
 * limits (R6-N14/N15), the buyer's payment report (R6-N19/N20/N21), the
 * customs rate (R6-N23), inverted ranges (R6-N28) and a page number that
 * is not a number (R6-N30, Node only).
 */
describe('QA Round 6 — bad input is refused with a message, never a server error', () => {
  let admin;
  let adminId;

  afterAll(async () => {
    await db.pool.end();
  });

  const csrfOf = (html) => html.match(/name="_csrf"\s+value="([^"]+)"/)[1];

  async function loginAdmin(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :n', { n: roleName });
    const email = `jest-r6in-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await bcrypt.hash('JestRound6in!', 10), role_id: role.id }
    );
    const agent = request.agent(app);
    const csrf = csrfOf((await agent.get('/login')).text);
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password: 'JestRound6in!' });
    return { agent, id: result.insertId };
  }

  async function post(agent, path, fields, page = '/') {
    const csrf = csrfOf((await agent.get(page)).text);
    return agent.post(path).type('form').send({ _csrf: csrf, ...fields });
  }

  async function flashAfter(agent, res) {
    const m = (await agent.get(res.headers.location)).text.match(/class="alert alert-(\w+)">([\s\S]*?)<\/div>/);
    return m ? { type: m[1], message: m[2].trim() } : { type: null, message: '' };
  }

  async function auditCount(action, entityId) {
    return Number((await db.queryOne('SELECT COUNT(*) AS n FROM audit_log WHERE action_type = :a AND entity_id = :e', { a: action, e: entityId })).n);
  }

  beforeEach(async () => {
    ({ agent: admin, id: adminId } = await loginAdmin());
  });

  it('an id that is not a positive whole number matches no route (R6-N05/N07)', async () => {
    for (const bad of ['-1', '0', '1.5', 'abc', '99999999999999999999']) {
      const res = await admin.post(`/hs-codes/${bad}/toggle`).type('form').send({});
      expect([bad, res.status, res.text]).toEqual([bad, 404, '404 Not Found']);
    }
    expect((await admin.get('/associates/abc/edit')).status).toBe(404);
    expect((await admin.get('/orders/abc/email/compose')).status).toBe(404);
  });

  it('a path to a missing order or record is not found (R6-N04/N06)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    expect(await orderAccessService.missingRecordForPath('/orders/999999999/annexure/terms')).toBe('Order not found.');
    expect(await orderAccessService.missingRecordForPath('/disputes/999999999/replies')).toBe('Not found.');
    expect(await orderAccessService.missingRecordForPath('/documents/999999999/download')).toBe('Not found.');
    expect(await orderAccessService.missingRecordForPath(`/orders/${orderId}/annexure/terms`)).toBeNull();
    expect(await orderAccessService.missingRecordForPath('/hs-codes/1/toggle')).toBeNull();

    const res = await post(admin, '/orders/999999999/production-status', { production_status: 'in_production' });
    expect([res.status, res.text]).toEqual([404, 'Order not found.']);

    // Only after the route's own checks: a user without the permission is
    // refused as before and never learns whether the order exists.
    const { agent: viewer } = await loginAdmin('Viewer / Auditor');
    const refused = await viewer.get('/orders/999999999/annexure');
    expect(refused.status).toBe(403);
    expect(refused.text).not.toContain('not found');
    expect((await admin.get(`/orders/${orderId}/annexure`)).status).toBe(200);
  });

  it('actions on missing records report not found and log nothing (R6-N04)', async () => {
    let res = await post(admin, '/holidays/999999999/delete', {}, '/holidays');
    expect((await flashAfter(admin, res)).type).toBe('error');
    expect(await auditCount('HOLIDAY_REMOVED', 999999999)).toBe(0);

    res = await post(admin, '/hs-codes/999999999/toggle', {}, '/hs-codes');
    expect((await flashAfter(admin, res)).message).toContain('was not found');
    expect(await auditCount('HS_CODE_TOGGLED', 999999999)).toBe(0);

    const orderId = await createTestOrder(await createTestClient());
    res = await post(admin, `/orders/${orderId}/payment/advance/inr-actual/delete`, {}, `/orders/${orderId}`);
    expect((await flashAfter(admin, res)).message).toContain('nothing was removed');
    expect(await auditCount('CA_INR_ACTUAL_DELETED', orderId)).toBe(0);

    res = await admin.get('/associates/999999999/edit');
    expect([res.status, res.text]).toEqual([404, 'Associate not found.']);
  });

  it('a payout cannot be paid above its amount or before its month (R6-N10)', async () => {
    const month = todayIst().slice(0, 7);
    const orderId = await createTestOrder(await createTestClient());
    const { insertId: id } = await db.execute(
      "INSERT INTO incentive_payouts (order_id, payee_kind, user_id, role, amount_inr, incentive_month, status) VALUES (:o, 'staff', :u, 'sales', 5000, :m, 'due')",
      { o: orderId, u: adminId, m: month }
    );
    const status = async () => (await db.queryOne('SELECT status FROM incentive_payouts WHERE id = :id', { id })).status;
    const page = `/incentives/payouts?month=${month}`;

    let res = await post(admin, `/incentives/payouts/${id}/paid`, { month, paid_on: todayIst(), paid_inr: '5000000' }, page);
    expect((await flashAfter(admin, res)).message).toContain('cannot be more than the payout');
    expect(await status()).toBe('due');

    res = await post(admin, `/incentives/payouts/${id}/paid`, { month, paid_on: '1900-01-01', paid_inr: '5000' }, page);
    expect((await flashAfter(admin, res)).message).toContain('cannot be before');
    expect(await status()).toBe('due');

    res = await post(admin, `/incentives/payouts/${id}/paid`, { month, paid_on: todayIst(), paid_inr: '5000' }, page);
    expect((await flashAfter(admin, res)).type).toBe('success');
    expect(await status()).toBe('paid');
  });

  it('an impossible month on the payouts page is ignored (R6-N29)', async () => {
    const res = await admin.get('/incentives/payouts?month=2026-13');
    expect(res.status).toBe(200);
    expect(res.text).not.toContain('2026-13');
    expect(incentiveService.isMonth('2026-12')).toBe(true);
    expect(incentiveService.isMonth('2026-00')).toBe(false);
  });

  it('product lines stay within what can be stored (R6-N14/N15)', async () => {
    expect(orderLineValidator.checkLine('10', false, '0.0001')).toContain('at most 2 decimals');
    expect(orderLineValidator.checkLine('10', false, '1000000000000000')).toContain('can be at most');
    expect(orderLineValidator.checkLine('1000000', false, '9999999')).toContain('line value');
    expect(orderLineValidator.checkLine('1.0001', false, '5')).toContain('at most 3 decimals');
    expect(orderLineValidator.checkLine('100', false, '45.50')).toBeNull();
    expect(orderLineValidator.checkLine('', true, '45.50')).toBeNull();

    const orderId = await createTestOrder(await createTestClient());
    const hs = (await db.queryOne('SELECT code FROM hs_codes WHERE is_active = 1 LIMIT 1')).code;
    const lines = async () => Number((await db.queryOne('SELECT COUNT(*) AS n FROM order_products WHERE order_id = :o', { o: orderId })).n);
    const before = await lines();
    const res = await post(admin, `/orders/${orderId}/products`, { description: 'Jest granite', hs_code: hs, quantity: '10', unit_price: '0.0001' }, `/orders/${orderId}`);
    expect((await flashAfter(admin, res)).message).toContain('at most 2 decimals');
    expect(await lines()).toBe(before);
  });

  it('the payment the buyer reports is checked (R6-N19/N20/N21)', async () => {
    expect(fieldValidator.reportedPayment('-500', '')).toBe('The amount paid must be at least 0.01.');
    expect(fieldValidator.reportedPayment('100000000000000000000', '')).toContain('too large');
    expect(fieldValidator.reportedPayment('100', '2099-01-01')).toBe('Enter the date you paid (today or earlier).');
    expect(fieldValidator.reportedPayment('100', '2026-02-31')).toBe('Enter the date you paid (today or earlier).');
    expect(fieldValidator.reportedPayment('12500.00', todayIst())).toBeNull();
    expect(fieldValidator.reportedPayment('', '')).toBeNull();

    const email = `jest-r6-client-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, 'JestClient123!');
    const orderId = await createTestOrder(clientId);
    const client = request.agent(app);
    const clientCsrf = csrfOf((await client.get('/client/login')).text);
    await client.post('/client/login').type('form').send({ _csrf: clientCsrf, email, password: 'JestClient123!' });
    for (const [amount, date] of [['-500', todayIst()], ['100', '2099-01-01'], ['100000000000000000000', '']]) {
      const res = await post(client, `/client/orders/${orderId}/report-payment`, { payment_type: 'advance', transaction_ref: 'UTR-JEST', amount, payment_date: date }, `/client/orders/${orderId}`);
      expect([amount, (await flashAfter(client, res)).type]).toEqual([amount, 'error']);
    }
    expect(Number((await db.queryOne('SELECT COUNT(*) AS n FROM client_payment_reports WHERE order_id = :o', { o: orderId })).n)).toBe(0);
  });

  it('a tiny customs rate is refused (R6-N23)', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const res = await post(admin, `/orders/${orderId}/shipping-bill`, { shipping_bill_no: 'SB-JEST', shipping_bill_date: todayIst(), customs_exchange_rate: '0.00001' }, `/orders/${orderId}`);
    expect((await flashAfter(admin, res)).type).toBe('error');
    expect(fieldValidator.exchangeRate(0.00001)).not.toBeNull();
  });

  it('inverted ranges are refused (R6-N28)', async () => {
    expect(fieldValidator.orderNumbers({ lead_time_min_days: '30', lead_time_max_days: '10' })).toContain('lead time minimum');
    expect(fieldValidator.orderNumbers({ sailing_lead_time_min_days: '40', sailing_lead_time_max_days: '5' })).toContain('sailing time minimum');
    expect(fieldValidator.orderNumbers({ estimated_net_weight_kg: '5000', estimated_gross_weight_kg: '100' })).toContain('net weight');
    expect(fieldValidator.orderNumbers({ lead_time_min_days: '10', lead_time_max_days: '30', estimated_net_weight_kg: '90', estimated_gross_weight_kg: '100' })).toBeNull();

    const orderId = await createTestOrder(await createTestClient());
    const res = await post(admin, `/orders/${orderId}/edit`, { lead_time_min_days: '30', lead_time_max_days: '10' }, `/orders/${orderId}`);
    expect((await flashAfter(admin, res)).type).toBe('error');
    expect(Number((await db.queryOne('SELECT lead_time_min_days AS v FROM orders WHERE id = :o', { o: orderId })).v)).not.toBe(30);
  });

  it('a page number that is not a number is page 1 (R6-N30)', async () => {
    expect((await admin.get('/audit-log?page=abc')).status).toBe(200);
    expect((await admin.get('/audit-log?entity_id=abc&user_id=abc')).status).toBe(200);
    expect((await admin.get('/reports/aggregate?stage_id=abc&incoterm_id=abc')).status).toBe(200);
  });
});
