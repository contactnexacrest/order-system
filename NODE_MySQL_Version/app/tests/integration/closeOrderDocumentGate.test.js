'use strict';

const db = require('../../src/config/db');
const ordersController = require('../../src/controllers/ordersController');
const orderRepository = require('../../src/repositories/orderRepository');
const orderShippingRepository = require('../../src/repositories/orderShippingRepository');
const documentGenerationService = require('../../src/services/documentGenerationService');
const stageGateService = require('../../src/services/stageGateService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

async function createTestUser(roleName = 'Admin') {
  const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: roleName });
  const result = await db.execute(
    `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
     VALUES ('Jest Test User', :email, NULL, 'x', :role_id, 1, 0, 0)`,
    { email: `jest-cog-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`, role_id: role.id }
  );
  return result.insertId;
}

async function createTestDocument(orderId, typeCode, status) {
  const docType = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
  const result = await db.execute(
    `INSERT INTO documents (order_id, document_type_id, document_reference, status)
     VALUES (:order_id, :dt, :ref, :status)`,
    { order_id: orderId, dt: docType.id, ref: `JEST-DOC-${Math.random().toString(16).slice(2, 10)}`, status }
  );
  return result.insertId;
}

/**
 * Port of CloseOrderDocumentGateTest.php. 90-item backlog Phase 6 Item 3
 * — SUPERSEDES the old closeOrderDocumentHint.test.js (and the
 * closeOrderBlockedBy helper it tested, now removed). That file locked
 * in a deliberate decision that Close Order must never be blocked on
 * BLI/COO Prep Sheet state. The business owner has now explicitly
 * reversed that decision: Stage 9 only unlocks after the Stage 8
 * balance payment clears (itself downstream of the freight/BL stages),
 * so by construction any order reaching Close Order has already
 * shipped — BLI + COOPREP are now a real, server-side hard requirement,
 * with a narrow edit_locked_data + reason override escape hatch.
 * Deliberately NOT exempted for a disputed order — disputes never touch
 * order status/stage progression. ordersController.markLost() gets a
 * companion guard: an order with a real Bill of Lading already on file
 * can no longer be marked "lost".
 */
describe('Close order document gate (90-item backlog Phase 6 Item 3)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { statusCode: null, redirectedTo: null };
    res.status = (code) => { res.statusCode = code; return res; };
    res.redirect = (url) => { res.redirectedTo = url; };
    return res;
  }

  async function unlockThrough(orderId, throughStage) {
    for (let stage = 1; stage <= throughStage; stage++) {
      await stageGateService.passAndUnlockNext(orderId, stage, null);
    }
  }

  async function prepareShippedOrderAtStage9(orderId) {
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk', vessel_name: 'MSC MAYA', etd: '2026-06-01', eta: '2026-06-20' });
    await orderShippingRepository.recordBl(orderId, 'MSCU1234567', '2026-06-15');
    await unlockThrough(orderId, 8);
    expect(await stageGateService.isUnlocked(orderId, 9)).toBe(true);
  }

  it('closureBlockedBy lists both types when neither is approved', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    expect(await documentGenerationService.closureBlockedBy(orderId)).toEqual(['BL Instruction Sheet', 'COO Prep Sheet']);
  });

  it('closureBlockedBy lists only the still outstanding type', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await createTestDocument(orderId, 'BLI', 'approved');
    expect(await documentGenerationService.closureBlockedBy(orderId)).toEqual(['COO Prep Sheet']);
  });

  it('closureBlockedBy is empty once both are approved or sent', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await createTestDocument(orderId, 'BLI', 'approved');
    await createTestDocument(orderId, 'COOPREP', 'sent');
    expect(await documentGenerationService.closureBlockedBy(orderId)).toEqual([]);
  });

  it('closeOrder refuses without both documents approved', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await prepareShippedOrderAtStage9(orderId);
    const userId = await createTestUser('Admin');
    const req = { params: { id: String(orderId) }, session: {}, user: { id: userId }, permissions: { close_orders: true, edit_locked_data: true }, body: { courier_tracking_number: 'DHL-TEST-1' } };

    await ordersController.closeOrder(req, fakeRes());

    expect((await orderRepository.find(orderId)).status).toBe('active');
  });

  it('closeOrder succeeds once both documents are approved', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await prepareShippedOrderAtStage9(orderId);
    await createTestDocument(orderId, 'BLI', 'approved');
    await createTestDocument(orderId, 'COOPREP', 'sent');
    const userId = await createTestUser('Admin');
    const req = { params: { id: String(orderId) }, session: {}, user: { id: userId }, permissions: { close_orders: true }, body: { courier_tracking_number: 'DHL-TEST-2' } };

    await ordersController.closeOrder(req, fakeRes());

    expect((await orderRepository.find(orderId)).status).toBe('complete');
  });

  it('closeOrder override requires edit_locked_data permission', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await prepareShippedOrderAtStage9(orderId);
    const userId = await createTestUser('Export Executive');
    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId },
      permissions: { close_orders: true, edit_locked_data: false },
      body: { courier_tracking_number: 'DHL-TEST-3', override_gate: '1', override_reason: 'Trying to force it through.' },
    };

    await ordersController.closeOrder(req, fakeRes());

    expect((await orderRepository.find(orderId)).status).toBe('active');
  });

  it('closeOrder override requires a reason', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await prepareShippedOrderAtStage9(orderId);
    const userId = await createTestUser('Admin');
    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId },
      permissions: { close_orders: true, edit_locked_data: true },
      body: { courier_tracking_number: 'DHL-TEST-4', override_gate: '1', override_reason: 'short' },
    };

    await ordersController.closeOrder(req, fakeRes());

    expect((await orderRepository.find(orderId)).status).toBe('active');
  });

  it('closeOrder override succeeds and is audit logged', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await prepareShippedOrderAtStage9(orderId);
    const userId = await createTestUser('Admin');
    const req = {
      params: { id: String(orderId) }, session: {}, user: { id: userId },
      permissions: { close_orders: true, edit_locked_data: true },
      body: { courier_tracking_number: 'DHL-TEST-5', override_gate: '1', override_reason: 'COO Prep genuinely not required for this shipment — cleared by MD.' },
    };

    await ordersController.closeOrder(req, fakeRes());

    expect((await orderRepository.find(orderId)).status).toBe('complete');

    const log = await db.queryOne(
      "SELECT * FROM audit_log WHERE action_type = 'CLOSE_ORDER_DOCUMENT_GATE_OVERRIDE' AND entity_type = 'orders' AND entity_id = :id",
      { id: orderId }
    );
    expect(log).toBeTruthy();
  });

  it('markLost refuses once a real Bill of Lading exists', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    await orderShippingRepository.upsert(orderId, { shipping_line: 'Maersk' });
    await orderShippingRepository.recordBl(orderId, 'MSCU7654321', '2026-06-15');
    const userId = await createTestUser('Admin');
    const req = { params: { id: String(orderId) }, session: {}, user: { id: userId }, permissions: {}, body: { reason: 'Buyer disappeared after shipment — trying to mark lost.' } };

    await ordersController.markLost(req, fakeRes());

    expect((await orderRepository.find(orderId)).status).toBe('active');
  });

  it('markLost still succeeds without a Bill of Lading', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const userId = await createTestUser('Admin');
    const req = { params: { id: String(orderId) }, session: {}, user: { id: userId }, permissions: {}, body: { reason: 'Buyer walked away before shipment.' } };

    await ordersController.markLost(req, fakeRes());

    expect((await orderRepository.find(orderId)).status).toBe('lost');
  });
});
