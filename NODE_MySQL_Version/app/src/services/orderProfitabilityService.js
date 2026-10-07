'use strict';

const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
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
  const freightCostInr = freight && freight.confirmed_freight_rate !== null
    ? Number(freight.confirmed_freight_rate)
    : 0;
  const insuranceCostInr = freight && freight.insurance_amount !== null
    ? Number(freight.insurance_amount)
    : 0;

  const costEntries = await orderCostEntryRepository.forOrder(orderId);
  const otherCostsInr = await orderCostEntryRepository.totalForOrder(orderId);

  const byCategory = {};
  for (const entry of costEntries) {
    const cat = entry.category;
    byCategory[cat] = (byCategory[cat] || 0) + Number(entry.amount_inr);
  }

  const totalCostInr = supplierCostInr + freightCostInr + insuranceCostInr + otherCostsInr;
  const profitInr = revenueInr - totalCostInr;
  const marginPct = revenueInr > 0 ? round2(profitInr / revenueInr * 100) : null;

  return {
    currency_code: currencyCode,
    fob_value_foreign: fobValue,
    revenue_inr: round2(revenueInr),
    revenue_is_estimated: revenueIsEstimated,
    supplier_cost_inr: round2(supplierCostInr),
    freight_cost_inr: round2(freightCostInr),
    insurance_cost_inr: round2(insuranceCostInr),
    other_costs_inr: round2(otherCostsInr),
    other_costs_by_category: byCategory,
    total_cost_inr: round2(totalCostInr),
    profit_inr: round2(profitInr),
    margin_pct: marginPct,
  };
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

  // Both legs cleared with a real INR actual recorded — the exact, fully-
  // realized figure, no estimate involved.
  if (advanceInrActual !== null && balanceInrActual !== null) {
    return [advanceInrActual + balanceInrActual, false];
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
    return [0, true];
  }

  const isEstimated = advanceInrActual === null || balanceInrActual === null;
  return [(advancePortion ?? 0) + (balancePortion ?? 0), isEstimated];
}

function round2(value) {
  return Math.round(value * 100) / 100;
}

module.exports = { computeForOrder };
