'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const stageGateService = require('../../src/services/stageGateService');
const orderReorderRequestRepository = require('../../src/repositories/orderReorderRequestRepository');
const lookupRepository = require('../../src/repositories/lookupRepository');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

async function assertEveryOptionPresent(listKey, html) {
  const options = await lookupRepository.dropdownOptions(listKey);
  expect(options.length).toBeGreaterThan(0);
  for (const option of options) {
    expect(html).toContain(option.option_value);
  }
}

/**
 * Port of UnitAndPackageTypeDropdownTest.php. 90-item backlog #43/#66: the
 * product-line/Supplier-PO "Unit" field and the order-level "Package Type"
 * field were both plain free-text inputs, unlike every other admin-managed
 * field on these same screens (COO Type, Container Type, Supplier Type),
 * which already render from dropdown_options. Same pattern as
 * shippingContainerTypeDropdown.test.js (90-item backlog #36).
 */
describe('Unit and Package Type dropdowns (90-item backlog #43/#66)', () => {
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

  it('New Order form renders unit and package type dropdowns', async () => {
    const res = await agent.get('/orders/create');
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="product_unit[]"');
    expect(res.text).not.toContain('<input type="text" name="product_unit[]"');
    expect(res.text).toContain('name="estimated_package_type"');
    expect(res.text).not.toContain('<input type="text" name="estimated_package_type"');

    await assertEveryOptionPresent('unit', res.text);
    await assertEveryOptionPresent('package_type', res.text);
  });

  it('order detail page renders the unit dropdown for product lines and Supplier PO', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="unit"');
    expect(res.text).not.toContain('<input type="text" name="unit"');
    await assertEveryOptionPresent('unit', res.text);
  });

  it('Edit Order Details renders the package type dropdown', async () => {
    const orderId = await createTestOrder(await createTestClient());

    const res = await agent.get(`/orders/${orderId}/edit`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="estimated_package_type"');
    expect(res.text).not.toContain('<input type="text" name="estimated_package_type"');
    await assertEveryOptionPresent('package_type', res.text);
  });

  it('reorder request review screen renders the unit dropdown', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const requestId = await orderReorderRequestRepository.create(clientId, orderId, null);
    await orderReorderRequestRepository.addProductLine(requestId, 1, 'Test Product', null, null, '10', false, 'PCS', null, null);

    const res = await agent.get(`/reorder-requests/${requestId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="unit[0]"');
    expect(res.text).not.toContain('<input type="text" name="unit[0]"');
    await assertEveryOptionPresent('unit', res.text);
  });

  it('client portal reorder form renders the unit dropdown', async () => {
    const email = `jest-unitpkg-client-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
    const password = 'JestUnitPkgTest123!';
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, password);
    const orderId = await createTestOrder(clientId);

    const clientAgent = request.agent(app);
    const loginPage = await clientAgent.get('/client/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await clientAgent.post('/client/login').type('form').send({ _csrf: csrf, email, password });
    expect(loginRes.status).toBe(302);
    expect(loginRes.headers.location).toBe('/client');

    const res = await clientAgent.get(`/client/orders/${orderId}/reorder`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="product_unit[0]"');
    expect(res.text).not.toContain('<input type="text" name="product_unit[0]"');
    await assertEveryOptionPresent('unit', res.text);
  });
});
