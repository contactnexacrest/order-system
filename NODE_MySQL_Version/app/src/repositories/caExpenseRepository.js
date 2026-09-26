'use strict';

const db = require('../config/db');
const financialYear = require('../helpers/financialYear');

// CA / Accounting module (Phase 4) — expenses imported one-way from Zoho
// Books. No create/update path for the expense data itself (Zoho Books
// stays the one place expenses are entered) — the only write this
// repository exposes besides the import insert is setTds(), a local-only
// annotation that never pushes back to Zoho.

async function existsByZohoId(zohoExpenseId) {
  const row = await db.queryOne('SELECT 1 AS present FROM ca_expenses WHERE zoho_expense_id = :id', { id: zohoExpenseId });
  return !!row;
}

/** @returns {Promise<number>} the inserted row's id */
async function insert(zohoExpenseId, category, description, vendorName, amount, currencyCode, expenseDate) {
  const result = await db.execute(
    `INSERT INTO ca_expenses (zoho_expense_id, category, description, vendor_name, amount, currency_code, expense_date)
     VALUES (:zoho_expense_id, :category, :description, :vendor_name, :amount, :currency_code, :expense_date)`,
    {
      zoho_expense_id: zohoExpenseId, category, description, vendor_name: vendorName,
      amount, currency_code: currencyCode, expense_date: expenseDate,
    }
  );
  return result.insertId;
}

/** @returns newest first */
async function all() {
  return db.query('SELECT * FROM ca_expenses ORDER BY expense_date DESC, id DESC');
}

async function find(id) {
  return db.queryOne('SELECT * FROM ca_expenses WHERE id = :id', { id });
}

/** All-time total of every imported expense — used by the reconciliation summary. */
async function totalAll() {
  const row = await db.queryOne('SELECT COALESCE(SUM(amount), 0) AS total FROM ca_expenses');
  return parseFloat(row.total);
}

/** FY labels with at least one imported expense — for the FY Lock page's picker (Phase 6). */
async function availableFinancialYears() {
  const rows = await db.query('SELECT expense_date FROM ca_expenses');
  return financialYear.labelsPresentIn(rows.map((r) => r.expense_date));
}

/** Local-only TDS annotation — never written back to Zoho Books. */
async function setTds(id, isTdsApplicable, tdsAmount, userId) {
  await db.execute(
    `UPDATE ca_expenses
     SET is_tds_applicable = :is_tds, tds_amount = :tds_amount, tds_set_by = :user_id, tds_set_at = NOW()
     WHERE id = :id`,
    { is_tds: isTdsApplicable ? 1 : 0, tds_amount: tdsAmount, user_id: userId, id }
  );
}

/**
 * TDS Payable Summary (closes a Reports/CA gap) — the per-expense TDS
 * annotation set via setTds() was never rolled up anywhere. Grouped by
 * calendar month AND quarter (Q1 Apr-Jun ... Q4 Jan-Mar) since TDS
 * returns (Form 26Q) are filed quarterly in India, while month is still
 * useful for a CA reconciling against the books month by month.
 *
 * @returns {Promise<Array<object>>} one row per month with at least one
 *   TDS-applicable expense, newest first
 */
async function tdsSummary() {
  const rows = await db.query(
    `SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month,
            COUNT(*) AS expense_count,
            COALESCE(SUM(amount), 0) AS total_expense_amount,
            COALESCE(SUM(tds_amount), 0) AS total_tds_amount
     FROM ca_expenses
     WHERE is_tds_applicable = 1
     GROUP BY month
     ORDER BY month DESC`
  );
  for (const r of rows) {
    const monthNum = parseInt(r.month.slice(5, 7), 10);
    const yearNum = parseInt(r.month.slice(0, 4), 10);
    // Indian FY quarters: Q1 Apr-Jun, Q2 Jul-Sep, Q3 Oct-Dec, Q4 Jan-Mar.
    if (monthNum >= 4 && monthNum <= 6) {
      r.quarter = `Q1 FY${yearNum}-${String(yearNum + 1).slice(2, 4)}`;
    } else if (monthNum >= 7 && monthNum <= 9) {
      r.quarter = `Q2 FY${yearNum}-${String(yearNum + 1).slice(2, 4)}`;
    } else if (monthNum >= 10 && monthNum <= 12) {
      r.quarter = `Q3 FY${yearNum}-${String(yearNum + 1).slice(2, 4)}`;
    } else {
      r.quarter = `Q4 FY${yearNum - 1}-${String(yearNum).slice(2, 4)}`;
    }
    r.expense_count = parseInt(r.expense_count, 10);
    r.total_expense_amount = Math.round((parseFloat(r.total_expense_amount) + Number.EPSILON) * 100) / 100;
    r.total_tds_amount = Math.round((parseFloat(r.total_tds_amount) + Number.EPSILON) * 100) / 100;
  }
  return rows;
}

module.exports = { existsByZohoId, insert, all, find, setTds, totalAll, availableFinancialYears, tdsSummary };
