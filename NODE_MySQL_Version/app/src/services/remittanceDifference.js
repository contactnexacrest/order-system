'use strict';

const db = require('../config/db');

/**
 * Port of RemittanceDifference.php. Owner decision (2 Nov 2026, "18 yes"):
 * when a buyer remittance (advance, freight, balance or a payment milestone)
 * differs from what is due, the person recording it says why:
 *  - bank_charges: the banks deducted charges on the way. The leg counts as
 *    paid in full (received + charges); the charges become an order cost
 *    ("Bank Charges") and go to Zoho Books with the customer payment.
 *  - short: the buyer paid less (cleared later with a manager's reason, G-5).
 *  - excess: the buyer paid more.
 * A difference of more than 1% (at least 1 unit) on the first receipt of a
 * leg, or any amount above what is still due, must have a reason.
 */

const REASONS = {
  bank_charges: 'Bank charges deducted by the banks',
  short: 'Buyer paid less (short payment)',
  excess: 'Buyer paid more',
};

const round2 = (n) => Math.round(Number(n) * 100) / 100;
const num = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const money = (currency, v) => `${currency} ${num(v)}`.trim();
const pct = (v) => String(Number(Number(v).toFixed(2)));

async function maxBankChargesPct() {
  const value = parseFloat(await require('../repositories/companySettingsRepository').get('max_bank_charges_pct'));
  return value > 0 ? value : 3;
}

/**
 * @param due    what the leg asks for in total (order currency)
 * @param soFar  what is already credited to the leg (received + charges)
 */
function evaluate(label, currency, due, soFar, amount, reason, chargesInput, maxPct = 3) {
  reason = reason && REASONS[reason] ? reason : null;
  due = Number(due) || 0;
  if (due <= 0) {
    return { reason: null, charges: 0, excess: 0, remaining: 0 };
  }
  const remaining = round2(Math.max(0, due - (Number(soFar) || 0)));
  const diff = round2(remaining - amount);
  const tolerance = Math.max(1, due * 0.01);
  const lower = String(label).toLowerCase();
  const m = (v) => money(currency, v);

  if (reason === null) {
    if (((Number(soFar) || 0) <= 0.005 && Math.abs(diff) > tolerance) || diff < -0.005) {
      return { error: `The ${lower} still due is ${m(remaining)}. You entered ${m(amount)} — a difference of ${m(Math.abs(diff))}. Choose why it is different in the Reason list and save again.` };
    }
    return { reason: null, charges: 0, excess: 0, remaining };
  }
  if (reason === 'bank_charges') {
    if (diff <= 0.005) {
      return { error: `Bank charges apply only when less than the ${lower} still due arrived (${m(remaining)}). Nothing is missing here — choose "Amount as due — no difference".` };
    }
    const charges = chargesInput !== null && chargesInput !== undefined && Number(chargesInput) > 0 ? round2(chargesInput) : diff;
    if (charges > diff + 0.005) {
      return { error: `Bank charges of ${m(charges)} are more than the difference of ${m(diff)} between the ${lower} still due and what arrived.` };
    }
    const limit = round2(due * maxPct / 100);
    if (charges > limit + 0.005) {
      return { error: `Bank charges of ${m(charges)} are more than ${pct(maxPct)}% of the ${lower} due (${m(limit)}). If the buyer paid less, choose a "Buyer paid less" reason instead.` };
    }
    return { reason: 'bank_charges', charges, excess: 0, remaining };
  }
  if (reason === 'short') {
    if (diff <= 0.005) {
      return { error: `Nothing is short: ${m(amount)} covers the ${lower} still due (${m(remaining)}).` };
    }
    return { reason: 'short', charges: 0, excess: 0, remaining };
  }
  if (diff >= -0.005) {
    return { error: `${m(amount)} is not more than the ${lower} still due (${m(remaining)}) — choose another reason, or "Amount as due — no difference".` };
  }
  return { reason: 'excess', charges: 0, excess: round2(-diff), remaining };
}

