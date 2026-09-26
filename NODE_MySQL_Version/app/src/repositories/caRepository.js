'use strict';

const orderPaymentStatusRepository = require('./orderPaymentStatusRepository');

// CA / Accounting module (Phase 1) — independent of the order-pipeline
// system (nav-gated on ca_module_view, never on manage_orders). Phase 1
// is just the INR settlement register; later phases (Zoho sync, expense
// import, reconciliation, FY/calendar reports) land as their own
// functions here rather than growing orderPaymentStatusRepository further.

const LEGS = [
  { leg: 'advance', amountCol: 'advance_amount', clearedCol: 'advance_cleared_at', inrCol: 'advance_inr_actual', inrAtCol: 'advance_inr_actual_recorded_at', inrByCol: 'advance_inr_actual_recorded_by' },
  { leg: 'balance', amountCol: 'balance_amount', clearedCol: 'balance_cleared_at', inrCol: 'balance_inr_actual', inrAtCol: 'balance_inr_actual_recorded_at', inrByCol: 'balance_inr_actual_recorded_by' },
  { leg: 'freight', amountCol: 'freight_amount', clearedCol: 'freight_cleared_at', inrCol: 'freight_inr_actual', inrAtCol: 'freight_inr_actual_recorded_at', inrByCol: 'freight_inr_actual_recorded_by' },
];

/**
 * One row per cleared settlement leg (advance/balance/freight), flattened
 * from orderPaymentStatusRepository.clearedSettlements() for the register
 * table — newest cleared date first.
 */
async function settlementRegister() {
  const rows = [];
  for (const ops of await orderPaymentStatusRepository.clearedSettlements()) {
    for (const legDef of LEGS) {
      if (ops[legDef.clearedCol] === null || ops[legDef.clearedCol] === undefined) {
        continue;
      }
      rows.push({
        order_id: ops.order_id,
        buyer_inquiry_ref: ops.buyer_inquiry_ref,
        company_legal_name: ops.company_legal_name,
        currency_code: ops.currency_code,
        leg: legDef.leg,
        foreign_amount: ops[legDef.amountCol],
        cleared_at: ops[legDef.clearedCol],
        inr_actual: ops[legDef.inrCol],
        inr_actual_recorded_at: ops[legDef.inrAtCol],
        inr_actual_recorded_by: ops[legDef.inrByCol],
      });
    }
  }
  rows.sort((a, b) => String(b.cleared_at).localeCompare(String(a.cleared_at)));
  return rows;
}

module.exports = { settlementRegister };
