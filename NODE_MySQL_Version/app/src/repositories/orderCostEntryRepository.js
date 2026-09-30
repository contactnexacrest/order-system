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
  other: 'Other',
};

async function create(orderId, category, description, amountInr, incurredAt, recordedBy) {
  const result = await db.execute(
    `INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by)
     VALUES (:order_id, :category, :description, :amount_inr, :incurred_at, :recorded_by)`,
    {
      order_id: orderId,
      category,
      description,
      amount_inr: amountInr,
      incurred_at: incurredAt,
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

module.exports = { CATEGORIES, create, forOrder, totalForOrder, find, remove };
