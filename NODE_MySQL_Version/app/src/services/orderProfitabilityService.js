'use strict';

const caExpenseRepository = require('../repositories/caExpenseRepository');
const caExportBenefitRepository = require('../repositories/caExportBenefitRepository');
const orderCostEntryRepository = require('../repositories/orderCostEntryRepository');
const orderExpenseRepository = require('../repositories/orderExpenseRepository');
const orderVendorBillRepository = require('../repositories/orderVendorBillRepository');
const orderFreightRepository = require('../repositories/orderFreightRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');
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
 *     insurance_amount, entered in the ORDER currency (Step 9a; the Debit
 *     Note charges the buyer exactly that) and converted to INR with
 *     freightRateToInr() — owner decision D6 of 2 Nov 2026 (register D-225). Until a rate
 *     is known they are left out of the INR total and flagged
 *     freight_rate_missing.
 *   - Every other cost line (CHA, documentation, port charges, bank
 *     charges, commission, packing/crates, ECGC insurance, due diligence,
 *     inland transport, other): orderCostEntryRepository, manually
 *     recorded per order, also INR.
 *
 * mysql2 returns DECIMAL columns as strings — every value below is
 * explicitly Number()/parseFloat()'d before arithmetic, never relying on
 * JS's implicit coercion (which concatenates rather than adds for `+`).
 */
/**
 * Owner decision D-245 P5: with withIncentives (the default) the result also carries the
 * associate commission and the team incentive — LOCKED figures once the MD has locked the
 * order, ESTIMATED ones before (so profit is never overstated) — and the profit before and
 * after them. The existing keys keep their meaning (locked cost entries only) so the CAFIN
 * document is unchanged. incentiveService.calculate() asks without, to avoid a circle.
 */
