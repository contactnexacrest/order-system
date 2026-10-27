'use strict';

const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../repositories/orderExpenseRepository');
const orderVendorBillRepository = require('../repositories/orderVendorBillRepository');
const orderFreightRepository = require('../repositories/orderFreightRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderProductRepository = require('../repositories/orderProductRepository');
const orderRepository = require('../repositories/orderRepository');
const orderSupplierPoRepository = require('../repositories/orderSupplierPoRepository');

/**
 * Port of App\Services\OrderProfitabilityService (docs/schema.sql Section
 * AP) — the "is this order/customer actually worth doing" figure
 * requested alongside government benefits/expenses: revenue vs. every
 * direct cost, down to a profit amount and margin %.
 *
 * Deliberately reuses figures the system already tracks rather than
 * asking staff to re-enter them:
 *   - Revenue: this order's product FOB value, converted to INR using the
 *     REAL settled rate wherever both the advance and balance legs have
 *     already cleared with their INR actual recorded, falling back to
 *     that leg's own exchange rate (Batch 3 #3: advance/balance/freight
 *     each carry their own rate now, not one shared order-wide rate) for
 *     any leg not yet cleared. Never partly-real, partly-estimated
 *     without saying so — revenueIsEstimated tells the view which case
 *     applies.
 *     90-item backlog #83: on CFR/CIF orders, order_payment_status's
 *     freight_amount leg is itself revenue (the buyer reimburses the
 *     freight NexaCrest pays the shipping line via a separate Debit
 *     Note, docs/SOP/06-stage6-freight.md) — so it's folded into
 *     revenue here too, using the same actual-vs-estimated convention
 *     as the advance/balance legs. Without this, the freight cost
 *     below is deducted but its matching reimbursement never
 *     counted, understating profit on every CFR/CIF order by the
 *     freight amount recovered. On an FOB order freight_amount is
 *     never recorded, so this leg contributes nothing and never
 *     marks the figure estimated on its account.
 *   - Supplier cost: order_supplier_po.total_payable_inr (already INR).
 *   - Freight/insurance cost: order_freight.confirmed_freight_rate +
 *     insurance_amount. ASSUMPTION (documented, not verified against a
 *     real invoice): both are treated as INR figures, the same convention
 *     order_supplier_po already uses, since in practice NexaCrest pays
 *     its freight forwarder domestically — see docs/SOP for the caveat if
 *     a forwarder is ever paid in foreign currency instead.
 *   - Every other cost line (CHA, documentation, port charges, bank
 *     charges, commission, packing/crates, ECGC insurance, due diligence,
 *     inland transport, other): orderCostEntryRepository, manually
 *     recorded per order, also INR.
 *
 * mysql2 returns DECIMAL columns as strings — every value below is
 * explicitly Number()/parseFloat()'d before arithmetic, never relying on
 * JS's implicit coercion (which concatenates rather than adds for `+`).
 */
async function computeForOrder(orderId) {
  const order = await orderRepository.find(orderId);
  const fobValue = await orderProductRepository.totalFobValue(orderId);
  const currencyCode = (order && order.currency_code) || null;
  const payment = await orderPaymentStatusRepository.find(orderId);

  const [revenueInr, revenueIsEstimated] = revenueInInr(fobValue, payment);

  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  const supplierCostInr = supplierPo && supplierPo.total_payable_inr !== null
    ? Number(supplierPo.total_payable_inr)
    : 0;

  const freight = await orderFreightRepository.find(orderId);
  let freightCostInr = freight && freight.confirmed_freight_rate !== null
    ? Number(freight.confirmed_freight_rate)
    : 0;
  const insuranceCostInr = freight && freight.insurance_amount !== null
    ? Number(freight.insurance_amount)
    : 0;

  // Owner decisions (2026-10-27): the logistics partners' bills (CHA,
  // transport, forwarder) are costs of the order. The forwarder's real
  // ocean-freight bills replace the 9a confirmed rate, so freight is never
  // counted twice.
  let logisticsCostInr = 0;
  let oceanFreightBillsInr = null;
  for (const bill of await orderVendorBillRepository.forOrder(orderId, 'logistics')) {
    if (bill.bill_for === 'ocean_freight') {
      oceanFreightBillsInr = (oceanFreightBillsInr || 0) + Number(bill.total_inr);
    } else {
      logisticsCostInr += Number(bill.total_inr);
    }
  }
  if (oceanFreightBillsInr !== null) {
    freightCostInr = oceanFreightBillsInr;
  }

  // The order's actual expenses. Once any real travel expense is recorded, it
  // replaces the provisional travel % cost line.
  const expenses = await orderExpenseRepository.summary(orderId);

  const costEntries = await orderCostEntryRepository.forOrder(orderId);
  let otherCostsInr = 0;
  const byCategory = {};
  for (const entry of costEntries) {
    const cat = entry.category;
    if (cat === 'travel_expenses' && expenses.has_travel) continue;
    otherCostsInr += Number(entry.amount_inr);
    byCategory[cat] = (byCategory[cat] || 0) + Number(entry.amount_inr);
  }

  const [exportBenefitsInr, exportBenefitsIsEstimated] = await exportBenefitsInInr(orderId);

  const totalCostInr = supplierCostInr + freightCostInr + insuranceCostInr + otherCostsInr + logisticsCostInr + expenses.total;
  const profitInr = revenueInr + exportBenefitsInr - totalCostInr;
  const marginPct = revenueInr > 0 ? round2(profitInr / revenueInr * 100) : null;

  return {
    currency_code: currencyCode,
    fob_value_foreign: fobValue,
    revenue_inr: round2(revenueInr),
    revenue_is_estimated: revenueIsEstimated,
    export_benefits_inr: round2(exportBenefitsInr),
    export_benefits_is_estimated: exportBenefitsIsEstimated,
    supplier_cost_inr: round2(supplierCostInr),
    freight_cost_inr: round2(freightCostInr),
    insurance_cost_inr: round2(insuranceCostInr),
    other_costs_inr: round2(otherCostsInr),
    other_costs_by_category: byCategory,
    logistics_cost_inr: round2(logisticsCostInr),
    freight_from_bills: oceanFreightBillsInr !== null,
    expenses_inr: round2(expenses.total),
    travel_is_provisional: !expenses.has_travel,
    total_cost_inr: round2(totalCostInr),
    profit_inr: round2(profitInr),
    margin_pct: marginPct,
  };
}

/**
 * 90-item backlog #54/#85: government export benefit claims (RODTEP and
 * any other dropdown_options('export_benefit_scheme') entry) linked to
 * this order were tracked and displayed on the order page, but never
 * actually added into the profit figure immediately below them.
 * ca_export_benefits has no exchange-rate column of its own — schema.sql
 * defaults currency_code to 'INR' and the scheme amounts in practice
 * always are, the same INR assumption order_freight/order_supplier_po
 * already make — so these are summed directly, no conversion. Mirrors
 * the claimed-vs-received / estimated-vs-actual convention revenueInInr()
 * already uses: a claim already marked received contributes its real
 * received_amount; one still pending contributes its claimed_amount as
 * an estimate, and the whole figure is flagged estimated if ANY
 * contributing claim hasn't been received yet.
 *
 * @returns {Promise<[number, boolean]>} [exportBenefitsInr, isEstimated]
 */
async function exportBenefitsInInr(orderId) {
  const benefits = await caExportBenefitRepository.forOrder(orderId);
  let total = 0;
  let isEstimated = false;
  for (const benefit of benefits) {
    if (benefit.received_amount !== null) {
      total += Number(benefit.received_amount);
    } else {
      total += Number(benefit.claimed_amount);
      isEstimated = true;
    }
  }
  return [total, isEstimated];
}

/**
 * @returns {[number, boolean]} [revenueInr, isEstimated]
 */
function revenueInInr(fobValue, payment) {
  if (!payment) {
    return [0, true];
  }

  const advanceAmount = payment.advance_amount !== null && payment.advance_amount !== undefined
    ? Number(payment.advance_amount)
    : null;
  const balanceAmount = payment.balance_amount !== null && payment.balance_amount !== undefined
    ? Number(payment.balance_amount)
    : null;
  const advanceInrActual = payment.advance_inr_actual !== null && payment.advance_inr_actual !== undefined
    ? Number(payment.advance_inr_actual)
    : null;
  const balanceInrActual = payment.balance_inr_actual !== null && payment.balance_inr_actual !== undefined
    ? Number(payment.balance_inr_actual)
    : null;
  const advanceRate = payment.advance_exchange_rate !== null && payment.advance_exchange_rate !== undefined
    ? Number(payment.advance_exchange_rate)
    : null;
  const balanceRate = payment.balance_exchange_rate !== null && payment.balance_exchange_rate !== undefined
    ? Number(payment.balance_exchange_rate)
    : null;

  const [freightPortion, freightIsEstimated] = freightLegInInr(
    payment.freight_amount !== null && payment.freight_amount !== undefined ? Number(payment.freight_amount) : null,
    payment.freight_inr_actual !== null && payment.freight_inr_actual !== undefined ? Number(payment.freight_inr_actual) : null,
    payment.freight_exchange_rate !== null && payment.freight_exchange_rate !== undefined ? Number(payment.freight_exchange_rate) : null
  );

  // Both legs cleared with a real INR actual recorded — the exact, fully-
  // realized figure, no estimate involved (plus the freight reimbursement
  // leg, #83, which may still be its own estimate on a CFR/CIF order
  // whose freight hasn't cleared yet).
  if (advanceInrActual !== null && balanceInrActual !== null) {
    return [advanceInrActual + balanceInrActual + freightPortion, freightIsEstimated];
  }

  // Pre-PI order: advance/balance amounts haven't even been split out
  // yet, so there's nothing per-leg to apply a per-leg rate to — the
  // best estimate available is still the full FOB value, at whichever
  // leg's rate has been recorded so far (in practice the same rate
  // either way, this early).
  if (advanceAmount === null && balanceAmount === null) {
    const rate = advanceRate ?? balanceRate;
    return rate !== null ? [fobValue * rate, true] : [0, true];
  }

  // Batch 3 #3: each leg now carries its own exchange rate, so a leg
  // that has already cleared (real INR actual) is never re-estimated
  // just because the OTHER leg hasn't cleared yet — only the leg(s)
  // still outstanding fall back to an estimate, using THEIR OWN rate.
  const advancePortion = advanceInrActual ?? ((advanceAmount !== null && advanceRate !== null) ? advanceAmount * advanceRate : null);
  const balancePortion = balanceInrActual ?? ((balanceAmount !== null && balanceRate !== null) ? balanceAmount * balanceRate : null);

  if (advancePortion === null && balancePortion === null) {
    return [freightPortion, freightPortion > 0 ? freightIsEstimated : true];
  }

  const isEstimated = advanceInrActual === null || balanceInrActual === null || freightIsEstimated;
  return [(advancePortion ?? 0) + (balancePortion ?? 0) + freightPortion, isEstimated];
}

/**
 * 90-item backlog #83: the freight reimbursement leg (CFR/CIF orders only
 * — see computeForOrder()'s doc comment above). Mirrors the same
 * actual-vs-estimated rule the advance/balance legs use, with one
 * addition: a leg that was never recorded at all (an FOB order, where
 * Stage 6 auto-skips and freight_amount stays null forever) contributes
 * nothing AND never marks the figure estimated on its account — distinct
 * from a CFR/CIF leg that's merely still outstanding, which does.
 *
 * @returns {[number, boolean]} [freightPortionInr, isEstimated]
 */
function freightLegInInr(amount, inrActual, rate) {
  if (amount === null) {
    return [0, false];
  }
  if (inrActual !== null) {
    return [inrActual, false];
  }
  if (rate !== null) {
    return [amount * rate, true];
  }
  return [0, true];
}

function round2(value) {
  return Math.round(value * 100) / 100;
}

module.exports = { computeForOrder };
