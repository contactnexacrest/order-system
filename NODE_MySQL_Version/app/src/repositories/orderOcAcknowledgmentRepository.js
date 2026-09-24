'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\OrderOcAcknowledgmentRepository (docs/schema.sql
 * Section AE) — the buyer's acknowledgment of the Order Confirmation at
 * the Stage 4->5 gate. One row per order; a resend of the OC upserts a
 * fresh sent_at/due_at and clears any prior acknowledgment, since the
 * buyer is being asked to confirm the version just sent.
 */

/** Called from emailDispatchService.dispatch() the moment an OC document is actually emailed. */
async function recordSent(orderId, documentId, sentAt, dueAt) {
  await db.execute(
    `INSERT INTO order_oc_acknowledgments (order_id, document_id, sent_at, due_at)
     VALUES (:order_id, :document_id, :sent_at, :due_at)
     ON DUPLICATE KEY UPDATE
        document_id = VALUES(document_id), sent_at = VALUES(sent_at), due_at = VALUES(due_at),
        acknowledged_at = NULL, acknowledged_via = NULL, acknowledged_note = NULL, recorded_by = NULL`,
    { order_id: orderId, document_id: documentId, sent_at: sentAt, due_at: dueAt }
  );
}

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_oc_acknowledgments WHERE order_id = :order_id', { order_id: orderId });
}

async function markAcknowledged(orderId, via, note, recordedBy) {
  await db.execute(
    `UPDATE order_oc_acknowledgments
     SET acknowledged_at = NOW(), acknowledged_via = :via, acknowledged_note = :note, recorded_by = :recorded_by
     WHERE order_id = :order_id`,
    { order_id: orderId, via, note, recorded_by: recordedBy }
  );
}

/** Unacknowledged rows past due — for the 48h auto-confirm cron. */
async function dueForAutoConfirm() {
  return db.query("SELECT * FROM order_oc_acknowledgments WHERE acknowledged_at IS NULL AND due_at <= NOW()");
}

module.exports = { recordSent, find, markAcknowledged, dueForAutoConfirm };