/** What the screen and the success message say will happen. */
function outcome(leg, label, currency, ev, amount, rate) {
  const lower = String(label).toLowerCase();
  const m = (v) => money(currency, v);
  if (ev.reason === 'bank_charges') {
    return `Bank charges ${m(ev.charges)} = ₹${num(round2(ev.charges * rate))} recorded as an order cost — the ${lower} counts as received in full.`;
  }
  if (ev.reason === 'short') {
    return `Short by ${m(round2(ev.remaining - amount))} — ` + (leg === 'advance'
      ? 'record the rest when it arrives, or a manager clears the advance short and the difference is carried into the balance.'
      : 'record the rest when it arrives, or a manager clears it short with a reason.');
  }
  if (ev.reason === 'excess') {
    return `Excess ${m(ev.excess)} — ` + (leg === 'advance' ? 'the balance due goes down by the same amount.' : 'settle it with the buyer (adjust against a later payment or refund).');
  }
  return '';
}

/** The most "our bank's charges" one receipt may carry (₹) — a typo guard, not a business rule. */
const MAX_OWN_BANK_CHARGES_INR = 100000;

/**
 * Owner (2 Nov 2026): "Our bank's charges on this credit" — the inward-remittance fee our own
 * bank debits separately (in rupees, incl. GST). Optional: null when blank, else { value } or { error }.
 */
function postedOwnBankCharges(body) {
  const raw = String((body && body.own_bank_charges_inr) ?? '').trim();
  if (raw === '') {
    return null;
  }
  const v = Number(raw);
  if (!Number.isFinite(v) || v <= 0) {
    return { error: "Our bank's charges must be an amount in rupees above 0 — or leave the field empty." };
  }
  const r = round2(v);
  if (r > MAX_OWN_BANK_CHARGES_INR) {
    return { error: `Our bank's charges of ₹${num(r)} look too high for one credit (more than ₹${num(MAX_OWN_BANK_CHARGES_INR)}). Check the bank advice and enter the fee only.` };
  }
  return { value: r };
}

/** Owner rule D-212: what it is, for which order and party, the amount, the bank reference. */
function ownBankChargesDescription(order, orderId, label, chargesInr, bankRef) {
  const orderRef = (order && (order.buyer_inquiry_ref || order.order_reference)) || `#${orderId}`;
  return `Our bank's inward remittance charges on ${(order && order.company_legal_name) || 'the buyer'}'s ${String(label).toLowerCase()}`
    + ` remittance for order ${orderRef}, ₹${num(chargesInr)} incl. GST · bank ref ${String(bankRef).trim()}`;
}

/** Owner rule D-212: what it is, for which order and party, what it settles, the bank reference. */
function bankChargesDescription(order, orderId, label, received, due, charges, chargesInr, bankRef, reasonText = null) {
  const currency = (order && order.currency_code) || '';
  const orderRef = (order && (order.buyer_inquiry_ref || order.order_reference)) || `#${orderId}`;
  return `Bank charges deducted from ${(order && order.company_legal_name) || 'the buyer'}'s ${String(label).toLowerCase()} remittance for order ${orderRef}`
    + `: received ${money(currency, received)} of ${money(currency, due)} due, charges ${money(currency, charges)} (₹${num(chargesInr)}) · bank ref ${String(bankRef).trim()}`
    + (reasonText ? ` · Reason: ${reasonText}` : '');
}

/**
 * After the receipt is saved: the charges become an order cost with a
 * description built from the facts (D-212), linked to the receipt; the
 * reason is audited.
 */
