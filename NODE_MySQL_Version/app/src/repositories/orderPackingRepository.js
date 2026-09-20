'use strict';

const db = require('../config/db');

async function upsert(orderId, data) {
  await db.execute(
    `INSERT INTO order_packing
        (order_id, actual_quantity_packed, crate_count, total_net_weight_kg, total_gross_weight_kg,
         total_cbm, packing_date, shortfall_pct)
     VALUES (:order_id, :qty, :crates, :net, :gross, :cbm, :packing_date, :shortfall_pct)
     ON DUPLICATE KEY UPDATE
        actual_quantity_packed = VALUES(actual_quantity_packed),
        crate_count = VALUES(crate_count),
        total_net_weight_kg = VALUES(total_net_weight_kg),
        total_gross_weight_kg = VALUES(total_gross_weight_kg),
        total_cbm = VALUES(total_cbm),
        packing_date = VALUES(packing_date),
        shortfall_pct = VALUES(shortfall_pct)`,
    {
      order_id: orderId,
      qty: data.actual_quantity_packed || null,
      crates: data.crate_count || null,
      net: data.total_net_weight_kg || null,
      gross: data.total_gross_weight_kg || null,
      cbm: data.total_cbm || null,
      packing_date: data.packing_date || null,
      shortfall_pct: data.shortfall_pct || null,
    }
  );
}

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_packing WHERE order_id = :order_id', { order_id: orderId });
}

async function markComplete(orderId, userId) {
  await db.execute('UPDATE order_packing SET packing_complete_confirmed_at = NOW(), packing_complete_confirmed_by = :user_id WHERE order_id = :order_id', { user_id: userId, order_id: orderId });
}

module.exports = { upsert, find, markComplete };
