'use strict';

const db = require('../config/db');

async function upsert(orderId, data) {
  await db.execute(
    `INSERT INTO order_freight
        (order_id, confirmed_freight_rate, insurance_amount, freight_forwarder_name, freight_forwarder_contact, gst_treatment)
     VALUES (:order_id, :rate, :insurance, :forwarder_name, :forwarder_contact, :gst_treatment)
     ON DUPLICATE KEY UPDATE
        confirmed_freight_rate = VALUES(confirmed_freight_rate),
        insurance_amount = VALUES(insurance_amount),
        freight_forwarder_name = VALUES(freight_forwarder_name),
        freight_forwarder_contact = VALUES(freight_forwarder_contact),
        gst_treatment = VALUES(gst_treatment)`,
    {
      order_id: orderId,
      rate: data.confirmed_freight_rate || null,
      insurance: data.insurance_amount || null,
      forwarder_name: data.freight_forwarder_name ?? null,
      forwarder_contact: data.freight_forwarder_contact ?? null,
      gst_treatment: data.gst_treatment ?? null,
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
