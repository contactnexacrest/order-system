'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * "we should show currency like USD/GBP/INR etc whenever needed — this
 * avoids confusion and makes it clear" — a sitewide audit found several
 * places showing a raw monetary figure with no currency code next to it
 * (Payment Status on the order page, the dashboard's overdue-payments
 * widget, and two report tables). Every createTestOrder fixture order is
 * USD (see tests/support/fixtures.js), so the assertions below check for
 * "USD" next to the figure.
 */
describe('Monetary figures show their currency code', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('shows the order currency next to Payment Status amounts on the order page', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000.0, '2026-06-01');
    await orderPaymentStatusRepository.setBalanceAmount(orderId, 3000.0, '2026-07-01');

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toMatch(/5,000\.00\s*USD/);
    expect(res.text).toMatch(/3,000\.00\s*USD/);
  });

  it('shows the currency on the dashboard overdue-balance widget', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.setBalanceAmount(orderId, 4200.0, '2020-01-01');

    const res = await agent.get('/');
    expect(res.status).toBe(200);
    expect(res.text).toMatch(/4,200\.00\s*USD/);
  });

  it('shows a Currency column on the per-client report', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-06-01');

    const res = await agent.get(`/reports/client/${clientId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('<th>Currency</th>');
  });

  it('shows a Currency column on the freight cost report', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderFreightRepository.upsert(orderId, {
      confirmed_freight_rate: '1500.00',
      insurance_amount: '0',
      freight_forwarder_name: 'Jest Forwarder',
    });

    const res = await agent.get('/reports/freight-cost');
    expect(res.status).toBe(200);
    expect(res.text).toContain('<th>Currency</th>');
    expect(res.text).toContain('USD');
  });
});
