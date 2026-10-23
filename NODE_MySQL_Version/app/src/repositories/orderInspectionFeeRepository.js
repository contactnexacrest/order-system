'use strict';

const db = require('../config/db');

/**
 * docs/schema.sql Section BT — export process step 8a: who pays the
 * 3rd-party pre-shipment inspection fee for an order, and how much. At
 * most one row per order, created lazily the first time it's recorded
 * — most orders never need this tracked at all (most goods either
 * don't need 3rd-party inspection, or the client pays directly).
 */

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_inspection_fees WHERE order_id = :order_id', { order_id: orderId });
}

async function upsert(orderId, fields) {
  await db.execute(
    `INSERT INTO order_inspection_fees (order_id, fee_payer, fee_amount, cost_entry_id, recorded_by)
     VALUES (:order_id, :fee_payer, :fee_amount, :cost_entry_id, :recorded_by)
     ON DUPLICATE KEY UPDATE
        fee_payer = VALUES(fee_payer),
        fee_amount = VALUES(fee_amount),
        cost_entry_id = VALUES(cost_entry_id),
        recorded_by = VALUES(recorded_by)`,
    {
      order_id: orderId,
      fee_payer: fields.fee_payer,
      fee_amount: fields.fee_amount,
      cost_entry_id: fields.cost_entry_id,
      recorded_by: fields.recorded_by,
    }
  );
}

module.exports = { find, upsert };
