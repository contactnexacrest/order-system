'use strict';

/**
 * Owner rule D-212 (2026-10-31): every money record carries a description
 * saying what it is, for which order and party, what it settles and its
 * bank reference — built here from the facts, never typed, so it is never
 * blank or wrong. Port of App\Services\PaymentDescription (same wording).
 */
const documentRepository = require('../repositories/documentRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderRepository = require('../repositories/orderRepository');

const LEG_LABELS = { advance: 'Advance', balance: 'Balance', freight: 'Freight' };

const money = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** 84.1 → "84.10", 83.3333 → "83.3333": two decimals at least, the rate as recorded otherwise. */
function rate(value) {
  const text = Number(value).toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
  return text.includes('.') && text.split('.')[1].length >= 2 ? text : Number(value).toFixed(2);
}

/** 5.0 → "5", 7.5 → "7.5". */
function percent(value) {
  return Number(value).toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

/** Batch 2: the pre-shipment inspection fee, naming the agency and its invoice. */
function inspectionFee(agency, invoiceNo) {
  return `Pre-shipment inspection fee — ${String(agency).trim()}, invoice ${String(invoiceNo).trim()}`;
}

/** Batch 2: the interest on the bank loan taken against the LC, with the LC and the loan it was charged on. */
function lcLoanInterest(lcNumber, loanAmount, interestRate) {
  const parts = ['Bank loan interest (LC order)'];
  if (lcNumber !== null && lcNumber !== undefined && String(lcNumber).trim() !== '') {
    parts.push(`LC ${String(lcNumber).trim()}`);
  }
  if (loanAmount !== null && loanAmount !== undefined && Number(loanAmount) > 0) {
    parts.push(`loan ₹${money(loanAmount)}${interestRate !== null && interestRate !== undefined && Number(interestRate) > 0 ? ` at ${percent(interestRate)}%` : ''}`);
  }
  return parts.join(' · ');
}

/** Batch 2: the provisional travel cost, with how it was worked out. */
function travelExpenses(distanceKm, pct, currency, fobTotal, fxRate) {
  return `Travel expenses (factory visits) — ${distanceKm !== null && distanceKm !== undefined ? `${percent(distanceKm)} km to the factory, ` : ''}${percent(pct)}% of the order value `
    + `${currency} ${money(fobTotal)}${currency !== 'INR' ? ` at ₹${rate(fxRate)}` : ''}`;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const ymd = (d) => String(d).slice(0, 10).split('-').map(Number);

/** 2026-10-01 … 2026-12-31 → "Oct–Dec 2026"; one month → "Oct 2026"; across years → "Dec 2026–Jan 2027". */
function periodLabel(from, to) {
  const [fy, fm] = ymd(from);
  const [ty, tm] = ymd(to);
  if (fy === ty && fm === tm) return `${MONTHS[fm - 1]} ${fy}`;
  return fy === ty ? `${MONTHS[fm - 1]}–${MONTHS[tm - 1]} ${ty}` : `${MONTHS[fm - 1]} ${fy}–${MONTHS[tm - 1]} ${ty}`;
}

/**
 * Owner decision 2026-10-31 (GST under LUT): a GST refund the bank
 * credited — "GST refund under LUT, Oct–Dec 2026, claim AA27… · provisional
 * · ₹185,400.00 credited 12 Feb 2027 · bank ref RFD05-…". credit: a
 * gstRefundRepository credit row with its claim's period_from, period_to
 * and claim_arn.
 */
function gstRefund(credit) {
  const { PARTS } = require('../repositories/gstRefundRepository');
  const [y, m, d] = ymd(credit.credited_on);
  return `GST refund under LUT, ${periodLabel(credit.period_from, credit.period_to)}, claim ${credit.claim_arn}`
    + ` · ${PARTS[credit.part] || credit.part} · ₹${money(credit.amount)} credited ${d} ${MONTHS[m - 1]} ${y} · bank ref ${credit.bank_reference}`;
}

/** What the payment settles: its invoice in Zoho, or, for an advance not yet applied, the Proforma Invoice it was asked for. */
async function against(orderId, leg, invoiceNumber) {
  if (invoiceNumber !== null && invoiceNumber !== undefined && invoiceNumber !== '') {
    return `${leg === 'freight' ? 'Debit Note ' : 'Commercial Invoice '}${invoiceNumber}`;
  }
  if (leg === 'advance') {
    const pi = await documentRepository.findLatestForOrderAndTypeCode(orderId, 'PI');
    return pi && pi.document_reference ? `Proforma Invoice ${pi.document_reference}` : null;
  }
  return null;
}

/**
 * A payment received from the buyer (advance, balance, freight or the
 * money paid under an LC), as it goes to Zoho Books. invoiceNumber: the
 * Zoho invoice it is applied to (CI, or the Debit Note for freight), when
 * there is one.
 */
async function buyerPayment(orderId, leg, invoiceNumber = null) {
  const order = (await orderRepository.find(orderId)) || {};
  const currency = String(order.currency_code || '');
  const head = `${leg === 'lc' ? 'LC money' : (LEG_LABELS[leg] || leg.charAt(0).toUpperCase() + leg.slice(1))} received from ${order.company_legal_name || 'the buyer'}`
    + ` for order ${order.buyer_inquiry_ref || order.order_reference || `#${orderId}`}`;
  const parts = [head];
  let bankRef;
  if (leg === 'lc') {
    const lc = (await orderLcDetailRepository.find(orderId)) || {};
    if (lc.lc_number) {
      parts.push(`LC ${lc.lc_number}${lc.lc_bank_name ? ` (${lc.lc_bank_name})` : ''}`);
    }
    if (lc.lc_received_amount !== null && lc.lc_received_amount !== undefined) {
      parts.push(`${currency} ${money(lc.lc_received_amount)}, ₹${money(lc.lc_inr_credited)} credited`
        + (Number(lc.lc_bank_charges_inr || 0) > 0 ? ` after ₹${money(lc.lc_bank_charges_inr)} bank charges` : ''));
    }
    bankRef = lc.lc_bank_reference;
  } else {
    const ops = (await orderPaymentStatusRepository.find(orderId)) || {};
    if (ops[`${leg}_amount`] !== null && ops[`${leg}_amount`] !== undefined) {
      const r = ops[`${leg}_exchange_rate`];
      parts.push(`${currency} ${money(ops[`${leg}_amount`])}${r !== null && r !== undefined ? ` at ₹${rate(r)}` : ''}`);
    }
    bankRef = ops[`${leg}_bank_reference`];
  }
  const what = await against(orderId, leg, invoiceNumber);
  if (what !== null) {
    parts.push(`against ${what}`);
  }
  if (bankRef) {
    parts.push(`bank ref ${bankRef}`);
  }
  return parts.join(' · ');
}

module.exports = { buyerPayment, inspectionFee, lcLoanInterest, travelExpenses, periodLabel, gstRefund };
