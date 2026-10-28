<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CaExportBenefitRepository;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;

/**
 * Payment Overview → Ledger. Every money event recorded against one order,
 * read from the order's own records (nothing borrowed from a master list).
 *
 * Owner decision (2026-10-26): the full picture — buyer receipts with the
 * day's rate and INR value, supplier advance/balance paid, every expense
 * (ECGC, inspection, travel, CHA, ...), export benefits received, and the
 * net — only for holders of manage_order_financials. Everyone else sees the
 * buyer-receipt events, as before.
 */
final class OrderLedgerService
{
    private const LEGS = ['advance' => 'Advance', 'freight' => 'Freight', 'balance' => 'Balance'];

    /**
     * @return array{rows: array<int, array<string,mixed>>, totals: array<string,float|int>|null}
     *   Each row: date, event, direction ('in'|'out'|'info'), amount (in its currency), currency,
     *   rate (₹ per unit, buyer receipts only), inr (the INR value counted in the totals, or null), source.
     */
    public static function forOrder(int $orderId, string $orderCurrency, bool $full): array
    {
        $rows = [];
        $payment = OrderPaymentStatusRepository::find($orderId) ?? [];
        foreach (self::LEGS as $leg => $label) {
            $amount = $payment[$leg . '_amount'] ?? null;
            $rate = $payment[$leg . '_exchange_rate'] ?? null;
            $inrActual = $payment[$leg . '_inr_actual'] ?? null;
            if (!empty($payment[$leg . '_remittance_received_at'])) {
                $inr = $inrActual !== null ? (float) $inrActual : ($rate !== null && $amount !== null ? (float) $amount * (float) $rate : null);
                $rows[] = [
                    'date' => (string) $payment[$leg . '_remittance_received_at'],
                    'event' => "{$label} remittance received from buyer",
                    'direction' => 'in',
                    'amount' => $amount,
                    'currency' => $orderCurrency,
                    'rate' => $rate,
                    'inr' => $inr,
                    'inr_basis' => $inrActual !== null ? 'actual' : ($inr !== null ? 'rate' : null),
                    'source' => 'Staff-recorded',
                ];
            }
            if (!empty($payment[$leg . '_cleared_at'])) {
                $rows[] = self::info((string) $payment[$leg . '_cleared_at'], "{$label} cleared (funds confirmed)", $amount, $orderCurrency, 'Staff-recorded');
            }
        }
        foreach (ClientPaymentReportRepository::forOrder($orderId) as $report) {
            $rows[] = self::info(
                (string) ($report['payment_date'] ?? $report['reported_at']),
                ucfirst((string) $report['payment_type']) . ' payment reported by the client',
                $report['amount'],
                $orderCurrency,
                $report['status'] === 'reviewed' ? 'Client-reported (reviewed)' : 'Client-reported (new)'
            );
        }

        if (!$full) {
            return ['rows' => self::sorted($rows), 'totals' => null];
        }

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        if ($supplierPo) {
            foreach (['advance' => 'Advance', 'balance' => 'Balance'] as $leg => $label) {
                if (!empty($supplierPo[$leg . '_paid_at'])) {
                    $paid = $supplierPo[$leg . '_paid_amount_inr'] ?? $supplierPo[$leg . '_amount_inr'] ?? null;
                    $rows[] = self::out((string) $supplierPo[$leg . '_paid_at'], "Supplier {$label} paid" . (!empty($supplierPo['supplier_legal_name']) ? " — {$supplierPo['supplier_legal_name']}" : ''), $paid, 'Supplier PO ' . ($supplierPo['supplier_po_reference'] ?? ''));
                }
            }
        }
        // Owner decisions (2026-10-27): logistics partners' bills paid, and the
        // order's actual expenses. Real travel expenses replace the provisional
        // travel % line.
        // Owner decision (2026-10-28, Option B): one line per payment — a
        // bill can be paid in more than one part.
        foreach (OrderVendorBillRepository::forOrder($orderId, 'logistics') as $bill) {
            foreach ($bill['payments'] as $payment) {
                $rows[] = self::out(
                    (string) $payment['paid_at'],
                    'Logistics paid — ' . ($bill['partner_name'] ?? 'Partner') . ' (' . (OrderVendorBillRepository::LOGISTICS_SERVICES[$bill['bill_for']] ?? $bill['bill_for']) . ', ' . strtolower(OrderVendorBillRepository::DOC_KINDS[$bill['doc_kind']]) . ' ' . $bill['bill_no'] . ')',
                    $payment['amount_inr'],
                    'Logistics bill · ' . $payment['payment_mode'] . ' ' . $payment['payment_ref']
                );
            }
        }
        $expenseSummary = OrderExpenseRepository::summary($orderId);
        foreach (OrderExpenseRepository::forOrder($orderId) as $expense) {
            $rows[] = self::out(
                (string) $expense['expense_date'],
                'Expense — ' . (OrderExpenseRepository::CATEGORIES[$expense['category']] ?? $expense['category']) . ': ' . $expense['details'],
                $expense['amount_inr'],
                !empty($expense['receipt_file_id']) ? 'Order expense (receipt)' : 'Order expense (no receipt)'
            );
        }
        foreach (OrderCostEntryRepository::forOrder($orderId) as $entry) {
            if ($entry['category'] === 'travel_expenses' && $expenseSummary['has_travel']) {
                continue;
            }
            $label = OrderCostEntryRepository::CATEGORIES[$entry['category']] ?? $entry['category'];
            $rows[] = self::out(
                (string) ($entry['incurred_at'] ?? $entry['created_at']),
                'Expense — ' . $label . (!empty($entry['description']) ? ": {$entry['description']}" : ''),
                $entry['amount_inr'],
                'Order cost entry'
            );
        }
        foreach (CaExportBenefitRepository::forOrder($orderId) as $benefit) {
            $rows[] = self::info((string) $benefit['claimed_at'], "Export benefit claimed — {$benefit['scheme_name']}", $benefit['claimed_amount'], (string) $benefit['currency_code'], 'Export benefit claim');
            if ($benefit['received_amount'] !== null) {
                $isInr = strtoupper((string) $benefit['currency_code']) === 'INR';
                $rows[] = [
                    'date' => (string) ($benefit['received_at'] ?? $benefit['claimed_at']),
                    'event' => "Export benefit received — {$benefit['scheme_name']}",
                    'direction' => 'in',
                    'amount' => $benefit['received_amount'],
                    'currency' => (string) $benefit['currency_code'],
                    'rate' => null,
                    'inr' => $isInr ? (float) $benefit['received_amount'] : null,
                    'inr_basis' => $isInr ? 'actual' : null,
                    'source' => 'Export benefit',
                ];
            }
        }

        $rows = self::sorted($rows);
        $totals = ['buyer_foreign' => 0.0, 'buyer_inr' => 0.0, 'buyer_without_rate' => 0, 'benefits_inr' => 0.0, 'supplier_inr' => 0.0, 'logistics_inr' => 0.0, 'expenses_inr' => 0.0];
        foreach ($rows as $row) {
            if ($row['direction'] === 'in' && $row['source'] === 'Staff-recorded') {
                $totals['buyer_foreign'] += (float) $row['amount'];
                if ($row['inr'] === null) {
                    $totals['buyer_without_rate']++;
                } else {
                    $totals['buyer_inr'] += $row['inr'];
                }
            } elseif ($row['direction'] === 'in' && $row['inr'] !== null) {
                $totals['benefits_inr'] += $row['inr'];
            } elseif ($row['direction'] === 'out') {
                $key = str_starts_with($row['event'], 'Supplier ') ? 'supplier_inr' : (str_starts_with($row['event'], 'Logistics ') ? 'logistics_inr' : 'expenses_inr');
                $totals[$key] += (float) $row['inr'];
            }
        }
        $totals['net_inr'] = $totals['buyer_inr'] + $totals['benefits_inr'] - $totals['supplier_inr'] - $totals['logistics_inr'] - $totals['expenses_inr'];
        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @return array<string,mixed> */
    private static function info(string $date, string $event, $amount, string $currency, string $source): array
    {
        return ['date' => $date, 'event' => $event, 'direction' => 'info', 'amount' => $amount, 'currency' => $currency, 'rate' => null, 'inr' => null, 'inr_basis' => null, 'source' => $source];
    }

    /** @return array<string,mixed> */
    private static function out(string $date, string $event, $amountInr, string $source): array
    {
        return ['date' => $date, 'event' => $event, 'direction' => 'out', 'amount' => $amountInr, 'currency' => 'INR', 'rate' => null, 'inr' => $amountInr !== null ? (float) $amountInr : 0.0, 'inr_basis' => 'actual', 'source' => $source];
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>>
     */
    private static function sorted(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp(substr($a['date'], 0, 10), substr($b['date'], 0, 10)));
        return $rows;
    }
}
