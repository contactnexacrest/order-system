'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

async function unlockThroughStage4(orderId) {
  for (let stage = 1; stage <= 4; stage++) {
    await stageGateService.passAndUnlockNext(orderId, stage, null);
  }
}

/**
 * Port of SupplierPoBasicValuePlaceholderTest.php. 90-item backlog
 * "basic_value_inr placeholder": every other field on the Supplier PO
 * form shows an example value while empty (Quantity, Unit Price, Advance
 * %), but Basic Value — an auto-calculated field, same as GST Amount/
 * Total Payable/Advance & Balance Amount — was the one such field the
 * business specifically wants a placeholder on.
 */
describe('Supplier PO basic_value_inr placeholder (90-item backlog "basic_value_inr placeholder")', () => {
  let agent;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const loginCsrf = extractCsrf(loginPage.text);
    const loginRes = await agent.post('/login').type('form').send({ _csrf: loginCsrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).not.toBe('/login');
    await userRepository.findByEmail(TEST_ADMIN_EMAIL);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('basic value field has a placeholder', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage4(orderId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="basic_value_inr" data-spo-calc="basic_value_inr" placeholder="e.g., 225000.00"');
  });
});
