<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;

/**
 * Order Profitability Sheet (docs/schema.sql Section AP) — the "is this
 * order/customer actually worth doing" figure requested alongside
 * government benefits/expenses: revenue vs. every direct cost, down to a
 * profit amount and margin %.
 *
 * Deliberately reuses figures the system already tracks rather than
 * asking staff to re-enter them (see the "how needed" scope decision this
 * was built under):
 *   - Revenue: this order's product FOB value, converted to INR using the
 *     REAL settled rate wherever a leg has already cleared with its INR
 *     actual recorded (CaExportBenefit/CA Phase 2 data), falling back to
 *     that leg's own exchange rate (Batch 3 #3: advance/balance/freight
 *     each carry their own rate now, not one shared order-wide rate) for
 *     any leg not yet cleared. Never partly-real, partly-estimated
 *     without saying so — the 'revenue_is_estimated' flag tells the view
 *     which case applies.
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
 *   - Logistics partners' bills (CHA, transport, forwarder) and the order's
 *     actual expenses (owner decisions 2026-10-27): order_vendor_bills /
 *     order_expenses. Real ocean-freight bills replace the confirmed rate
 *     above; real travel expenses replace the provisional travel % line.
 *   - Every other cost line (CHA, documentation, port charges, bank
 *     charges, commission, packing/crates, ECGC insurance, due diligence,
 *     inland transport, other): OrderCostEntryRepository, manually
 *     recorded per order, also INR.
 */
final class OrderProfitabilityService
{
    /** @return array<string,mixed> */
    public static function computeForOrder(int $orderId): array
    {
        $order = OrderRepository::find($orderId);
        $fobValue = OrderProductRepository::totalFobValue($orderId);
        $currencyCode = $order['currency_code'] ?? null;
        $payment = self::withLcPayment($order, OrderPaymentStatusRepository::find($orderId));

        [$revenueInr, $isEstimated] = self::revenueInInr($fobValue, $payment);
        $revenueInr += (float) ($payment['lc_extra_inr'] ?? 0.0);

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        $supplierCostInr = ($supplierPo !== null && $supplierPo['total_payable_inr'] !== null) ? (float) $supplierPo['total_payable_inr'] : 0.0;

        $freight = OrderFreightRepository::find($orderId);
        $freightCostInr = ($freight !== null && $freight['confirmed_freight_rate'] !== null) ? (float) $freight['confirmed_freight_rate'] : 0.0;
        // Q-R5-2: insurance is a seller cost on CIF only; on FOB/CFR the buyer insures.
        $isCif = strtoupper((string) ($order['incoterm_code'] ?? '')) === 'CIF';
        $insuranceCostInr = ($isCif && $freight !== null && $freight['insurance_amount'] !== null) ? (float) $freight['insurance_amount'] : 0.0;

        // Owner decisions (2026-10-27): the logistics partners' bills (CHA,
        // transport, forwarder) are costs of the order. The forwarder's real
        // ocean-freight bills replace the 9a confirmed rate, so freight is
        // never counted twice. Owner decision (2026-10-28, Option B): a tax
        // invoice counts at its total; a proforma only for what was paid on
        // it, and not at all once a tax invoice replaces it — so a proforma
        // and its invoice for the same charges are never both counted.
        $logisticsCostInr = 0.0;
        $oceanFreightBillsInr = null;
        foreach (OrderVendorBillRepository::forOrder($orderId, 'logistics') as $bill) {
            if ($bill['bill_for'] === 'ocean_freight') {
                $oceanFreightBillsInr = ($oceanFreightBillsInr ?? 0.0) + $bill['cost_inr'];
            } else {
                $logisticsCostInr += $bill['cost_inr'];
            }
        }
        if ($oceanFreightBillsInr !== null) {
            $freightCostInr = $oceanFreightBillsInr;
        }

        // The order's actual expenses. Once any real travel expense is
        // recorded, it replaces the provisional travel % cost line.
        $expenses = OrderExpenseRepository::summary($orderId);

        $costEntries = OrderCostEntryRepository::forOrder($orderId);
        $otherCostsInr = 0.0;
        $byCategory = [];
        foreach ($costEntries as $entry) {
            $cat = $entry['category'];
            if ($cat === 'travel_expenses' && $expenses['has_travel']) {
                continue;
            }
            $otherCostsInr += (float) $entry['amount_inr'];
            $byCategory[$cat] = ($byCategory[$cat] ?? 0.0) + (float) $entry['amount_inr'];
        }

        [$exportBenefitsInr, $exportBenefitsIsEstimated] = self::exportBenefitsInInr($orderId);

        // Owner decision 2026-10-29 (CAFIN change confirmed twice): expenses
        // booked in Zoho Books and linked to this order count in its cost —
        // except a possible duplicate of a cost already entered in the app.
        $zohoExpensesInr = 0.0;
        foreach (\App\Repositories\CaExpenseRepository::forOrderCounted($orderId) as $zohoExpense) {
            $zohoExpensesInr += $zohoExpense['counted'] ? (float) $zohoExpense['amount'] : 0.0;
        }

        $totalCostInr = $supplierCostInr + $freightCostInr + $insuranceCostInr + $otherCostsInr + $logisticsCostInr + $expenses['total'] + $zohoExpensesInr;
        $profitInr = $revenueInr + $exportBenefitsInr - $totalCostInr;
        $marginPct = $revenueInr > 0 ? round($profitInr / $revenueInr * 100, 2) : null;

        return [
            'currency_code'        => $currencyCode,
            'fob_value_foreign'    => $fobValue,
            'revenue_inr'          => round($revenueInr, 2),
            'zoho_expenses_inr'    => round($zohoExpensesInr, 2),
            'revenue_is_estimated' => $isEstimated,
            'export_benefits_inr'  => round($exportBenefitsInr, 2),
            'export_benefits_is_estimated' => $exportBenefitsIsEstimated,
            'supplier_cost_inr'    => round($supplierCostInr, 2),
            'freight_cost_inr'     => round($freightCostInr, 2),
            'insurance_cost_inr'   => round($insuranceCostInr, 2),
            'other_costs_inr'      => round($otherCostsInr, 2),
            'other_costs_by_category' => $byCategory,
            'logistics_cost_inr'   => round($logisticsCostInr, 2),
            'freight_from_bills'   => $oceanFreightBillsInr !== null,
            'expenses_inr'         => round($expenses['total'], 2),
            'travel_is_provisional' => !$expenses['has_travel'],
            'total_cost_inr'       => round($totalCostInr, 2),
            'profit_inr'           => round($profitInr, 2),
            'margin_pct'           => $marginPct,
        ];
    }