async function afterReceipt(orderId, leg, milestone, receiptId, ev, amount, rate, receivedOn, bankRef, userId) {
  // Owner (2 Nov 2026): our own bank's fee on this credit, booked as an order cost now.
  if (ev && ev.own_charges_inr && receiptId) {
    const ownOrder = await require('../repositories/orderRepository').find(orderId);
    const ownLabel = require('./partPaymentService').label(leg, milestone);
    const ownInr = Number(ev.own_charges_inr);
    const ownDescription = ownBankChargesDescription(ownOrder, orderId, ownLabel, ownInr, bankRef);
    const ownCostId = await require('../repositories/orderCostEntryRepository').create(orderId, 'bank_charges', ownDescription, ownInr, String(receivedOn).slice(0, 10), userId, 'company_account');
    await db.execute('UPDATE order_payment_receipts SET own_bank_charges_inr = :a, own_bank_charges_cost_id = :c WHERE id = :id', { a: ownInr, c: ownCostId, id: receiptId });
    await require('../repositories/auditLogRepository').log(userId, 'REMITTANCE_OWN_BANK_CHARGES_RECORDED', 'order_payment_receipts', receiptId,
      leg + (milestone ? `:${milestone.seq}` : ''), null, String(ownInr), ownDescription);
  }
  if (!ev || !ev.reason || !receiptId) {
    return null;
  }
  const auditLogRepository = require('../repositories/auditLogRepository');
  const order = await require('../repositories/orderRepository').find(orderId);
  const partPaymentService = require('./partPaymentService');
  const label = partPaymentService.label(leg, milestone);
  const field = leg + (milestone ? `:${milestone.seq}` : '');
  const reasonText = ev.reason_text || null;
  await db.execute('UPDATE order_payment_receipts SET difference_reason_id = :rid, difference_reason_text = :rt, difference_reason_custom = :rc WHERE id = :id',
    { rid: ev.reason_id || null, rt: reasonText, rc: ev.custom ? 1 : 0, id: receiptId });
  if (ev.reason === 'bank_charges') {
    const chargesInr = round2(ev.charges * rate);
    const description = bankChargesDescription(order, orderId, label, amount, ev.remaining, ev.charges, chargesInr, bankRef, reasonText);
    const costId = await require('../repositories/orderCostEntryRepository').create(orderId, 'bank_charges', description, chargesInr, String(receivedOn).slice(0, 10), userId, 'company_account');
    await db.execute('UPDATE order_payment_receipts SET bank_charges_cost_id = :c WHERE id = :id', { c: costId, id: receiptId });
    await auditLogRepository.log(userId, 'REMITTANCE_BANK_CHARGES_RECORDED', 'order_payment_receipts', receiptId, field, null, String(chargesInr), description);
    return costId;
  }
  await auditLogRepository.log(userId, ev.reason === 'short' ? 'REMITTANCE_SHORT_RECORDED' : 'REMITTANCE_EXCESS_RECORDED', 'order_payment_receipts', receiptId,
    field, String(ev.remaining), String(amount), REASONS[ev.reason] + (reasonText ? ` — ${reasonText}` : ''));
  return null;
}

/**
 * The charges in rupees on a leg's (or milestone's) receipts — sent to Zoho Books with the payment.
 * D-244 (4 Nov 2026): includes the rupees the bank kept from the INR credited ("the bank deducted
 * its charges"), so the payment settles in full and the charges are counted once, as the cost.
 */
async function chargesInr(orderId, leg, milestoneId = null) {
  const row = await db.queryOne("SELECT COALESCE(SUM(COALESCE(bank_charges_inr, 0) + CASE WHEN rate_gap_choice = 'bank_charges' THEN COALESCE(rate_gap_inr, 0) ELSE 0 END), 0) AS t FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id <=> :m", { o: orderId, l: leg, m: milestoneId });
  return round2(row ? row.t : 0);
}

/** Owner follow-up (2 Nov 2026): how each admin-managed reason is treated. */
const TREATMENTS = REASONS;
/** The last drop-down option: a reason typed in by hand, with how to treat it. */
const OTHER = 'other';

/** The admin-managed reasons ("Why is it different?" drop-down), in their order. */
async function reasons(activeOnly = true) {
  const rows = await db.query(`SELECT id, label, treatment, sort_order, is_active FROM remittance_difference_reasons${activeOnly ? ' WHERE is_active = 1' : ''} ORDER BY sort_order, id`);
  return rows.map((r) => ({ id: Number(r.id), label: String(r.label), treatment: String(r.treatment), sort_order: Number(r.sort_order), is_active: Number(r.is_active) }));
}

