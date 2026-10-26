'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const stageGateService = require('../../src/services/stageGateService');
const userRepository = require('../../src/repositories/userRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of ExchangeRateBeforeClearingTest.php. 90-item backlog "exchange-
 * rate-at-clearing-time": docs/SOP/ca-02-revenue-reports.md says a leg's
 * Exchange Rate is "typically entered once that leg is about to clear"
 * — i.e. BEFORE the leg is marked cleared, as an anticipatory estimate,
 * separate from the real INR actual recorded AT clearing. The widget
 * used to only ever render from inside the INR Actual widget, which
 * itself returns early until cleared_at is set.
 */
describe('Exchange rate before clearing (90-item backlog "exchange-rate-at-clearing-time")', () => {
  let agent;
  let adminId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    adminId = (await userRepository.findByEmail(TEST_ADMIN_EMAIL)).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('offers the advance exchange rate form once received but before clearing', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000.0, '2026-01-10');

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="advance_exchange_rate"');
  });

  it('does not offer the advance exchange rate form before any amount is recorded', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await orderPaymentStatusRepository.initializeForOrder(orderId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    // The remittance form itself now asks for the day's rate alongside the
    // amount; what must not appear yet is the separate CA rate box.
    expect(res.text).not.toContain('/payment/advance/exchange-rate"');
  });

  it('still offers the advance exchange rate form after clearing', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-12', adminId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="advance_exchange_rate"');
  });
});
