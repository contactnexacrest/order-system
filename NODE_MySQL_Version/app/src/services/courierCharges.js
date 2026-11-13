'use strict';

/**
 * Owner request (3 Nov 2026): Step 13f (Close Order — document despatch) takes the courier charges for
 * the original document set at the same time as the tracking number, so the cost is never forgotten. When
 * charges are entered, an order cost "Courier Charges" is booked at once, described from the facts (owner
 * rule D-212), paid from the account chosen (Company account by default), and goes to Zoho Books at the
 * next sync like any other cost entry. Port of PHP App\Services\CourierCharges.
 */
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const { round2 } = require('../helpers/money'); // HNT-L01: PHP round()

const MAX_INR = 100000;

const num = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Reads the optional courier fields; null when no charges were entered, a string when invalid. */
function parse(body) {
  const raw = String((body && body.courier_charges_inr) ?? '').trim();
  const company = String((body && body.courier_company) ?? '').trim().slice(0, 100);
  const paidFrom = String((body && body.courier_paid_from) ?? 'company_account');
  if (raw === '') return null;
  const n = Number(raw);
  if (!Number.isFinite(n) || n < 0) return 'Courier charges must be a number of rupees (0 or more), or leave the box blank.';
  const amount = round2(n);
  if (amount > MAX_INR) {
    return `Courier charges of ₹${num(amount)} look too high for one document courier (more than ₹${num(MAX_INR)}). Check the courier bill.`;
  }
  if (amount <= 0) return null;
  if (!orderCostEntryRepository.PAID_FROM[paidFrom]) return 'Choose what the courier charges were paid from.';
  return { amount, company, paid_from: paidFrom };
}

/** Owner rule D-212: what it is, for which order and party, who carried it and the AWB. */
function description(order, orderId, courier, tracking, amount) {
  const orderRef = (order && (order.buyer_inquiry_ref || order.order_reference)) || `#${orderId}`;
  const buyer = (order && order.company_legal_name) || 'the buyer';
  const carrier = String(courier || '').trim() !== '' ? `${String(courier).trim()} AWB ` : 'AWB ';
  return `Courier charges for the original document set of order ${orderRef} to ${buyer} — ${carrier}${String(tracking).trim()}, ₹${num(amount)} incl. GST`;
}

/** Books the cost and audits it; returns the cost entry id. */
async function book(order, orderId, charges, tracking, userId) {
  const text = description(order, orderId, charges.company, tracking, charges.amount);
  const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
  const costId = await orderCostEntryRepository.create(orderId, 'courier', text, charges.amount, today, userId, charges.paid_from);
  await auditLogRepository.log(userId, 'COURIER_CHARGES_RECORDED', 'order_cost_entries', costId, 'amount_inr', null, charges.amount.toFixed(2), text);
  return costId;
}

module.exports = { MAX_INR, parse, description, book };
