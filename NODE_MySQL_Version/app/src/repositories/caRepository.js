'use strict';

const orderPaymentStatusRepository = require('./orderPaymentStatusRepository');
const companySettingsRepository = require('./companySettingsRepository');
const financialYear = require('../helpers/financialYear');

// CA / Accounting module — independent of the order-pipeline system
// (nav-gated on ca_module_view, never on manage_orders). Phase 1 was the
// INR settlement register; Phase 2 adds forex gain/loss, FIRC/eBRC
// tracking, and FY/calendar revenue reports on top of the same register
// data. Later phases (Zoho sync, expense import, reconciliation) land as
// their own functions here rather than growing orderPaymentStatusRepository
// further.

const LEGS = [
  { leg: 'advance', amountCol: 'advance_amount', clearedCol: 'advance_cleared_at', inrCol: 'advance_inr_actual', inrAtCol: 'advance_inr_actual_recorded_at', inrByCol: 'advance_inr_actual_recorded_by', fircCol: 'advance_firc_reference', fircAtCol: 'advance_firc_received_at', zohoAtCol: 'advance_zoho_synced_at', zohoRefCol: 'advance_zoho_reference' },
  { leg: 'balance', amountCol: 'balance_amount', clearedCol: 'balance_cleared_at', inrCol: 'balance_inr_actual', inrAtCol: 'balance_inr_actual_recorded_at', inrByCol: 'balance_inr_actual_recorded_by', fircCol: 'balance_firc_reference', fircAtCol: 'balance_firc_received_at', zohoAtCol: 'balance_zoho_synced_at', zohoRefCol: 'balance_zoho_reference' },
  { leg: 'freight', amountCol: 'freight_amount', clearedCol: 'freight_cleared_at', inrCol: 'freight_inr_actual', inrAtCol: 'freight_inr_actual_recorded_at', inrByCol: 'freight_inr_actual_recorded_by', fircCol: 'freight_firc_reference', fircAtCol: 'freight_firc_received_at', zohoAtCol: 'freight_zoho_synced_at', zohoRefCol: 'freight_zoho_reference' },
];

/**
 * One row per cleared settlement leg (advance/balance/freight), flattened
 * from orderPaymentStatusRepository.clearedSettlements() — newest cleared
 * date first. Each row also carries the forex gain/loss (inr_actual -
 * foreign_amount * assumed_exchange_rate, when both are on record) and
 * whether its FIRC/eBRC reference is still missing past the configured
 * alert window.
 */
async function settlementRegister() {
  const alertDays = parseInt((await companySettingsRepository.get('fema_realization_alert_days')) ?? '270', 10);
  const now = Date.now();

  const rows = [];
  for (const ops of await orderPaymentStatusRepository.clearedSettlements()) {
    for (const legDef of LEGS) {
      if (ops[legDef.clearedCol] === null || ops[legDef.clearedCol] === undefined) {
        continue;
      }
      const foreignAmount = ops[legDef.amountCol] !== null ? parseFloat(ops[legDef.amountCol]) : null;
      const inrActual = ops[legDef.inrCol] !== null ? parseFloat(ops[legDef.inrCol]) : null;
      const assumedRate = ops.assumed_exchange_rate !== null && ops.assumed_exchange_rate !== undefined ? parseFloat(ops.assumed_exchange_rate) : null;

      const expectedInr = (foreignAmount !== null && assumedRate !== null) ? foreignAmount * assumedRate : null;
      const forexGainLoss = (inrActual !== null && expectedInr !== null) ? inrActual - expectedInr : null;

      const clearedAtTs = new Date(ops[legDef.clearedCol]).getTime();
      const daysSinceCleared = Number.isNaN(clearedAtTs) ? 0 : Math.floor((now - clearedAtTs) / 86400000);
      const fircPending = (ops[legDef.fircCol] === null || ops[legDef.fircCol] === undefined) && daysSinceCleared >= alertDays;

      rows.push({
        order_id: ops.order_id,
        client_id: ops.client_id,
        buyer_inquiry_ref: ops.buyer_inquiry_ref,
        company_legal_name: ops.company_legal_name,
        currency_code: ops.currency_code,
        leg: legDef.leg,
        foreign_amount: foreignAmount,
        cleared_at: ops[legDef.clearedCol],
        inr_actual: inrActual,
        inr_actual_recorded_at: ops[legDef.inrAtCol],
        inr_actual_recorded_by: ops[legDef.inrByCol],
        assumed_exchange_rate: assumedRate,
        expected_inr: expectedInr,
        forex_gain_loss: forexGainLoss,
        firc_reference: ops[legDef.fircCol] ?? null,
        firc_received_at: ops[legDef.fircAtCol] ?? null,
        firc_pending: fircPending,
        zoho_synced_at: ops[legDef.zohoAtCol] ?? null,
        zoho_reference: ops[legDef.zohoRefCol] ?? null,
      });
    }
  }
  rows.sort((a, b) => String(b.cleared_at).localeCompare(String(a.cleared_at)));
  return rows;
}

