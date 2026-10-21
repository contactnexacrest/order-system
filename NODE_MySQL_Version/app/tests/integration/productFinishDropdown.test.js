'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const lookupRepository = require('../../src/repositories/lookupRepository');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder, createTestClientLogin } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

async function assertEveryOptionPresent(listKey, text) {
  const options = await lookupRepository.dropdownOptions(listKey);
  expect(options.length).toBeGreaterThan(0);
  for (const option of options) {
    expect(text).toContain(option.option_value);
  }
}

/**
 * Port of ProductFinishDropdownTest.php. 90-item backlog "product Finish
 * dropdown": the order product line's Finish field was plain free text
 * everywhere it appears (New Order form, the order page's Add/Edit
 * Product Line forms, the client reorder request form), unlike the
 * Supplier PO's Surface Finish field (90-item backlog #51a), which
 * already got the dropdown_options treatment. Same fix, reusing the same
 * 'surface_finish' list.
 */
describe('Product Finish dropdown (90-item backlog "product Finish dropdown")', () => {
  let staffAgent;

  beforeAll(async () => {
    staffAgent = request.agent(app);
    const loginPage = await staffAgent.get('/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await staffAgent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
  });

  afterAll(async () => {
    await db.pool.end();
  });

  it('order page Add/Edit Product Line forms render Finish as a dropdown', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await orderProductRepository.add(orderId, 1, 'Granite Slab', null, 'Polished', '10', false, 'PCS', '100', '68022310');

    const res = await staffAgent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="finish"');
    expect(res.text).not.toContain('<input type="text" name="finish"');
    await assertEveryOptionPresent('surface_finish', res.text);
  });

  it('New Order form renders product Finish as a dropdown', async () => {
    const res = await staffAgent.get('/orders/create');
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="product_finish[]"');
    expect(res.text).not.toContain('<input type="text" name="product_finish[]"');
    await assertEveryOptionPresent('surface_finish', res.text);
  });

  it('client reorder form renders product Finish as a dropdown', async () => {
    const email = `jest-finish-dropdown-${Math.random().toString(16).slice(2, 8)}@nexacrest.test`;
    const clientId = await createTestClient(email);
    await createTestClientLogin(clientId, 'JestFinishTest123!');
    const orderId = await createTestOrder(clientId);
    await orderProductRepository.add(orderId, 1, 'Granite Slab', null, 'Polished', '10', false, 'PCS', '100', '68022310');

    const clientAgent = request.agent(app);
    const loginPage = await clientAgent.get('/client/login');
    const csrf = extractCsrf(loginPage.text);
    const loginRes = await clientAgent.post('/client/login').type('form').send({ _csrf: csrf, email, password: 'JestFinishTest123!' });
    expect(loginRes.status).toBe(302);

    const res = await clientAgent.get(`/client/orders/${orderId}/reorder`);
    expect(res.status).toBe(200);

    expect(res.text).toContain('name="product_finish[0]"');
    expect(res.text).not.toContain('<input type="text" name="product_finish[0]"');
    await assertEveryOptionPresent('surface_finish', res.text);
  });

  it('a selected Finish value is saved when adding a product line', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);

    const page = await staffAgent.get(`/orders/${orderId}`);
    const csrf = extractCsrf(page.text);
    const addRes = await staffAgent
      .post(`/orders/${orderId}/products`)
      .type('form')
      .send({ _csrf: csrf, description: 'Granite Monument', hs_code: '68022310', finish: 'Honed' });
    expect(addRes.status).toBe(302);

    const products = await orderProductRepository.forOrder(orderId);
    expect(products[0].finish).toBe('Honed');
  });
});
