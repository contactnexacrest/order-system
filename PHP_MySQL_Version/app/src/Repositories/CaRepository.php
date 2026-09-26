<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\FinancialYear;

/**
 * CA / Accounting module — independent of the order-pipeline system
 * (nav-gated on ca_module_view, never on manage_orders). Phase 1 was the
 * INR settlement register; Phase 2 adds forex gain/loss, FIRC/eBRC
 * tracking, and FY/calendar revenue reports on top of the same register
 * data. Later phases (Zoho sync, expense import, reconciliation) land as
 * their own methods here rather than growing OrderPaymentStatusRepository
 * further.
 */
final class CaRepository
{
    private const LEGS = [
        ['leg' => 'advance', 'amount_col' => 'advance_amount', 'cleared_col' => 'advance_cleared_at', 'inr_col' => 'advance_inr_actual', 'inr_at_col' => 'advance_inr_actual_recorded_at', 'inr_by_col' => 'advance_inr_actual_recorded_by', 'firc_col' => 'advance_firc_reference', 'firc_at_col' => 'advance_firc_received_at', 'zoho_at_col' => 'advance_zoho_synced_at', 'zoho_ref_col' => 'advance_zoho_reference'],
        ['leg' => 'balance', 'amount_col' => 'balance_amount', 'cleared_col' => 'balance_cleared_at', 'inr_col' => 'balance_inr_actual', 'inr_at_col' => 'balance_inr_actual_recorded_at', 'inr_by_col' => 'balance_inr_actual_recorded_by', 'firc_col' => 'balance_firc_reference', 'firc_at_col' => 'balance_firc_received_at', 'zoho_at_col' => 'balance_zoho_synced_at', 'zoho_ref_col' => 'balance_zoho_reference'],
        ['leg' => 'freight', 'amount_col' => 'freight_amount', 'cleared_col' => 'freight_cleared_at', 'inr_col' => 'freight_inr_actual', 'inr_at_col' => 'freight_inr_actual_recorded_at', 'inr_by_col' => 'freight_inr_actual_recorded_by', 'firc_col' => 'freight_firc_reference', 'firc_at_col' => 'freight_firc_received_at', 'zoho_at_col' => 'freight_zoho_synced_at', 'zoho_ref_col' => 'freight_zoho_reference'],
    ];

    /**
     * One row per cleared settlement leg (advance/balance/freight),
     * flattened from OrderPaymentStatusRepository::clearedSettlements() —
     * newest cleared date first. Each row also carries the forex
     * gain/loss (inr_actual - foreign_amount * assumed_exchange_rate, when
     * both are on record) and whether its FIRC/eBRC reference is still
     * missing past the configured alert window.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function settlementRegister(): array
    {
        $alertDays = (int) (CompanySettingsRepository::get('fema_realization_alert_days') ?? '270');
        $todayTs = time();

        $rows = [];
        foreach (OrderPaymentStatusRepository::clearedSettlements() as $ops) {
            foreach (self::LEGS as $legDef) {
                if ($ops[$legDef['cleared_col']] === null) {
                    continue;
                }
                $foreignAmount = $ops[$legDef['amount_col']] !== null ? (float) $ops[$legDef['amount_col']] : null;
                $inrActual = $ops[$legDef['inr_col']] !== null ? (float) $ops[$legDef['inr_col']] : null;
                $assumedRate = $ops['assumed_exchange_rate'] !== null ? (float) $ops['assumed_exchange_rate'] : null;

                $expectedInr = ($foreignAmount !== null && $assumedRate !== null) ? $foreignAmount * $assumedRate : null;
                $forexGainLoss = ($inrActual !== null && $expectedInr !== null) ? $inrActual - $expectedInr : null;

                $clearedAtTs = strtotime((string) $ops[$legDef['cleared_col']]);
                $daysSinceCleared = $clearedAtTs !== false ? (int) floor(($todayTs - $clearedAtTs) / 86400) : 0;
                $fircPending = $ops[$legDef['firc_col']] === null && $daysSinceCleared >= $alertDays;

                $rows[] = [
                    'order_id' => (int) $ops['order_id'],
                    'client_id' => (int) $ops['client_id'],
                    'buyer_inquiry_ref' => $ops['buyer_inquiry_ref'],
                    'company_legal_name' => $ops['company_legal_name'],
                    'currency_code' => $ops['currency_code'],
                    'leg' => $legDef['leg'],
                    'foreign_amount' => $foreignAmount,
                    'cleared_at' => $ops[$legDef['cleared_col']],
                    'inr_actual' => $inrActual,
                    'inr_actual_recorded_at' => $ops[$legDef['inr_at_col']],
                    'inr_actual_recorded_by' => $ops[$legDef['inr_by_col']] !== null ? (int) $ops[$legDef['inr_by_col']] : null,
                    'assumed_exchange_rate' => $assumedRate,
                    'expected_inr' => $expectedInr,
                    'forex_gain_loss' => $forexGainLoss,
                    'firc_reference' => $ops[$legDef['firc_col']],
                    'firc_received_at' => $ops[$legDef['firc_at_col']],
                    'firc_pending' => $fircPending,
                    'zoho_synced_at' => $ops[$legDef['zoho_at_col']],
                    'zoho_reference' => $ops[$legDef['zoho_ref_col']],
                ];
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['cleared_at'], (string) $a['cleared_at']));
        return $rows;
    }

    /**
     * Every leg with an INR actual on record that hasn't been pushed to
     * Zoho Books yet — what CaSyncService::syncPendingRevenue() works
     * through on each run (manual or scheduled).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function legsPendingZohoSync(): array
    {
        return array_values(array_filter(
            self::settlementRegister(),
            static fn(array $r): bool => $r['inr_actual'] !== null && $r['zoho_synced_at'] === null
        ));
    }

    /** FY labels (e.g. '2026-27') with at least one cleared leg, newest first. @return array<int,string> */
    public static function availableFinancialYears(): array
    {
        $dates = array_column(self::settlementRegister(), 'cleared_at');
        return FinancialYear::labelsPresentIn($dates);
    }

