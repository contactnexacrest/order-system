'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OrderLeadTimeDaysTest.php. 90-item backlog "dual lead-time
 * fields in days": alongside the existing free-text est_lead_time_text
 * (unchanged, still the only thing printed on the Quotation), orders/create
 * and orders/edit now also capture lead_time_min_days/lead_time_max_days
 * as plain numbers — internal reporting/sorting only, never shown on any
 * customer-facing document.
 */
describe('Order lead-time min/max days (90-item backlog "dual lead-time fields in days")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = () => {};
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = () => {};
    return res;
  }

  async function incotermId(code) {
    return (await db.queryOne('SELECT id FROM incoterms WHERE code = :code', { code })).id;
  }
  async function currencyId() {
    return (await db.queryOne("SELECT id FROM currencies WHERE code = 'USD'")).id;
  }
  async function paymentPresetId() {
    return (await db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")).id;
  }

  async function createTestUser(roleName = 'Export Executive') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-leadtime-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  it('create form persists lead time min and max days', async () => {
    const clientId = await createTestClient();
    const userId = await createTestUser();
    const req = {
      session: {},
      user: { id: userId },
      body: {
        client_id: String(clientId),
        incoterm_id: String(await incotermId('FOB')),
        currency_id: String(await currencyId()),
        payment_preset_id: String(await paymentPresetId()),
        est_lead_time_text: '45-60 days from advance receipt',
        lead_time_min_days: '45',
        lead_time_max_days: '60',
        product_description: ['Lead-time test granite slab'],
        product_hs_code: ['680293'],
        product_quantity: ['10'],
        product_unit_price: ['5.00'],
      },
    };

    await ordersController.store(req, fakeRes());

    const row = await db.queryOne('SELECT MAX(id) AS id FROM orders');
    const order = await orderRepository.find(row.id);

    expect(order.lead_time_min_days).toBe(45);
    expect(order.lead_time_max_days).toBe(60);
    expect(order.est_lead_time_text).toBe('45-60 days from advance receipt');
  });

  it('lead time days are optional on create', async () => {
    const clientId = await createTestClient();
    const userId = await createTestUser();
    const req = {
      session: {},
      user: { id: userId },
      body: {
        client_id: String(clientId),
        incoterm_id: String(await incotermId('FOB')),
        currency_id: String(await currencyId()),
        payment_preset_id: String(await paymentPresetId()),
        product_description: ['No lead-time granite slab'],
        product_hs_code: ['680293'],
        product_quantity: ['10'],
        product_unit_price: ['5.00'],
      },
    };

    await ordersController.store(req, fakeRes());

    const row = await db.queryOne('SELECT MAX(id) AS id FROM orders');
    const order = await orderRepository.find(row.id);

    expect(order.lead_time_min_days).toBeNull();
    expect(order.lead_time_max_days).toBeNull();
  });

  it('updateDetails overwrites lead time days', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const req = {
      params: { id: String(orderId) },
      session: {},
      body: {
        incoterm_id: String(await incotermId('FOB')),
        currency_id: String(await currencyId()),
        lead_time_min_days: '20',
        lead_time_max_days: '30',
      },
    };

    await ordersController.updateDetails(req, fakeRes());

    const order = await orderRepository.find(orderId);
    expect(order.lead_time_min_days).toBe(20);
    expect(order.lead_time_max_days).toBe(30);
  });
});
