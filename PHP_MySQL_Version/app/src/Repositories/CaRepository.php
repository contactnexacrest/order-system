<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * CA / Accounting module (Phase 1) — independent of the order-pipeline
 * system (nav-gated on ca_module_view, never on manage_orders). Phase 1
 * is just the INR settlement register; later phases (Zoho sync, expense
 * import, reconciliation, FY/calendar reports) land as their own methods
 * here rather than growing OrderPaymentStatusRepository further.
 */
final class CaRepository
{
    /**
     * One row per cleared settlement leg (advance/balance/freight),
     * flattened from OrderPaymentStatusRepository::clearedSettlements()
     * for the register table — newest cleared date first.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function settlementRegister(): array
    {
        $rows = [];
        foreach (OrderPaymentStatusRepository::clearedSettlements() as $ops) {
            foreach (
                [
                    ['leg' => 'advance', 'amount_col' => 'advance_amount', 'cleared_col' => 'advance_cleared_at', 'inr_col' => 'advance_inr_actual', 'inr_at_col' => 'advance_inr_actual_recorded_at', 'inr_by_col' => 'advance_inr_actual_recorded_by'],
                    ['leg' => 'balance', 'amount_col' => 'balance_amount', 'cleared_col' => 'balance_cleared_at', 'inr_col' => 'balance_inr_actual', 'inr_at_col' => 'balance_inr_actual_recorded_at', 'inr_by_col' => 'balance_inr_actual_recorded_by'],
                    ['leg' => 'freight', 'amount_col' => 'freight_amount', 'cleared_col' => 'freight_cleared_at', 'inr_col' => 'freight_inr_actual', 'inr_at_col' => 'freight_inr_actual_recorded_at', 'inr_by_col' => 'freight_inr_actual_recorded_by'],
                ] as $legDef
            ) {
                if ($ops[$legDef['cleared_col']] === null) {
                    continue;
                }
                $rows[] = [
                    'order_id' => (int) $ops['order_id'],
                    'buyer_inquiry_ref' => $ops['buyer_inquiry_ref'],
                    'company_legal_name' => $ops['company_legal_name'],
                    'currency_code' => $ops['currency_code'],
                    'leg' => $legDef['leg'],
                    'foreign_amount' => $ops[$legDef['amount_col']],
                    'cleared_at' => $ops[$legDef['cleared_col']],
                    'inr_actual' => $ops[$legDef['inr_col']],
                    'inr_actual_recorded_at' => $ops[$legDef['inr_at_col']],
                    'inr_actual_recorded_by' => $ops[$legDef['inr_by_col']] !== null ? (int) $ops[$legDef['inr_by_col']] : null,
                ];
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['cleared_at'], (string) $a['cleared_at']));
        return $rows;
    }
}
