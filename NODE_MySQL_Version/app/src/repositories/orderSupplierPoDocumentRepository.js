'use strict';

const db = require('../config/db');

/**
 * Every uploaded copy of the supplier's signed PO acknowledgment for one
 * specific order_supplier_po row, newest first — see schema.sql SECTION S.
 * Keyed by order_supplier_po_id (not order_id) since an order can have
 * more than one Supplier PO version; the acknowledgment belongs to the PO
 * version it was signed against. attach() never overwrites a prior row,
 * so re-uploading a corrected copy is real version history, not a
 * replacement.
 */

async function attach(orderSupplierPoId, fileId) {
  const result = await db.execute(
    'INSERT INTO order_supplier_po_documents (order_supplier_po_id, file_id) VALUES (:order_supplier_po_id, :file_id)',
    { order_supplier_po_id: orderSupplierPoId, file_id: fileId }
  );
  return result.insertId;
}

async function forSupplierPo(orderSupplierPoId) {
  return db.query(
    `SELECT d.*, fs.original_filename, fs.server_path, fs.mime_type, fs.uploaded_at
     FROM order_supplier_po_documents d JOIN file_store fs ON fs.id = d.file_id
     WHERE d.order_supplier_po_id = :order_supplier_po_id
     ORDER BY fs.uploaded_at DESC`,
    { order_supplier_po_id: orderSupplierPoId }
  );
}

module.exports = { attach, forSupplierPo };
