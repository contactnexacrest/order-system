'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const stageGateService = require('../../src/services/stageGateService');
const lookupRepository = require('../../src/repositories/lookupRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of ShippingContainerTypeDropdownTest.php. 90-item backlog #36:
 * Container Type is an admin-managed dropdown list (Settings > Dropdown
 * Options) everywhere else it's captured — New Order creation, Edit Order
 * Details — but Stage 7's "Shipping / Vessel Details" form on the order
 * page rendered it as a free-text <input>, letting staff type anything
 * instead of picking from the same admin-managed list, so
 * order_shipping.container_type could drift out of sync with the values
 * the admin screen actually defines. Fixed by rendering the same dropdown
 * here too (ordersController.show() now passes containerTypes).
 */
describe('Shipping container type dropdown (90-item backlog #36)', () => {
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

  it('Stage 7 shipping form renders the admin-managed container type dropdown', async () => {
    const orderId = await createTestOrder(await createTestClient());
    // FOB order, so Stage 6 (Freight Payment) auto-skips, same as
    // perLegExchangeRate.test.js, to reach Stage 7 unlocked.
    for (let stage = 1; stage <= 5; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, userId);
    }
    await stageGateService.maybeAutoSkipFreightStage(orderId, userId);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="ship_container_type"');
    expect(res.text).not.toContain('<input type="text" name="ship_container_type"');

    const containerTypes = await lookupRepository.dropdownOptions('container_type');
    expect(containerTypes.length).toBeGreaterThan(0);
    for (const option of containerTypes) {
      expect(res.text).toContain(option.option_value);
    }
  });
});