    /**
     * 90-item backlog #54/#85: government export benefit claims (RODTEP
     * and any other dropdown_options('export_benefit_scheme') entry)
     * linked to this order were tracked and displayed on the order page,
     * but never actually added into the profit figure immediately below
     * them — a shipment's real government incentive was invisible to the
     * one number ("is this order actually worth doing") the whole
     * Profitability Sheet exists to answer. ca_export_benefits has no
     * exchange-rate column of its own — schema.sql defaults currency_code
     * to 'INR' and the scheme amounts in practice always are, the same
     * INR assumption order_freight/order_supplier_po already make — so
     * these are summed directly, no conversion. Mirrors the
     * claimed-vs-received / estimated-vs-actual convention revenueInInr()
     * already uses: a claim already marked received contributes its real
     * received_amount; one still pending contributes its claimed_amount
     * as an estimate (the government has already acknowledged the
     * entitlement; it just hasn't paid out yet), and the whole figure is
     * flagged estimated if ANY contributing claim hasn't yet.
     *
     * @return array{0: float, 1: bool} [exportBenefitsInr, isEstimated]
     */
    private static function exportBenefitsInInr(int $orderId): array
    {
        $benefits = CaExportBenefitRepository::forOrder($orderId);
        $total = 0.0;
        $isEstimated = false;
        foreach ($benefits as $benefit) {
            if ($benefit['received_amount'] !== null) {
                $total += (float) $benefit['received_amount'];
            } else {
                $total += (float) $benefit['claimed_amount'];
                $isEstimated = true;
            }
        }
        return [$total, $isEstimated];
    }

    /**
     * Owner decision 2026-10-29 (CAFIN change confirmed twice): on an LC
     * order the money the bank paid under the LC (the rupees credited)
     * counts in the revenue. It takes the place of the first buyer leg no
     * money has come in for yet — the advance, else the balance (an order
     * switched to LC after its cash advance came in) — and when both had
     * already come in it is added on top as 'lc_extra_inr', so the revenue
     * always equals the money received from the buyer.
     *
     * @param array<string,mixed>|null $payment
     * @return array<string,mixed>|null
     */
    private static function withLcPayment(?array $order, ?array $payment): ?array
    {
        if ($payment === null || ($order['payment_method'] ?? '') !== 'LC') {
            return $payment;
        }
        $lc = \App\Repositories\OrderLcDetailRepository::find((int) $order['id']);
        if ($lc === null || $lc['lc_received_at'] === null || (float) $lc['lc_received_amount'] <= 0) {
            return $payment;
        }
        $amount = (float) $lc['lc_received_amount'];
        $credited = (float) $lc['lc_inr_credited'];
        foreach (['advance', 'balance'] as $leg) {
            if ($payment[$leg . '_inr_actual'] === null && empty($payment[$leg . '_cleared_at'])) {
                $payment[$leg . '_amount'] = $amount;
                $payment[$leg . '_inr_actual'] = $credited;
                $payment[$leg . '_exchange_rate'] = $credited / $amount;
                return $payment;
            }
        }
        $payment['lc_extra_inr'] = $credited;
        return $payment;
    }

