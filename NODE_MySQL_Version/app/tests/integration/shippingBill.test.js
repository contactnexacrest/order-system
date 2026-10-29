'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const { todayIst } = require('../../src/helpers/dbDateTime');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Owner decision 2026-10-29 (CA advice): the shipping bill's number, date
 * and customs exchange rate are recorded on the order — the CI and Debit
 * Note go to Zoho Books at that rate. Mirrors PHP's ShippingBillTest.php.
 */
describe('Shipping bill and customs exchange rate', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginRes = await agent.post('/login').type('form').send({ _csrf: extractCsrf(loginPage.text), email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function post(orderId, body) {
    const page = await agent.get(`/orders/${orderId}`);
    expect(page.text).toContain(`action="/orders/${orderId}/shipping-bill"`);
    const res = await agent.post(`/orders/${orderId}/shipping-bill`).type('form').send({ _csrf: extractCsrf(page.text), ...body });
    expect(res.status).toBe(302);
    expect(res.headers.location).toBe(`/orders/${orderId}#shipping-bill`);
    const after = await agent.get(`/orders/${orderId}`);
    return after.text;
  }

  it('records the shipping bill and lets it be corrected', async () => {
    const orderId = await createTestOrder(await createTestClient());

    let html = await post(orderId, { shipping_bill_no: '1234567', shipping_bill_date: '2026-10-27', customs_exchange_rate: '84.35' });
    expect(html).toContain('Shipping bill 1234567 recorded (customs rate 84.35).');
    let row = await orderShippingRepository.find(orderId);
    expect([row.shipping_bill_no, String(row.shipping_bill_date).slice(0, 10), String(row.customs_exchange_rate)]).toEqual(['1234567', '2026-10-27', '84.3500']);
    expect(html).toMatch(/Shipping bill <strong>1234567<\/strong> dated 27 Oct 2026 &middot; customs exchange rate <strong>&#8377;84.35<\/strong>/);

    html = await post(orderId, { shipping_bill_no: '1234567', shipping_bill_date: '2026-10-27', customs_exchange_rate: '84.4' });
    row = await orderShippingRepository.find(orderId);
    expect(String(row.customs_exchange_rate)).toBe('84.4000');
    const audit = await db.queryOne("SELECT old_value, new_value FROM audit_log WHERE action_type = 'ORDER_SHIPPING_BILL_RECORDED' AND entity_id = :id ORDER BY id DESC LIMIT 1", { id: orderId });
    expect([audit.old_value, audit.new_value]).toEqual(['84.3500', '84.4']);
  });

  it('refuses bad input with a message', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const good = { shipping_bill_no: '1234567', shipping_bill_date: '2026-10-27', customs_exchange_rate: '84.35' };
    const tomorrow = new Date(`${todayIst()}T00:00:00Z`);
    tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);

    expect(await post(orderId, { ...good, shipping_bill_no: '' })).toContain('Enter the shipping bill number (up to 30 characters).');
    expect(await post(orderId, { ...good, shipping_bill_no: '9'.repeat(31) })).toContain('Enter the shipping bill number (up to 30 characters).');
    expect(await post(orderId, { ...good, shipping_bill_date: tomorrow.toISOString().slice(0, 10) })).toContain('Enter the shipping bill date (not in the future).');
    expect(await post(orderId, { ...good, shipping_bill_date: '2026-02-30' })).toContain('Enter the shipping bill date (not in the future).');
    expect(await post(orderId, { ...good, customs_exchange_rate: '0' })).toContain('Enter the customs exchange rate printed on the shipping bill');
    expect(await post(orderId, { ...good, customs_exchange_rate: 'abc' })).toContain('Enter the customs exchange rate printed on the shipping bill');
    expect((await orderShippingRepository.find(orderId))?.shipping_bill_no ?? null).toBeNull();
  });
});
