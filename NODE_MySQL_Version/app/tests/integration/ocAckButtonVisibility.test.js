'use strict';

const db = require('../../src/config/db');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const stageGateService = require('../../src/services/stageGateService');
const ordersController = require('../../src/controllers/ordersController');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of OcAckButtonVisibilityTest.php. 90-item backlog, P0 blocker
 * (#23/#77): the Stage 4 "Order Confirmation: Buyer Acknowledgement"
 * section only ever rendered the "Record Buyer's Email Acknowledgement"
 * form once an order_oc_acknowledgments row already existed — i.e. only
 * after a real send had completed. The backend action already handled the
 * "OC generated but never actually sent" case (ensureRowExists()), so this
 * covers ordersController.show() now actually passing ocDocGenerated to
 * the view, which orders/show.njk uses to offer the override form in that
 * case too instead of a dead-end message with no button at all.
 */
describe('OC acknowledgment override button visibility (P0 #23/#77)', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  function fakeRes() {
    const res = { redirectedTo: null };
    res.redirect = (url) => { res.redirectedTo = url; };
    res.renderView = (view, data) => { res.renderedView = view; res.renderedData = data; };
    return res;
  }

  async function createTestDocument(orderId, typeCode, status) {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-OCVIS-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  async function unlockStage4(orderId) {
    await stageGateService.passAndUnlockNext(orderId, 1, null);
    await stageGateService.passAndUnlockNext(orderId, 2, null);
    await stageGateService.passAndUnlockNext(orderId, 3, null);
    expect(await stageGateService.isUnlocked(orderId, 4)).toBe(true);
  }

  it('flags ocDocGenerated true once the OC exists, even though it was never sent', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockStage4(orderId);
    await createTestDocument(orderId, 'OC', 'draft');
    expect(await orderOcAcknowledgmentRepository.find(orderId)).toBeNull();

    const permissions = new Proxy({}, { get: () => true });
    const req = { params: { id: String(orderId) }, permissions, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.ocDocGenerated).toBe(true);
    expect(res.renderedData.ocAcknowledgment).toBeNull();
  });

  it('flags ocDocGenerated false when no OC document exists at all', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockStage4(orderId);

    const permissions = new Proxy({}, { get: () => true });
    const req = { params: { id: String(orderId) }, permissions, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.ocDocGenerated).toBe(false);
  });

  it('still reports the real ocAcknowledgment row once the OC has actually been sent', async () => {
    const orderId = await createTestOrder(await createTestClient());
    await unlockStage4(orderId);
    const documentId = await createTestDocument(orderId, 'OC', 'approved');
    await orderOcAcknowledgmentRepository.recordSent(
      orderId, documentId, new Date().toISOString().slice(0, 19).replace('T', ' '),
      new Date(Date.now() + 48 * 3600 * 1000).toISOString().slice(0, 19).replace('T', ' ')
    );

    const permissions = new Proxy({}, { get: () => true });
    const req = { params: { id: String(orderId) }, permissions, session: {} };
    const res = fakeRes();
    await ordersController.show(req, res);

    expect(res.renderedData.ocDocGenerated).toBe(true);
    expect(res.renderedData.ocAcknowledgment).toBeTruthy();
    expect(res.renderedData.ocAcknowledgment.acknowledged_at).toBeNull();
  });
});
