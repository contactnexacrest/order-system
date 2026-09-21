'use strict';

const db = require('../config/db');

/**
 * Every uploaded copy of the buyer's signed PO, newest first — see
 * schema.sql SECTION S. attach() never overwrites a prior row, so
 * re-uploading a corrected copy is real version history, not a
 * replacement.
 */

async function attach(orderId, fileId) {
  const result = await db.execute('INSERT INTO order_buyer_po_documents (order_id, file_id) VALUES (:order_id, :file_id)', {
    order_id: orderId,
    file_id: fileId,
  });
  return result.insertId;
}

async function forOrder(orderId) {
  return db.query(
    `SELECT d.*, fs.original_filename, fs.server_path, fs.mime_type, fs.uploaded_at
     FROM order_buyer_po_documents d JOIN file_store fs ON fs.id = d.file_id
     WHERE d.order_id = :order_id
     ORDER BY fs.uploaded_at DESC`,
    { order_id: orderId }
  );
}

module.exports = { attach, forOrder };
