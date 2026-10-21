'use strict';

const db = require('../config/db');

/**
 * Spec Section 10 — "EMAIL & DEFERRED SEND SYSTEM", 2-level approval.
 * Level 1 (any user with send permission) creates a row here in
 * 'pending_approval'. Level 2 (a privileged approver) flips it to
 * 'approved'/'rejected'. The dispatch-deferred-emails background job is
 * what actually sends 'approved' rows once scheduled_at has passed — see
 * that job's own docblock for why this isn't done synchronously.
 */

async function create(orderId, documentId, templateKey, recipientEmail, subject, bodySnapshot, scheduledAt, requestedBy) {
  const result = await db.execute(
    `INSERT INTO email_log
        (order_id, document_id, template_key, recipient_email, subject, body_snapshot, scheduled_at, requested_by, status)
     VALUES
        (:order_id, :document_id, :template_key, :recipient_email, :subject, :body_snapshot, :scheduled_at, :requested_by, 'pending_approval')`,
    {
      order_id: orderId, document_id: documentId, template_key: templateKey, recipient_email: recipientEmail,
      subject, body_snapshot: bodySnapshot, scheduled_at: scheduledAt, requested_by: requestedBy,
    }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM email_log WHERE id = :id', { id });
}

async function forOrder(orderId) {
  return db.query('SELECT * FROM email_log WHERE order_id = :order_id ORDER BY created_at DESC', { order_id: orderId });
}

/**
 * True if this document already has a send in flight — either awaiting
 * Level-2 approval or approved and waiting for its scheduled dispatch.
 * Used to block a second Level-1 request for the same document while one
 * is still active, rather than letting the buyer end up with two
 * competing sends.
 */
async function hasActiveSendFor(documentId) {
  const row = await db.queryOne(
    "SELECT 1 AS x FROM email_log WHERE document_id = :document_id AND status IN ('pending_approval', 'approved') LIMIT 1",
    { document_id: documentId }
  );
  return !!row;
}

/** Level 2 approval queue. */
async function pendingApproval() {
  return db.query(
    `SELECT el.*, o.order_reference
     FROM email_log el LEFT JOIN orders o ON o.id = el.order_id
     WHERE el.status = 'pending_approval'
     ORDER BY el.created_at`
  );
}

/**
 * User-reported batch item 16: the Email Approvals sidebar link had no
 * pending-count badge, unlike Quotation Intake Review/PI Intake
 * Review/Notifications. Counts the exact same rows awaitingDispatch()
 * lists (pending_approval + approved-but-not-yet-dispatched), so the
 * badge number always matches what staff see when they click through.
 */
async function awaitingDispatchCount() {
  const row = await db.queryOne("SELECT COUNT(*) AS c FROM email_log WHERE status IN ('pending_approval', 'approved')");
  return parseInt(row.c, 10);
}

/**
 * The full deferred window — awaiting Level-2 approval, or already approved
 * but not yet dispatched (scheduled_at still in the future or the
 * background job hasn't ticked yet). This is everything a "Cancel" action
 * can still stop before it becomes 'sent'.
 */
async function awaitingDispatch() {
  // d.document_reference/document_type_code label the attachment link in
  // the approvals screen — an approver needs to see exactly what they're
  // sending (body_snapshot, already on el.*) and exactly what PDF goes out
  // with it before they can meaningfully decide.
  return db.query(
    `SELECT el.*, o.order_reference, d.document_reference, dt.code AS document_type_code
     FROM email_log el
     LEFT JOIN orders o ON o.id = el.order_id
     LEFT JOIN documents d ON d.id = el.document_id
     LEFT JOIN document_types dt ON dt.id = d.document_type_id
     WHERE el.status IN ('pending_approval', 'approved')
     ORDER BY el.created_at`
  );
}

async function approve(id, approvedBy) {
  await db.execute("UPDATE email_log SET status = 'approved', approved_by = :approved_by, approved_at = NOW() WHERE id = :id", {
    approved_by: approvedBy,
    id,
  });
}

async function reject(id, approvedBy, reason) {
  await db.execute(
    "UPDATE email_log SET status = 'rejected', approved_by = :approved_by, approved_at = NOW(), rejection_reason = :reason WHERE id = :id",
    { approved_by: approvedBy, reason, id }
  );
}

/** Approved rows due to send (background dispatcher only). */
async function dueForSend() {
  return db.query("SELECT * FROM email_log WHERE status = 'approved' AND (scheduled_at IS NULL OR scheduled_at <= NOW())");
}

/**
 * QA-5 EML-06: the dispatch job can overlap itself (a slow run still going
 * when the next scheduler tick fires, or an in-process scheduler racing an
 * OS cron entry pointed at the same script) and both runs' dueForSend()
 * would see the same 'approved' row — whichever thread reads first, both
 * would then send it. Claiming the row atomically first (UPDATE ... WHERE
 * status = 'approved', checked via affectedRows) means only one of the two
 * racing UPDATEs can ever win; the loser sees 0 rows affected and skips
 * the send.
 *
 * @returns {Promise<boolean>} true if THIS call won the claim (row was
 *   'approved' and is now 'sending'); false if another run already
 *   claimed it first.
 */
async function claimForSend(id) {
  const result = await db.execute("UPDATE email_log SET status = 'sending' WHERE id = :id AND status = 'approved'", { id });
  return result.affectedRows > 0;
}

async function markSent(id) {
  await db.execute("UPDATE email_log SET status = 'sent', sent_at = NOW() WHERE id = :id", { id });
}

async function markFailed(id) {
  await db.execute("UPDATE email_log SET status = 'failed' WHERE id = :id", { id });
}

/**
 * Pulls a send back before it goes out — from 'pending_approval' (Level-1
 * requester changed their mind before anyone reviewed it) or from
 * 'approved' (still sitting in its deferred window, not yet picked up by
 * the dispatch job). Once a row is 'sent' this can no longer apply to it —
 * see emailDispatchService.cancelSend()'s status check.
 */
async function cancel(id, cancelledBy, reason) {
  await db.execute(
    "UPDATE email_log SET status = 'cancelled', cancelled_by = :cancelled_by, cancelled_at = NOW(), cancellation_reason = :reason WHERE id = :id",
    { cancelled_by: cancelledBy, reason, id }
  );
}

module.exports = {
  create,
  find,
  forOrder,
  hasActiveSendFor,
  pendingApproval,
  awaitingDispatch,
  awaitingDispatchCount,
  approve,
  reject,
  cancel,
  dueForSend,
  claimForSend,
  markSent,
  markFailed,
};
