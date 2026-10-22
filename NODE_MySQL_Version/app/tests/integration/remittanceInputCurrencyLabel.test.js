'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const documentRepository = require('../../src/repositories/documentRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * 90-item backlog #82: the order page's Payment Status section already
 * labels its DISPLAY-only amounts with the order's currency code (e.g.
 * "5,000.00 USD"), but the actual "Amount Received *" INPUT fields on the
 * advance/freight/balance remittance-recording forms gave no indication of
 * which currency to type the figure in. Mirrors PHP's
 * RemittanceInputCurrencyLabelTest.php.
 */
describe('Remittance input currency label (90-item backlog #82)', () => {
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

  it('labels the advance remittance input with the order\'s own currency', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'GBP');
    await stageGateService.passAndUnlockNext(orderId, 1, userId);
    await stageGateService.passAndUnlockNext(orderId, 2, userId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain('name="advance_amount"');
    expect(res.text).toMatch(/Advance Amount Received \*\s*<small class="muted">\(in GBP\)<\/small>\s*<input type="text" name="advance_amount"/);
  });

  it('labels the freight remittance input with the order\'s own currency', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'CIF', 'Standard — New Buyer', 'EUR');
    for (let stage = 1; stage <= 5; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, userId);
    }
    await orderFreightRepository.upsert(orderId, {
      confirmed_freight_rate: '500',
      insurance_amount: '50',
      freight_forwarder_name: 'Test Forwarder',
      freight_forwarder_contact: 'test@forwarder.test',
    });
    await orderPaymentStatusRepository.initializeForOrder(orderId);

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain('name="freight_amount"');
    expect(res.text).toMatch(/Freight Amount Received \*\s*<small class="muted">\(in EUR\)<\/small>\s*<input type="text" name="freight_amount"/);
  });

  it('labels the balance remittance input with the order\'s own currency', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB', 'Standard — New Buyer', 'EUR');
    for (let stage = 1; stage <= 5; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, userId);
    }
    await stageGateService.maybeAutoSkipFreightStage(orderId, userId);
    await orderShippingRepository.upsert(orderId, { etd: null, eta: null });
    await orderShippingRepository.recordBl(orderId, 'BL-CURRLABEL-TEST', '2026-01-01');
    await stageGateService.passAndUnlockNext(orderId, 7, userId);
    const ciType = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: 'CI' });
    await documentRepository.create(orderId, ciType.id, 'CI-CURRLABEL-TEST', 1, null, null, userId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);

    const res = await agent.get(`/orders/${orderId}`);

    expect(res.text).toContain('name="balance_amount"');
    expect(res.text).toMatch(/Balance Amount Received \*\s*<small class="muted">\(in EUR\)<\/small>\s*<input type="text" name="balance_amount"/);
  });
});
