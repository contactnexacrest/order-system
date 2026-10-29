'use strict';

const db = require('../config/db');
const financialYear = require('../helpers/financialYear');

// CA / Accounting module (Phase 4) — expenses imported one-way from Zoho
// Books. Zoho Books stays the one place expenses are entered: the import
// inserts them and mirrors later changes and deletions made there
// (updateFromZoho(), remove()). The only local writes are setTds() and the
// order link, annotations that never push back to Zoho.

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

/** @returns newest first, with the linked order's reference joined in for display */
async function all() {
  return db.query(
    `SELECT ce.*, o.order_reference
     FROM ca_expenses ce
     LEFT JOIN orders o ON o.id = ce.order_id
     ORDER BY ce.expense_date DESC, ce.id DESC`
  );
}

async function find(id) {
  return db.queryOne('SELECT * FROM ca_expenses WHERE id = :id', { id });
}

/**
 * Expenses linked to one specific order — shown on that order's own
 * detail page (e.g. ECGC insurance or a third-party inspection fee paid
 * for this shipment) alongside any linked export benefits.
 *
 * @returns newest first
 */
async function forOrder(orderId) {
  return db.query('SELECT * FROM ca_expenses WHERE order_id = :order_id ORDER BY expense_date DESC, id DESC', { order_id: orderId });
}

/**
 * Point 2 follow-up — Zoho Books has no concept of this app's order IDs,
 * so an imported expense can only ever be linked to the order it was
 * actually incurred for as a manual, local-only step (never pushed back
 * to Zoho). Passing null unlinks it, to correct a mis-link.
 */
async function linkToOrder(id, orderId) {
  await db.execute('UPDATE ca_expenses SET order_id = :order_id WHERE id = :id', { order_id: orderId, id });
}

/**
 * Owner decision 2026-10-29 (Books is the source of truth for what the CA
 * enters there): every expense imported so far, keyed by its Zoho id, so the
 * import can mirror Books' changes and deletions.
 *
 * @return {Promise<Map<string, object>>}
 */
async function allByZohoId() {
  return new Map((await db.query('SELECT * FROM ca_expenses')).map((r) => [String(r.zoho_expense_id), r]));
}

/** Books changed it: the imported copy follows (the order link and the TDS note stay). */
async function updateFromZoho(id, category, description, vendorName, amount, currencyCode, expenseDate) {
  await db.execute(
    'UPDATE ca_expenses SET category = :category, description = :description, vendor_name = :vendor_name, amount = :amount, currency_code = :currency_code, expense_date = :expense_date WHERE id = :id',
    { category, description, vendor_name: vendorName, amount, currency_code: currencyCode, expense_date: expenseDate, id }
  );
}

async function remove(id) {
  await db.execute('DELETE FROM ca_expenses WHERE id = :id', { id });
}

/** Whether a bank statement line is matched to this expense (it then is not removed automatically). */
async function isBankMatched(id) {
  return !!(await db.queryOne('SELECT 1 AS present FROM ca_bank_statement_lines WHERE matched_expense_id = :id LIMIT 1', { id }));
}

/**
 * Owner decision 2026-10-29 (order-wise import): the order whose buyer
 * enquiry reference or order reference appears, as a whole word, in a Books
 * expense's Reference # — or null when none or more than one does.
 */
async function orderIdForReference(reference) {
  const tokens = [...new Set(String(reference || '').split(/[\s,;]+/).map((t) => t.trim().toLowerCase()).filter(Boolean))];
  if (tokens.length === 0) return null;
  const params = Object.fromEntries(tokens.map((t, i) => [`t${i}`, t]));
  const marks = tokens.map((_, i) => `:t${i}`).join(',');
  const rows = await db.query(`SELECT id FROM orders WHERE LOWER(buyer_inquiry_ref) IN (${marks}) OR LOWER(order_reference) IN (${marks})`, params);
  const ids = [...new Set(rows.map((r) => Number(r.id)))];
  return ids.length === 1 ? ids[0] : null;
}

/**
 * Owner decision 2026-10-29: the expenses booked in Zoho Books and linked to
 * this order, each marked whether it counts as this order's cost. It does
 * not when it has the same amount and date as a cost already entered in the
 * app for this order (an order expense, an order cost or a supplier /
 * logistics tax invoice) — a possible duplicate for the CA to check — nor
 * when it is not in rupees. The counted ones are part of the order's cost,
 * profit, cash position and CAFIN (C5) — CAFIN change confirmed twice by
 * the owner, 2026-10-29.
 *
 * @return {Promise<object[]>} each with `counted` (bool) and `reason` (string|null)
 */
async function forOrderCounted(orderId) {
  const rows = await db.query(
    `SELECT amount_inr AS a, expense_date AS d FROM order_expenses WHERE order_id = :o1
     UNION ALL SELECT amount_inr, COALESCE(incurred_at, DATE(created_at)) FROM order_cost_entries WHERE order_id = :o2
     UNION ALL SELECT total_inr, bill_date FROM order_vendor_bills WHERE order_id = :o3 AND doc_kind = 'invoice'`,
    { o1: orderId, o2: orderId, o3: orderId }
  );
  const app = new Set(rows.map((r) => `${Number(r.a).toFixed(2)}|${r.d}`));
  return (await forOrder(orderId)).map((e) => {
    let reason = null;
    if (String(e.currency_code).toUpperCase() !== 'INR') {
      reason = 'not in rupees — not counted';
    } else if (app.has(`${Number(e.amount).toFixed(2)}|${e.expense_date}`)) {
      reason = 'possible duplicate of a cost entered in the app — not counted';
    }
    return { ...e, counted: reason === null, reason };
  });
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

module.exports = {
  existsByZohoId, insert, all, find, forOrder, linkToOrder, setTds, totalAll, availableFinancialYears, tdsSummary,
  allByZohoId, updateFromZoho, remove, isBankMatched, orderIdForReference, forOrderCounted,
};