/**
 * Every leg with an INR actual on record that hasn't been pushed to Zoho
 * Books yet — what caSyncService.syncPendingRevenue() works through on
 * each run (manual or scheduled).
 */
async function legsPendingZohoSync() {
  const rows = await settlementRegister();
  return rows.filter((r) => r.inr_actual !== null && r.zoho_synced_at === null);
}

/**
 * Every leg with an INR actual on record that hasn't been matched to a
 * bank statement line yet — what the reconciliation summary
 * (caController.reconciliation()) lists as outstanding on the revenue
 * side.
 *
 * @param {string[]} matchedKeys 'orderId:leg' keys already matched (caBankStatementRepository.matchedRevenueKeys())
 */
async function legsWithInrActualUnmatched(matchedKeys) {
  const matched = new Set(matchedKeys);
  const rows = await settlementRegister();
  return rows.filter((r) => r.inr_actual !== null && !matched.has(`${r.order_id}:${r.leg}`));
}

/** All-time total of every recorded INR actual — used by the reconciliation summary. */
async function totalInrActualAll() {
  const rows = await settlementRegister();
  return rows.reduce((sum, r) => sum + (r.inr_actual || 0), 0);
}

/** FY labels (e.g. '2026-27') with at least one cleared leg, newest first. */
async function availableFinancialYears() {
  const rows = await settlementRegister();
  return financialYear.labelsPresentIn(rows.map((r) => r.cleared_at));
}

/** Calendar years with at least one cleared leg, newest first. */
async function availableCalendarYears() {
  const rows = await settlementRegister();
  const years = new Set(rows.map((r) => parseInt(String(r.cleared_at).slice(0, 4), 10)));
  return Array.from(years).sort((a, b) => b - a);
}

/**
 * Revenue summary for one financial year or one calendar year — totals by
 * currency, plus overall INR-actual and forex gain/loss totals. Computed
 * from settlementRegister() rather than a separate query, so this can
 * never disagree with the register table itself.
 */
async function revenueReport(mode, period) {
  let from;
  let to;
  if (mode === 'fy') {
    const b = financialYear.bounds(period);
    from = b.start;
    to = b.end;
  } else {
    from = `${period}-01-01`;
    to = `${period}-12-31`;
  }

  const all = await settlementRegister();
  const rows = all.filter((r) => {
    const d = String(r.cleared_at).slice(0, 10);
    return d >= from && d <= to;
  });

  const byCurrency = {};
  let totalInrActual = 0;
  let totalForexGainLoss = 0;
  let legsMissingInr = 0;
  for (const r of rows) {
    const cc = r.currency_code;
    if (!byCurrency[cc]) {
      byCurrency[cc] = { foreign_total: 0, inr_actual_total: 0, leg_count: 0 };
    }
    byCurrency[cc].foreign_total += r.foreign_amount ?? 0;
    byCurrency[cc].inr_actual_total += r.inr_actual ?? 0;
    byCurrency[cc].leg_count += 1;
    if (r.inr_actual === null) {
      legsMissingInr += 1;
    } else {
      totalInrActual += r.inr_actual;
    }
    if (r.forex_gain_loss !== null) {
      totalForexGainLoss += r.forex_gain_loss;
    }
  }

  return {
    from,
    to,
    rows,
    byCurrency,
    totalInrActual,
    totalForexGainLoss,
    legsMissingInr,
    legCount: rows.length,
  };
}

module.exports = {
  settlementRegister, availableFinancialYears, availableCalendarYears, revenueReport, legsPendingZohoSync,
  legsWithInrActualUnmatched, totalInrActualAll,
};
