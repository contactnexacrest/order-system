'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of LogisticsPartnerFreightTest.php. Owner request (2026-10-27): the
 * freight forwarder in Step 9a is a logistics partner picked from the
 * Suppliers directory, not free text; Step 7a's supplier list leaves
 * logistics-only partners out; factory addresses are edited only in the
 * directory.
 */
describe('Logistics partner in Step 9a (owner request 2026-10-27)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function cifOrder() {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    for (let stage = 1; stage <= 5; stage++) await stageGateService.passAndUnlockNext(orderId, stage, null);
    return orderId;
  }

  async function save(orderId, body) {
    const req = { user: { id: null }, params: { id: String(orderId) }, session: {}, body: { confirmed_freight_rate: '950', insurance_amount: '120', ...body } };
    await ordersController.saveFreightTerms(req, { redirect: () => {} });
    const messages = flash.pull(req);
    return messages[messages.length - 1] || { type: '' };
  }

  it('requires a logistics partner from the directory and copies its details', async () => {
    const orderId = await cifOrder();
    expect((await save(orderId, {})).type).toBe('error');
    const quarry = await supplierRepository.create({ supplier_legal_name: 'Quarry Co' });
    await supplierRepository.setTypes(quarry, ['Quarry Owner']);
    expect((await save(orderId, { freight_forwarder_supplier_id: String(quarry) })).type).toBe('error');
    expect(await orderFreightRepository.find(orderId)).toBeNull();

    const partner = await supplierRepository.create({ supplier_legal_name: 'Blue Wave Logistics', contact_person: 'Ravi', phone: '+91 90000 11111', email: 'ops@bluewave.test' });
    await supplierRepository.setTypes(partner, [supplierRepository.LOGISTICS_TYPE]);
    expect((await save(orderId, { freight_forwarder_supplier_id: String(partner), freight_forwarder_name: 'ignored free text' })).type).toBe('success');
    const freight = await orderFreightRepository.find(orderId);
    expect(freight.freight_forwarder_name).toBe('Blue Wave Logistics');
    expect(freight.freight_forwarder_contact).toBe('Ravi · +91 90000 11111 · ops@bluewave.test');
    expect(Number(freight.freight_forwarder_supplier_id)).toBe(partner);
  });

  it('lists partners in 9a and suppliers in 7a', async () => {
    const orderId = await cifOrder();
    const partner = await supplierRepository.create({ supplier_legal_name: `Blue Wave ${orderId}` });
    await supplierRepository.setTypes(partner, [supplierRepository.LOGISTICS_TYPE]);
    const quarry = await supplierRepository.create({ supplier_legal_name: `Quarry Co ${orderId}` });
    await supplierRepository.setTypes(quarry, ['Quarry Owner']);

    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    const html = (await agent.get(`/orders/${orderId}`)).text;

    expect(html).toMatch(new RegExp(`name="freight_forwarder_supplier_id"[\\s\\S]*?value="${partner}"`));
    const spoSelect = html.slice(html.indexOf('id="spo-supplier-select"'), html.indexOf('id="spo-supplier-select"') + 3000);
    expect(spoSelect).toContain(`Quarry Co ${orderId}`);
    expect(spoSelect).not.toContain(`Blue Wave ${orderId}`);
    expect(html).not.toContain('Manage factory addresses');
  });
});
