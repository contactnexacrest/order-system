'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const stageGateService = require('../../src/services/stageGateService');
const orderProfitabilityService = require('../../src/services/orderProfitabilityService');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Batch 3 #3: order_payment_status.assumed_exchange_rate was a single rate
 * shared by all three settlement legs, set once — but advance, balance and
 * freight clear on different dates, often months apart, at genuinely
 * different market rates. Replaced with advance_exchange_rate/
 * balance_exchange_rate/freight_exchange_rate, each captured and used
 * independently. Mirrors PHP's PerLegExchangeRateTest.php.
 */
describe('Per-leg exchange rate (Batch 3 #3)', () => {
  let agent;
  let userId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');

    const user = await userRepository.findByEmail(TEST_ADMIN_EMAIL);
    userId = user.id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function recordLegRateViaRoute(orderId, leg, rate) {
    const formPage = await agent.get(`/orders/${orderId}`);
    const csrf = extractCsrf(formPage.text);
    return agent
      .post(`/orders/${orderId}/payment/${leg}/exchange-rate`)
      .type('form')
      .send({ _csrf: csrf, [`${leg}_exchange_rate`]: String(rate) });
  }

  it("records each leg's rate independently", async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);

    await recordLegRateViaRoute(orderId, 'advance', 88.5);
    await recordLegRateViaRoute(orderId, 'balance', 90.25);
    await recordLegRateViaRoute(orderId, 'freight', 91.0);

    const payment = await orderPaymentStatusRepository.find(orderId);
    expect(Number(payment.advance_exchange_rate)).toBeCloseTo(88.5, 3);
    expect(Number(payment.balance_exchange_rate)).toBeCloseTo(90.25, 3);
    expect(Number(payment.freight_exchange_rate)).toBeCloseTo(91.0, 3);
    expect(payment.advance_exchange_rate_set_by).toBe(userId);
  });

  it("updating one leg's rate never touches another leg's rate", async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 85.0, userId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'balance', 86.0, userId);

    await recordLegRateViaRoute(orderId, 'advance', 95.0);

    const payment = await orderPaymentStatusRepository.find(orderId);
    expect(Number(payment.advance_exchange_rate)).toBeCloseTo(95.0, 3);
    expect(Number(payment.balance_exchange_rate)).toBeCloseTo(86.0, 3);
  });

  it('rejects a zero or missing rate', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);

    await recordLegRateViaRoute(orderId, 'advance', 0);

    const payment = await orderPaymentStatusRepository.find(orderId);
    expect(payment.advance_exchange_rate).toBeNull();
  });

  it("forex gain/loss uses each leg's own rate, not another leg's", async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    // Balance Payment's section only renders once Stage 8 unlocks (BL
    // issued) — walk the stage gates forward so both legs' widgets are
    // actually on the rendered page, not just advance's. FOB order
    // (createTestOrder's default), so Stage 6 is skipped, not passed.
    for (let stage = 1; stage <= 5; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, userId);
    }
    await stageGateService.maybeAutoSkipFreightStage(orderId, userId);
    await stageGateService.passAndUnlockNext(orderId, 7, userId);

    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 90.0, userId);
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 91000.0, userId);

    await orderPaymentStatusRepository.recordBalanceReceived(orderId, 1000.0, '2026-04-10');
    await orderPaymentStatusRepository.markBalanceCleared(orderId, '2026-04-15', userId);
    // $balanceCleared in the view is driven by Stage 8's gate having been
    // PASSED, not by this column alone — same sequence the real
    // clearBalancePayment route performs.
    await stageGateService.passAndUnlockNext(orderId, 8, userId);
    // Deliberately a very different rate from advance's — months apart, a
    // genuinely different market rate, the whole point of this feature.
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'balance', 86.0, userId);
    await orderPaymentStatusRepository.setBalanceInrActual(orderId, 86500.0, userId);

    const res = await agent.get(`/orders/${orderId}`);

    // Hand-computed: advance expected = 1000*90 = 90000, gain = 91000-90000 = 1000.
    expect(res.text).toContain('Forex gain: &#8377;1,000.00');
    // Hand-computed: balance expected = 1000*86 = 86000, gain = 86500-86000 = 500.
    expect(res.text).toContain('Forex gain: &#8377;500.00');
    expect(res.text).toContain('90.0000');
    expect(res.text).toContain('86.0000');
  });

  it('order profitability falls back to the full FOB value at whichever leg rate is on file, pre-PI', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderProductRepository.add(orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
    // FOB value = 10 * 100 = 1000; advance_amount/balance_amount both
    // still null (pre-PI order).
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 87.0, userId);

    const p = await orderProfitabilityService.computeForOrder(orderId);

    expect(p.revenue_inr).toBe(87000.0);
    expect(p.revenue_is_estimated).toBe(true);
  });

  it("order profitability mixes a real advance with an estimated balance using balance's own rate", async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderProductRepository.add(orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 300.0, '2026-01-10');
    await orderPaymentStatusRepository.recordBalanceReceived(orderId, 700.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 27000.0, userId); // advance leg realized exactly
    // Balance hasn't cleared yet — estimate it using ITS OWN rate, not
    // advance's, even though advance already has a real actual.
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'balance', 92.0, userId);

    const p = await orderProfitabilityService.computeForOrder(orderId);

    // 27000 (real advance) + 700*92 (estimated balance) = 27000 + 64400 = 91400.
    expect(p.revenue_inr).toBe(91400.0);
    expect(p.revenue_is_estimated).toBe(true);
  });

  it('rejects an unknown leg at the repository level', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);

    await expect(orderPaymentStatusRepository.setLegExchangeRate(orderId, 'not_a_real_leg', 85.0, userId)).rejects.toThrow();
  });
});
