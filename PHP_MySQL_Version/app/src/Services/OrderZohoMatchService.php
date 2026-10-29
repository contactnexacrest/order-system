<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderZohoInvoiceRepository;
use App\Repositories\ZohoPurchaseRepository;

/**
 * Owner decision 2026-10-29: the per-order "App vs Zoho" check. Reads the
 * order's records in the app and what Zoho Books holds for them, and says
 * for each one whether they match. Reads only — it never changes the app
 * or Books.
 *
 * Statuses: match, not_sent (not in Books yet — the last sync error, if
 * any, says why), before_go_live (dated before the go-live date: the CA
 * books it by hand), different (in Books with another amount), missing
 * (the app sent it, but Books no longer has it), books_only (in Books for
 * this order, but not in the app).
 */
final class OrderZohoMatchService
{
    public const STATUS_LABELS = [
        'match' => 'Matches',
        'not_sent' => 'Not in Zoho Books yet',
        'before_go_live' => 'Before the go-live date',
        'different' => 'Different in Zoho Books',
        'missing' => 'Missing in Zoho Books',
        'books_only' => 'Only in Zoho Books',
    ];

    /**
     * @return array{rows: list<array{group:string, item:string, app:?string, books:?string, status:string, note:string}>, counts: array<string,int>}
     */
    public static function check(int $orderId): array
    {
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            throw new \RuntimeException("Order #{$orderId} not found.");
        }
        $pdo = Database::connection();
        $ref = (string) $order['buyer_inquiry_ref'];
        $currency = (string) ($order['currency_code'] ?? 'INR');
        $start = ZohoBooksService::syncStartDate();
        $errors = self::lastErrors($orderId);
        $rows = [];
        $add = static function (string $group, string $item, ?float $app, ?float $books, string $status, string $note = '', string $unit = 'INR') use (&$rows): void {
            $fmt = static fn (?float $v): ?string => $v === null ? null : $unit . ' ' . number_format($v, 2);
            $rows[] = ['group' => $group, 'item' => $item, 'app' => $fmt($app), 'books' => $fmt($books), 'status' => $status, 'note' => $note];
        };
        $compare = static fn (float $app, float $books): string => abs($app - $books) <= 0.01 ? 'match' : 'different';
        $notSent = static fn (?string $date): string => $start !== null && $date !== null && substr($date, 0, 10) < $start ? 'before_go_live' : 'not_sent';
        $errorFor = static fn (string $type, ?string $leg): string => $errors["{$type}:{$leg}"] ?? '';

        // --- Sales: the CI and Debit Note, the buyer's payments, the LC receipt.
        $contactId = $pdo->query('SELECT zoho_contact_id FROM clients WHERE id = ' . (int) $order['client_id'])->fetchColumn() ?: null;
        $books = $contactId !== null ? ZohoBooksService::contactRecords((string) $contactId, false) : ['invoices' => [], 'customerpayments' => []];
        $invoicesById = array_column($books['invoices'], null, 'invoice_id');
        $paymentsById = array_column($books['customerpayments'], null, 'payment_id');
        $seenInvoices = [];
        $seenPayments = [];
        $pending = array_column(array_filter(OrderZohoInvoiceRepository::documentsToSync(null), static fn (array $d): bool => $d['order_id'] === $orderId), 'kind');
        foreach (ZohoInvoiceData::KIND_LABELS as $kind => $label) {
            $known = OrderZohoInvoiceRepository::find($orderId, $kind);
            if ($known !== null) {
                $seenInvoices[] = (string) $known['zoho_invoice_id'];
                $inBooks = $invoicesById[(string) $known['zoho_invoice_id']] ?? null;
                $item = "{$label} {$known['zoho_invoice_number']}";
                if ($inBooks === null) {
                    $add('Sales', $item, (float) $known['total'], null, 'missing', 'Sent by the app, but Books no longer has it.', $currency);
                } else {
                    $add('Sales', $item, (float) $known['total'], (float) $inBooks['total'], $compare((float) $known['total'], (float) $inBooks['total']), 'Rate ' . rtrim(rtrim(number_format((float) $known['exchange_rate'], 6, '.', ''), '0'), '.'), $currency);
                }
            } elseif (in_array($kind, $pending, true)) {
                $add('Sales', $label, null, null, 'not_sent', $errorFor('invoice', $kind), $currency);
            }
        }
        $payment = $pdo->query("SELECT * FROM order_payment_status WHERE order_id = {$orderId}")->fetch() ?: [];
        foreach (['advance' => 'Advance', 'balance' => 'Balance', 'freight' => 'Freight'] as $leg => $label) {
            if (($payment["{$leg}_inr_actual"] ?? null) === null) {
                continue;
            }
            $amount = $currency === 'INR' ? (float) $payment["{$leg}_inr_actual"] : (float) $payment["{$leg}_amount"];
            $zohoId = $payment["{$leg}_zoho_reference"] ?? null;
            self::payment($add, $compare, "{$label} payment", $amount, $zohoId, $paymentsById, $seenPayments, $currency, $notSent((string) $payment["{$leg}_cleared_at"]), $errorFor('revenue_payment', $leg));
        }
        $lc = OrderLcDetailRepository::find($orderId);
        if ($lc !== null && $lc['lc_received_at'] !== null) {
            $amount = $currency === 'INR' ? (float) $lc['lc_inr_credited'] + (float) $lc['lc_bank_charges_inr'] : (float) $lc['lc_received_amount'];
            self::payment($add, $compare, 'LC payment', $amount, $lc['lc_zoho_payment_id'], $paymentsById, $seenPayments, $currency, $notSent((string) $lc['lc_received_at']), $errorFor('revenue_payment', 'lc'));
        }
        foreach ($books['invoices'] as $row) {
            if ((string) ($row['reference_number'] ?? '') === $ref && !in_array((string) $row['invoice_id'], $seenInvoices, true)) {
                $add('Sales', "Invoice {$row['invoice_number']}", null, (float) $row['total'], 'books_only', 'In Books under this order, but not sent by the app.', (string) ($row['currency_code'] ?? $currency));
            }
        }
        foreach ($books['customerpayments'] as $row) {
            if (str_starts_with((string) ($row['reference_number'] ?? ''), "{$ref}-") && !in_array((string) $row['payment_id'], $seenPayments, true)) {
                $add('Sales', "Payment {$row['reference_number']}", null, (float) $row['amount'], 'books_only', 'In Books under this order, but not sent by the app.', (string) ($row['currency_code'] ?? $currency));
            }
        }

