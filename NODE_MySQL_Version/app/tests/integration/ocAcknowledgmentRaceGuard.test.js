'use strict';

const db = require('../../src/config/db');
const orderOcAcknowledgmentRepository = require('../../src/repositories/orderOcAcknowledgmentRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * QA-5 EML-06 (same overlapping-run defect class as the email dispatcher,
 * folded in while fixing it): the three callers of markAcknowledged()
 * (staff-recorded via email reply, client-portal, and the 48h auto-confirm
 * job) each pre-check `acknowledged_at IS NULL` and then write
 * unconditionally — a check-then-act race if two land at the same moment
 * (e.g. the buyer acknowledges in the portal in the same instant the 48h
 * job reaches their order). markAcknowledged() now makes the write itself
 * the atomic claim.
 */
describe('orderOcAcknowledgmentRepository — EML-06 race guard', () => {
  async function createTestDocument(orderId, typeCode = 'OC', status = 'sent') {
    const type = await db.queryOne('SELECT id FROM document_types WHERE code = :code', { code: typeCode });
    const result = await db.execute(
      `INSERT INTO documents (order_id, document_type_id, document_reference, status)
       VALUES (:order_id, :type_id, :ref, :status)`,
      { order_id: orderId, type_id: type.id, ref: `JEST-OCACK-${Math.random().toString(16).slice(2, 10)}`, status }
    );
    return result.insertId;
  }

  afterAll(async () => {
    await db.pool.end();
  });

  it('wins once and loses on a second attempt', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const documentId = await createTestDocument(orderId);
    await orderOcAcknowledgmentRepository.recordSent(orderId, documentId, new Date(), new Date(Date.now() + 48 * 3600 * 1000));

    await expect(orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'client_portal', null, null)).resolves.toBe(true);
    const ack = await orderOcAcknowledgmentRepository.find(orderId);
    expect(ack.acknowledged_via).toBe('client_portal');

    // The 48h auto-confirm job reaching the same order a moment later —
    // this is the exact overlap the fix closes.
    await expect(orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'auto_48h', null, null)).resolves.toBe(false);

    const unchanged = await orderOcAcknowledgmentRepository.find(orderId);
    expect(unchanged.acknowledged_via).toBe('client_portal');
  });

  it('refuses an order with no pending acknowledgment', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    // No order_oc_acknowledgments row exists at all for this order.

    await expect(orderOcAcknowledgmentRepository.markAcknowledged(orderId, 'staff_recorded_email', 'note', 1)).resolves.toBe(false);
  });
});
