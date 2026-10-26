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

  // Owner decision (2026-10-26, t4): the rate is recorded with the
  // remittance; the separate box is only a collapsed, reasoned correction.
  it('needs a reason to correct a recorded rate, and logs it', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 5000.0, '2026-01-10');
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 85.0, adminId);

    const page = await agent.get(`/orders/${orderId}`);
    expect(page.text).toContain('Correct a wrongly typed rate');
    expect(page.text).not.toContain('Record Rate');
    const csrf = extractCsrf(page.text);

    await agent.post(`/orders/${orderId}/payment/advance/exchange-rate`).type('form').send({ _csrf: csrf, advance_exchange_rate: '95' });
    expect(Number((await orderPaymentStatusRepository.find(orderId)).advance_exchange_rate)).toBe(85);

    await agent.post(`/orders/${orderId}/payment/advance/exchange-rate`).type('form')
      .send({ _csrf: csrf, advance_exchange_rate: '95', correction_reason: "Typed the wrong day's rate" });
    expect(Number((await orderPaymentStatusRepository.find(orderId)).advance_exchange_rate)).toBe(95);
    const logged = await db.queryOne(
      "SELECT old_value, new_value, reason FROM audit_log WHERE entity_type = 'order_payment_status' AND entity_id = :id AND action_type = 'CA_EXCHANGE_RATE_CORRECTED'",
      { id: orderId }
    );
    expect(logged).toEqual(expect.objectContaining({ new_value: '95', reason: "Typed the wrong day's rate" }));
  });
});
