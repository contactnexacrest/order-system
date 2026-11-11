'use strict';

const db = require('../config/db');

/** docs/schema.sql Section AI — order progress chat thread. */

async function create(orderId, authorType, authorUserId, authorClientId, body) {
  const result = await db.execute(
    `INSERT INTO order_comments (order_id, author_type, author_user_id, author_client_id, body)
     VALUES (:order_id, :author_type, :author_user_id, :author_client_id, :body)`,
    { order_id: orderId, author_type: authorType, author_user_id: authorUserId, author_client_id: authorClientId, body }
  );
  return result.insertId;
}

async function attachFile(commentId, fileStoreId) {
  await db.execute(
    'INSERT INTO order_comment_attachments (comment_id, file_store_id) VALUES (:comment_id, :file_store_id)',
    { comment_id: commentId, file_store_id: fileStoreId }
  );
}

async function markEmailSent(commentId) {
  await recordEmailStatus(commentId, 'sent');
}

/**
 * D-268: what happened to a staff post's client e-mail — 'sent',
 * 'sending_off' (Admin switched e-mail sending off), 'no_email' (no
 * client e-mail on file) or 'failed' (the send itself failed).
 */
async function recordEmailStatus(commentId, status) {
  await db.execute(
    'UPDATE order_comments SET email_status = :status, email_sent = :sent WHERE id = :id',
    { status, sent: status === 'sent' ? 1 : 0, id: commentId }
  );
}

/**
 * D-268: the note staff see beside a staff post in Order Communication,
 * giving the true reason when the client was not e-mailed. A client's own
 * post carries no note. A post saved before D-268 has no recorded reason,
 * so it only says it was not e-mailed.
 */
function emailNote(comment) {
  if (!comment || comment.author_type !== 'staff') return '';
  if (Number(comment.email_sent) === 1) return 'e-mailed';
  switch (comment.email_status) {
    case 'sending_off': return 'not e-mailed — e-mail sending is switched off in Admin'; // posts from before D-280
    case 'testing_mode': return 'not e-mailed — testing mode holds e-mails to buyers and suppliers'; // D-280
    case 'switched_off': return 'not e-mailed — this e-mail is switched off in Admin › E-mail settings'; // D-280
    case 'no_email': return 'not e-mailed — no client e-mail on file';
    case 'failed': return 'not e-mailed — the e-mail could not be sent';
    default: return 'not e-mailed';
  }
}

/**
 * Ownership check for a comment-attachment download link: confirms
 * fileStoreId is actually attached to a comment on orderId before either
 * the staff or client download route serves it.
 */
async function findAttachmentForOrder(fileStoreId, orderId) {
  return db.queryOne(
    `SELECT f.* FROM order_comment_attachments a
     JOIN order_comments c ON c.id = a.comment_id
     JOIN file_store f ON f.id = a.file_store_id
     WHERE a.file_store_id = :file_store_id AND c.order_id = :order_id
     LIMIT 1`,
    { file_store_id: fileStoreId, order_id: orderId }
  );
}

/**
 * The full thread for an order, oldest first (chat reads top-to-bottom),
 * each comment carrying its author's display name/role and its
 * attachments (joined against file_store for original filename/mime
 * type/size — enough for the view to decide image vs video vs plain
 * download link).
 */
async function forOrder(orderId) {
  const rows = await db.query(
    `SELECT c.*,
            u.name AS staff_name,
            cl.company_legal_name AS client_name
     FROM order_comments c
     LEFT JOIN users u ON u.id = c.author_user_id
     LEFT JOIN clients cl ON cl.id = c.author_client_id
     WHERE c.order_id = :order_id
     ORDER BY c.created_at ASC, c.id ASC`,
    { order_id: orderId }
  );
  if (!rows.length) return [];

  const ids = rows.map((r) => r.id);
  const placeholders = ids.map((_, i) => `:id${i}`).join(',');
  const params = {};
  ids.forEach((id, i) => { params[`id${i}`] = id; });

  const attachments = await db.query(
    `SELECT a.comment_id, f.id AS file_id, f.original_filename, f.mime_type, f.file_size_bytes
     FROM order_comment_attachments a
     JOIN file_store f ON f.id = a.file_store_id
     WHERE a.comment_id IN (${placeholders})
     ORDER BY a.id ASC`,
    params
  );
  const byComment = {};
  for (const att of attachments) {
    (byComment[att.comment_id] = byComment[att.comment_id] || []).push(att);
  }

  for (const row of rows) {
    row.attachments = byComment[row.id] || [];
    row.email_note = emailNote(row);
  }
  return rows;
}

module.exports = { create, attachFile, markEmailSent, recordEmailStatus, emailNote, findAttachmentForOrder, forOrder };