/** The first active reason of a treatment (the list's own order) — or null. */
async function reasonIdFor(treatment) {
  const row = await db.queryOne('SELECT id FROM remittance_difference_reasons WHERE treatment = :t AND is_active = 1 ORDER BY sort_order, id LIMIT 1', { t: treatment });
  return row ? Number(row.id) : null;
}

/**
 * Reads the posted "Why is it different?" choice: '' (amount as due), a
 * reason id from the list, or 'other' with a typed reason and how to treat it.
 */
async function resolvePosted(body) {
  const none = { treatment: null, reason_id: null, reason_text: null, custom: false };
  const choice = String((body && body.difference_reason_choice) || '').trim();
  if (choice === '') {
    return none;
  }
  if (choice === OTHER) {
    const text = String((body && body.difference_reason_other) || '').replace(/\s+/g, ' ').trim();
    const treatment = String((body && body.difference_treatment) || '').trim();
    if (text.length < 10) {
      return { ...none, error: 'Type the reason the amount is different (at least 10 characters), or choose one from the list.' };
    }
    if (text.length > 255) {
      return { ...none, error: 'Keep the reason to 255 characters or fewer.' };
    }
    if (!TREATMENTS[treatment]) {
      return { ...none, error: 'Choose how to treat the difference: bank charges, buyer paid less, or buyer paid more.' };
    }
    return { treatment, reason_id: null, reason_text: text, custom: true };
  }
  if (!/^\d+$/.test(choice)) {
    return { ...none, error: 'Choose a reason from the list, or "Other — type the reason".' };
  }
  const row = await db.queryOne('SELECT id, label, treatment FROM remittance_difference_reasons WHERE id = :id AND is_active = 1', { id: Number(choice) });
  if (!row) {
    return { ...none, error: 'That reason is no longer in the list. Choose another one.' };
  }
  return { treatment: String(row.treatment), reason_id: Number(row.id), reason_text: String(row.label), custom: false };
}

/** The posted bank charges (order currency), or null to use the difference. */
function postedCharges(body) {
  const raw = String((body && body.bank_charges) || '').trim();
  return raw !== '' && !Number.isNaN(Number(raw)) ? Number(raw) : null;
}

/**
 * Checks the posted reason against the numbers in one go and carries the
 * reason (id and text) along for the receipt and its description.
 */
async function evaluatePosted(body, label, currency, due, soFar, amount, maxPct = null) {
  const p = await resolvePosted(body);
  if (p.error) {
    return { error: p.error };
  }
  const ev = evaluate(label, currency, due, soFar, amount, p.treatment, postedCharges(body), maxPct === null ? await maxBankChargesPct() : maxPct);
  if (ev.error) {
    return ev;
  }
  const own = postedOwnBankCharges(body);
  if (own && own.error) {
    return { error: own.error };
  }
  return { ...ev, reason_id: p.reason_id, reason_text: p.treatment !== null ? p.reason_text : null, custom: p.custom, own_charges_inr: own ? own.value : null };
}

/** Reasons typed in by hand, most used first, so Admin / Finance can add the frequent ones to the list. */
async function customReasonsUsed() {
  return db.query(
    `SELECT difference_reason_text AS reason, difference_reason AS treatment, COUNT(*) AS uses, MAX(created_at) AS last_used
       FROM order_payment_receipts WHERE difference_reason_custom = 1 AND difference_reason_text IS NOT NULL
      GROUP BY difference_reason_text, difference_reason ORDER BY uses DESC, last_used DESC LIMIT 200`
  );
}

module.exports = {
  REASONS, TREATMENTS, OTHER, maxBankChargesPct, evaluate, outcome, afterReceipt, bankChargesDescription, chargesInr,
  reasons, reasonIdFor, resolvePosted, postedCharges, evaluatePosted, customReasonsUsed,
  MAX_OWN_BANK_CHARGES_INR, postedOwnBankCharges, ownBankChargesDescription,
};
