'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const clientPaymentReportRepository = require('../../src/repositories/clientPaymentReportRepository');
const supplierRepository = require('../../src/repositories/supplierRepository');
const supplierFactoryAddressRepository = require('../../src/repositories/supplierFactoryAddressRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of N08AuditLogCoverageClusterDTest.php. External QA report N-08,
 * Cluster D: 18 order financial/shipping/BL actions never called
 * auditLogRepository.log(), so none of them ever notified anyone either.
 * Unlike the PHP mirror, uploadBuyerPoDocument/uploadSupplierPoDocument/
 * attachBlCopy ARE covered here — multer's in-memory req.file has none of
 * PHP's move_uploaded_file() real-HTTP-upload restriction.
 */
describe('Audit log coverage, N-08 Cluster D', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest N08D Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-n08d-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function lastAuditLogAction() {
    return db.queryOne('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
  }

  async function unlockThrough(orderId, throughStage) {
    for (let stage = 1; stage <= throughStage; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
  }

  function fullSupplierPoData(overrides = {}) {
    return {
      material_stone_type: null, grade: null, surface_finish: null,
      dimensions: null, dimensional_tolerance: null, quantity: null,
      unit: null, colour_reference: null, special_requirements: null,
      unit_price_inr: null, basic_value_inr: null, gst_rate_pct: null,
      gst_amount_inr: null, total_payable_inr: null, advance_pct: null,
      advance_amount_inr: null, balance_amount_inr: null,
      delivery_location: null, required_delivery_date: null, packing_requirement: null,
      ...overrides,
    };
  }

  async function createTestSupplierPo(orderId) {
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'N08 Test Supplier' });
    return orderSupplierPoRepository.create(orderId, supplierId, 'SUPPO-N08-1', fullSupplierPoData());
  }

  function fakeReq(orderId, userId, body = {}, extraParams = {}, file = undefined) {
    return { params: { id: String(orderId), ...extraParams }, body, user: { id: userId }, file, session: {} };
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.status = () => res;
    res.send = () => {};
    return res;
  }

  it('uploadBuyerPoDocument is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    const file = { buffer: Buffer.from('%PDF-1.4 fake'), originalname: 'buyer-po.pdf', mimetype: 'application/pdf' };

    await ordersController.uploadBuyerPoDocument(fakeReq(orderId, userId, {}, {}, file), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_BUYER_PO_DOCUMENT_UPLOADED');
  });

  it('markPaymentReportReviewed is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const reportId = await clientPaymentReportRepository.create(orderId, 'advance', 'REF123', null, 1000.0, '2026-10-20', null);
    const userId = await createTestUser();

    await ordersController.markPaymentReportReviewed(fakeReq(orderId, userId, {}, { reportId: String(reportId) }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('CLIENT_PAYMENT_REPORT_REVIEWED');
  });

  it('createSupplier is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await ordersController.createSupplier(fakeReq(orderId, userId, { supplier_legal_name: 'N08 New Supplier' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('SUPPLIER_CREATED');
  });

  it('createSupplierFactoryAddress is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'N08 Test Supplier' });
    const userId = await createTestUser();

    await ordersController.createSupplierFactoryAddress(fakeReq(orderId, userId, { address: '123 Factory Road' }, { supplierId: String(supplierId) }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('SUPPLIER_FACTORY_ADDRESS_CREATED');
  });

  it('deleteSupplierFactoryAddress is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const supplierId = await supplierRepository.create({ supplier_legal_name: 'N08 Test Supplier' });
    const addressId = await supplierFactoryAddressRepository.create(supplierId, '123 Factory Road', null);
    const userId = await createTestUser();

    await ordersController.deleteSupplierFactoryAddress(fakeReq(orderId, userId, {}, { addressId: String(addressId) }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('SUPPLIER_FACTORY_ADDRESS_DELETED');
  });

  it('markSupplierAdvancePaid is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestSupplierPo(orderId);
    const userId = await createTestUser();

    await ordersController.markSupplierAdvancePaid(fakeReq(orderId, userId), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('SUPPLIER_ADVANCE_PAID');
  });

  it('markSupplierBalancePaid is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestSupplierPo(orderId);
    const userId = await createTestUser();

    await ordersController.markSupplierBalancePaid(fakeReq(orderId, userId), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('SUPPLIER_BALANCE_PAID');
  });

  it('uploadSupplierPoDocument is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await createTestSupplierPo(orderId);
    const userId = await createTestUser();
    const file = { buffer: Buffer.from('%PDF-1.4 fake'), originalname: 'supplier-po-ack.pdf', mimetype: 'application/pdf' };

    await ordersController.uploadSupplierPoDocument(fakeReq(orderId, userId, {}, {}, file), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('SUPPLIER_PO_DOCUMENT_UPLOADED');
  });

  it('saveFreightTerms is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await ordersController.saveFreightTerms(fakeReq(orderId, userId, { confirmed_freight_rate: '500' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_FREIGHT_TERMS_SAVED');
  });

  it('recordFreightPayment is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 5);
    const userId = await createTestUser();

    await ordersController.recordFreightPayment(fakeReq(orderId, userId, { freight_amount: '750' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('FREIGHT_PAYMENT_RECORDED');
  });

  it('savePacking is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 6);
    const userId = await createTestUser();

    await ordersController.savePacking(fakeReq(orderId, userId, { crate_count: '5' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_PACKING_SAVED');
  });

  it('saveShipping is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThrough(orderId, 6);
    const userId = await createTestUser();

    await ordersController.saveShipping(fakeReq(orderId, userId, { shipping_line: 'Maersk' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_SHIPPING_DETAILS_SAVED');
  });

  it('attachBlCopy is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    const file = { buffer: Buffer.from('%PDF-1.4 fake'), originalname: 'bl-copy.pdf', mimetype: 'application/pdf' };

    await ordersController.attachBlCopy(fakeReq(orderId, userId, {}, {}, file), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_BL_COPY_ATTACHED');
  });

  it('recordScannedBlSent is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await ordersController.recordScannedBlSent(fakeReq(orderId, userId), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_SCANNED_BL_SENT');
  });

  it('recordBlOriginalsReceived is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await ordersController.recordBlOriginalsReceived(fakeReq(orderId, userId, { bl_originals_count: '3' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_BL_ORIGINALS_RECEIVED');
  });

  it('recordBlEndorsed is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await ordersController.recordBlEndorsed(fakeReq(orderId, userId), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_BL_ENDORSED');
  });

  it('saveBlEndorsement is logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();

    await ordersController.saveBlEndorsement(fakeReq(orderId, userId, { bl_number: 'MSCU1234567' }), fakeRes());

    expect((await lastAuditLogAction()).action_type).toBe('ORDER_BL_ENDORSEMENT_SAVED');
  });

  // generateBlEndorsement is intentionally not covered here: it renders a
  // real PDF via the same headless-browser pipeline documentGeneration
  // tests elsewhere use, and that pipeline's cold start is unreliable
  // under this specific jest run (confirmed working standalone — a
  // direct node script calling this exact controller method with this
  // exact fixture logged ORDER_BL_ENDORSEMENT_GENERATED correctly, just
  // slower than is practical inside this suite). Covered instead by
  // N08AuditLogCoverageClusterDTest.php's own
  // testGenerateBlEndorsementIsLogged(), which passes reliably.
});
