'use strict';

const db = require('../config/db');

/**
 * docs/schema.sql Section BR — Letter of Credit + bank loan interest
 * tracking for an order (export process steps 5-1a/5-2a/5-3a). At most
 * one row per order, created lazily the first time the client's LC
 * details are saved — most orders never opt for LC at all.
 */

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_lc_details WHERE order_id = :order_id', { order_id: orderId });
}

async function upsert(orderId, fields) {
  await db.execute(
    `INSERT INTO order_lc_details
        (order_id, lc_number, lc_file_id, bank_loan_amount, bank_loan_interest_rate,
         bank_loan_interest_paid, interest_cost_entry_id, recorded_by)
     VALUES
        (:order_id, :lc_number, :lc_file_id, :bank_loan_amount, :bank_loan_interest_rate,
         :bank_loan_interest_paid, :interest_cost_entry_id, :recorded_by)
     ON DUPLICATE KEY UPDATE
        lc_number = VALUES(lc_number),
        lc_file_id = VALUES(lc_file_id),
        bank_loan_amount = VALUES(bank_loan_amount),
        bank_loan_interest_rate = VALUES(bank_loan_interest_rate),
        bank_loan_interest_paid = VALUES(bank_loan_interest_paid),
        interest_cost_entry_id = VALUES(interest_cost_entry_id),
        recorded_by = VALUES(recorded_by)`,
    {
      order_id: orderId,
      lc_number: fields.lc_number,
      lc_file_id: fields.lc_file_id,
      bank_loan_amount: fields.bank_loan_amount,
      bank_loan_interest_rate: fields.bank_loan_interest_rate,
      bank_loan_interest_paid: fields.bank_loan_interest_paid,
      interest_cost_entry_id: fields.interest_cost_entry_id,
      recorded_by: fields.recorded_by,
    }
  );
}

/**
 * Step 5 LC payment leg (order.payment_method = 'LC'): records the LC
 * that replaces the cash advance entirely — the issuing bank, LC
 * number, LC amount, and charges billed to the client. Separate from
 * upsert() above (which records the supplementary bank-loan-interest
 * paperwork that can apply to ANY order regardless of payment method) —
 * this never touches the bank_loan_ or lc_confirmed_ columns, so
 * recording/re-recording LC details never silently resets an
 * already-confirmed LC back to unconfirmed.
 */
async function recordLegDetails(orderId, lcNumber, lcBankName, lcAmount, lcChargesAmount, lcFileId, recordedBy) {
  await db.execute(
    `INSERT INTO order_lc_details
        (order_id, lc_number, lc_bank_name, lc_amount, lc_charges_amount, lc_file_id, recorded_by)
     VALUES
        (:order_id, :lc_number, :lc_bank_name, :lc_amount, :lc_charges_amount, :lc_file_id, :recorded_by)
     ON DUPLICATE KEY UPDATE
        lc_number = VALUES(lc_number),
        lc_bank_name = VALUES(lc_bank_name),
        lc_amount = VALUES(lc_amount),
        lc_charges_amount = VALUES(lc_charges_amount),
        lc_file_id = COALESCE(VALUES(lc_file_id), lc_file_id),
        recorded_by = VALUES(recorded_by)`,
    {
      order_id: orderId,
      lc_number: lcNumber,
      lc_bank_name: lcBankName,
      lc_amount: lcAmount,
      lc_charges_amount: lcChargesAmount,
      lc_file_id: lcFileId,
      recorded_by: recordedBy,
    }
  );
}

/** Step 5e (LC leg): this leg's equivalent of orderPaymentStatusRepository.markAdvanceCleared(). */
async function confirmUsable(orderId, confirmedBy) {
  await db.execute(
    'UPDATE order_lc_details SET lc_confirmed_at = NOW(), lc_confirmed_by = :confirmed_by WHERE order_id = :order_id',
    { confirmed_by: confirmedBy, order_id: orderId }
  );
}

/**
 * Owner decision 2026-10-29: the money the bank actually paid under the
 * LC — date, amount in the order currency, rupees credited and bank
 * charges deducted. It goes to Zoho Books as a customer payment applied to
 * the CI (caSyncService), so it can be corrected only until then.
 */
async function recordReceived(orderId, receivedAt, amount, inrCredited, bankChargesInr, userId) {
  const r2 = (v) => Math.round(Number(v) * 100) / 100;
  await db.execute(
    `UPDATE order_lc_details
        SET lc_received_at = :received_at, lc_received_amount = :amount, lc_inr_credited = :inr,
            lc_bank_charges_inr = :charges, lc_received_by = :user_id
      WHERE order_id = :order_id AND lc_zoho_synced_at IS NULL`,
    { received_at: receivedAt, amount: r2(amount), inr: r2(inrCredited), charges: r2(bankChargesInr), user_id: userId, order_id: orderId }
  );
}

/**
 * Owner decision 2026-10-29: every LC payment received, for the Zoho sync —
 * not yet in Books, or in Books but not yet applied to the CI. Same
 * production / test rule as the other payments.
 */
async function receiptsForZohoSync() {
  const zohoGuard = require('../services/zohoGuard');
  const rows = await db.query(
    `SELECT l.*, o.client_id, o.buyer_inquiry_ref, o.company_legal_name, o.currency_code,
            (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
     FROM order_lc_details l
     JOIN orders o ON o.id = l.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE l.lc_received_at IS NOT NULL AND (l.lc_zoho_synced_at IS NULL OR l.lc_zoho_applied_at IS NULL)
     ORDER BY l.lc_received_at, l.order_id`
  );
  return rows.filter((r) => (zohoGuard.isProduction()
    ? Number(r.is_test_or_sample) !== 1
    : String(r.company_legal_name || '').startsWith(zohoGuard.TEST_PREFIX)));
}

async function setZohoSynced(orderId, paymentId, applied) {
  await db.execute(`UPDATE order_lc_details SET lc_zoho_payment_id = :id, lc_zoho_synced_at = NOW(), lc_zoho_applied_at = ${applied ? 'NOW()' : 'NULL'} WHERE order_id = :order_id`, { id: paymentId, order_id: orderId });
}

async function setZohoApplied(orderId) {
  await db.execute('UPDATE order_lc_details SET lc_zoho_applied_at = NOW() WHERE order_id = :order_id', { order_id: orderId });
}

/** The clean-up script removed this payment from Books: forget it here too. */
async function forgetZohoPayment(paymentId) {
  await db.execute('UPDATE order_lc_details SET lc_zoho_payment_id = NULL, lc_zoho_synced_at = NULL, lc_zoho_applied_at = NULL WHERE lc_zoho_payment_id = :id', { id: paymentId });
}

module.exports = { find, upsert, recordLegDetails, confirmUsable, recordReceived, receiptsForZohoSync, setZohoSynced, setZohoApplied, forgetZohoPayment };
