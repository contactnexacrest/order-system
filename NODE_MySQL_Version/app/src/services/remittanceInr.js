'use strict';

const db = require('../config/db');
const fieldValidator = require('../helpers/fieldValidator');

/**
 * Port of RemittanceInr.php. Owner decision D-244 (4 Nov 2026, "C yes"): a
 * buyer remittance (advance, freight, balance, payment milestone) is recorded
 * with the rupees the bank actually credited, not a typed rate. The rate is
 * worked out from them (INR credited ÷ amount received). The rate printed on
 * the bank's advice is optional; when given, it checks the INR:
 *   gap = amount × bank's rate − INR credited
 *   |gap| ≤ ₹1 → saved as it is (rounding);
 *   |gap| > ₹1 → the person recording it says why:
 *     bank_charges — the bank deducted its charges: the gap is booked at once
 *                    as a Bank Charges order cost (rule D-212 description),
 *                    and the receipt is valued at the bank's rate, so the
 *                    charges are counted once (as the cost);
 *     typo         — something was typed wrong: nothing is saved;
 *     other        — another reason (at least 10 characters), saved on the receipt.
 * "INR not known yet" keeps the old way: the rate is typed, the INR stays
 * empty, and the CA register shows the amount at the recorded rate as an
 * estimate until the INR actual is entered.
 */

/** Up to this many rupees between amount × bank's rate and the INR credited is rounding. */
const TOLERANCE_INR = 1.0;

const CHOICES = {
  bank_charges: 'The bank deducted its charges — book the difference as Bank Charges',
  typo: 'I typed something wrong — let me correct it',
  other: 'Other reason — type it',
};

const round2 = (n) => Math.round(Number(n) * 100) / 100;
const round4 = (n) => Math.round(Number(n) * 10000) / 10000;
const fmt = (n, digits) => Number(n).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
const money = (currency, amount) => `${currency} ${fmt(amount, 2)}`.trim();
const isNumeric = (s) => /^\s*-?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?\s*$/i.test(String(s));

/** "At the bank's rate of ₹83.5000, USD 10,000.00 is ₹835,000.00, but ₹834,500.00 was credited — ₹500.00 less." */
function gapSentence(currency, amount, bankRate, inr, gap) {
  return `At the bank's rate of ₹${fmt(bankRate, 4)}, ${money(currency, amount)} is ₹${fmt(round2(amount * bankRate), 2)}, but ₹${fmt(inr, 2)} was credited — ₹${fmt(Math.abs(gap), 2)} ${gap > 0 ? 'less' : 'more'}.`;
}

/**
 * Reads the posted INR fields of one remittance form. `prefix` is the fields'
 * name prefix ('advance_', 'freight_', 'balance_', or '' on a milestone form).
 */
function fromPost(body, prefix, amount, currency) {
  const b = body || {};
  if (b[`${prefix}inr_unknown`]) {
    const rate = parseFloat(b[`${prefix}exchange_rate`] || 0);
    if (!(rate > 0)) {
      return { error: `You ticked "INR not known yet": enter the exchange rate (₹ per 1 ${currency}) — or untick it and enter the INR credited by the bank.` };
    }
    const err = fieldValidator.exchangeRate(rate);
    if (err) return { error: err };
    return { inr: null, rate: round4(rate), effective_rate: round4(rate), bank_rate: null, gap: null, choice: null, reason: null };
  }
  const raw = String(b[`${prefix}inr_credited`] ?? '').trim();
  if (raw === '' || !isNumeric(raw)) {
    return { error: 'Enter the INR credited by the bank (₹) — the rupees that reached our account, from the bank\'s credit advice. If it is not known yet, tick "INR not known yet — enter the rate".' };
  }
  const inr = round2(parseFloat(raw));
  const inrErr = fieldValidator.moneyAmount(inr, 'The INR credited by the bank');
  if (inrErr) return { error: inrErr };
  const effective = inr / amount;
  const effErr = fieldValidator.exchangeRate(effective, `The worked-out exchange rate (₹ ${fmt(inr, 2)} ÷ ${currency} ${fmt(amount, 2)})`);
  if (effErr) return { error: `${effErr} Check the amount received and the INR credited.` };
  const bankRaw = String(b[`${prefix}bank_rate`] ?? '').trim();
  let bankRate = null;
  if (bankRaw !== '') {
    if (!isNumeric(bankRaw)) {
      return { error: `The bank's rate on the advice must be a number (₹ per 1 ${currency}) — or leave it empty.` };
    }
    bankRate = round4(parseFloat(bankRaw));
    const bankErr = fieldValidator.exchangeRate(bankRate, "The bank's rate on the advice");
    if (bankErr) return { error: bankErr };
  }
  const gap = bankRate !== null ? round2(amount * bankRate - inr) : null;
  let choice = null;
  let reason = null;
  if (gap !== null && Math.abs(gap) > TOLERANCE_INR) {
    choice = String(b.rate_gap_choice ?? '').trim();
    const gapText = gapSentence(currency, amount, bankRate, inr, gap);
    if (choice === '') {
      return { error: `${gapText} Say why: the bank deducted its charges, a figure was typed wrong, or another reason.` };
    }
    if (choice === 'typo') {
      return { error: `${gapText} You said a figure was typed wrong: correct the amount, the INR credited or the bank's rate so they agree (within ₹1), then save again.` };
    }
    if (choice === 'bank_charges') {
      if (gap < 0) {
        return { error: `${gapText} The bank credited more than the amount at its rate, so it cannot be bank charges. Check the figures, or choose "Other reason".` };
      }
    } else if (choice === 'other') {
      reason = String(b.rate_gap_reason ?? '').replace(/\s+/g, ' ').trim();
      if ([...reason].length < 10) {
        return { error: 'Type the reason the INR credited differs from the bank\'s rate (at least 10 characters).' };
      }
      if ([...reason].length > 255) {
        return { error: 'Keep the reason to 255 characters or fewer.' };
      }
    } else {
      return { error: 'Choose why the INR credited differs from the bank\'s rate.' };
    }
  }
  // The bank kept its charges in rupees: the receipt is worth amount × the bank's rate, the charges are the cost.
  const bookRate = choice === 'bank_charges' ? (inr + gap) / amount : effective;
  return { inr, rate: round4(bookRate), effective_rate: round4(effective), bank_rate: bankRate, gap, choice, reason };
}

