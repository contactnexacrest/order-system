'use strict';

const db = require('../config/db');

// CA / Accounting module (Phase 5) — bank statement lines imported from a
// CSV export, matched one at a time to either a revenue settlement leg or
// a ca_expenses row. See schema.sql's comment on ca_bank_statement_lines
// for why a line can hold at most one kind of match.

/**
 * Inserts one parsed line, skipping it silently if its hash already
 * exists (a duplicate from a repeat/overlapping upload) — this is the one
 * place de-duplication is enforced, via the line_hash UNIQUE key.
 *
 * @returns {Promise<boolean>} true if inserted, false if it was a duplicate
 */
async function insertLine(lineHash, transactionDate, description, reference, credit, debit, importedBy) {
  try {
    await db.execute(
      `INSERT INTO ca_bank_statement_lines
          (line_hash, transaction_date, description, reference, credit_amount, debit_amount, imported_by)
       VALUES
          (:line_hash, :transaction_date, :description, :reference, :credit, :debit, :imported_by)`,
      {
        line_hash: lineHash, transaction_date: transactionDate, description, reference,
        credit, debit, imported_by: importedBy,
      }
    );
    return true;
  } catch (e) {
    if (e.code === 'ER_DUP_ENTRY') {
      return false; // duplicate line_hash — already imported
    }
    throw e;
  }
}

/** @returns newest first, with matched order/expense info joined in for display */
async function all() {
  return db.query(
    `SELECT bsl.*, o.buyer_inquiry_ref AS matched_order_ref, ce.category AS matched_expense_category, ce.vendor_name AS matched_expense_vendor
     FROM ca_bank_statement_lines bsl
     LEFT JOIN orders o ON o.id = bsl.matched_order_id
     LEFT JOIN ca_expenses ce ON ce.id = bsl.matched_expense_id
     ORDER BY bsl.transaction_date DESC, bsl.id DESC`
  );
}

/** @returns {Promise<string[]>} 'orderId:leg' keys currently matched to a bank line */
async function matchedRevenueKeys() {
  const rows = await db.query('SELECT matched_order_id, matched_leg FROM ca_bank_statement_lines WHERE matched_order_id IS NOT NULL');
  return rows.map((r) => `${r.matched_order_id}:${r.matched_leg}`);
}

/** @returns {Promise<number[]>} ca_expenses ids currently matched to a bank line */
async function matchedExpenseIds() {
  const rows = await db.query('SELECT matched_expense_id FROM ca_bank_statement_lines WHERE matched_expense_id IS NOT NULL');
  return rows.map((r) => r.matched_expense_id);
}

async function matchToRevenue(lineId, orderId, leg, userId) {
  await db.execute(
    `UPDATE ca_bank_statement_lines
     SET matched_order_id = :order_id, matched_leg = :leg, matched_expense_id = NULL, matched_by = :user_id, matched_at = NOW()
     WHERE id = :id`,
    { order_id: orderId, leg, user_id: userId, id: lineId }
  );
}

async function matchToExpense(lineId, expenseId, userId) {
  await db.execute(
    `UPDATE ca_bank_statement_lines
     SET matched_expense_id = :expense_id, matched_order_id = NULL, matched_leg = NULL, matched_by = :user_id, matched_at = NOW()
     WHERE id = :id`,
    { expense_id: expenseId, user_id: userId, id: lineId }
  );
}

async function unmatch(lineId) {
  await db.execute(
    `UPDATE ca_bank_statement_lines
     SET matched_order_id = NULL, matched_leg = NULL, matched_expense_id = NULL, matched_by = NULL, matched_at = NULL
     WHERE id = :id`,
    { id: lineId }
  );
}

/** All-time totals across every imported line. */
async function totals() {
  const row = await db.queryOne(
    `SELECT
        COALESCE(SUM(credit_amount), 0) AS total_credit,
        COALESCE(SUM(debit_amount), 0) AS total_debit,
        COALESCE(SUM(CASE WHEN matched_order_id IS NOT NULL THEN credit_amount ELSE 0 END), 0) AS matched_credit,
        COALESCE(SUM(CASE WHEN matched_expense_id IS NOT NULL THEN debit_amount ELSE 0 END), 0) AS matched_debit,
        COUNT(*) AS line_count
     FROM ca_bank_statement_lines`
  );
  return {
    totalCredit: parseFloat(row.total_credit),
    totalDebit: parseFloat(row.total_debit),
    matchedCredit: parseFloat(row.matched_credit),
    matchedDebit: parseFloat(row.matched_debit),
    lineCount: row.line_count,
  };
}

module.exports = { insertLine, all, matchedRevenueKeys, matchedExpenseIds, matchToRevenue, matchToExpense, unmatch, totals };
