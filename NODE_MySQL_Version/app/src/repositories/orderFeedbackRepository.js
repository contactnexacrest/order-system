'use strict';

const db = require('../config/db');

/** docs/schema.sql Section BV — export process step 14: client rating + comments, one row per order. */
async function find(orderId) {
  return db.queryOne('SELECT * FROM order_feedback WHERE order_id = :order_id', { order_id: orderId });
}

async function upsert(orderId, clientId, rating, comments) {
  await db.execute(
    `INSERT INTO order_feedback (order_id, client_id, rating, comments)
     VALUES (:order_id, :client_id, :rating, :comments)
     ON DUPLICATE KEY UPDATE rating = :rating2, comments = :comments2, client_id = :client_id2`,
    { order_id: orderId, client_id: clientId, rating, comments, rating2: rating, comments2: comments, client_id2: clientId }
  );
}

module.exports = { find, upsert };
