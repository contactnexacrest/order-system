'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const ordersController = require('../../src/controllers/ordersController');
const portRepository = require('../../src/repositories/portRepository');
const portRouteRepository = require('../../src/repositories/portRouteRepository');
const portRouteController = require('../../src/controllers/portRouteController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of PortRouteAndSailingLeadTimeTest.php. 90-item backlog item 15:
 * a port-to-port sailing-days reference document (`port_routes`), plus a
 * new sailing_lead_time_min_days/max_days pair on orders — split out
 * from the existing lead_time_min_days/max_days, which stays as
 * "Production Lead Time". Covers portRouteRepository's CRUD/lookup, the
 * JSON endpoint, and that orders/create and orders/edit actually persist
 * the new fields as plain, freely-editable numbers.
 */
describe('Port routes & sailing lead time (90-item backlog item 15)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, redirectedTo: null, jsonBody: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = () => {};
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = () => {};
    res.json = (data) => { res.jsonBody = data; };
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
      { email: `jest-portroute-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function makePorts() {
    const loadingPortId = await portRepository.create(`Jest Loading Port ${Math.random().toString(16).slice(2, 8)}`, null, null, 'loading', 1);
    const dischargePortId = await portRepository.create(`Jest Discharge Port ${Math.random().toString(16).slice(2, 8)}`, null, null, 'discharge', 1);
    return { loadingPortId, dischargePortId };
  }

  it('create then findByPorts returns the route', async () => {
    const { loadingPortId, dischargePortId } = await makePorts();

    const id = await portRouteRepository.create(loadingPortId, dischargePortId, 18, 24, 'Direct service');

    expect(id).toBeGreaterThan(0);
    const route = await portRouteRepository.findByPorts(loadingPortId, dischargePortId);
    expect(route.sailing_days_min).toBe(18);
    expect(route.sailing_days_max).toBe(24);
    expect(route.notes).toBe('Direct service');
  });

  it('findByPorts returns null for an unrecorded pair', async () => {
    const { loadingPortId, dischargePortId } = await makePorts();

    expect(await portRouteRepository.findByPorts(loadingPortId, dischargePortId)).toBeNull();
  });

  it('findByPorts ignores a deactivated route', async () => {
    const { loadingPortId, dischargePortId } = await makePorts();
    const id = await portRouteRepository.create(loadingPortId, dischargePortId, 18, 24, null);
    await portRouteRepository.toggleActive(id);

    expect(await portRouteRepository.findByPorts(loadingPortId, dischargePortId)).toBeNull();
  });

  it('create rejects a duplicate port pair', async () => {
    const { loadingPortId, dischargePortId } = await makePorts();
    await portRouteRepository.create(loadingPortId, dischargePortId, 18, 24, null);

    await expect(portRouteRepository.create(loadingPortId, dischargePortId, 20, 26, null)).rejects.toMatchObject({ code: 'ER_DUP_ENTRY' });
  });

  it('lookup endpoint returns found true with range', async () => {
    const { loadingPortId, dischargePortId } = await makePorts();
    await portRouteRepository.create(loadingPortId, dischargePortId, 18, 24, null);
    const req = { query: { from: String(loadingPortId), to: String(dischargePortId) } };

    const res = fakeRes();
    await portRouteController.lookup(req, res);

    expect(res.jsonBody).toEqual({ found: true, min: 18, max: 24 });
  });

  it('lookup endpoint returns found false for an unknown pair', async () => {
    const { loadingPortId, dischargePortId } = await makePorts();
    const req = { query: { from: String(loadingPortId), to: String(dischargePortId) } };

    const res = fakeRes();
    await portRouteController.lookup(req, res);

    expect(res.jsonBody).toEqual({ found: false });
  });

  it('create form persists sailing lead time days alongside production lead time', async () => {
    const clientId = await createTestClient();
    const userId = await createTestUser();
    const { loadingPortId, dischargePortId } = await makePorts();
    const req = {
      session: {},
      user: { id: userId },
      body: {
        client_id: String(clientId),
        incoterm_id: String(await incotermId('FOB')),
        currency_id: String(await currencyId()),
        payment_preset_id: String(await paymentPresetId()),
        port_of_loading_id: String(loadingPortId),
        port_of_discharge_id: String(dischargePortId),
        lead_time_min_days: '45',
        lead_time_max_days: '60',
        sailing_lead_time_min_days: '18',
        sailing_lead_time_max_days: '24',
        product_description: ['Sailing-lead-time test granite slab'],
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
    expect(order.sailing_lead_time_min_days).toBe(18);
    expect(order.sailing_lead_time_max_days).toBe(24);
  });

  it('sailing lead time days are optional on create', async () => {
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
        product_description: ['No sailing-lead-time granite slab'],
        product_hs_code: ['680293'],
        product_quantity: ['10'],
        product_unit_price: ['5.00'],
      },
    };

    await ordersController.store(req, fakeRes());

    const row = await db.queryOne('SELECT MAX(id) AS id FROM orders');
    const order = await orderRepository.find(row.id);

    expect(order.sailing_lead_time_min_days).toBeNull();
    expect(order.sailing_lead_time_max_days).toBeNull();
  });

  it('updateDetails overwrites sailing lead time days', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const req = {
      params: { id: String(orderId) },
      session: {},
      body: {
        incoterm_id: String(await incotermId('FOB')),
        currency_id: String(await currencyId()),
        sailing_lead_time_min_days: '20',
        sailing_lead_time_max_days: '30',
      },
    };

    await ordersController.updateDetails(req, fakeRes());

    const order = await orderRepository.find(orderId);
    expect(order.sailing_lead_time_min_days).toBe(20);
    expect(order.sailing_lead_time_max_days).toBe(30);
  });
});