    /** Calendar years with at least one cleared leg, newest first. @return array<int,int> */
    public static function availableCalendarYears(): array
    {
        $years = [];
        foreach (array_column(self::settlementRegister(), 'cleared_at') as $date) {
            $years[(int) substr((string) $date, 0, 4)] = true;
        }
        $result = array_keys($years);
        rsort($result);
        return $result;
    }

    /**
     * Revenue summary for one financial year or one calendar year —
     * totals by currency, plus overall INR-actual and forex gain/loss
     * totals. Computed from settlementRegister() rather than a separate
     * query, so this can never disagree with the register table itself.
     *
     * @param 'fy'|'calendar' $mode
     */
    public static function revenueReport(string $mode, string $period): array
    {
        if ($mode === 'fy') {
            $bounds = FinancialYear::bounds($period);
            $from = $bounds['start'];
            $to = $bounds['end'];
        } else {
            $from = $period . '-01-01';
            $to = $period . '-12-31';
        }

        $rows = array_values(array_filter(
            self::settlementRegister(),
            static fn(array $r): bool => substr((string) $r['cleared_at'], 0, 10) >= $from && substr((string) $r['cleared_at'], 0, 10) <= $to
        ));

        $byCurrency = [];
        $totalInrActual = 0.0;
        $totalForexGainLoss = 0.0;
        $legsMissingInr = 0;
        foreach ($rows as $r) {
            $cc = $r['currency_code'];
            $byCurrency[$cc] ??= ['foreign_total' => 0.0, 'inr_actual_total' => 0.0, 'leg_count' => 0];
            $byCurrency[$cc]['foreign_total'] += (float) ($r['foreign_amount'] ?? 0);
            $byCurrency[$cc]['inr_actual_total'] += (float) ($r['inr_actual'] ?? 0);
            $byCurrency[$cc]['leg_count']++;
            if ($r['inr_actual'] === null) {
                $legsMissingInr++;
            } else {
                $totalInrActual += $r['inr_actual'];
            }
            if ($r['forex_gain_loss'] !== null) {
                $totalForexGainLoss += $r['forex_gain_loss'];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'byCurrency' => $byCurrency,
            'totalInrActual' => $totalInrActual,
            'totalForexGainLoss' => $totalForexGainLoss,
            'legsMissingInr' => $legsMissingInr,
            'legCount' => count($rows),
        ];
    }
}
