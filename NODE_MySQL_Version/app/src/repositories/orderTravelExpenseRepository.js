'use strict';

const db = require('../config/db');

/**
 * docs/schema.sql Section BW — export process step 11a: travel
 * expenses for factory visits, recorded as a percentage of the order's
 * FOB value. At most one row per order, created lazily the first time
 * it's recorded — same "lazy one-to-one row" pattern as
 * orderLcDetailRepository / orderInspectionFeeRepository.
 */

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_travel_expenses WHERE order_id = :order_id', { order_id: orderId });
}

async function upsert(orderId, fields) {
  await db.execute(
    `INSERT INTO order_travel_expenses (order_id, factory_distance_km, expense_pct, expense_amount, cost_entry_id, recorded_by)
     VALUES (:order_id, :factory_distance_km, :expense_pct, :expense_amount, :cost_entry_id, :recorded_by)
     ON DUPLICATE KEY UPDATE
        factory_distance_km = VALUES(factory_distance_km),
        expense_pct = VALUES(expense_pct),
        expense_amount = VALUES(expense_amount),
        cost_entry_id = VALUES(cost_entry_id),
        recorded_by = VALUES(recorded_by)`,
    {
      order_id: orderId,
      factory_distance_km: fields.factory_distance_km,
      expense_pct: fields.expense_pct,
      expense_amount: fields.expense_amount,
      cost_entry_id: fields.cost_entry_id,
      recorded_by: fields.recorded_by,
    }
  );
}

module.exports = { find, upsert };