        // --- Purchases: suppliers' and logistics partners' tax invoices and the payments made to them.
        $vendorBooks = [];
        $vendorRecords = static function (?string $vendorId) use (&$vendorBooks): array {
            if ($vendorId === null) {
                return ['bills' => [], 'vendorpayments' => []];
            }
            return $vendorBooks[$vendorId] ??= ZohoBooksService::contactRecords($vendorId, true);
        };
        $seenBills = [];
        foreach ($pdo->query("SELECT id FROM order_vendor_bills WHERE order_id = {$orderId} AND doc_kind = 'invoice' ORDER BY bill_date, id")->fetchAll() as $r) {
            $bill = ZohoPurchaseRepository::findBill((int) $r['id']);
            $item = "Bill {$bill['bill_no']} — {$bill['vendor_name']}";
            if ($bill['zoho_bill_id'] === null) {
                $add('Purchases', $item, (float) $bill['total_inr'], null, $notSent((string) $bill['bill_date']), $errorFor('vendor_bill', 'bill'));
                continue;
            }
            $seenBills[] = (string) $bill['zoho_bill_id'];
            $inBooks = array_column($vendorRecords($bill['vendor_zoho_contact_id'])['bills'], null, 'bill_id')[(string) $bill['zoho_bill_id']] ?? null;
            $inBooks === null
                ? $add('Purchases', $item, (float) $bill['total_inr'], null, 'missing', 'Sent by the app, but Books no longer has it.')
                : $add('Purchases', $item, (float) $bill['total_inr'], (float) $inBooks['total'], $compare((float) $bill['total_inr'], (float) $inBooks['total']));
        }
        foreach ($pdo->query("SELECT p.*, b.id AS bill_id FROM order_vendor_bill_payments p JOIN order_vendor_bills b ON b.id = p.bill_id WHERE b.order_id = {$orderId} ORDER BY p.paid_at, p.id")->fetchAll() as $p) {
            $bill = ZohoPurchaseRepository::findBill((int) $p['bill_id']);
            $item = "Payment {$p['payment_ref']} — {$bill['vendor_name']}";
            if ($p['zoho_payment_id'] === null) {
                $add('Purchases', $item, (float) $p['amount_inr'], null, $notSent((string) $p['paid_at']), $errorFor('vendor_payment', 'payment'));
                continue;
            }
            $inBooks = array_column($vendorRecords($bill['vendor_zoho_contact_id'])['vendorpayments'], null, 'payment_id')[(string) $p['zoho_payment_id']] ?? null;
            $inBooks === null
                ? $add('Purchases', $item, (float) $p['amount_inr'], null, 'missing', 'Sent by the app, but Books no longer has it.')
                : $add('Purchases', $item, (float) $p['amount_inr'], (float) $inBooks['amount'], $compare((float) $p['amount_inr'], (float) $inBooks['amount']));
        }
        foreach ($vendorBooks as $records) {
            foreach ($records['bills'] as $row) {
                if ((string) ($row['reference_number'] ?? '') === $ref && !in_array((string) $row['bill_id'], $seenBills, true)) {
                    $add('Purchases', "Bill {$row['bill_number']} — {$row['vendor_name']}", null, (float) $row['total'], 'books_only', 'In Books under this order, but not in the app.');
                }
            }
        }

