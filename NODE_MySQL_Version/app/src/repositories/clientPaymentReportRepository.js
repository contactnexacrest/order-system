'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\ClientPaymentReportRepository (docs/schema.sql
 * Section AD) — a client's own "I've paid" self-report. Purely
 * informational: nothing here ever touches order_payment_status or a
 * stage gate. Staff still record the actual advance/balance/freight
 * receipt the normal way (ordersController.recordAdvancePayment() etc)
 * once they've checked the real bank statement.
 */

async function create(orderId, paymentType, transactionRef, payerBankDetails, amount, paymentDate, screenshotFileId) {
  const result = await db.execute(
    `INSERT INTO client_payment_reports
        (order_id, payment_type, transaction_ref, payer_bank_details, amount, payment_date, screenshot_file_id)
     VALUES
        (:order_id, :payment_type, :transaction_ref, :payer_bank_details, :amount, :payment_date, :screenshot_file_id)`,
    {
      order_id: orderId,
      payment_type: paymentType,
      transaction_ref: transactionRef,
      payer_bank_details: payerBankDetails,
      amount,
      payment_date: paymentDate,
      screenshot_file_id: screenshotFileId,
    }
  );
  return result.insertId;
}

/** Newest first, for both the client's own view and the staff order page. */
async function forOrder(orderId) {
  return db.query(
    `SELECT r.*, u.name AS reviewed_by_name, fs.mime_type AS screenshot_mime_type, fs.original_filename AS screenshot_filename
     FROM client_payment_reports r
     LEFT JOIN users u ON u.id = r.reviewed_by
     LEFT JOIN file_store fs ON fs.id = r.screenshot_file_id
     WHERE r.order_id = :order_id
     ORDER BY r.reported_at DESC`,
    { order_id: orderId }
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM client_payment_reports WHERE id = :id', { id });
}

async function markReviewed(id, reviewedBy) {
  await db.execute(
    `UPDATE client_payment_reports SET status = 'reviewed', reviewed_by = :reviewed_by, reviewed_at = NOW()
     WHERE id = :id`,
    { id, reviewed_by: reviewedBy }
  );
}

module.exports = { create, forOrder, find, markReviewed };
