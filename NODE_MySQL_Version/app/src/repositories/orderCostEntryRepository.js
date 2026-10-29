'use strict';

const db = require('../config/db');

/**
 * Order Profitability Sheet (docs/schema.sql Section AP) — manually
 * recorded cost lines not already captured elsewhere in the schema. See
 * orderProfitabilityService for how these combine with the
 * automatically-pulled supplier/freight/insurance costs into a total.
 */
const CATEGORIES = {
  ecgc_insurance: 'ECGC Insurance',
  due_diligence: 'Buyer Due Diligence',
  inland_transport: 'Inland Transportation',
  cha_charges: 'CHA Charges',
  documentation: 'Documentation',
  port_charges: 'Port Charges',
  bank_charges: 'Bank Charges',
  commission: 'Commission',
  packing_crates: 'Packing / Wooden Crates',
  bank_loan_interest: 'Bank Loan Interest (LC order)',
  inspection_fee: 'Pre-Shipment Inspection Fee',
  travel_expenses: 'Travel Expenses (Factory Visits)',
  other: 'Other',
};

/**
 * Owner decision 2026-10-29: the real paid costs that go to Zoho Books
 * as expenses. The others never go: travel % is only a budget, bank loan
 * interest and bank charges the CA books from the bank statement, and
 * CHA / inland transport / port charges belong on logistics partner bills
 * (which go to Books themselves).
 */
const ZOHO_CATEGORIES = ['ecgc_insurance', 'due_diligence', 'documentation', 'commission', 'packing_crates', 'inspection_fee', 'other'];

/** Why a category does not go to Zoho Books — shown on the order page. */
const NOT_IN_ZOHO = {
  inland_transport: 'Not sent to Zoho Books: record transport as a logistics partner bill instead.',
  cha_charges: 'Not sent to Zoho Books: record CHA charges as a logistics partner bill instead.',
  port_charges: 'Not sent to Zoho Books: record port charges as a logistics partner bill instead.',
  bank_charges: 'Not sent to Zoho Books: the CA books bank charges from the bank statement.',
  bank_loan_interest: 'Not sent to Zoho Books: the CA books loan interest from the bank statement.',
  travel_expenses: 'Not sent to Zoho Books: a budget only — real travel goes as order expenses.',
};

/** What a cost was paid from (owner decision 2026-10-29). */
const PAID_FROM = { company_account: 'Company account', company_card: 'Company card' };

async function create(orderId, category, description, amountInr, incurredAt, recordedBy, paidFrom = null) {
  const result = await db.execute(
    `INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, paid_from, recorded_by)
     VALUES (:order_id, :category, :description, :amount_inr, :incurred_at, :paid_from, :recorded_by)`,
    {
      order_id: orderId,
      category,
      description,
      amount_inr: amountInr,
      incurred_at: incurredAt,
      paid_from: paidFrom,
      recorded_by: recordedBy,
    }
  );
  return result.insertId;
}

/** Newest first, with the recording user's name joined in. */
async function forOrder(orderId) {
  return db.query(
    `SELECT oce.*, u.name AS recorded_by_name
     FROM order_cost_entries oce
     JOIN users u ON u.id = oce.recorded_by
     WHERE oce.order_id = :order_id
     ORDER BY oce.incurred_at DESC, oce.id DESC`,
    { order_id: orderId }
  );
}

async function totalForOrder(orderId) {
  const row = await db.queryOne(
    'SELECT COALESCE(SUM(amount_inr), 0) AS total FROM order_cost_entries WHERE order_id = :order_id',
    { order_id: orderId }
  );
  return parseFloat(row.total);
}

async function find(id) {
  return db.queryOne('SELECT * FROM order_cost_entries WHERE id = :id', { id });
}

async function remove(id) {
  await db.execute('DELETE FROM order_cost_entries WHERE id = :id', { id });
}

/**
 * docs/schema.sql Section BR — keeps the single bank_loan_interest cost
 * entry for an order's LC tracking in sync with whatever amount is
 * currently recorded there, rather than creating a new entry on every
 * edit.
 */
async function updateAmount(id, amountInr, description) {
  await db.execute(
    'UPDATE order_cost_entries SET amount_inr = :amount_inr, description = :description WHERE id = :id',
    { amount_inr: amountInr, description, id }
  );
}

/** The inspection fee's own form says what it was paid from (owner decision 2026-10-29). */
async function setPaidFrom(id, paidFrom) {
  await db.execute('UPDATE order_cost_entries SET paid_from = :paid_from WHERE id = :id', { paid_from: paidFrom, id });
}

module.exports = { CATEGORIES, ZOHO_CATEGORIES, NOT_IN_ZOHO, PAID_FROM, create, forOrder, totalForOrder, find, remove, updateAmount, setPaidFrom };
