'use strict';

const db = require('../../src/config/db');
const clientsController = require('../../src/controllers/clientsController');
const ordersController = require('../../src/controllers/ordersController');
const auditLogRepository = require('../../src/repositories/auditLogRepository');
const companySettingsRepository = require('../../src/repositories/companySettingsRepository');
const orderPaymentStatusRepository = require('../../src/repositories/orderPaymentStatusRepository');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of AuditLogCoverageTest.php. External QA report L-23: a full order
 * journey used to log only 5 kinds of entry (document approvals, portal
 * provisioned, OC acknowledged) — client creation, order creation,
 * advance/balance recorded and cleared, every stage gate, Supplier PO
 * saved/signed, and BL issued were all silently untraced (and, since
 * every auditLogRepository.log() call also fires an action-notification
 * email, also silently un-notified).
 */
describe('Audit log coverage (External QA report L-23)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function createTestUser(roleName = 'Admin') {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest AuditCov Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
      { email: `jest-auditcov-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
    );
    return result.insertId;
  }

  async function createTestDocument(orderId, typeCode, status = 'approved') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-AUDITCOV-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  async function createTestSupplier() {
    const result = await db.execute("INSERT INTO suppliers (supplier_legal_name) VALUES ('L-23 Audit Test Supplier Co')");
    return result.insertId;
  }

  async function latestLogFor(actionType) {
    return db.queryOne('SELECT * FROM audit_log WHERE action_type = :action_type ORDER BY id DESC LIMIT 1', { action_type: actionType });
  }

  function fakeRes() {
    const res = {};
    res.redirect = () => {};
    res.renderView = () => {};
    return res;
  }

  function fakeReq(user, body, params = {}) {
    return { user, body, params, session: {} };
  }

  it('client creation is audit-logged', async () => {
    const userId = await createTestUser();
    const req = fakeReq({ id: userId }, { company_legal_name: 'L-23 Audit Test Buyer Ltd', billing_address: '1 Test Street' });

    await clientsController.store(req, fakeRes());

    const log = await latestLogFor('CLIENT_CREATED');
    expect(log).not.toBeNull();
    expect(Number(log.user_id)).toBe(userId);
    expect(log.new_value).toBe('L-23 Audit Test Buyer Ltd');
  });

  it('order creation is audit-logged', async () => {
    const userId = await createTestUser('Export Executive');
    const clientId = await createTestClient();
    const incoterm = await db.queryOne("SELECT id FROM incoterms WHERE code = 'FOB'");
    const currency = await db.queryOne("SELECT id FROM currencies WHERE code = 'USD'");
    const preset = await db.queryOne("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'");
    const req = fakeReq({ id: userId }, {
      client_id: String(clientId),
      incoterm_id: String(incoterm.id),
      currency_id: String(currency.id),
      payment_preset_id: String(preset.id),
      product_description: ['L-23 audit test granite slab'],
      product_hs_code: ['680293'],
      product_quantity: ['10'],
      product_unit_price: ['5.00'],
    });

    await ordersController.store(req, fakeRes());

    const orderRow = await db.queryOne('SELECT MAX(id) AS id FROM orders');
    const log = await latestLogFor('ORDER_CREATED');
    expect(log).not.toBeNull();
    expect(Number(log.user_id)).toBe(userId);
    expect(log.entity_type).toBe('orders');
    expect(Number(log.entity_id)).toBe(Number(orderRow.id));
  });

  it('every stage gate pass is audit-logged', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'CIF'); // CIF so Stage 6 is never auto-skipped
    const userId = await createTestUser();

    for (let stage = 1; stage <= 9; stage++) {
      expect(await stageGateService.passAndUnlockNext(orderId, stage, userId)).toBe(true);
    }

    const rows = await db.query(
      "SELECT new_value FROM audit_log WHERE action_type = 'ORDER_STAGE_GATE_PASSED' AND entity_type = 'orders' AND entity_id = :order_id ORDER BY id ASC",
      { order_id: orderId }
    );
    expect(rows.map((r) => r.new_value)).toEqual(['1', '2', '3', '4', '5', '6', '7', '8', '9']);
  });

  it('the FOB freight-stage auto-skip is audit-logged', async () => {
    const orderId = await createTestOrder(await createTestClient(), 'FOB');
    const userId = await createTestUser();
    for (let stage = 1; stage <= 5; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }

    await stageGateService.maybeAutoSkipFreightStage(orderId, userId);

    const log = await latestLogFor('ORDER_STAGE_GATE_SKIPPED');
    expect(log).not.toBeNull();
    expect(Number(log.entity_id)).toBe(orderId);
    expect(log.new_value).toBe('6');
  });

  it('advance payment recorded and cleared are both audit-logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const userId = await createTestUser('Accounts Executive');
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);

    await ordersController.recordAdvancePayment(
      fakeReq({ id: userId }, { advance_amount: '1000.00', advance_received_at: '2026-01-10', advance_exchange_rate: '83.5' }, { id: String(orderId) }),
      fakeRes()
    );
    const recordedLog = await latestLogFor('ADVANCE_PAYMENT_RECORDED');
    expect(recordedLog).not.toBeNull();
    expect(Number(recordedLog.entity_id)).toBe(orderId);
    expect(recordedLog.new_value).toBe('1000');

    await createTestDocument(orderId, 'PI', 'sent');
    await ordersController.clearAdvancePayment(
      fakeReq({ id: userId }, { advance_cleared_at: '2026-01-15' }, { id: String(orderId) }),
      fakeRes()
    );
    const clearedLog = await latestLogFor('ADVANCE_PAYMENT_CLEARED');
    expect(clearedLog).not.toBeNull();
    expect(clearedLog.new_value).toBe('2026-01-15');
  });

  it('balance payment recorded and cleared are both audit-logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await orderPaymentStatusRepository.initializeForOrder(orderId);
    const userId = await createTestUser('Accounts Executive');
    for (let stage = 1; stage <= 7; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    await createTestDocument(orderId, 'CI', 'sent');

    await ordersController.recordBalancePayment(
      fakeReq({ id: userId }, { balance_amount: '1500.00', balance_received_at: '2026-03-01', balance_exchange_rate: '84' }, { id: String(orderId) }),
      fakeRes()
    );
    const recordedLog = await latestLogFor('BALANCE_PAYMENT_RECORDED');
    expect(recordedLog).not.toBeNull();
    expect(recordedLog.new_value).toBe('1500');

    await ordersController.clearBalancePayment(
      fakeReq({ id: userId }, { balance_cleared_at: '2026-03-05' }, { id: String(orderId) }),
      fakeRes()
    );
    const clearedLog = await latestLogFor('BALANCE_PAYMENT_CLEARED');
    expect(clearedLog).not.toBeNull();
    expect(clearedLog.new_value).toBe('2026-03-05');
  });

  it('supplier PO saved and signed are both audit-logged', async () => {
    await companySettingsRepository.set('wet_signature_required_supplier_po', '0');
    try {
      const orderId = await createTestOrder(await createTestClient());
      const userId = await createTestUser();
      for (let stage = 1; stage <= 4; stage++) {
        await stageGateService.passAndUnlockNext(orderId, stage, null);
      }
      const supplierId = await createTestSupplier();

      await ordersController.saveSupplierPo(
        fakeReq({ id: userId }, { supplier_id: String(supplierId) }, { id: String(orderId) }),
        fakeRes()
      );
      const savedLog = await latestLogFor('SUPPLIER_PO_SAVED');
      expect(savedLog).not.toBeNull();
      expect(Number(savedLog.entity_id)).toBe(orderId);

      await createTestDocument(orderId, 'SUPPO', 'approved');
      await ordersController.confirmSupplierSigned(fakeReq({ id: userId }, {}, { id: String(orderId) }), fakeRes());

      const signedLog = await latestLogFor('SUPPLIER_PO_SIGNED');
      expect(signedLog).not.toBeNull();
      expect(signedLog.entity_type).toBe('order_supplier_po');
    } finally {
      await companySettingsRepository.set('wet_signature_required_supplier_po', '1');
    }
  });

  it('BL issuance is audit-logged', async () => {
    const orderId = await createTestOrder(await createTestClient());
    const userId = await createTestUser();
    for (let stage = 1; stage <= 6; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
    await createTestDocument(orderId, 'PL', 'sent');
    await createTestDocument(orderId, 'BLI', 'sent');

    await ordersController.recordBlIssued(
      fakeReq({ id: userId }, { bl_number: 'L23MSCU0001', bl_date: '2026-06-15' }, { id: String(orderId) }),
      fakeRes()
    );

    const log = await latestLogFor('BL_ISSUED');
    expect(log).not.toBeNull();
    expect(Number(log.entity_id)).toBe(orderId);
    expect(log.new_value).toBe('L23MSCU0001');
  });

  /**
   * forOrder() is the order page's own audit trail — writing a log row
   * against entity_type 'order_payment_status'/'order_supplier_po' is not
   * enough on its own if the one place staff actually read the trail from
   * never looked at those entity types (true even for the pre-existing
   * CA_INR_ACTUAL_* entries before this fix).
   */
  it('forOrder surfaces payment-status and supplier-PO entries', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await auditLogRepository.log(null, 'ADVANCE_PAYMENT_RECORDED', 'order_payment_status', orderId, 'advance_amount', null, '999');

    const supplierId = await createTestSupplier();
    const poResult = await db.execute(
      "INSERT INTO order_supplier_po (order_id, supplier_id, supplier_po_reference) VALUES (:order_id, :supplier_id, 'FOR-ORDER-TEST-SPO')",
      { order_id: orderId, supplier_id: supplierId }
    );
    await auditLogRepository.log(null, 'SUPPLIER_PO_SIGNED', 'order_supplier_po', poResult.insertId, 'supplier_po_reference', null, 'FOR-ORDER-TEST-SPO');

    const rows = await auditLogRepository.forOrder(orderId);
    const actionTypes = rows.map((r) => r.action_type);

    expect(actionTypes).toContain('ADVANCE_PAYMENT_RECORDED');
    expect(actionTypes).toContain('SUPPLIER_PO_SIGNED');
  });
});