    /**
     * @param array<string,mixed>|null $payment
     * @return array{0: float, 1: bool} [revenueInr, isEstimated]
     */
    private static function revenueInInr(float $fobValue, ?array $payment): array
    {
        if ($payment === null) {
            return [0.0, true];
        }

        $advanceAmount = $payment['advance_amount'] !== null ? (float) $payment['advance_amount'] : null;
        $balanceAmount = $payment['balance_amount'] !== null ? (float) $payment['balance_amount'] : null;
        $advanceInrActual = $payment['advance_inr_actual'] !== null ? (float) $payment['advance_inr_actual'] : null;
        $balanceInrActual = $payment['balance_inr_actual'] !== null ? (float) $payment['balance_inr_actual'] : null;
        $advanceRate = $payment['advance_exchange_rate'] !== null ? (float) $payment['advance_exchange_rate'] : null;
        $balanceRate = $payment['balance_exchange_rate'] !== null ? (float) $payment['balance_exchange_rate'] : null;

        [$freightPortion, $freightIsEstimated] = self::freightLegInInr(
            $payment['freight_amount'] !== null ? (float) $payment['freight_amount'] : null,
            $payment['freight_inr_actual'] !== null ? (float) $payment['freight_inr_actual'] : null,
            $payment['freight_exchange_rate'] !== null ? (float) $payment['freight_exchange_rate'] : null
        );

        // Both legs cleared with a real INR actual recorded — the exact,
        // fully-realized figure, no estimate involved (plus the freight
        // reimbursement leg, #83, which may still be its own estimate on
        // a CFR/CIF order whose freight hasn't cleared yet).
        if ($advanceInrActual !== null && $balanceInrActual !== null) {
            return [$advanceInrActual + $balanceInrActual + $freightPortion, $freightIsEstimated];
        }

        // Pre-PI order: advance/balance amounts haven't even been split
        // out yet, so there's nothing per-leg to apply a per-leg rate to
        // — the best estimate available is still the full FOB value,
        // at whichever leg's rate has been recorded so far (in practice
        // the same rate either way, this early).
        if ($advanceAmount === null && $balanceAmount === null) {
            $rate = $advanceRate ?? $balanceRate;
            return $rate !== null ? [$fobValue * $rate, true] : [0.0, true];
        }

        // Batch 3 #3: each leg now carries its own exchange rate, so a
        // leg that has already cleared (real INR actual) is never
        // re-estimated just because the OTHER leg hasn't cleared yet —
        // only the leg(s) still outstanding fall back to an estimate,
        // using THEIR OWN rate (not the other leg's).
        $advancePortion = $advanceInrActual ?? (($advanceAmount !== null && $advanceRate !== null) ? $advanceAmount * $advanceRate : null);
        $balancePortion = $balanceInrActual ?? (($balanceAmount !== null && $balanceRate !== null) ? $balanceAmount * $balanceRate : null);

        if ($advancePortion === null && $balancePortion === null) {
            // Nothing to convert with for either leg yet.
            return [$freightPortion, $freightPortion > 0.0 ? $freightIsEstimated : true];
        }

        $isEstimated = $advanceInrActual === null || $balanceInrActual === null || $freightIsEstimated;
        return [($advancePortion ?? 0.0) + ($balancePortion ?? 0.0) + $freightPortion, $isEstimated];
    }

    /**
     * 90-item backlog #83: the freight reimbursement leg (CFR/CIF orders
     * only — see revenueInInr()'s caller-facing doc comment above).
     * Mirrors the same actual-vs-estimated rule the advance/balance legs
     * use, with one addition: a leg that was never recorded at all (an
     * FOB order, where Stage 6 auto-skips and freight_amount stays null
     * forever) contributes nothing AND never marks the figure estimated
     * on its account — distinct from a CFR/CIF leg that's merely still
     * outstanding, which does.
     *
     * @return array{0: float, 1: bool} [freightPortionInr, isEstimated]
     */
    private static function freightLegInInr(?float $amount, ?float $inrActual, ?float $rate): array
    {
        if ($amount === null) {
            return [0.0, false];
        }
        if ($inrActual !== null) {
            return [$inrActual, false];
        }
        if ($rate !== null) {
            return [$amount * $rate, true];
        }
        return [0.0, true];
    }
}
