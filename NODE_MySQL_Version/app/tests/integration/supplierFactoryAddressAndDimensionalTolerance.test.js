'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const userRepository = require('../../src/repositories/userRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const supplierFactoryAddressRepository = require('../../src/repositories/supplierFactoryAddressRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');

function extractCsrf(html) {
  const m = html.match(/name="_csrf"\s+value="([^"]+)"/);
  if (!m) throw new Error('CSRF token not found in response HTML');
  return m[1];
}

/**
 * Port of SupplierFactoryAddressAndDimensionalToleranceTest.php.
 * User-reported batch item 12: "Dimensional Tolerance = must be text not
 * place holder - dropdown + Delivery Location = factory address
 * (supplier details must have factory address add option - one owner
 * may have multiple factory addresses - dropdown)".
 */
describe('Supplier factory addresses + Dimensional Tolerance dropdown (user-reported batch, item 12)', () => {
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

  async function unlockThrough(orderId, throughStage) {
    for (let stage = 1; stage <= throughStage; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
  }

  it('Dimensional Tolerance is a dropdown with a real default, not just a placeholder', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 4);

    const res = await agent.get(`/orders/${orderId}`);
    expect(res.status).toBe(200);

    const pos = res.text.indexOf('name="dimensional_tolerance"');
    expect(pos).toBeGreaterThan(-1);
    expect(res.text.slice(pos - 20, pos + 20)).toContain('<select');
    const fragment = res.text.slice(pos, pos + 600);
    expect(fragment).toContain('±1 mm');
    expect(fragment).toMatch(/value="±1 mm"\s+selected/);
  });

  it('a factory address can be added and appears on the supplier\'s own dropdown option', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 4);
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Udaipur Granite Works' });

    const page = await agent.get(`/orders/${orderId}`);
    const csrf = extractCsrf(page.text);
    const addRes = await agent
      .post(`/orders/${orderId}/suppliers/${supplierId}/factory-addresses`)
      .type('form')
      .send({ _csrf: csrf, address: 'Plot 12, Industrial Area, Udaipur, Rajasthan', label: 'Udaipur Factory' });
    expect(addRes.status).toBe(302);

    const addresses = await supplierFactoryAddressRepository.forSupplier(supplierId);
    expect(addresses).toHaveLength(1);
    expect(addresses[0].address).toBe('Plot 12, Industrial Area, Udaipur, Rajasthan');
    expect(addresses[0].label).toBe('Udaipur Factory');

    const res = await agent.get(`/orders/${orderId}`);
    const optionPos = res.text.indexOf(`value="${supplierId}" data-addresses=`);
    expect(optionPos).toBeGreaterThan(-1);
    const optionTag = res.text.slice(optionPos, optionPos + 400);
    expect(optionTag).toContain('Plot 12, Industrial Area, Udaipur, Rajasthan');

    const manageSectionPos = res.text.indexOf('Manage factory addresses');
    expect(manageSectionPos).toBeGreaterThan(-1);
    expect(res.text.slice(manageSectionPos, manageSectionPos + 2000)).toContain('Udaipur Factory');
  });

  it('a factory address can be removed', async () => {
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'Removable Supplier' });
    const addressId = await supplierFactoryAddressRepository.create(supplierId, '1 Test Road', null);
    const orderId = await createTestOrder(await createTestClient());

    const page = await agent.get(`/orders/${orderId}`);
    const csrf = extractCsrf(page.text);
    const delRes = await agent
      .post(`/orders/${orderId}/suppliers/factory-addresses/${addressId}/delete`)
      .type('form')
      .send({ _csrf: csrf });
    expect(delRes.status).toBe(302);

    expect(await supplierFactoryAddressRepository.find(addressId)).toBeNull();
  });
});
