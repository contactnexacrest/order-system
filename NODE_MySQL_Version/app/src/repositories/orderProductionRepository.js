'use strict';

const db = require('../config/db');

async function upsert(orderId, supplierId, startDate, expectedCompletion) {
  await db.execute(
    `INSERT INTO order_production (order_id, supplier_id, production_start_date, expected_completion_date)
     VALUES (:order_id, :supplier_id, :start_date, :expected_completion)
     ON DUPLICATE KEY UPDATE supplier_id = VALUES(supplier_id),
        production_start_date = VALUES(production_start_date),
        expected_completion_date = VALUES(expected_completion_date)`,
    { order_id: orderId, supplier_id: supplierId, start_date: startDate, expected_completion: expectedCompletion }
  );
}

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_production WHERE order_id = :order_id', { order_id: orderId });
}

async function markComplete(orderId, userId) {
  await db.execute(
    'UPDATE order_production SET production_complete_confirmed_at = NOW(), production_complete_confirmed_by = :user_id WHERE order_id = :order_id',
    { user_id: userId, order_id: orderId }
  );
}

module.exports = { upsert, find, markComplete };
