'use strict';

const db = require('../config/db');

/**
 * Port of App\Repositories\OrderExpenseRepository. Owner decisions
 * (2026-10-27): the order's actual expenses — travel, hotel, courier, ... —
 * each with its receipt, or a reason when no bill exists. The travel % of
 * FOB (order_travel_expenses) stays the provisional figure until real travel
 * expenses are recorded; a late voucher keeps its real expense_date.
 * docs/schema.sql Section CE.
 */
const CATEGORIES = {
  travel_fuel: 'Travel — fuel / tolls',
  travel_ticket: 'Travel — train / flight / bus',
  local_conveyance: 'Local conveyance (taxi / auto)',
  hotel: 'Hotel',
  food: 'Food',
  courier: 'Courier',
  samples: 'Samples',
  other: 'Other',
};

/** Categories that are travel — once any is recorded, it replaces the provisional travel %. */
const TRAVEL_CATEGORIES = ['travel_fuel', 'travel_ticket', 'local_conveyance', 'hotel', 'food'];

const PAID_BY = {
  company_account: 'Company account',
  company_card: 'Company card',
  staff: 'Staff (to reimburse)',
};

/** A voucher entered more than this many days after the expense shows as "added later". */
const LATE_AFTER_DAYS = 30;

async function create(orderId, data, userId) {
  const result = await db.execute(
    `INSERT INTO order_expenses (order_id, expense_date, category, details, amount_inr, paid_by, staff_name, receipt_file_id, no_receipt_reason, created_by)
     VALUES (:order_id, :expense_date, :category, :details, :amount_inr, :paid_by, :staff_name, :receipt_file_id, :no_receipt_reason, :created_by)`,
    {
      order_id: orderId,
      expense_date: data.expense_date,
      category: data.category,
      details: data.details,
      amount_inr: data.amount_inr,
      paid_by: data.paid_by,
      staff_name: data.staff_name ?? null,
      receipt_file_id: data.receipt_file_id ?? null,
      no_receipt_reason: data.no_receipt_reason ?? null,
      created_by: userId,
    }
  );
  return result.insertId;
}

async function find(id) {
  return db.queryOne('SELECT * FROM order_expenses WHERE id = :id', { id });
}

/** By expense date, with the recording user's name and an "added later" flag. */
async function forOrder(orderId) {
  return db.query(
    `SELECT e.*, u.name AS created_by_name,
            (DATEDIFF(DATE(e.created_at), e.expense_date) > ${LATE_AFTER_DAYS}) AS added_later
     FROM order_expenses e
     LEFT JOIN users u ON u.id = e.created_by
     WHERE e.order_id = :order_id
     ORDER BY e.expense_date, e.id`,
    { order_id: orderId }
  );
}

async function remove(id) {
  await db.execute('DELETE FROM order_expenses WHERE id = :id', { id });
}

async function summary(orderId) {
  const out = { total: 0, travel: 0, has_travel: false, count: 0, with_receipt: 0, to_reimburse: 0 };
  for (const e of await forOrder(orderId)) {
    const amount = parseFloat(e.amount_inr);
    out.total += amount;
    out.count += 1;
    if (TRAVEL_CATEGORIES.includes(e.category)) {
      out.travel += amount;
      out.has_travel = true;
    }
    if (e.receipt_file_id) out.with_receipt += 1;
    if (e.paid_by === 'staff') out.to_reimburse += amount;
  }
  return out;
}

module.exports = { CATEGORIES, TRAVEL_CATEGORIES, PAID_BY, LATE_AFTER_DAYS, create, find, forOrder, remove, summary };
