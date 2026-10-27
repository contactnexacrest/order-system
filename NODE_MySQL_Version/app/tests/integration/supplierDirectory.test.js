'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const supplierController = require('../../src/controllers/supplierController');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const supplierFactoryAddressRepository = require('../../src/repositories/supplierFactoryAddressRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const permissionService = require('../../src/services/permissionService');
const stageGateService = require('../../src/services/stageGateService');
const flash = require('../../src/helpers/flash');
const { TEST_ADMIN_EMAIL, TEST_ADMIN_PASSWORD } = require('../support/globalSetup');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of SupplierDirectoryTest.php. Suppliers directory (docs/schema.sql
 * Section BZ): CRUD with several types per supplier, delete only for a
 * never-used supplier, and the order staying self-contained —
 * order_supplier_po keeps its own copy of the supplier's details (and the
 * one type chosen for that order), so editing or deleting in the directory
 * never changes an existing order.
 */
describe('Suppliers directory (Section BZ)', () => {
  let agent;
  let userId;

  beforeAll(async () => {
    agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    const loginRes = await agent.post('/login').type('form').send({ _csrf: csrf, email: TEST_ADMIN_EMAIL, password: TEST_ADMIN_PASSWORD });
    expect(loginRes.status).toBe(302);
    userId = await createTestUser('Admin');
  });

  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName) {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-supplier-dir-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  const uniqueName = (prefix) => `${prefix} ${Math.random().toString(16).slice(2, 10)}`;
  const fakeReq = (body = {}, params = {}) => ({ params, body, query: {}, user: { id: userId }, session: {} });
  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.status = () => res;
    res.send = () => {};
    return res;
  }
  const lastFlash = (req) => {
    const messages = flash.pull(req);
    return messages.length ? messages[messages.length - 1].message : '';
  };
  async function supplierIdByName(name) {
    const row = await db.queryOne('SELECT id FROM suppliers WHERE supplier_legal_name = :n', { n: name });
    return row ? row.id : null;
  }
  function poData(overrides = {}) {
    const keys = ['material_stone_type', 'grade', 'surface_finish', 'dimensions', 'dimensional_tolerance', 'quantity', 'unit',
      'colour_reference', 'special_requirements', 'unit_price_inr', 'basic_value_inr', 'gst_rate_pct', 'gst_amount_inr',
      'total_payable_inr', 'advance_pct', 'advance_amount_inr', 'balance_amount_inr', 'delivery_location',
      'required_delivery_date', 'packing_requirement'];
    return { ...Object.fromEntries(keys.map((k) => [k, null])), ...overrides };
  }
  async function orderAtStage5() {
    const orderId = await createTestOrder(await createTestClient());
    for (let stage = 1; stage <= 4; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    return orderId;
  }

  it('creates a supplier with several types and contact details', async () => {
    const name = uniqueName('Directory Quarry');
    await supplierController.create(fakeReq({
      supplier_legal_name: name,
      types: ['Quarry Owner', 'Factory Owner'],
      city: 'Udaipur',
      email: 'quarry@example.com',
      whatsapp_number: '+91 90000 00001',
      contact_person_phone: '+91 90000 00002',
    }), fakeRes());

    const id = await supplierIdByName(name);
    expect(id).not.toBeNull();
    const supplier = await supplierRepository.find(id);
    expect(supplier.types).toEqual(['Factory Owner', 'Quarry Owner']);
    expect(supplier.city).toBe('Udaipur');
    expect(supplier.email).toBe('quarry@example.com');
    expect(Number(supplier.created_by)).toBe(userId);
    const audit = await db.queryOne("SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'SUPPLIER_CREATED' AND entity_id = :id", { id });
    expect(Number(audit.c)).toBe(1);
  });

  it('requires at least one valid type', async () => {
    const name = uniqueName('No Type Supplier');
    let req = fakeReq({ supplier_legal_name: name, types: [] });
    await supplierController.create(req, fakeRes());
    expect(await supplierIdByName(name)).toBeNull();
    expect(lastFlash(req)).toContain('at least one supplier type');

    req = fakeReq({ supplier_legal_name: name, types: ['Made Up Type'] });
    await supplierController.create(req, fakeRes());
    expect(await supplierIdByName(name)).toBeNull();
    expect(lastFlash(req)).toContain('not a valid supplier type');
  });

  it('lists by type and searches by name', async () => {
    const trader = uniqueName('Filter Trader');
    const quarry = uniqueName('Filter Quarry');
    await supplierRepository.create({ supplier_legal_name: trader, types: ['Trader'] });
    await supplierRepository.create({ supplier_legal_name: quarry, types: ['Quarry Owner'] });

    let names = (await supplierRepository.all(true, 'Trader')).map((s) => s.supplier_legal_name);
    expect(names).toContain(trader);
    expect(names).not.toContain(quarry);

    names = (await supplierRepository.all(true, null, quarry)).map((s) => s.supplier_legal_name);
    expect(names).toEqual([quarry]);
  });

  it('editing or deactivating a supplier never changes an order\'s Supplier PO', async () => {
    const id = await supplierRepository.create({
      supplier_legal_name: 'Snapshot Stone Co',
      address: 'Old Address', email: 'old@example.com', phone: '111',
      types: ['Quarry Owner', 'Trader'],
    });
    const orderId = await createTestOrder(await createTestClient());
    await orderSupplierPoRepository.create(orderId, id, 'SUPPO-DIR-1', poData({ supplier_type: 'Trader' }));

    await supplierController.update(fakeReq({ supplier_legal_name: 'Renamed Stone Co', address: 'New Address', email: 'new@example.com', phone: '222', types: ['Factory Owner'] }, { id: String(id) }), fakeRes());
    await supplierController.toggleActive(fakeReq({}, { id: String(id) }), fakeRes());

    expect((await supplierRepository.find(id)).supplier_legal_name).toBe('Renamed Stone Co');
    const po = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(po.supplier_legal_name).toBe('Snapshot Stone Co');
    expect(po.supplier_address).toBe('Old Address');
    expect(po.supplier_email).toBe('old@example.com');
    expect(po.supplier_phone).toBe('111');
    expect(po.supplier_type).toBe('Trader');
  });

  it('drops a deactivated supplier out of the Supplier PO list', async () => {
    const id = await supplierRepository.create({ supplier_legal_name: uniqueName('Toggle Supplier'), types: ['Trader'] });
    await supplierController.toggleActive(fakeReq({}, { id: String(id) }), fakeRes());

    expect((await supplierRepository.all()).map((s) => s.id)).not.toContain(id);
    expect((await supplierRepository.all(true)).map((s) => s.id)).toContain(id);
  });

  it('deletes an unused supplier with its factory addresses but refuses a used one', async () => {
    const unused = await supplierRepository.create({ supplier_legal_name: uniqueName('Unused Supplier'), types: ['Trader'] });
    await supplierFactoryAddressRepository.create(unused, 'Plot 9, Test Area', 'Main');
    await supplierController.remove(fakeReq({}, { id: String(unused) }), fakeRes());
    expect(await supplierRepository.find(unused)).toBeNull();
    expect(await supplierFactoryAddressRepository.forSupplier(unused)).toEqual([]);

    const used = await supplierRepository.create({ supplier_legal_name: uniqueName('Used Supplier'), types: ['Trader'] });
    await orderSupplierPoRepository.create(await createTestOrder(await createTestClient()), used, 'SUPPO-DIR-2', poData());
    const req = fakeReq({}, { id: String(used) });
    await supplierController.remove(req, fakeRes());
    expect(await supplierRepository.find(used)).not.toBeNull();
    expect(lastFlash(req)).toContain('deactivate it instead');
  });

  it('asks which type applies when the supplier has several', async () => {
    const orderId = await orderAtStage5();
    const id = await supplierRepository.create({ supplier_legal_name: uniqueName('Two Type Supplier'), types: ['Quarry Owner', 'Factory Owner'] });

    let req = { ...fakeReq({ supplier_id: String(id) }, { id: String(orderId) }) };
    await ordersController.saveSupplierPo(req, fakeRes());
    expect(await orderSupplierPoRepository.findLatestForOrder(orderId)).toBeNull();
    expect(lastFlash(req)).toContain('supplier types applies to this order');

    req = fakeReq({ supplier_id: String(id), supplier_type: 'Factory Owner' }, { id: String(orderId) });
    await ordersController.saveSupplierPo(req, fakeRes());
    expect((await orderSupplierPoRepository.findLatestForOrder(orderId)).supplier_type).toBe('Factory Owner');
  });

  // Owner decision (2026-10-27): 0% advance is saved as 0 (not blank), so the whole amount is the balance.
  it('saves a 0% advance on the Supplier PO', async () => {
    const orderId = await orderAtStage5();
    const id = await supplierRepository.create({ supplier_legal_name: uniqueName('No Advance Supplier'), types: ['Trader'] });
    await ordersController.saveSupplierPo(fakeReq({ supplier_id: String(id), total_payable_inr: '53100.00', advance_pct: '0', advance_amount_inr: '0.00', balance_amount_inr: '53100.00' }, { id: String(orderId) }), fakeRes());

    const po = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(po.advance_pct).not.toBeNull();
    expect(Number(po.advance_pct)).toBe(0);
    expect(Number(po.advance_amount_inr)).toBe(0);
    expect(Number(po.balance_amount_inr)).toBe(53100);
  });

  it('picks a single type automatically and refuses an inactive supplier', async () => {
    const orderId = await orderAtStage5();
    const id = await supplierRepository.create({ supplier_legal_name: uniqueName('One Type Supplier'), types: ['Trader'] });
    await ordersController.saveSupplierPo(fakeReq({ supplier_id: String(id), supplier_type: 'Quarry Owner' }, { id: String(orderId) }), fakeRes());
    expect((await orderSupplierPoRepository.findLatestForOrder(orderId)).supplier_type).toBe('Trader');

    const otherOrder = await orderAtStage5();
    await supplierRepository.toggleActive(id);
    const req = fakeReq({ supplier_id: String(id) }, { id: String(otherOrder) });
    await ordersController.saveSupplierPo(req, fakeRes());
    expect(await orderSupplierPoRepository.findLatestForOrder(otherOrder)).toBeNull();
    expect(lastFlash(req)).toContain('active supplier');
  });

  it('gives manage_suppliers to the Admin tier only', async () => {
    const expected = { Admin: true, 'Managing Director': true, 'Executive Director': true, 'Export Executive': false, 'Logistics Executive': false };
    for (const [roleName, allowed] of Object.entries(expected)) {
      const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
      const permissions = await permissionService.load(await createTestUser(roleName), role.id);
      expect([roleName, Boolean(permissions.manage_suppliers)]).toEqual([roleName, allowed]);
    }
  });

  it('order page offers the directory instead of an inline Add Supplier form', async () => {
    const orderId = await orderAtStage5();
    await supplierRepository.create({ supplier_legal_name: uniqueName('Page Supplier'), types: ['Quarry Owner', 'Trader'] });

    const { text } = await agent.get(`/orders/${orderId}`);

    expect(text).not.toContain(`action="/orders/${orderId}/suppliers"`);
    expect(text).toContain('href="/suppliers"');
    expect(text).toContain('id="spo-supplier-type-select"');
    expect(text).toContain('data-types="[&quot;Quarry Owner&quot;,&quot;Trader&quot;]"');
  });

  it('serves the directory pages to an Admin', async () => {
    const id = await supplierRepository.create({ supplier_legal_name: uniqueName('Served Supplier'), types: ['Trader'] });
    const list = await agent.get('/suppliers?type=Trader');
    expect(list.status).toBe(200);
    expect(list.text).toContain('Served Supplier');
    const edit = await agent.get(`/suppliers/${id}/edit`);
    expect(edit.status).toBe(200);
    expect(edit.text).toContain('value="Trader" checked');
  });
});
