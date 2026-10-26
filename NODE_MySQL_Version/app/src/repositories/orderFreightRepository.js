'use strict';

const db = require('../config/db');

// gst_treatment is deliberately never written here (dropped along with
// its dead admin dropdown): the FDN is always a pure cost reimbursement,
// never a taxable service supply, so there is nothing left for this
// column to configure. The column itself is left in the schema rather
// than dropped — removing a column is a real migration, not a code change.
async function upsert(orderId, data) {
  await db.execute(
    `INSERT INTO order_freight
        (order_id, confirmed_freight_rate, insurance_amount, freight_forwarder_name, freight_forwarder_contact, freight_forwarder_supplier_id)
     VALUES (:order_id, :rate, :insurance, :forwarder_name, :forwarder_contact, :forwarder_supplier_id)
     ON DUPLICATE KEY UPDATE
        freight_forwarder_supplier_id = VALUES(freight_forwarder_supplier_id),
        confirmed_freight_rate = VALUES(confirmed_freight_rate),
        insurance_amount = VALUES(insurance_amount),
        freight_forwarder_name = VALUES(freight_forwarder_name),
        freight_forwarder_contact = VALUES(freight_forwarder_contact)`,
    {
      order_id: orderId,
      rate: data.confirmed_freight_rate || null,
      insurance: data.insurance_amount || null,
      forwarder_name: data.freight_forwarder_name ?? null,
      forwarder_contact: data.freight_forwarder_contact ?? null,
      forwarder_supplier_id: data.freight_forwarder_supplier_id ?? null,
    }
  );
}

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_freight WHERE order_id = :order_id', { order_id: orderId });
}

async function setFdnDocumentId(orderId, documentId) {
  await db.execute('UPDATE order_freight SET fdn_document_id = :document_id WHERE order_id = :order_id', { document_id: documentId, order_id: orderId });
}

async function markCleared(orderId, userId) {
  await db.execute('UPDATE order_freight SET freight_cleared_at = NOW(), freight_cleared_by = :user_id WHERE order_id = :order_id', { user_id: userId, order_id: orderId });
}

module.exports = { upsert, find, setFdnDocumentId, markCleared };
