'use strict';

/**
 * Owner decision D-395 (20 Nov 2026, mockup Option B "needs attention first"):
 * the figures on the CA / Accounting home page (/ca). Read only — nothing here
 * changes a record; every figure comes from data the CA pages already show.
 *
 *  - attention(): the "Needs attention" boxes, each shown only to a person who
 *    may open the page it links to (same gates as the routes: ca_reports
 *    level, ca_module_manage);
 *  - registerSummary() / filterRegister(): the INR Settlement Register's
 *    summary row and its quick filters (All · FIRC pending · Not in Zoho ·
 *    Estimated only).
 *
 * PHP twin: PHP_MySQL_Version/app/src/Services/CaOverviewService.php
 */

const db = require('../config/db');
const { round2, format2 } = require('../helpers/money');

const FILTERS = ['all', 'firc', 'zoho', 'estimated'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** A register row whose FIRC / eBRC proof is overdue (the row's own flag). */
function isFircPending(r) {
  return !!r.firc_pending;
}

/** A register row whose Zoho column reads "Pending": rupees recorded, not yet in Zoho Books. */
function isNotInZoho(r) {
  return r.inr_actual != null && r.zoho_synced_at == null;
}

/** A register row shown at the recorded rate only (INR credited not recorded yet). */
function isEstimated(r) {
  return r.inr_actual == null && r.inr_estimated != null;
}

function normaliseFilter(show) {
  return FILTERS.includes(show) ? show : 'all';
}

function filterRegister(rows, show) {
  const test = { firc: isFircPending, zoho: isNotInZoho, estimated: isEstimated }[show];
  return test ? rows.filter(test) : rows;
}

/** The summary row above the register, over the rows the person may see. */
function registerSummary(rows) {
  let inr = 0;
  let forex = 0;
  let forexKnown = false;
  let firc = 0;
  let zoho = 0;
  let est = 0;
  for (const r of rows) {
    inr += Number(r.inr_actual || 0);
    if (r.forex_gain_loss != null) {
      forex += Number(r.forex_gain_loss);
      forexKnown = true;
    }
    if (isFircPending(r)) firc += 1;
    if (isNotInZoho(r)) zoho += 1;
    if (isEstimated(r)) est += 1;
  }
  return {
    legs: rows.length,
    inr_credited: round2(inr),
    forex_net: round2(forex),
    forex_known: forexKnown,
    firc_pending: firc,
    not_in_zoho: zoho,
    estimated: est,
  };
}

/** Bank statement lines matched to neither a receipt nor an expense. */
async function unmatchedBankLines() {
  const row = await db.queryOne(
    'SELECT COUNT(*) AS n FROM ca_bank_statement_lines WHERE matched_order_id IS NULL AND matched_expense_id IS NULL'
  );
  return Number(row ? row.n : 0);
}

/** 'YYYY-MM' → the deposit date: 7th of the next month; March → 30 April. */
function tdsDueDate(month) {
  let y = parseInt(month.slice(0, 4), 10);
  let m = parseInt(month.slice(5, 7), 10);
  if (m === 3) return `${String(y).padStart(4, '0')}-04-30`;
  m += 1;
  if (m === 13) {
    m = 1;
    y += 1;
  }
  return `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}-07`;
}

/** TDS on the expenses of the month of `today` and the date it must be deposited by. */
async function tdsForMonth(today) {
  const month = today.slice(0, 7);
  const row = await db.queryOne(
    `SELECT COALESCE(SUM(tds_amount), 0) AS t FROM ca_expenses
     WHERE is_tds_applicable = 1 AND DATE_FORMAT(expense_date, '%Y-%m') = :month`,
    { month }
  );
  return { amount: round2(Number(row ? row.t : 0)), month, due: tdsDueDate(month) };
}

/** 'YYYY-MM-DD' → 'j M Y' as PHP prints it, e.g. '7 Dec 2026'. */
function shortDay(iso) {
  return `${parseInt(iso.slice(8, 10), 10)} ${MONTHS[parseInt(iso.slice(5, 7), 10) - 1]} ${iso.slice(0, 4)}`;
}

/** Export-benefit claims not yet received, within the person's CA reports level (as on Export Benefits). */
async function benefitClaimsWaiting() {
  const reportAccess = require('./reportAccess');
  let benefits = await require('../repositories/caExportBenefitRepository').all();
  const level = await reportAccess.level('ca_reports');
  if (level === reportAccess.OWN || level === reportAccess.DEPARTMENT) {
    benefits = await reportAccess.visibleRows('ca_reports', benefits);
  }
  return benefits.filter((b) => b.received_amount === null || b.received_amount === undefined).length;
}

/**
 * The "Needs attention" boxes, in page order. A box appears only for a person who
 * may open the page its button leads to.
 */
async function attention(summary, canManageCa, allLevel, today) {
  const boxes = [];
  const tone = (n, busy) => (n > 0 ? busy : 'ok');

  boxes.push({ key: 'firc', value: String(summary.firc_pending), label: 'FIRC / eBRC pending',
    button: 'Show', href: '/ca?show=firc#register', tone: tone(summary.firc_pending, 'bad') });
  if (allLevel) {
    const n = await unmatchedBankLines();
    boxes.push({ key: 'bank', value: String(n), label: 'Bank lines not matched',
      button: 'Reconcile', href: '/ca/reconciliation', tone: tone(n, 'bad') });
  }
  if (canManageCa) {
    boxes.push({ key: 'zoho', value: String(summary.not_in_zoho), label: 'Not yet in Zoho',
      button: 'Sync', href: '/ca/zoho-sync', tone: tone(summary.not_in_zoho, 'warn') });
  }
  if (allLevel) {
    const tds = await tdsForMonth(today);
    boxes.push({ key: 'tds', value: `₹${format2(tds.amount)}`,
      label: `TDS on this month's expenses — deposit by ${shortDay(tds.due)}`,
      button: 'Summary', href: '/ca/tds-summary', tone: tone(tds.amount, 'warn') });
  }
  const n = await benefitClaimsWaiting();
  boxes.push({ key: 'benefits', value: String(n), label: 'Benefit claims waiting for money',
    button: 'Claims', href: '/ca/export-benefits', tone: tone(n, 'warn') });
  return boxes;
}

module.exports = {
  FILTERS, isFircPending, isNotInZoho, isEstimated, normaliseFilter, filterRegister, registerSummary,
  unmatchedBankLines, tdsDueDate, tdsForMonth, shortDay, benefitClaimsWaiting, attention,
};
