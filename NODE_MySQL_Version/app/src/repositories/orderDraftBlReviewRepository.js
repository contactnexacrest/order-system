'use strict';

const db = require('../config/db');

/**
 * docs/schema.sql Section BS — export process steps 9h-9j: the CHA
 * draft BL review loop. Each row is one round (one draft the CHA
 * sent); round_number is per-order, starting at 1, so the UI can show
 * "Round 2 — revision requested" etc. At most one round per order may
 * be 'pending' at a time — the next draft can't be logged until the
 * current one has been approved or sent back for revision.
 */

/** Newest round first. */
async function forOrder(orderId) {
  return db.query(
    `SELECT r.*, u.name AS uploaded_by_name, rv.name AS reviewed_by_name
     FROM order_draft_bl_reviews r
     JOIN users u ON u.id = r.uploaded_by
     LEFT JOIN users rv ON rv.id = r.reviewed_by
     WHERE r.order_id = :order_id
     ORDER BY r.round_number DESC`,
    { order_id: orderId }
  );
}

async function latestForOrder(orderId) {
  return db.queryOne(
    'SELECT * FROM order_draft_bl_reviews WHERE order_id = :order_id ORDER BY round_number DESC LIMIT 1',
    { order_id: orderId }
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM order_draft_bl_reviews WHERE id = :id', { id });
}

/** Creates the next round for this order (round_number = previous max + 1). */
async function createRound(orderId, draftFileId, uploadedBy) {
  const maxRow = await db.queryOne(
    'SELECT COALESCE(MAX(round_number), 0) AS max_round FROM order_draft_bl_reviews WHERE order_id = :order_id',
    { order_id: orderId }
  );
  const nextRound = Number(maxRow.max_round) + 1;

  const result = await db.execute(
    `INSERT INTO order_draft_bl_reviews (order_id, round_number, draft_file_id, uploaded_by)
     VALUES (:order_id, :round_number, :draft_file_id, :uploaded_by)`,
    { order_id: orderId, round_number: nextRound, draft_file_id: draftFileId, uploaded_by: uploadedBy }
  );
  return result.insertId;
}

/** @param {'approved'|'revision_requested'} status */
async function review(id, status, notes, reviewedBy) {
  await db.execute(
    `UPDATE order_draft_bl_reviews
     SET status = :status, review_notes = :notes, reviewed_by = :reviewed_by, reviewed_at = NOW()
     WHERE id = :id`,
    { status, notes, reviewed_by: reviewedBy, id }
  );
}

module.exports = { forOrder, latestForOrder, find, createRound, review };
