'use strict';

const db = require('../config/db');
const orderRepository = require('../repositories/orderRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');

/**
 * Port of App\Services\BalanceDueService. Gap list G-2 (owner, 1 Nov
 * 2026, register D-218): a balance paid after the BL — the order's balance
 * option "against BL", and every Letter of Credit order — has no due date
 * until the scanned BL is sent to the buyer (Step 11b). From that day it is
 * due within the order's balance days. A "before shipment" balance keeps no
 * fixed date (it is due on shipment readiness).
 */

function dueAfterBl(order) {
  return (order && order.balance_trigger_option === 'B_AGAINST_BL') || (order && order.payment_method === 'LC');
}

function addDays(ymd, days) {
  const d = new Date(`${ymd}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

/** Sets the balance due date from the day the scanned BL was sent. Returns it, or null when not applicable. */
async function startFromScannedBl(orderId, sentOn) {
  const order = await orderRepository.find(orderId);
  const payment = await orderPaymentStatusRepository.find(orderId);
  if (!order || !payment || !dueAfterBl(order) || payment.balance_cleared_at !== null) {
    return null;
  }
  const days = order.balance_days !== null && order.balance_days !== undefined ? parseInt(order.balance_days, 10) : 7;
  const due = addDays(sentOn, days);
  await db.execute('UPDATE order_payment_status SET balance_due_date = :d WHERE order_id = :o', { d: due, o: orderId });
  return due;
}

module.exports = { dueAfterBl, startFromScannedBl };
