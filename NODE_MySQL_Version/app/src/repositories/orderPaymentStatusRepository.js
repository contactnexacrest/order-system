'use strict';

const db = require('../config/db');

async function initializeForOrder(orderId) {
  await db.execute('INSERT INTO order_payment_status (order_id) VALUES (:order_id)', { order_id: orderId });
}

async function find(orderId) {
  return db.queryOne('SELECT * FROM order_payment_status WHERE order_id = :order_id', { order_id: orderId });
}

async function recordAdvanceReceived(orderId, amount, receivedAt) {
  await db.execute(
    'UPDATE order_payment_status SET advance_amount = :amount, advance_remittance_received_at = :received_at WHERE order_id = :order_id',
    { amount, received_at: receivedAt, order_id: orderId }
  );
}

async function markAdvanceCleared(orderId, clearedAt, clearedBy) {
  await db.execute(
    'UPDATE order_payment_status SET advance_cleared_at = :cleared_at, advance_cleared_by = :cleared_by WHERE order_id = :order_id',
    { cleared_at: clearedAt, cleared_by: clearedBy, order_id: orderId }
  );
}

async function setBalanceAmount(orderId, balanceAmount, balanceDueDate) {
  await db.execute(
    'UPDATE order_payment_status SET balance_amount = :amount, balance_due_date = :due_date WHERE order_id = :order_id',
    { amount: balanceAmount, due_date: balanceDueDate, order_id: orderId }
  );
}

async function recordFreightReceived(orderId, amount, receivedAt) {
  await db.execute(
    'UPDATE order_payment_status SET freight_amount = :amount, freight_remittance_received_at = :received_at WHERE order_id = :order_id',
    { amount, received_at: receivedAt, order_id: orderId }
  );
}

async function markFreightCleared(orderId, clearedAt, clearedBy) {
  await db.execute(
    'UPDATE order_payment_status SET freight_cleared_at = :cleared_at, freight_cleared_by = :cleared_by WHERE order_id = :order_id',
    { cleared_at: clearedAt, cleared_by: clearedBy, order_id: orderId }
  );
}

async function recordBalanceReceived(orderId, amount, receivedAt) {
  await db.execute(
    'UPDATE order_payment_status SET balance_amount = :amount, balance_remittance_received_at = :received_at WHERE order_id = :order_id',
    { amount, received_at: receivedAt, order_id: orderId }
  );
}

async function markBalanceCleared(orderId, clearedAt, clearedBy) {
  await db.execute(
    'UPDATE order_payment_status SET balance_cleared_at = :cleared_at, balance_cleared_by = :cleared_by WHERE order_id = :order_id',
    { cleared_at: clearedAt, cleared_by: clearedBy, order_id: orderId }
  );
}

module.exports = {
  initializeForOrder, find, recordAdvanceReceived, markAdvanceCleared, setBalanceAmount,
  recordFreightReceived, markFreightCleared, recordBalanceReceived, markBalanceCleared,
};
