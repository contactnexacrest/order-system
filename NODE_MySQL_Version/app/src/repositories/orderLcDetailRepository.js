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

module.exports = { find, upsert };
