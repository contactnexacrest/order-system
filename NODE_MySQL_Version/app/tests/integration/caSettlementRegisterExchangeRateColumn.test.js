'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of CaSettlementRegisterExchangeRateColumnTest.php. 90-item backlog
 * #56/#84: caRepository.settlementRegister() already computes each
 * cleared leg's own exchange_rate (used internally for forex gain/loss),
 * but the INR Settlement Register table on the CA hub page never
 * actually rendered it as a column.
 */
describe('CA settlement register Exchange Rate column (backlog #56/#84)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  const PASSWORD = 'Ca-Settlement-Test-1!';

  async function createTestStaffUserWithRealPassword() {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: 'Admin' });
    const hash = await passwordHash.hash(PASSWORD);
    const email = `jest-ca-settlement-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest CA Test User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash, role_id: role.id }
    );
    return { userId: result.insertId, email };
  }

  async function loginAndGetCaHubHtml(email) {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrfToken = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrfToken, email, password: PASSWORD });
    expect(loginRes.status).toBe(302);

    const hub = await agent.get('/ca');
    expect(hub.status).toBe(200);
    return hub.text;
  }

  it('renders an Exchange Rate column with each leg\'s own rate', async () => {
    const { userId, email } = await createTestStaffUserWithRealPassword();
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    await orderPaymentStatusRepository.setLegExchangeRate(orderId, 'advance', 90.5, userId);
    await orderPaymentStatusRepository.setAdvanceInrActual(orderId, 91200.0, userId);

    const html = await loginAndGetCaHubHtml(email);

    expect(html).toContain('<th>Exchange Rate</th>');
    const headerPos = html.indexOf('<th>Exchange Rate</th>');
    const ratePos = html.indexOf('90.5000');
    expect(ratePos).toBeGreaterThan(-1);
    expect(ratePos).toBeGreaterThan(headerPos);
  });

  it('shows a dash when no rate is recorded yet', async () => {
    const { userId, email } = await createTestStaffUserWithRealPassword();
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await orderPaymentStatusRepository.recordAdvanceReceived(orderId, 1000.0, '2026-01-10');
    await orderPaymentStatusRepository.markAdvanceCleared(orderId, '2026-01-15', userId);
    // Deliberately no setLegExchangeRate() call.

    const html = await loginAndGetCaHubHtml(email);

    expect(html).toContain('<th>Exchange Rate</th>');
  });
});
