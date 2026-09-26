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

// ----------------------------------------------------------------
// CA / Accounting module (Phase 1) — INR actual settlement amounts.
// Gated on inr_actual_edit/inr_actual_delete in ordersController, not
// tied to the Mark Cleared actions above (see schema.sql comment on
// order_payment_status for why they're deliberately separate actions).
// ----------------------------------------------------------------

async function setAdvanceInrActual(orderId, amount, recordedBy) {
  await db.execute(
    'UPDATE order_payment_status SET advance_inr_actual = :amount, advance_inr_actual_recorded_at = NOW(), advance_inr_actual_recorded_by = :recorded_by WHERE order_id = :order_id',
    { amount, recorded_by: recordedBy, order_id: orderId }
  );
}

async function clearAdvanceInrActual(orderId) {
  await db.execute(
    'UPDATE order_payment_status SET advance_inr_actual = NULL, advance_inr_actual_recorded_at = NULL, advance_inr_actual_recorded_by = NULL WHERE order_id = :order_id',
    { order_id: orderId }
  );
}

async function setBalanceInrActual(orderId, amount, recordedBy) {
  await db.execute(
    'UPDATE order_payment_status SET balance_inr_actual = :amount, balance_inr_actual_recorded_at = NOW(), balance_inr_actual_recorded_by = :recorded_by WHERE order_id = :order_id',
    { amount, recorded_by: recordedBy, order_id: orderId }
  );
}

async function clearBalanceInrActual(orderId) {
  await db.execute(
    'UPDATE order_payment_status SET balance_inr_actual = NULL, balance_inr_actual_recorded_at = NULL, balance_inr_actual_recorded_by = NULL WHERE order_id = :order_id',
    { order_id: orderId }
  );
}

async function setFreightInrActual(orderId, amount, recordedBy) {
  await db.execute(
    'UPDATE order_payment_status SET freight_inr_actual = :amount, freight_inr_actual_recorded_at = NOW(), freight_inr_actual_recorded_by = :recorded_by WHERE order_id = :order_id',
    { amount, recorded_by: recordedBy, order_id: orderId }
  );
}

async function clearFreightInrActual(orderId) {
  await db.execute(
    'UPDATE order_payment_status SET freight_inr_actual = NULL, freight_inr_actual_recorded_at = NULL, freight_inr_actual_recorded_by = NULL WHERE order_id = :order_id',
    { order_id: orderId }
  );
}

/**
 * Every order with at least one cleared settlement leg, for the CA
 * module's settlement register (caRepository). Joined here rather than
 * in caRepository since this is still just order_payment_status data —
 * caRepository flattens the three legs into rows.
 */
async function clearedSettlements() {
  return db.query(
    `SELECT ops.*, o.buyer_inquiry_ref, o.client_id, c.company_legal_name, cur.code AS currency_code
     FROM order_payment_status ops
     JOIN orders o ON o.id = ops.order_id
     JOIN clients c ON c.id = o.client_id
     JOIN currencies cur ON cur.id = o.currency_id
     WHERE ops.advance_cleared_at IS NOT NULL
        OR ops.balance_cleared_at IS NOT NULL
        OR ops.freight_cleared_at IS NOT NULL
     ORDER BY o.id DESC`
  );
}

// ----------------------------------------------------------------
// CA / Accounting module (Phase 2) — assumed exchange rate (for the
// forex gain/loss shown in the register) and per-leg FIRC/eBRC
// references. Same inr_actual_edit gating as Phase 1's INR-actual
// fields — this is the same CA financial-data set, not a new
// permission tier.
// ----------------------------------------------------------------

async function setAssumedExchangeRate(orderId, rate, setBy) {
  await db.execute(
    'UPDATE order_payment_status SET assumed_exchange_rate = :rate, assumed_exchange_rate_set_at = NOW(), assumed_exchange_rate_set_by = :set_by WHERE order_id = :order_id',
    { rate, set_by: setBy, order_id: orderId }
  );
}

async function setAdvanceFirc(orderId, reference, receivedAt) {
  await db.execute(
    'UPDATE order_payment_status SET advance_firc_reference = :ref, advance_firc_received_at = :received_at WHERE order_id = :order_id',
    { ref: reference, received_at: receivedAt, order_id: orderId }
  );
}

async function setBalanceFirc(orderId, reference, receivedAt) {
  await db.execute(
    'UPDATE order_payment_status SET balance_firc_reference = :ref, balance_firc_received_at = :received_at WHERE order_id = :order_id',
    { ref: reference, received_at: receivedAt, order_id: orderId }
  );
}

async function setFreightFirc(orderId, reference, receivedAt) {
  await db.execute(
    'UPDATE order_payment_status SET freight_firc_reference = :ref, freight_firc_received_at = :received_at WHERE order_id = :order_id',
    { ref: reference, received_at: receivedAt, order_id: orderId }
  );
}

// ----------------------------------------------------------------
// CA / Accounting module (Phase 3) — marks a leg as pushed to Zoho
// Books. Written only by caSyncService, after a successful
// zohoBooksService.pushRevenuePayment() call.
// ----------------------------------------------------------------

async function setAdvanceZohoSync(orderId, zohoReference) {
  await db.execute(
    'UPDATE order_payment_status SET advance_zoho_synced_at = NOW(), advance_zoho_reference = :ref WHERE order_id = :order_id',
    { ref: zohoReference, order_id: orderId }
  );
}

async function setBalanceZohoSync(orderId, zohoReference) {
  await db.execute(
    'UPDATE order_payment_status SET balance_zoho_synced_at = NOW(), balance_zoho_reference = :ref WHERE order_id = :order_id',
    { ref: zohoReference, order_id: orderId }
  );
}

async function setFreightZohoSync(orderId, zohoReference) {
  await db.execute(
    'UPDATE order_payment_status SET freight_zoho_synced_at = NOW(), freight_zoho_reference = :ref WHERE order_id = :order_id',
    { ref: zohoReference, order_id: orderId }
  );
}

module.exports = {
  initializeForOrder, find, recordAdvanceReceived, markAdvanceCleared, setBalanceAmount,
  recordFreightReceived, markFreightCleared, recordBalanceReceived, markBalanceCleared,
  setAdvanceInrActual, clearAdvanceInrActual, setBalanceInrActual, clearBalanceInrActual,
  setFreightInrActual, clearFreightInrActual, clearedSettlements,
  setAssumedExchangeRate, setAdvanceFirc, setBalanceFirc, setFreightFirc,
  setAdvanceZohoSync, setBalanceZohoSync, setFreightZohoSync,
};