        // --- Expenses: the order's expenses and its real paid cost entries.
        foreach (OrderExpenseRepository::forOrder($orderId) as $e) {
            $label = OrderExpenseRepository::CATEGORIES[$e['category']] ?? (string) $e['category'];
            self::expense($add, $compare, "{$label} — {$e['details']}", (float) $e['amount_inr'], $e['zoho_expense_id'] ?? null, $notSent((string) $e['expense_date']), $errorFor('order_expense', 'expense'));
        }
        foreach (OrderCostEntryRepository::forOrder($orderId) as $c) {
            if (!in_array($c['category'], OrderCostEntryRepository::ZOHO_CATEGORIES, true)) {
                continue;
            }
            $label = OrderCostEntryRepository::CATEGORIES[$c['category']];
            self::expense($add, $compare, "Cost: {$label}" . (!empty($c['description']) ? " — {$c['description']}" : ''), (float) $c['amount_inr'], $c['zoho_expense_id'], $notSent((string) ($c['incurred_at'] ?? $c['created_at'])), $errorFor('order_cost', 'cost'));
        }

        // --- Export benefits received.
        foreach ($pdo->query("SELECT * FROM ca_export_benefits WHERE order_id = {$orderId} AND received_amount > 0 ORDER BY received_at, id")->fetchAll() as $b) {
            $item = "{$b['scheme_name']} received";
            if ($b['zoho_journal_id'] === null) {
                $add('Export benefits', $item, (float) $b['received_amount'], null, $notSent((string) $b['received_at']), $errorFor('export_benefit', 'journal'));
                continue;
            }
            $journal = ZohoBooksService::readRecord('journal', (string) $b['zoho_journal_id']);
            $journal === null
                ? $add('Export benefits', $item, (float) $b['received_amount'], null, 'missing', 'Sent by the app, but Books no longer has it.')
                : $add('Export benefits', $item, (float) $b['received_amount'], (float) ($journal['total'] ?? 0), $compare((float) $b['received_amount'], (float) ($journal['total'] ?? 0)));
        }

        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }
        return ['rows' => $rows, 'counts' => $counts];
    }

    /** @param list<string> $seen */
    private static function payment(callable $add, callable $compare, string $item, float $amount, ?string $zohoId, array $inBooks, array &$seen, string $currency, string $notSentStatus, string $error): void
    {
        if ($zohoId === null) {
            $add('Sales', $item, $amount, null, $notSentStatus, $error, $currency);
            return;
        }
        $seen[] = $zohoId;
        $row = $inBooks[$zohoId] ?? null;
        $row === null
            ? $add('Sales', $item, $amount, null, 'missing', 'Sent by the app, but Books no longer has it.', $currency)
            : $add('Sales', $item, $amount, (float) $row['amount'], $compare($amount, (float) $row['amount']), '', $currency);
    }

    private static function expense(callable $add, callable $compare, string $item, float $amount, ?string $zohoId, string $notSentStatus, string $error): void
    {
        if ($zohoId === null) {
            $add('Expenses', $item, $amount, null, $notSentStatus, $error);
            return;
        }
        $row = ZohoBooksService::readRecord('expense', $zohoId);
        $row === null
            ? $add('Expenses', $item, $amount, null, 'missing', 'Sent by the app, but Books no longer has it.')
            : $add('Expenses', $item, $amount, (float) ($row['total'] ?? $row['amount'] ?? 0), $compare($amount, (float) ($row['total'] ?? $row['amount'] ?? 0)));
    }

    /** @return array<string,string> "sync_type:leg" => the latest error message for this order, when it is newer than the latest success */
    private static function lastErrors(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT sync_type, leg, status, message FROM zoho_sync_log WHERE entity_id = :id ORDER BY id');
        $stmt->execute(['id' => $orderId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $key = "{$r['sync_type']}:{$r['leg']}";
            if ($r['status'] === 'error') {
                $out[$key] = (string) $r['message'];
            } elseif ($r['status'] === 'success') {
                unset($out[$key]);
            }
        }
        return $out;
    }
}
