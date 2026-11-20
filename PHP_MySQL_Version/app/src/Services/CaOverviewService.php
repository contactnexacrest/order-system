<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\CaExportBenefitRepository;

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
 * Node twin: NODE_MySQL_Version/app/src/services/caOverviewService.js
 */
final class CaOverviewService
{
    public const FILTERS = ['all', 'firc', 'zoho', 'estimated'];

    /** A register row whose FIRC / eBRC proof is overdue (the row's own flag). */
    public static function isFircPending(array $r): bool
    {
        return !empty($r['firc_pending']);
    }

    /** A register row whose Zoho column reads "Pending": rupees recorded, not yet in Zoho Books. */
    public static function isNotInZoho(array $r): bool
    {
        return $r['inr_actual'] !== null && $r['zoho_synced_at'] === null;
    }

    /** A register row shown at the recorded rate only (INR credited not recorded yet). */
    public static function isEstimated(array $r): bool
    {
        return $r['inr_actual'] === null && ($r['inr_estimated'] ?? null) !== null;
    }

    public static function normaliseFilter(?string $show): string
    {
        return in_array($show, self::FILTERS, true) ? (string) $show : 'all';
    }

    /** @param array<int, array<string,mixed>> $rows @return array<int, array<string,mixed>> */
    public static function filterRegister(array $rows, string $show): array
    {
        $test = match ($show) {
            'firc' => [self::class, 'isFircPending'],
            'zoho' => [self::class, 'isNotInZoho'],
            'estimated' => [self::class, 'isEstimated'],
            default => null,
        };
        return $test === null ? $rows : array_values(array_filter($rows, $test));
    }

    /**
     * The summary row above the register, over the rows the person may see.
     *
     * @param array<int, array<string,mixed>> $rows
     * @return array{legs:int, inr_credited:float, forex_net:float, forex_known:bool, firc_pending:int, not_in_zoho:int, estimated:int}
     */
    public static function registerSummary(array $rows): array
    {
        $inr = 0.0;
        $forex = 0.0;
        $forexKnown = false;
        $firc = $zoho = $est = 0;
        foreach ($rows as $r) {
            $inr += (float) ($r['inr_actual'] ?? 0);
            if ($r['forex_gain_loss'] !== null) {
                $forex += (float) $r['forex_gain_loss'];
                $forexKnown = true;
            }
            $firc += self::isFircPending($r) ? 1 : 0;
            $zoho += self::isNotInZoho($r) ? 1 : 0;
            $est += self::isEstimated($r) ? 1 : 0;
        }
        return [
            'legs' => count($rows),
            'inr_credited' => round($inr, 2),
            'forex_net' => round($forex, 2),
            'forex_known' => $forexKnown,
            'firc_pending' => $firc,
            'not_in_zoho' => $zoho,
            'estimated' => $est,
        ];
    }

    /** Bank statement lines matched to neither a receipt nor an expense. */
    public static function unmatchedBankLines(): int
    {
        return (int) Database::connection()->query(
            'SELECT COUNT(*) FROM ca_bank_statement_lines WHERE matched_order_id IS NULL AND matched_expense_id IS NULL'
        )->fetchColumn();
    }

    /**
     * TDS on the expenses of the given month and the date it must be deposited by:
     * the 7th of the next month (30 April for March), as the TDS Payable Summary groups it.
     *
     * @return array{amount:float, month:string, due:string}
     */
    public static function tdsForMonth(string $today): array
    {
        $month = substr($today, 0, 7);
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(tds_amount), 0) FROM ca_expenses
             WHERE is_tds_applicable = 1 AND DATE_FORMAT(expense_date, '%Y-%m') = ?"
        );
        $stmt->execute([$month]);
        return ['amount' => round((float) $stmt->fetchColumn(), 2), 'month' => $month, 'due' => self::tdsDueDate($month)];
    }

    /** 'YYYY-MM' → the deposit date: 7th of the next month; March → 30 April. */
    public static function tdsDueDate(string $month): string
    {
        $y = (int) substr($month, 0, 4);
        $m = (int) substr($month, 5, 2);
        if ($m === 3) {
            return sprintf('%04d-04-30', $y);
        }
        $m++;
        if ($m === 13) {
            $m = 1;
            $y++;
        }
        return sprintf('%04d-%02d-07', $y, $m);
    }

    /** Export-benefit claims not yet received, within the person's CA reports level (as on Export Benefits). */
    public static function benefitClaimsWaiting(): int
    {
        $benefits = CaExportBenefitRepository::all();
        $level = ReportAccess::level('ca_reports');
        if ($level === ReportAccess::OWN || $level === ReportAccess::DEPARTMENT) {
            $benefits = ReportAccess::visibleRows('ca_reports', $benefits);
        }
        return count(array_filter($benefits, static fn(array $b): bool => $b['received_amount'] === null));
    }

    /**
     * The "Needs attention" boxes, in page order. A box appears only for a person who
     * may open the page its button leads to.
     *
     * @param array{firc_pending:int, not_in_zoho:int} $summary registerSummary() of the rows the person sees
     * @return array<int, array{key:string, value:string, label:string, button:string, href:string, tone:string}>
     */
    public static function attention(array $summary, bool $canManageCa, bool $allLevel, string $today): array
    {
        $boxes = [];
        $tone = static fn(float $n, string $busy): string => $n > 0 ? $busy : 'ok';

        $boxes[] = ['key' => 'firc', 'value' => (string) $summary['firc_pending'], 'label' => 'FIRC / eBRC pending',
            'button' => 'Show', 'href' => '/ca?show=firc#register', 'tone' => $tone($summary['firc_pending'], 'bad')];
        if ($allLevel) {
            $n = self::unmatchedBankLines();
            $boxes[] = ['key' => 'bank', 'value' => (string) $n, 'label' => 'Bank lines not matched',
                'button' => 'Reconcile', 'href' => '/ca/reconciliation', 'tone' => $tone($n, 'bad')];
        }
        if ($canManageCa) {
            $boxes[] = ['key' => 'zoho', 'value' => (string) $summary['not_in_zoho'], 'label' => 'Not yet in Zoho',
                'button' => 'Sync', 'href' => '/ca/zoho-sync', 'tone' => $tone($summary['not_in_zoho'], 'warn')];
        }
        if ($allLevel) {
            $tds = self::tdsForMonth($today);
            $boxes[] = ['key' => 'tds', 'value' => '₹' . number_format($tds['amount'], 2),
                'label' => 'TDS on this month\'s expenses — deposit by ' . date('j M Y', (int) strtotime($tds['due'])),
                'button' => 'Summary', 'href' => '/ca/tds-summary', 'tone' => $tone($tds['amount'], 'warn')];
        }
        $n = self::benefitClaimsWaiting();
        $boxes[] = ['key' => 'benefits', 'value' => (string) $n, 'label' => 'Benefit claims waiting for money',
            'button' => 'Claims', 'href' => '/ca/export-benefits', 'tone' => $tone($n, 'warn')];
        return $boxes;
    }
}