/** Rule D-212: what it is, for which order and party, the figures it comes from, the bank reference. */
function gapChargesDescription(order, orderId, label, amount, bankRate, inr, gap, bankRef) {
  const o = order || {};
  const currency = o.currency_code || '';
  const orderRef = o.buyer_inquiry_ref || o.order_reference || `#${orderId}`;
  return `Bank charges deducted in rupees from ${o.company_legal_name || 'the buyer'}'s ${String(label).toLowerCase()} remittance for order ${orderRef}`
    + `: ${money(currency, amount)} at the bank's rate ₹${fmt(bankRate, 4)} = ₹${fmt(round2(amount * bankRate), 2)}`
    + `, credited ₹${fmt(inr, 2)}, charges ₹${fmt(gap, 2)} · bank ref ${String(bankRef || '').trim()}`;
}

/** The receipt's D-244 INR columns, as named parameters. */
function inrParams(inr) {
  const i = inr || {};
  return { ic: i.inr ?? null, br: i.bank_rate ?? null, gi: i.gap ?? null, gc: i.choice ?? null, gr: i.reason ?? null };
}

/**
 * After the receipt row is written with its INR fields: for "the bank
 * deducted its charges", books the gap as a Bank Charges order cost linked
 * to the receipt; "other" is audited.
 */
async function afterReceipt(orderId, leg, milestone, receiptId, inr, amount, receivedOn, bankRef, userId) {
  if (!inr || !receiptId) {
    return null;
  }
  const auditLogRepository = require('../repositories/auditLogRepository');
  const what = leg + (milestone ? `:${milestone.seq}` : '');
  if (inr.choice === 'bank_charges') {
    const order = await require('../repositories/orderRepository').find(orderId);
    const label = require('./partPaymentService').label(leg, milestone);
    const description = gapChargesDescription(order, orderId, label, amount, Number(inr.bank_rate), Number(inr.inr), Number(inr.gap), bankRef);
    // Bank Charges cost entries are not sent to Zoho as expenses (29 Oct rule): the charges go with the customer payment.
    const costId = await require('../repositories/orderCostEntryRepository').create(orderId, 'bank_charges', description, Number(inr.gap), String(receivedOn).slice(0, 10), userId, 'company_account');
    await db.execute('UPDATE order_payment_receipts SET rate_gap_cost_id = :c WHERE id = :id', { c: costId, id: receiptId });
    await auditLogRepository.log(userId, 'REMITTANCE_INR_GAP_BANK_CHARGES', 'order_payment_receipts', receiptId, what, null, String(inr.gap), description);
    return costId;
  }
  if (inr.choice === 'other') {
    await auditLogRepository.log(userId, 'REMITTANCE_INR_GAP_EXPLAINED', 'order_payment_receipts', receiptId, what, inr.bank_rate !== null ? String(inr.bank_rate) : null, String(inr.inr), String(inr.reason));
  }
  return null;
}

/**
 * The leg's INR actual from its receipts: the sum of the rupees credited when
 * every receipt has them, else null (not known yet).
 */
function legInrActual(receipts) {
  if (!receipts || receipts.length === 0) {
    return null;
  }
  let sum = 0;
  for (const r of receipts) {
    if (r.inr_credited === null || r.inr_credited === undefined) {
      return null;
    }
    sum += Number(r.inr_credited);
  }
  return round2(sum);
}

/** What the success message says about the rupees. */
function statement(currency, amount, inr, rate) {
  if (!inr || inr.inr === null || inr.inr === undefined) {
    return `Received ${money(currency, amount)} at the exchange rate of ₹${fmt(rate, 4)} (≈ ₹${fmt(amount * rate, 2)}, estimated until the INR credited is known).`;
  }
  let text = `Received ${money(currency, amount)}; ₹${fmt(inr.inr, 2)} credited by the bank — exchange rate worked out ₹${fmt(inr.effective_rate, 4)}.`;
  if (inr.choice === 'bank_charges') {
    text += ` ₹${fmt(inr.gap, 2)} the bank kept (at its rate of ₹${fmt(inr.bank_rate, 4)}) is recorded as an order cost "Bank Charges".`;
  } else if (inr.choice === 'other') {
    text += ` Difference from the bank's rate: ₹${fmt(Math.abs(inr.gap), 2)} — ${inr.reason}.`;
  }
  return text;
}

/** The rupees on a leg's (or milestone's) receipts the bank kept, booked as Bank Charges (D-244). */
async function gapChargesInr(orderId, leg, milestoneId = null) {
  const row = await db.queryOne("SELECT COALESCE(SUM(rate_gap_inr), 0) AS t FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id <=> :m AND rate_gap_choice = 'bank_charges'", { o: orderId, l: leg, m: milestoneId });
  return round2(row ? row.t : 0);
}

module.exports = {
  TOLERANCE_INR, CHOICES, fromPost, gapSentence, gapChargesDescription, inrParams, afterReceipt, legInrActual, statement, gapChargesInr,
};
