'use strict';

const db = require('../config/db');

async function upsert(orderId, data) {
  await db.execute(
    `INSERT INTO order_shipping
        (order_id, shipping_line, vessel_name, voyage_number, etd, eta, container_type, container_no, seal_no)
     VALUES (:order_id, :shipping_line, :vessel, :voyage, :etd, :eta, :container_type, :container_no, :seal_no)
     ON DUPLICATE KEY UPDATE
        shipping_line = VALUES(shipping_line), vessel_name = VALUES(vessel_name),
        voyage_number = VALUES(voyage_number), etd = VALUES(etd), eta = VALUES(eta),
        container_type = VALUES(container_type), container_no = VALUES(container_no), seal_no = VALUES(seal_no)`,
    {
      order_id: orderId,
      shipping_line: data.shipping_line ?? null,
      vessel: data.vessel_name ?? null,
      voyage: data.voyage_number ?? null,
      etd: data.etd || null,
      eta: data.eta || null,
      container_type: data.container_type ?? null,
      container_no: data.container_no ?? null,
      seal_no: data.seal_no ?? null,
    }
  );
}

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_shipping WHERE order_id = :order_id', { order_id: orderId });
}

async function recordBl(orderId, blNumber, blDate) {
  await db.execute('UPDATE order_shipping SET bl_number = :bl_number, bl_date = :bl_date WHERE order_id = :order_id', { bl_number: blNumber, bl_date: blDate, order_id: orderId });
}

async function recordBlOriginalsReceived(orderId, count) {
  await db.execute('UPDATE order_shipping SET bl_originals_received_at = NOW(), bl_originals_received_count = :count WHERE order_id = :order_id', { count, order_id: orderId });
}

async function recordBlEndorsed(orderId, userId) {
  await db.execute('UPDATE order_shipping SET bl_endorsed_at = NOW(), bl_endorsed_by = :user_id WHERE order_id = :order_id', { user_id: userId, order_id: orderId });
}

async function recordCourierSent(orderId, trackingNumber) {
  await db.execute('UPDATE order_shipping SET courier_tracking_number = :tracking, courier_sent_at = NOW() WHERE order_id = :order_id', { tracking: trackingNumber, order_id: orderId });
}

async function recordScannedBlSent(orderId) {
  await db.execute('UPDATE order_shipping SET scanned_bl_sent_to_buyer_at = NOW() WHERE order_id = :order_id', { order_id: orderId });
}

module.exports = { upsert, find, recordBl, recordBlOriginalsReceived, recordBlEndorsed, recordCourierSent, recordScannedBlSent };
