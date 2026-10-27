'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const orderFreightRepository = require('../../src/repositories/orderFreightRepository');
const logisticsPartnerRepository = require('../../src/repositories/logisticsPartnerRepository');
const stageGateService = require('../../src/services/stageGateService');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of LogisticsPartnerFreightTest.php. Owner request (2026-10-27): the
 * freight forwarder in Step 9a is picked from the Logistics Partners
 * directory (like the supplier in 7a from the Suppliers directory), not free
 * text; factory addresses are edited only in the Suppliers directory.
 */
describe('Freight forwarder from the Logistics Partners directory (owner request 2026-10-27)', () => {
  let userId;

  beforeAll(async () => {
    userId = (await db.queryOne('SELECT id FROM users WHERE email = :email', { email: TEST_ADMIN_EMAIL })).id;
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function cifOrder() {
    const orderId = await createTestOrder(await createTestClient(), 'CIF');
    for (let stage = 1; stage <= 5; stage++) await stageGateService.passAndUnlockNext(orderId, stage, null);
    return orderId;
  }

  async function save(orderId, body) {
    const req = { user: { id: userId }, params: { id: String(orderId) }, session: {}, body: { confirmed_freight_rate: '950', insurance_amount: '120', ...body } };
    await ordersController.saveFreightTerms(req, { redirect: () => {} });
    const messages = flash.pull(req);
    return messages[messages.length - 1] || { type: '' };
  }

  it('requires an active logistics partner and copies its details', async () => {
    const orderId = await cifOrder();
    expect((await save(orderId, {})).type).toBe('error');
    const inactive = await logisticsPartnerRepository.create({ partner_name: 'Old Forwarder', service_type: 'freight_forwarder' }, userId);
    await logisticsPartnerRepository.toggleActive(inactive);
    expect((await save(orderId, { freight_forwarder_partner_id: String(inactive) })).type).toBe('error');
    expect(await orderFreightRepository.find(orderId)).toBeNull();

    const partner = await logisticsPartnerRepository.create({ partner_name: 'Blue Wave Logistics', service_type: 'freight_forwarder', contact_person_name: 'Ravi', phone: '+91 90000 11111', email: 'ops@bluewave.test' }, userId);
    expect((await save(orderId, { freight_forwarder_partner_id: String(partner), freight_forwarder_name: 'ignored free text' })).type).toBe('success');
    const freight = await orderFreightRepository.find(orderId);
    expect(freight.freight_forwarder_name).toBe('Blue Wave Logistics');
    expect(freight.freight_forwarder_contact).toBe('Ravi · +91 90000 11111 · ops@bluewave.test');
    expect(Number(freight.freight_forwarder_partner_id)).toBe(partner);
  });

  it('lists the Logistics Partners directory in 9a', async () => {
    const orderId = await cifOrder();
    const partner = await logisticsPartnerRepository.create({ partner_name: `Blue Wave ${orderId}`, service_type: 'freight_forwarder' }, userId);
    const agent = request.agent(app);
    const login = await agent.get('/login');
    await agent.post('/login').type('form').send({ _csrf: login.text.match(/name="_csrf"\s+value="([^"]+)"/)[1], email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    const html = (await agent.get(`/orders/${orderId}`)).text;
    expect(html).toMatch(new RegExp(`name="freight_forwarder_partner_id"[\\s\\S]*?value="${partner}">Blue Wave ${orderId} \\(Freight Forwarder\\)`));
    expect(html).not.toContain('Manage factory addresses');
  });
});
