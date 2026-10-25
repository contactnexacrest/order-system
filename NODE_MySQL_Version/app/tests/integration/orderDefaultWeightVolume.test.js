'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const flash = require('../../src/helpers/flash');
const ordersController = require('../../src/controllers/ordersController');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderDefaultWeightVolumeTest.php. 90-item backlog "default
 * weight/volume values": the New Order form's Total CBM/Gross Weight/Net
 * Weight fields used to always start blank. They now pre-fill from
 * configurable company_settings — staff still edit per order as always,
 * and a validation-retry's own typed value (or the #41/#46 intake
 * prefill) always wins over the configured default.
 */
describe('Order default weight/volume values (90-item backlog "default weight/volume values")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = {};
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  it('create form prefills configured weight/volume defaults', async () => {
    const req = { query: {}, session: {} };
    const res = fakeRes();

    await ordersController.create(req, res);

    const totalCbm = await companySettingsRepository.get('default_estimated_total_cbm');
    const grossWeight = await companySettingsRepository.get('default_estimated_gross_weight_kg');
    const netWeight = await companySettingsRepository.get('default_estimated_net_weight_kg');

    expect(res.renderedData.old.estimated_total_cbm).toBe(totalCbm);
    expect(res.renderedData.old.estimated_gross_weight_kg).toBe(grossWeight);
    expect(res.renderedData.old.estimated_net_weight_kg).toBe(netWeight);
  });

  it('a validation-retry value wins over the configured default', async () => {
    const req = { query: {}, session: {} };
    flash.setOld(req, { estimated_total_cbm: '99.9' });
    const res = fakeRes();

    await ordersController.create(req, res);

    expect(res.renderedData.old.estimated_total_cbm).toBe('99.9');
  });

  async function loggedInAgent() {
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    return agent;
  }

  // Owner request (2026-10-25): a typical full container for this business.
  it('seeds a full container as the default estimates', async () => {
    expect(await companySettingsRepository.get('default_estimated_total_cbm')).toBe('8.2');
    expect(await companySettingsRepository.get('default_estimated_gross_weight_kg')).toBe('27000');
    expect(await companySettingsRepository.get('default_estimated_net_weight_kg')).toBe('26000');
  });

  // Owner request (2026-10-25): the configured default instead of an "e.g."
  // placeholder — also on Edit Order, for an order that has no estimate
  // saved; a saved value always wins.
  it('shows the defaults on the edit form for an order with no estimate saved', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await db.execute(
      'UPDATE orders SET estimated_total_cbm = NULL, estimated_gross_weight_kg = NULL, estimated_net_weight_kg = 25500 WHERE id = :id',
      { id: orderId }
    );
    const agent = await loggedInAgent();

    const { text } = await agent.get(`/orders/${orderId}/edit`);

    expect(text).toContain('name="estimated_total_cbm" value="8.2"');
    expect(text).toContain('name="estimated_gross_weight_kg" value="27000"');
    expect(text).toMatch(/name="estimated_net_weight_kg" value="25500(\.0+)?"/);
    expect(text).not.toMatch(/name="estimated_(total_cbm|gross_weight_kg|net_weight_kg)" value="[^"]*" placeholder=/);
  });

  it('has no example placeholders on these fields of the create form', async () => {
    const agent = await loggedInAgent();

    const { text } = await agent.get('/orders/create');

    expect(text).not.toMatch(/name="estimated_(total_cbm|gross_weight_kg|net_weight_kg)" value="[^"]*" placeholder=/);
  });
});
