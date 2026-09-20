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

/** Level 2 approval queue. */
async function pendingApproval() {
  return db.query(
    `SELECT el.*, o.order_reference
     FROM email_log el LEFT JOIN orders o ON o.id = el.order_id
     WHERE el.status = 'pending_approval'
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

async function markSent(id) {
  await db.execute("UPDATE email_log SET status = 'sent', sent_at = NOW() WHERE id = :id", { id });
}

async function markFailed(id) {
  await db.execute("UPDATE email_log SET status = 'failed' WHERE id = :id", { id });
}

module.exports = { create, find, forOrder, pendingApproval, approve, reject, dueForSend, markSent, markFailed };
