'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * "As the order is incomplete, then beneficiaries scheme won't be
 * applicable, so showing is of no use. But when the order is complete and
 * we receive the funds for CI and remittance is noted, then only
 * government beneficiaries schemes will come in picture till then it is
 * not." Verifies the actual rendered order page, not just the controller's
 * view-data object (see caOrderLinking.test.js for that level).
 */
describe('Government Export Benefits section is gated on order-complete + CI remittance', () => {
  let agent;
  let userId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    const me = await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL });
    userId = me.id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('hides a claimed benefit and shows the not-applicable message before the order is complete', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-GATE-1', 9000, '2026-06-01', 'INR', null, userId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('Not applicable until the order is complete');
    expect(res.text).not.toContain('RODTEP');
  });

  it('shows the claimed benefit once the order is complete and remittance is received', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await caExportBenefitRepository.record(orderId, 'RODTEP', 'SB-GATE-2', 9000, '2026-06-01', 'INR', null, userId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordBalanceReceived(orderId, 9000.0, '2026-06-01');
    await orderRepository.markComplete(orderId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);
    expect(res.text).toContain('RODTEP');
    expect(res.text).not.toContain('Not applicable until the order is complete');
  });
});