async function computeForOrder(orderId, withIncentives = true) {
  const order = await orderRepository.find(orderId);
  const fobValue = await orderProductRepository.totalFobValue(orderId);
  const currencyCode = (order && order.currency_code) || null;
  const payment = await withLcPayment(order, await orderPaymentStatusRepository.find(orderId));

  const [revenueBeforeLcExtra, revenueIsEstimated] = revenueInInr(fobValue, payment);
  let revenueInr = revenueBeforeLcExtra + Number((payment && payment.lc_extra_inr) || 0);
  // Owner 2 Nov 2026: where the rupees actually credited are used, the bank charges the banks
  // deducted are added back — the buyer paid them; they are counted once, as the order cost.
  for (const leg of ['advance', 'balance', 'freight']) {
    if (payment && payment[`${leg}_inr_actual`] !== null && payment[`${leg}_inr_actual`] !== undefined) {
      revenueInr += await require('./remittanceDifference').chargesInr(orderId, leg);
    }
  }

  const supplierPo = await orderSupplierPoRepository.findLatestForOrder(orderId);
  const supplierCostInr = supplierPo && supplierPo.total_payable_inr !== null
    ? Number(supplierPo.total_payable_inr)
    : 0;

  // Owner decision D6 of 2 Nov 2026 (confirmed twice; register D-225): the Step 9a
  // confirmed freight rate and insurance are entered in the ORDER's
  // currency (the Debit Note charges the buyer exactly that), so they are
  // converted to INR here — never added to rupee costs as they are.
  // Q-R5-2: insurance is a seller cost on CIF only; on FOB/CFR the buyer insures.
  const freight = await orderFreightRepository.find(orderId);
  const isCif = String((order && order.incoterm_code) || '').toUpperCase() === 'CIF';
  const freightForeign = freight && freight.confirmed_freight_rate !== null && freight.confirmed_freight_rate !== undefined
    ? Number(freight.confirmed_freight_rate)
    : null;
  const insuranceForeign = isCif && freight && freight.insurance_amount !== null && freight.insurance_amount !== undefined
    ? Number(freight.insurance_amount)
    : null;
  const [freightRate, freightRateSource] = freightRateToInr(payment);
  let freightCostInr = freightForeign !== null && freightRate !== null ? freightForeign * freightRate : 0;
  const insuranceCostInr = insuranceForeign !== null && freightRate !== null ? insuranceForeign * freightRate : 0;

  // Owner decisions (2026-10-27): the logistics partners' bills (CHA,
  // transport, forwarder) are costs of the order. The forwarder's real
  // ocean-freight bills replace the 9a confirmed rate, so freight is never
  // counted twice. Owner decision (2026-10-28, Option B): a tax invoice
  // counts at its total; a proforma only for what was paid on it, and not at
  // all once a tax invoice replaces it — so a proforma and its invoice for
  // the same charges are never both counted.
  let logisticsCostInr = 0;
  let oceanFreightBillsInr = null;
  for (const bill of await orderVendorBillRepository.forOrder(orderId, 'logistics')) {
    if (bill.bill_for === 'ocean_freight') {
      oceanFreightBillsInr = (oceanFreightBillsInr || 0) + bill.cost_inr;
    } else {
      logisticsCostInr += bill.cost_inr;
    }
  }
  if (oceanFreightBillsInr !== null) {
    freightCostInr = oceanFreightBillsInr;
  }
  // Foreign amounts still waiting for a rate: kept out of the INR total and flagged.
  const freightRateMissing = freightRate === null
    && ((freightForeign !== null && oceanFreightBillsInr === null) || insuranceForeign !== null);

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

  // Owner decision 2026-10-29 (CAFIN change confirmed twice): expenses
  // booked in Zoho Books and linked to this order count in its cost —
  // except a possible duplicate of a cost already entered in the app.
  let zohoExpensesInr = 0;
  for (const zohoExpense of await caExpenseRepository.forOrderCounted(orderId)) {
    zohoExpensesInr += zohoExpense.counted ? Number(zohoExpense.amount) : 0;
  }

  const totalCostInr = supplierCostInr + freightCostInr + insuranceCostInr + otherCostsInr + logisticsCostInr + expenses.total + zohoExpensesInr;
  const profitInr = revenueInr + exportBenefitsInr - totalCostInr;
  const marginPct = revenueInr > 0 ? round2(profitInr / revenueInr * 100) : null;

  const incentiveEntries = await require('../repositories/incentiveRepository').costEntries(orderId);
  const result = {
    currency_code: currencyCode,
    fob_value_foreign: fobValue,
    revenue_inr: round2(revenueInr),
    zoho_expenses_inr: round2(zohoExpensesInr),
    // Owner decision 2026-10-30: the team incentive and associate commission,
    // already inside other_costs_inr (commission cost entries), shown apart.
    team_incentive_inr: round2(Number((incentiveEntries.team || {}).amount_inr || 0)),
    associate_commission_inr: round2(Number((incentiveEntries.associate || {}).amount_inr || 0)),
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
    freight_foreign: freightForeign,
    insurance_foreign: insuranceForeign,
    freight_rate_used: freightRate,
    freight_rate_source: freightRateSource,
    freight_rate_missing: freightRateMissing,
    expenses_inr: round2(expenses.total),
    travel_is_provisional: !expenses.has_travel,
    total_cost_inr: round2(totalCostInr),
    profit_inr: round2(profitInr),
    margin_pct: marginPct,
  };
  result.freight_conversion_text = result.freight_from_bills ? null : conversionText(result, 'freight');
  result.insurance_conversion_text = conversionText(result, 'insurance');
  if (withIncentives) Object.assign(result, await incentiveLayer(orderId, result));
  return result;
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
 * Owner decision 2026-10-29 (CAFIN change confirmed twice): on an LC
 * order the money the bank paid under the LC (the rupees credited)
 * counts in the revenue. It takes the place of the first buyer leg no
 * money has come in for yet — the advance, else the balance (an order
 * switched to LC after its cash advance came in) — and when both had
 * already come in it is added on top as `lc_extra_inr`, so the revenue
 * always equals the money received from the buyer.
 */
async function withLcPayment(order, payment) {
  if (!payment || !order || (order.payment_method || '') !== 'LC') {
    return payment;
  }
  const lc = await orderLcDetailRepository.find(Number(order.id));
  if (!lc || lc.lc_received_at === null || Number(lc.lc_received_amount) <= 0) {
    return payment;
  }
  const amount = Number(lc.lc_received_amount);
  const credited = Number(lc.lc_inr_credited);
  for (const leg of ['advance', 'balance']) {
    if (payment[`${leg}_inr_actual`] === null && !payment[`${leg}_cleared_at`]) {
      return {
        ...payment,
        [`${leg}_amount`]: amount,
        [`${leg}_inr_actual`]: credited,
        [`${leg}_exchange_rate`]: credited / amount,
      };
    }
  }
  return { ...payment, lc_extra_inr: credited };
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
 * Owner decision D6 of 2 Nov 2026 (register D-225): how a Step 9a amount became rupees, for the Step 12
 * screen and the CA annexure — "USD 6,000.00 × 83.50 = ₹501,000.00", or
 * "USD 6,000.00 — not counted yet, no exchange rate". Null when there is no
 * such amount. Mirrors PHP's OrderProfitabilityService::conversionText().
 */
function conversionText(profit, which) {
  const foreign = profit[which === 'insurance' ? 'insurance_foreign' : 'freight_foreign'];
  if (foreign === null || foreign === undefined) return null;
  const fmt = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const amount = `${profit.currency_code || ''} ${fmt(foreign)}`.trim();
  const rate = profit.freight_rate_used;
  if (rate === null || rate === undefined) return `${amount} — not counted yet, no exchange rate`;
  return `${amount} × ${fmt(rate)} = ₹${fmt(round2(Number(foreign) * Number(rate)))}`;
}

/**
 * Owner decision D6 of 2 Nov 2026 (register D-225): the rate that turns the Step 9a
 * freight and insurance (order currency) into INR. In order of preference:
 * the buyer's freight payment as actually credited (INR actual ÷ amount),
 * else that leg's own rate; else the rate the advance or balance actually
 * settled at; else the advance/balance rate already used for the estimated
 * revenue. [null, null] when no rate is known yet. Mirrors PHP's
 * OrderProfitabilityService::freightRateToInr().
 *
 * @returns {[number|null, string|null]} [rate, source]
 */
function freightRateToInr(payment) {
  if (!payment) return [null, null];
  const num = (v) => (v !== null && v !== undefined && v !== '' ? Number(v) : null);
  const actualRate = (inr, amount) => (inr !== null && amount !== null && amount > 0 ? inr / amount : null);
  const candidates = [
    ['freight_payment', actualRate(num(payment.freight_inr_actual), num(payment.freight_amount))],
    ['freight_rate', num(payment.freight_exchange_rate)],
    ['advance_payment', actualRate(num(payment.advance_inr_actual), num(payment.advance_amount))],
    ['balance_payment', actualRate(num(payment.balance_inr_actual), num(payment.balance_amount))],
    ['advance_rate', num(payment.advance_exchange_rate)],
    ['balance_rate', num(payment.balance_exchange_rate)],
  ];
  for (const [source, rate] of candidates) {
    if (rate !== null && rate > 0) return [rate, source];
  }
  return [null, null];
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

/**
 * D-245 P5: profit before commission and incentive → − associate commission → profit for the
 * incentive → − team incentive → profit after. Each amount says whether it is estimated,
 * locked, paid or held. A locked figure replaces the estimate; it is never added on top (the
 * locked amounts are the incentive cost entries already inside other_costs_inr, taken back out
 * here first).
 */
async function incentiveLayer(orderId, base) {
  const incentiveRepository = require('../repositories/incentiveRepository');
  const entries = await incentiveRepository.costEntries(orderId);
  const lockedTeam = round2(Number((entries.team || {}).amount_inr || 0));
  const lockedAssoc = round2(Number((entries.associate || {}).amount_inr || 0));
  const costBefore = round2(Number(base.total_cost_inr) - lockedTeam - lockedAssoc);
  const profitBefore = round2(Number(base.profit_inr) + lockedTeam + lockedAssoc);
  const row = await incentiveRepository.forOrder(orderId);
  const order = await orderRepository.find(orderId);
  const locked = !!(row && row.locked_at);
  let commission; let incentive; let commissionState; let incentiveState;
  if (locked) {
    commission = lockedAssoc;
    incentive = lockedTeam;
    commissionState = await payoutState(orderId, 'associate', lockedAssoc > 0 || !!row.associate_id);
    incentiveState = await payoutState(orderId, 'staff', true);
  } else if (!order || ['cancelled', 'lost'].includes(order.status)) {
    commission = 0; incentive = 0; commissionState = null; incentiveState = null;
  } else {
    const calc = await require('./incentiveService').calculate(orderId, base);
    const a = calc.associate;
    commission = a && a.state !== 'unpaid' ? round2(Number(a.amount_inr)) : 0;
    commissionState = a ? 'estimated' : null;
    incentive = round2(Number(calc.team_total));
    incentiveState = 'estimated';
  }
  const forIncentive = round2(profitBefore - commission);
  const after = round2(forIncentive - incentive);
  const revenue = Number(base.revenue_inr);
  return {
    cost_before_incentives_inr: costBefore,
    other_costs_excl_incentives_inr: round2(Number(base.other_costs_inr) - lockedTeam - lockedAssoc),
    profit_before_commission_inr: profitBefore,
    commission_inr: commission,
    commission_state: commissionState,
    profit_for_incentive_inr: forIncentive,
    incentive_inr: incentive,
    incentive_state: incentiveState,
    profit_after_incentive_inr: after,
    margin_after_pct: revenue > 0 ? round2(after / revenue * 100) : null,
    incentives_locked: locked,
  };
}

/** locked | paid (every payout of that kind paid) | held (any held); null when there is nothing for that payee kind. */
async function payoutState(orderId, kind, expected) {
  const payouts = (await require('../repositories/incentiveRepository').payoutsForOrder(orderId)).filter((p) => p.payee_kind === kind);
  if (payouts.length === 0) return expected ? 'locked' : null;
  const statuses = payouts.map((p) => p.status);
  if (statuses.includes('held')) return 'held';
  return statuses.every((st) => st === 'paid') ? 'paid' : 'locked';
}

/** "estimated" / "locked" / "paid" / "held" as shown beside an amount. */
function stateLabel(state) {
  return { estimated: 'Estimated', locked: 'Locked', paid: 'Paid', held: 'Held', ready: 'Ready to lock' }[state] || '—';
}

module.exports = { computeForOrder, conversionText, incentiveLayer, stateLabel };
