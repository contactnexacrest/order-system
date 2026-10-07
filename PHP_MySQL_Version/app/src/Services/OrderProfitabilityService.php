<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderSupplierPoRepository;

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
 *   - Supplier cost: order_supplier_po.total_payable_inr (already INR).
 *   - Freight/insurance cost: order_freight.confirmed_freight_rate +
 *     insurance_amount. ASSUMPTION (documented, not verified against a
 *     real invoice): both are treated as INR figures, the same convention
 *     order_supplier_po already uses, since in practice NexaCrest pays
 *     its freight forwarder domestically — see docs/SOP for the caveat if
 *     a forwarder is ever paid in foreign currency instead.
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
        $payment = OrderPaymentStatusRepository::find($orderId);

        [$revenueInr, $isEstimated] = self::revenueInInr($fobValue, $payment);

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        $supplierCostInr = ($supplierPo !== null && $supplierPo['total_payable_inr'] !== null) ? (float) $supplierPo['total_payable_inr'] : 0.0;

        $freight = OrderFreightRepository::find($orderId);
        $freightCostInr = ($freight !== null && $freight['confirmed_freight_rate'] !== null) ? (float) $freight['confirmed_freight_rate'] : 0.0;
        $insuranceCostInr = ($freight !== null && $freight['insurance_amount'] !== null) ? (float) $freight['insurance_amount'] : 0.0;

        $costEntries = OrderCostEntryRepository::forOrder($orderId);
        $otherCostsInr = OrderCostEntryRepository::totalForOrder($orderId);

        $byCategory = [];
        foreach ($costEntries as $entry) {
            $cat = $entry['category'];
            $byCategory[$cat] = ($byCategory[$cat] ?? 0.0) + (float) $entry['amount_inr'];
        }

        $totalCostInr = $supplierCostInr + $freightCostInr + $insuranceCostInr + $otherCostsInr;
        $profitInr = $revenueInr - $totalCostInr;
        $marginPct = $revenueInr > 0 ? round($profitInr / $revenueInr * 100, 2) : null;

        return [
            'currency_code'        => $currencyCode,
            'fob_value_foreign'    => $fobValue,
            'revenue_inr'          => round($revenueInr, 2),
            'revenue_is_estimated' => $isEstimated,
            'supplier_cost_inr'    => round($supplierCostInr, 2),
            'freight_cost_inr'     => round($freightCostInr, 2),
            'insurance_cost_inr'   => round($insuranceCostInr, 2),
            'other_costs_inr'      => round($otherCostsInr, 2),
            'other_costs_by_category' => $byCategory,
            'total_cost_inr'       => round($totalCostInr, 2),
            'profit_inr'           => round($profitInr, 2),
            'margin_pct'           => $marginPct,
        ];
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

        // Both legs cleared with a real INR actual recorded — the exact,
        // fully-realized figure, no estimate involved.
        if ($advanceInrActual !== null && $balanceInrActual !== null) {
            return [$advanceInrActual + $balanceInrActual, false];
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
            return [0.0, true];
        }

        $isEstimated = $advanceInrActual === null || $balanceInrActual === null;
        return [($advancePortion ?? 0.0) + ($balancePortion ?? 0.0), $isEstimated];
    }
}
