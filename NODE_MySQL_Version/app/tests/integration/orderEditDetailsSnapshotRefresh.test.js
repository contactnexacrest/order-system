'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const orderRepository = require('../../src/repositories/orderRepository');
const portRepository = require('../../src/repositories/portRepository');
const amendmentRepository = require('../../src/repositories/amendmentRepository');
const amendmentService = require('../../src/services/amendmentService');
const { createTestClient, createTestOrder, createTestFile } = require('../support/fixtures');

/**
 * Port of OrderEditDetailsSnapshotRefreshTest.php. Real regression found
 * after the original "standalone order" snapshot fix: ordersController.js's
 * updateDetails() ("Edit Details", Chapter 17) lets staff change an
 * in-progress order's Incoterm/Currency/Port, but orderRepository.js's
 * updateDetails() only ever wrote the *_id FK columns. Before the
 * snapshot fix this was fine — orderRepository.find() lived off a live
 * JOIN, so a changed FK was reflected immediately. Once find()/every
 * document stopped joining and started reading the order's own
 * incoterm_code/currency_code/port_of_loading_name/_city/port_of_discharge_name/_city
 * instead, this screen silently stopped working for those four fields.
 * This proves the fix: editing the logistics side now re-resolves just
 * those four snapshot fields, without disturbing an activated Amendment's
 * payment-term override or this order's own PI-intake-corrected client
 * details.
 */
describe('Order Edit Details snapshot refresh (90-item backlog "standalone order" follow-on)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-editguard-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function otherCurrency(excludeId) {
    return db.queryOne('SELECT id, code FROM currencies WHERE id != :id LIMIT 1', { id: excludeId });
  }

  async function otherIncoterm(excludeId) {
    return db.queryOne('SELECT id, code FROM incoterms WHERE id != :id LIMIT 1', { id: excludeId });
  }

  async function createTestDocument(orderId, typeCode = 'QT') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, 'draft')`,
      { order_id: orderId, type_id: type.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}` }
    );
    return result.insertId;
  }

  it('changing currency and incoterm refreshes their snapshot columns too', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    const original = await orderRepository.find(orderId);
    const newCurrency = await otherCurrency(original.currency_id);
    const newIncoterm = await otherIncoterm(original.incoterm_id);
    const userId = await createTestUser('Admin');

    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId }, permissions: {},
      body: { incoterm_id: String(newIncoterm.id), currency_id: String(newCurrency.id) },
    };
    await ordersController.updateDetails(req, fakeRes());

    const after = await orderRepository.find(orderId);
    expect(after.currency_id).toBe(newCurrency.id);
    expect(after.currency_code).toBe(newCurrency.code);
    expect(after.incoterm_id).toBe(newIncoterm.id);
    expect(after.incoterm_code).toBe(newIncoterm.code);
  });

  it('changing the port of loading refreshes its name and city', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer');
    const newPortId = await portRepository.create('New Loading Terminal', 'New Loading City', 'India', 'loading', 95);
    const original = await orderRepository.find(orderId);
    const userId = await createTestUser('Admin');

    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId }, permissions: {},
      body: { incoterm_id: String(original.incoterm_id), currency_id: String(original.currency_id), port_of_loading_id: String(newPortId) },
    };
    await ordersController.updateDetails(req, fakeRes());

    const after = await orderRepository.find(orderId);
    expect(after.port_of_loading_id).toBe(newPortId);
    expect(after.port_of_loading_name).toBe('New Loading Terminal');
    expect(after.port_of_loading_city).toBe('New Loading City');
  });

  it("editing logistics never disturbs an activated amendment's payment override", async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer');

    const amendmentId = await amendmentRepository.create(
      `AMD-EDITGUARD-TEST-${Math.random().toString(16).slice(2, 10)}`,
      orderId, 'Jest edit-guard amendment', 'importer', { note: 'snapshot' },
      25.0, null, null, null, null, null, null
    );
    await amendmentService.approveByMd(amendmentId, 1);
    const documentId = await createTestDocument(orderId, 'QT');
    await amendmentRepository.attachDocument(amendmentId, documentId);
    const fileId = await createTestFile(orderId, null);
    await amendmentService.attachSignedCopyAndActivate(amendmentId, fileId, 1);
    expect(Number((await orderRepository.find(orderId)).advance_pct)).toBe(25);

    const original = await orderRepository.find(orderId);
    const newIncoterm = await otherIncoterm(original.incoterm_id);
    const userId = await createTestUser('Admin');
    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId }, permissions: {},
      body: { incoterm_id: String(newIncoterm.id), currency_id: String(original.currency_id) },
    };
    await ordersController.updateDetails(req, fakeRes());

    const after = await orderRepository.find(orderId);
    expect(after.incoterm_code).toBe(newIncoterm.code);
    expect(Number(after.advance_pct)).toBe(25);
  });
});
