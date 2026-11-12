'use strict';

const db = require('../config/db');

/**
 * Owner decision D-337 (12 Nov 2026): requests to copy the client's current
 * details onto an order that already has an issued document
 * (order_buyer_detail_requests). One PENDING request per order at a time.
 * Every decision moves the row only from PENDING (decide()), so two people
 * deciding at once cannot both win. Port of OrderBuyerDetailRequestRepository.php.
 */
const SELECT = `SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
                  FROM order_buyer_detail_requests r
                  LEFT JOIN users u ON u.id = r.requested_by
                  LEFT JOIN users d ON d.id = r.decided_by`;

async function find(id) {
  return db.queryOne(`${SELECT} WHERE r.id = :id`, { id });
}

async function pendingForOrder(orderId) {
  return db.queryOne(`${SELECT} WHERE r.order_id = :o AND r.status = 'PENDING' ORDER BY r.id LIMIT 1`, { o: orderId });
}

/** Newest first. */
async function forOrder(orderId) {
  return db.query(`${SELECT} WHERE r.order_id = :o ORDER BY r.id DESC`, { o: orderId });
}

/** The PENDING requests of every order of this client. */
async function pendingForClient(clientId) {
  return db.query(
    `SELECT r.*, o.order_reference FROM order_buyer_detail_requests r JOIN orders o ON o.id = r.order_id
      WHERE o.client_id = :c AND r.status = 'PENDING' ORDER BY r.id`, { c: clientId });
}

/**
 * Creates the request only when the order has no PENDING one (one open request per order).
 * @returns {Promise<number>} the new id, or 0 when a request is already waiting
 */
async function create(orderId, userId, reason, changesJson, hash) {
  const res = await db.execute(
    `INSERT INTO order_buyer_detail_requests (order_id, requested_by, reason, changes_json, client_snapshot_hash)
     SELECT :o, :u, :r, :c, :h FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM order_buyer_detail_requests x WHERE x.order_id = :o2 AND x.status = 'PENDING')`,
    { o: orderId, u: userId, r: reason, c: changesJson, h: hash, o2: orderId });
  return res.affectedRows === 1 ? Number(res.insertId) : 0;
}

/** PENDING → to. Returns false when the request was no longer PENDING. */
async function decide(id, to, userId, note) {
  const res = await db.execute(
    `UPDATE order_buyer_detail_requests SET status = :s, decided_by = :u, decided_at = NOW(), decision_note = :n
      WHERE id = :id AND status = 'PENDING'`, { s: to, u: userId, n: note, id });
  return res.affectedRows === 1;
}

module.exports = { find, pendingForOrder, forOrder, pendingForClient, create, decide };
