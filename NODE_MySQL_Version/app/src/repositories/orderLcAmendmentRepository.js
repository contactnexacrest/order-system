'use strict';

const db = require('../config/db');

/** Port of OrderLcAmendmentRepository.php. Gap list G-4 (owner, 1 Nov 2026): every amendment to an order's Letter of Credit, oldest first. */

async function add(orderId, amendedOn, reference, changeText, fileId, userId) {
  const r = await db.execute(
    'INSERT INTO order_lc_amendments (order_id, amended_on, reference, change_text, file_id, recorded_by) VALUES (:o, :d, :r, :c, :f, :u)',
    { o: orderId, d: amendedOn, r: reference, c: changeText, f: fileId, u: userId }
  );
  return r.insertId;
}

async function forOrder(orderId) {
  return db.query(
    'SELECT a.*, u.name AS recorded_by_name FROM order_lc_amendments a LEFT JOIN users u ON u.id = a.recorded_by WHERE a.order_id = :o ORDER BY a.amended_on, a.id',
    { o: orderId }
  );
}

module.exports = { add, forOrder };
