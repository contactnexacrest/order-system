'use strict';

const db = require('../config/db');

async function upsert(orderId, data) {
  await db.execute(
    `INSERT INTO order_shipping
        (order_id, shipping_line, vessel_name, voyage_number, etd, eta, container_type, container_count, container_no, seal_no)
     VALUES (:order_id, :shipping_line, :vessel, :voyage, :etd, :eta, :container_type, :container_count, :container_no, :seal_no)
     ON DUPLICATE KEY UPDATE
        shipping_line = VALUES(shipping_line), vessel_name = VALUES(vessel_name),
        voyage_number = VALUES(voyage_number), etd = VALUES(etd), eta = VALUES(eta),
        container_type = VALUES(container_type), container_count = VALUES(container_count),
        container_no = VALUES(container_no), seal_no = VALUES(seal_no)`,
    {
      order_id: orderId,
      shipping_line: data.shipping_line ?? null,
      vessel: data.vessel_name ?? null,
      voyage: data.voyage_number ?? null,
      etd: data.etd || null,
      eta: data.eta || null,
      container_type: data.container_type ?? null,
      container_count: data.container_count || null,
      container_no: data.container_no ?? null,
      seal_no: data.seal_no ?? null,
    }
  );
}

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_shipping WHERE order_id = :order_id', { order_id: orderId });
}

/**
 * BL issued: the actual gate that lets Commercial Invoice (Stage 8) be
 * generated — CI's date must match the BL date. Upserts (QA Round 4,
 * N-02) because staff can reach "Confirm BL Issued" without ever having
 * saved "Shipping / Vessel Details" first — a plain UPDATE would
 * silently affect zero rows and the BL number/date would never actually
 * be stored anywhere, even though the stage gate still passes.
 */
async function recordBl(orderId, blNumber, blDate) {
  await db.execute(
    `INSERT INTO order_shipping (order_id, bl_number, bl_date)
     VALUES (:order_id, :bl_number, :bl_date)
     ON DUPLICATE KEY UPDATE bl_number = VALUES(bl_number), bl_date = VALUES(bl_date)`,
    { bl_number: blNumber, bl_date: blDate, order_id: orderId }
  );
}

/**
 * Attaches the actual scanned BL document to the already-recorded BL
 * number/date. Upserts (N-02) for the same reason as recordBl() above —
 * the scanned copy can be attached in a separate step from confirming
 * the BL number, and the row still might not exist yet.
 */
async function attachBlCopy(orderId, fileId) {
  await db.execute(
    `INSERT INTO order_shipping (order_id, bl_copy_file_id)
     VALUES (:order_id, :file_id)
     ON DUPLICATE KEY UPDATE bl_copy_file_id = VALUES(bl_copy_file_id)`,
    { file_id: fileId, order_id: orderId }
  );
}

/** Upserts (N-02) — see recordBl() above. */
async function recordBlOriginalsReceived(orderId, count) {
  await db.execute(
    `INSERT INTO order_shipping (order_id, bl_originals_received_at, bl_originals_received_count)
     VALUES (:order_id, NOW(), :count)
     ON DUPLICATE KEY UPDATE bl_originals_received_at = NOW(), bl_originals_received_count = VALUES(bl_originals_received_count)`,
    { count, order_id: orderId }
  );
}

/** Upserts (N-02) — see recordBl() above. */
async function recordBlEndorsed(orderId, userId) {
  await db.execute(
    `INSERT INTO order_shipping (order_id, bl_endorsed_at, bl_endorsed_by)
     VALUES (:order_id, NOW(), :user_id)
     ON DUPLICATE KEY UPDATE bl_endorsed_at = NOW(), bl_endorsed_by = VALUES(bl_endorsed_by)`,
    { user_id: userId, order_id: orderId }
  );
}

async function recordCourierSent(orderId, trackingNumber) {
  await db.execute('UPDATE order_shipping SET courier_tracking_number = :tracking, courier_sent_at = NOW() WHERE order_id = :order_id', { tracking: trackingNumber, order_id: orderId });
}

async function recordScannedBlSent(orderId) {
  await db.execute('UPDATE order_shipping SET scanned_bl_sent_to_buyer_at = NOW() WHERE order_id = :order_id', { order_id: orderId });
}

/**
 * docs/schema.sql Section BS — records the draft BL most recently
 * received from the CHA as the order's CURRENT one (clearing any
 * earlier approval — a new draft always needs its own review). Upserts
 * because a draft can arrive before "Shipping / Vessel Details" has
 * ever been saved, i.e. before any order_shipping row exists yet.
 */
async function attachDraftBl(orderId, fileId) {
  await db.execute(
    `INSERT INTO order_shipping (order_id, draft_bl_file_id, draft_bl_uploaded_at, draft_bl_approved_at, draft_bl_approved_by)
     VALUES (:order_id, :file_id, NOW(), NULL, NULL)
     ON DUPLICATE KEY UPDATE
        draft_bl_file_id = VALUES(draft_bl_file_id),
        draft_bl_uploaded_at = VALUES(draft_bl_uploaded_at),
        draft_bl_approved_at = NULL,
        draft_bl_approved_by = NULL`,
    { order_id: orderId, file_id: fileId }
  );
}

async function approveDraftBl(orderId, userId) {
  await db.execute(
    'UPDATE order_shipping SET draft_bl_approved_at = NOW(), draft_bl_approved_by = :user_id WHERE order_id = :order_id',
    { user_id: userId, order_id: orderId }
  );
}

module.exports = {
  upsert, find, recordBl, attachBlCopy, recordBlOriginalsReceived, recordBlEndorsed, recordCourierSent, recordScannedBlSent,
  attachDraftBl, approveDraftBl,
};
