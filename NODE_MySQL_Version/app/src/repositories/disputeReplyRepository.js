'use strict';

const db = require('../config/db');

/** docs/schema.sql Section AO — a dispute's own reply thread (not order_comments). */

async function create(disputeId, authorUserId, body) {
  const result = await db.execute(
    'INSERT INTO dispute_replies (dispute_id, author_user_id, body) VALUES (:dispute_id, :author_user_id, :body)',
    { dispute_id: disputeId, author_user_id: authorUserId, body }
  );
  return result.insertId;
}

/** A dispute's replies, oldest first, with the author's display name. */
async function forDispute(disputeId) {
  return db.query(
    `SELECT r.*, u.name AS author_name
     FROM dispute_replies r
     JOIN users u ON u.id = r.author_user_id
     WHERE r.dispute_id = :dispute_id
     ORDER BY r.created_at ASC, r.id ASC`,
    { dispute_id: disputeId }
  );
}

module.exports = { create, forDispute };
