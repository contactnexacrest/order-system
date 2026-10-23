'use strict';

const db = require('../config/db');

/**
 * External QA report M-09: "Formal order cancellation workflow (reason,
 * approval, money/refund handling, audit) distinct from 'mark lost'."
 * Request/approve-or-reject, closest in shape to amendmentRepository's
 * own request/MD-approve/reject pattern — see docs/migrations/
 * 2026-10-23c-order-cancellation-workflow.sql for the schema.
 */

async function create(orderId, reason, requestedBy) {
  const result = await db.execute(
    'INSERT INTO order_cancellations (order_id, reason, requested_by) VALUES (:order_id, :reason, :requested_by)',
    { order_id: orderId, reason, requested_by: requestedBy }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM order_cancellations WHERE id = :id', { id });
}

async function findPendingForOrder(orderId) {
  return db.queryOne(
    "SELECT * FROM order_cancellations WHERE order_id = :order_id AND status = 'pending' ORDER BY id DESC LIMIT 1",
    { order_id: orderId }
  );
}

/** Most recent first, requester/decider names joined in for display. */
async function forOrder(orderId) {
  return db.query(
    `SELECT oc.*, ru.name AS requested_by_name, du.name AS decided_by_name
     FROM order_cancellations oc
     LEFT JOIN users ru ON ru.id = oc.requested_by
     LEFT JOIN users du ON du.id = oc.decided_by
     WHERE oc.order_id = :order_id
     ORDER BY oc.id DESC`,
    { order_id: orderId }
  );
}

async function approve(id, decidedBy, decisionNotes, refundAmount, refundStatus) {
  await db.execute(
    `UPDATE order_cancellations
     SET status = 'approved', decided_by = :decided_by, decided_at = NOW(), decision_notes = :decision_notes,
         refund_amount = :refund_amount, refund_status = :refund_status
     WHERE id = :id`,
    { decided_by: decidedBy, decision_notes: decisionNotes, refund_amount: refundAmount, refund_status: refundStatus, id }
  );
}

async function reject(id, decidedBy, decisionNotes) {
  await db.execute(
    "UPDATE order_cancellations SET status = 'rejected', decided_by = :decided_by, decided_at = NOW(), decision_notes = :decision_notes WHERE id = :id",
    { decided_by: decidedBy, decision_notes: decisionNotes, id }
  );
}

/** Recording that the refund money has actually moved — a separate, lower-bar step from the approval decision itself, since the bank transfer happens outside this system on its own timeline. */
async function completeRefund(id, recordedBy, refundNotes) {
  await db.execute(
    "UPDATE order_cancellations SET refund_status = 'completed', refund_notes = :refund_notes, refund_recorded_by = :recorded_by, refund_recorded_at = NOW() WHERE id = :id",
    { refund_notes: refundNotes, recorded_by: recordedBy, id }
  );
}

module.exports = { create, find, findPendingForOrder, forOrder, approve, reject, completeRefund };
