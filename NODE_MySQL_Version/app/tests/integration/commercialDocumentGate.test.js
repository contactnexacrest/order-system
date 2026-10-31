'use strict';

const db = require('../../src/config/db');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderSupplierPoRepository = require('../../src/repositories/orderSupplierPoRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const stageGateService = require('../../src/services/stageGateService');
const ordersController = require('../../src/controllers/ordersController');
const flash = require('../../src/helpers/flash');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of CommercialDocumentGateTest.php. 90-item backlog #52/#53: neither
 * confirmSupplierSigned (Stage 5->6, "the supplier signed and returned
 * their PO") nor recordBalancePayment (Stage 8, "the buyer remitted the
 * balance against the Commercial Invoice") ever checked that the
 * commercial document they're implicitly about — the SUPPO / CI PDF
 * actually generated and handed to the counterparty — existed at all.
 * Fixed with the same "generated" bar the OC check already uses
 * (documentRepository.findLatestForOrderAndTypeCode()).
 *
 * Investigation also found recordBalancePayment had no Stage 8 gate at
 * all (unlike its neighbour clearBalancePayment, which does check) —
 * fixed alongside the CI-document check since both are the same action.
 */
describe('Commercial document gate — SUPPO/CI must exist before confirming (90-item backlog #52/#53)', () => {
  async function createTestDocument(orderId, typeCode, status = 'approved') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-COMMGATE-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  async function createTestSupplierPo(orderId) {
    const supplierResult = await db.execute(
      "INSERT INTO suppliers (supplier_legal_name) VALUES ('Jest CommGate Test Supplier Co')"
    );
    const poResult = await db.execute(
      `INSERT INTO order_supplier_po (order_id, supplier_id, supplier_po_reference)
       VALUES (:order_id, :supplier_id, 'COMMGATE-SPO-JEST')`,
      { order_id: orderId, supplier_id: supplierResult.insertId }
    );
    return poResult.insertId;
  }

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest CommGate Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-commgate-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  // External QA report L-23: passAndUnlockNext()/maybeAutoSkipFreightStage()
  // now audit-log against users(id) via a FOREIGN KEY — the literal
  // placeholder `0` these helpers used (harmless before that fix, since
  // nothing read userId) now violates the constraint. A real test user.
  async function unlockThroughStage5(orderId) {
    const userId = await createTestUser();
    for (let stage = 1; stage <= 4; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, userId);
    }
  }

  async function unlockThroughStage8(orderId) {
    const userId = await createTestUser();
    for (let stage = 1; stage <= 5; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, userId);
    }
    await stageGateService.maybeAutoSkipFreightStage(orderId, userId);
    await stageGateService.passAndUnlockNext(orderId, 7, userId);
  }

  function fakeReq(params, body = {}, user = null) {
    return { params, body, session: {}, user };
  }

  function fakeRes() {
    const res = { statusCode: 200, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.send = (body) => { res.body = body; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  beforeAll(async () => {
    // Wet-signature guard is a separate concern (item #106); turn it off
    // so these tests isolate the SUPPO-document-generated check.
    await companySettingsRepository.set('wet_signature_required_supplier_po', '0');
  });

  afterAll(async () => {
    await companySettingsRepository.set('wet_signature_required_supplier_po', '1');
    await db.pool.end();
  });

  // ---------------------------------------------------------------
  // #52 — confirmSupplierSigned
  // ---------------------------------------------------------------

  it('confirmSupplierSigned refuses without a generated SUPPO document', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage5(orderId);
    await createTestSupplierPo(orderId);
    const req = fakeReq({ id: String(orderId) }, {}, { id: 1 });

    await ordersController.confirmSupplierSigned(req, fakeRes());

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    expect(flashMessages[0].message).toContain('Generate the Supplier PO document');
    const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(supplierPo.status).not.toBe('signed');
    expect(await stageGateService.isUnlocked(orderId, 6)).toBe(false);
  });

  it('confirmSupplierSigned succeeds once the SUPPO document exists', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage5(orderId);
    await createTestSupplierPo(orderId);
    await createTestDocument(orderId, 'SUPPO');
    const req = fakeReq({ id: String(orderId) }, {}, { id: 1 });

    await ordersController.confirmSupplierSigned(req, fakeRes());

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('success');
    const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
    expect(supplierPo.status).toBe('signed');
  });

  // ---------------------------------------------------------------
  // #53 — recordBalancePayment
  // ---------------------------------------------------------------

  it('recordBalancePayment refuses while Stage 8 is locked', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const req = fakeReq({ id: String(orderId) }, { balance_amount: '1000' });

    await ordersController.recordBalancePayment(req, fakeRes());

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    const payment = await orderPaymentStatusRepository.find(orderId);
    expect(payment.balance_remittance_received_at).toBeNull();
  });

  it('recordBalancePayment refuses without a generated CI document', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage8(orderId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const req = fakeReq({ id: String(orderId) }, { balance_amount: '1000' });

    await ordersController.recordBalancePayment(req, fakeRes());

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('error');
    expect(flashMessages[0].message).toContain('Generate the Commercial Invoice');
    const payment = await orderPaymentStatusRepository.find(orderId);
    expect(payment.balance_remittance_received_at).toBeNull();
  });

  it('recordBalancePayment succeeds once the CI document exists', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockThroughStage8(orderId);
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    await createTestDocument(orderId, 'CI');
    const req = fakeReq({ id: String(orderId) }, { balance_amount: '1000', balance_received_at: '2026-05-01', balance_exchange_rate: '84', balance_bank_reference: 'UTR-TEST-0001' });

    await ordersController.recordBalancePayment(req, fakeRes());

    const flashMessages = flash.pull(req);
    expect(flashMessages[0].type).toBe('success');
    const payment = await orderPaymentStatusRepository.find(orderId);
    expect(payment.balance_remittance_received_at).not.toBeNull();
    expect(parseFloat(payment.balance_amount)).toBe(1000);
  });
});
