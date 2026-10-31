'use strict';

const db = require('../config/db');

// QA-5 ORD-05: optional executor lets this join the caller's transaction
// (order creation — see ordersController.store()), defaulting to the plain
// pool for every other existing call site.
async function initializeForOrder(orderId, executor = db) {
  await executor.execute('INSERT INTO order_payment_status (order_id) VALUES (:order_id)', { order_id: orderId });
}

/**
 * Owner report (2026-10-27, blocker): the balance still due is what is left
 * of the FOB order value after the advance the bank ACTUALLY received —
 * never FOB × balance % on its own. Before any advance is recorded it falls
 * back to the advance due by the payment terms.
 */
// D-215 (5a, owner confirmed twice): `milestones` is what the order's payment milestones take off the balance —
// the milestones received (what is owed now) or, for the planned balance, all of them.
function balanceDue(fobTotal, advancePct, payment, milestones = 0) {
  const fob = parseFloat(fobTotal) || 0;
  const advance = payment && payment.advance_amount !== null && payment.advance_amount !== undefined
    ? parseFloat(payment.advance_amount)
    : Math.round(fob * (parseFloat(advancePct) || 0)) / 100;
  return Math.max(0, Math.round((fob - advance - (parseFloat(milestones) || 0)) * 100) / 100);
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

/** Owner rule D-212: the bank reference of a buyer remittance (advance / balance / freight). */
async function setLegBankReference(orderId, leg, reference) {
  if (!EXCHANGE_RATE_LEGS.includes(leg)) {
    throw new Error(`Unknown payment leg: ${leg}`);
  }
  await db.execute(`UPDATE order_payment_status SET ${leg}_bank_reference = :ref WHERE order_id = :order_id`, { ref: String(reference).trim(), order_id: orderId });
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
    `SELECT ops.*, o.buyer_inquiry_ref, o.client_id, o.company_legal_name, o.currency_code,
            (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
     FROM order_payment_status ops
     JOIN orders o ON o.id = ops.order_id
     JOIN clients c ON c.id = o.client_id
     WHERE ops.advance_cleared_at IS NOT NULL
        OR ops.balance_cleared_at IS NOT NULL
        OR ops.freight_cleared_at IS NOT NULL
     ORDER BY o.id DESC`
  );
}

/** Every buyer payment leg (advance/balance/freight) cleared on the given date, for the "today's incoming payments" report. */
async function clearedOnDate(date) {
  return db.query(
    `SELECT ops.*, o.order_reference, o.company_legal_name, o.currency_code
     FROM order_payment_status ops
     JOIN orders o ON o.id = ops.order_id
     WHERE DATE(ops.advance_cleared_at) = :date1
        OR DATE(ops.balance_cleared_at) = :date2
        OR DATE(ops.freight_cleared_at) = :date3
     ORDER BY o.id DESC`,
    { date1: date, date2: date, date3: date }
  );
}

// ----------------------------------------------------------------
// CA / Accounting module (Phase 2, Batch 3 #3) — one exchange rate per
// settlement leg (for the forex gain/loss shown in the register) and
// per-leg FIRC/eBRC references. Same inr_actual_edit gating as Phase
// 1's INR-actual fields — this is the same CA financial-data set, not a
// new permission tier.
// ----------------------------------------------------------------

const EXCHANGE_RATE_LEGS = ['advance', 'balance', 'freight'];

async function setLegExchangeRate(orderId, leg, rate, setBy) {
  if (!EXCHANGE_RATE_LEGS.includes(leg)) {
    throw new Error(`Unknown settlement leg: ${leg}`);
  }
  await db.execute(
    `UPDATE order_payment_status SET ${leg}_exchange_rate = :rate, ${leg}_exchange_rate_set_at = NOW(), ${leg}_exchange_rate_set_by = :set_by WHERE order_id = :order_id`,
    { rate, set_by: setBy, order_id: orderId }
  );
}

/**
 * QA Round 6, R6-N22: a remittance and its exchange rate are saved together
 * or not at all. Owner rule D-212: with the bank reference it came with.
 */
async function recordLegReceivedWithRate(orderId, leg, amount, receivedAt, rate, setBy, bankReference = null) {
  if (!EXCHANGE_RATE_LEGS.includes(leg)) {
    throw new Error(`Unknown settlement leg: ${leg}`);
  }
  return db.transaction(async (c) => {
    // QA Round 6, R6-N17: a second click with the same details (amount, day
    // received, rate) records nothing more — the row is locked, so of two
    // clicks at the same moment the second waits and sees the first one's
    // values. Port of OrderPaymentStatusRepository::legAlreadyRecorded().
    const current = await c.queryOne(
      `SELECT ${leg}_amount AS amount, DATE_FORMAT(${leg}_remittance_received_at, '%Y-%m-%d') AS received_on, ${leg}_exchange_rate AS rate
       FROM order_payment_status WHERE order_id = :order_id FOR UPDATE`,
      { order_id: orderId }
    );
    if (current && current.amount !== null && current.rate !== null
      && Math.abs(Number(current.amount) - Math.round(Number(amount) * 100) / 100) < 0.005
      && String(current.received_on) === String(receivedAt).slice(0, 10)
      && Math.abs(Number(current.rate) - Math.round(Number(rate) * 10000) / 10000) < 0.00005) {
      return false;
    }
    await c.execute(
      `UPDATE order_payment_status SET ${leg}_amount = :amount, ${leg}_remittance_received_at = :received_at WHERE order_id = :order_id`,
      { amount, received_at: receivedAt, order_id: orderId }
    );
    if (bankReference !== null) {
      await c.execute(`UPDATE order_payment_status SET ${leg}_bank_reference = :ref WHERE order_id = :order_id`, { ref: String(bankReference).trim(), order_id: orderId });
    }
    await c.execute(
      `UPDATE order_payment_status SET ${leg}_exchange_rate = :rate, ${leg}_exchange_rate_set_at = NOW(), ${leg}_exchange_rate_set_by = :set_by WHERE order_id = :order_id`,
      { rate, set_by: setBy, order_id: orderId }
    );
    return true;
  });
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

/**
 * Zoho integration Stage 2 — the leg's Books payment has been applied to
 * its invoice (advance and balance to the Commercial Invoice, freight to
 * the Debit Note). Written only by caSyncService.
 */
async function setLegZohoApplied(orderId, leg) {
  if (!['advance', 'balance', 'freight'].includes(leg)) {
    throw new Error(`Unknown payment leg ${leg}.`);
  }
  await db.execute(`UPDATE order_payment_status SET ${leg}_zoho_applied_at = NOW() WHERE order_id = :order_id`, { order_id: orderId });
}

module.exports = {
  recordLegReceivedWithRate,
  initializeForOrder, find, balanceDue, recordAdvanceReceived, markAdvanceCleared, setBalanceAmount,
  recordFreightReceived, markFreightCleared, recordBalanceReceived, markBalanceCleared,
  setAdvanceInrActual, clearAdvanceInrActual, setBalanceInrActual, clearBalanceInrActual,
  setFreightInrActual, clearFreightInrActual, clearedSettlements, clearedOnDate,
  setLegExchangeRate, setLegBankReference, setAdvanceFirc, setBalanceFirc, setFreightFirc,
  setAdvanceZohoSync, setBalanceZohoSync, setFreightZohoSync, setLegZohoApplied,
};
