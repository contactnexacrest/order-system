<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\FinancialYear;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\CaFyLockRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderZohoInvoiceRepository;
use App\Repositories\ZohoPurchaseRepository;
use App\Repositories\ZohoSyncLogRepository;

/**
 * Owner decision 2026-10-29: the app is the source of truth for what it
 * sends to Zoho Books. At the start of each sync, every record the app has
 * already sent (dated on or after the go-live date) is checked against
 * Books:
 *
 * - deleted in Books: the app forgets the Books link, so the same sync
 *   sends it again ("Re-created … — it had been deleted in Zoho Books");
 * - a different amount in Books: it is put back to the app's values
 *   ("Restored … — it had been changed in Zoho Books").
 *
 * A record dated in a financial year the CA has locked in the app is never
 * changed automatically: it is logged for the CA to decide. Books is read
 * a list at a time (per customer, per vendor, all expenses, all journals),
 * never one call per record, except to confirm a record is really gone.
 */
final class ZohoRestoreService
{
    private const RECREATED = 'Re-created in Zoho Books — it had been deleted there. ';
    private const RESTORED = "Restored to the app's values — it had been changed in Zoho Books. ";

    /** @var array<string, string> record key => the note its next Sync Log row starts with */
    private static array $notes = [];

    /** @var bool|null whether this process's scheduled run is the day's full check */
    private static ?bool $dailyDue = null;

    /**
     * Owner decision 2026-10-29: checking every record already in Books
     * reads Books for every customer and vendor, and Zoho limits API calls
     * per day — so a scheduled run does it at most once every 23 hours,
     * while Sync Now (manual) always does.
     */
    public static function due(string $triggeredBy): bool
    {
        if ($triggeredBy === 'manual') {
            return true;
        }
        if (self::$dailyDue === null) {
            $last = (string) \App\Repositories\CompanySettingsRepository::get('zoho_books_restore_checked_at');
            self::$dailyDue = $last === '' || strtotime($last) === false || strtotime($last) <= time() - 23 * 3600;
        }
        return self::$dailyDue;
    }

    /** A scheduled run finished the day's full check: remember when. */
    public static function markScheduledCheckDone(): void
    {
        \App\Repositories\CompanySettingsRepository::set('zoho_books_restore_checked_at', date('Y-m-d H:i:s'));
        self::$dailyDue = null;
    }

    /** The note for a record the sync is about to send again or update (once), else ''. */
    public static function note(string $key): string
    {
        $note = self::$notes[$key] ?? '';
        unset(self::$notes[$key]);
        return $note;
    }

    /**
     * The CI / Debit Notes, the buyer's payments and the LC payments.
     *
     * @return array{restored:int, failed:int}
     */
    public static function revenue(?string $startDate, string $triggeredBy, ?int $userId): array
    {
        if (!self::due($triggeredBy)) {
            return ['restored' => 0, 'failed' => 0];
        }
        $out = ['restored' => 0, 'failed' => 0];
        $pdo = Database::connection();
        $allowed = static fn (array $r): bool => ZohoGuard::isProduction() ? (int) $r['is_test_or_sample'] !== 1 : str_starts_with((string) $r['company_legal_name'], ZohoGuard::TEST_PREFIX);
        $flags = '(o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample';

        $invoices = array_filter($pdo->query("SELECT z.*, o.client_id, o.company_legal_name, c.zoho_contact_id, COALESCE(d.document_date, DATE(d.generated_at), DATE(z.pushed_at)) AS record_date, {$flags}
            FROM order_zoho_invoices z JOIN orders o ON o.id = z.order_id JOIN clients c ON c.id = o.client_id LEFT JOIN documents d ON d.id = z.document_id")->fetchAll(), $allowed);
        $payments = [];
        foreach ($pdo->query("SELECT ops.*, o.client_id, o.company_legal_name, o.buyer_inquiry_ref, o.currency_code, c.zoho_contact_id, {$flags}
            FROM order_payment_status ops JOIN orders o ON o.id = ops.order_id JOIN clients c ON c.id = o.client_id")->fetchAll() as $ops) {
            if (!$allowed($ops)) {
                continue;
            }
            foreach (['advance', 'balance', 'freight'] as $leg) {
                if ($ops["{$leg}_zoho_reference"] !== null) {
                    $currency = (string) ($ops['currency_code'] ?? 'INR');
                    $payments[] = ['key' => "leg:{$ops['order_id']}:{$leg}", 'leg' => $leg, 'order_id' => (int) $ops['order_id'], 'contact' => $ops['zoho_contact_id'], 'id' => (string) $ops["{$leg}_zoho_reference"],
                        'date' => substr((string) $ops["{$leg}_cleared_at"], 0, 10), 'amount' => $currency === 'INR' ? (float) $ops["{$leg}_inr_actual"] : (float) $ops["{$leg}_amount"], 'applied' => $ops["{$leg}_zoho_applied_at"] !== null,
                        'args' => [(int) $ops['client_id'], (string) $ops['company_legal_name'], $currency, $ops["{$leg}_amount"] !== null ? (float) $ops["{$leg}_amount"] : null, (float) $ops["{$leg}_inr_actual"], substr((string) $ops["{$leg}_cleared_at"], 0, 10), "{$ops['buyer_inquiry_ref']}-{$leg}"], 'charges' => 0.0];
                }
            }
        }
        foreach ($pdo->query("SELECT l.*, o.client_id, o.company_legal_name, o.buyer_inquiry_ref, o.currency_code, c.zoho_contact_id, {$flags}
            FROM order_lc_details l JOIN orders o ON o.id = l.order_id JOIN clients c ON c.id = o.client_id WHERE l.lc_zoho_payment_id IS NOT NULL")->fetchAll() as $lc) {
            if (!$allowed($lc)) {
                continue;
            }
            $currency = (string) ($lc['currency_code'] ?? 'INR');
            $payments[] = ['key' => "lc:{$lc['order_id']}", 'leg' => 'lc', 'order_id' => (int) $lc['order_id'], 'contact' => $lc['zoho_contact_id'], 'id' => (string) $lc['lc_zoho_payment_id'], 'date' => (string) $lc['lc_received_at'],
                'amount' => $currency === 'INR' ? (float) $lc['lc_inr_credited'] + (float) $lc['lc_bank_charges_inr'] : (float) $lc['lc_received_amount'], 'applied' => $lc['lc_zoho_applied_at'] !== null,
                'args' => [(int) $lc['client_id'], (string) $lc['company_legal_name'], $currency, (float) $lc['lc_received_amount'], (float) $lc['lc_inr_credited'], (string) $lc['lc_received_at'], "{$lc['buyer_inquiry_ref']}-lc"], 'charges' => (float) $lc['lc_bank_charges_inr']];
        }

        $books = [];
        $read = static function (?string $contactId) use (&$books): array {
            return $contactId === null ? ['invoices' => [], 'customerpayments' => []] : ($books[$contactId] ??= ZohoBooksService::contactRecords($contactId, false));
        };
        $inScope = static fn (string $date): bool => $startDate === null || $date >= $startDate;

        foreach ($invoices as $inv) {
            if (!$inScope((string) $inv['record_date'])) {
                continue;
            }
            $label = ZohoInvoiceData::KIND_LABELS[$inv['kind']] . " {$inv['zoho_invoice_number']}";
            $key = "invoice:{$inv['order_id']}:{$inv['kind']}";
            try {
                $row = array_column($read($inv['zoho_contact_id'])['invoices'], null, 'invoice_id')[(string) $inv['zoho_invoice_id']] ?? ZohoBooksService::invoiceByNumber((string) $inv['zoho_invoice_number']);
                if ($row === null) {
                    if (self::locked((string) $inv['record_date'], 'invoice', (int) $inv['order_id'], $inv['kind'], (string) $inv['zoho_invoice_id'], "{$label} was deleted in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    OrderZohoInvoiceRepository::forgetZohoInvoice((string) $inv['zoho_invoice_id']);
                    $legs = $inv['kind'] === 'ci' ? ['advance', 'balance'] : ['freight'];
                    foreach ($legs as $leg) {
                        $pdo->prepare("UPDATE order_payment_status SET {$leg}_zoho_applied_at = NULL WHERE order_id = :id AND {$leg}_zoho_synced_at IS NOT NULL")->execute(['id' => $inv['order_id']]);
                    }
                    if ($inv['kind'] === 'ci') {
                        $pdo->prepare('UPDATE order_lc_details SET lc_zoho_applied_at = NULL WHERE order_id = :id AND lc_zoho_synced_at IS NOT NULL')->execute(['id' => $inv['order_id']]);
                    }
                    self::$notes[$key] = self::RECREATED;
                } elseif (abs((float) $row['total'] - (float) $inv['total']) > 0.01) {
                    if (self::locked((string) $inv['record_date'], 'invoice', (int) $inv['order_id'], $inv['kind'], (string) $inv['zoho_invoice_id'], "{$label} was changed in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    $pdo->prepare("UPDATE order_zoho_invoices SET pushed_hash = '' WHERE zoho_invoice_id = :id")->execute(['id' => $inv['zoho_invoice_id']]);
                    self::$notes[$key] = self::RESTORED . '(Books had ' . number_format((float) $row['total'], 2, '.', '') . ') ';
                }
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('invoice', 'order', (int) $inv['order_id'], $inv['kind'], 'error', (string) $inv['zoho_invoice_id'], "Could not check {$label} in Zoho Books: " . $e->getMessage(), $triggeredBy, $userId);
                $out['failed']++;
            }
        }

        foreach ($payments as $p) {
            if (!$inScope($p['date'])) {
                continue;
            }
            $what = ($p['leg'] === 'lc' ? 'LC payment' : ucfirst($p['leg']) . ' payment') . " {$p['args'][6]}";
            try {
                $row = array_column($read($p['contact'])['customerpayments'], null, 'payment_id')[$p['id']] ?? ZohoBooksService::readRecord('customerpayment', $p['id']);
                if ($row === null) {
                    if (self::locked($p['date'], 'revenue_payment', $p['order_id'], $p['leg'], $p['id'], "{$what} was deleted in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    if ($p['leg'] === 'lc') {
                        OrderLcDetailRepository::forgetZohoPayment($p['id']);
                    } else {
                        $pdo->prepare("UPDATE order_payment_status SET {$p['leg']}_zoho_synced_at = NULL, {$p['leg']}_zoho_reference = NULL, {$p['leg']}_zoho_applied_at = NULL WHERE order_id = :id")->execute(['id' => $p['order_id']]);
                    }
                    self::$notes[$p['key']] = self::RECREATED;
                } elseif (abs((float) $row['amount'] - $p['amount']) > 0.01) {
                    if (self::locked($p['date'], 'revenue_payment', $p['order_id'], $p['leg'], $p['id'], "{$what} was changed in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    $kind = $p['leg'] === 'freight' ? 'dn' : 'ci';
                    $invoice = $p['applied'] ? OrderZohoInvoiceRepository::find($p['order_id'], $kind) : null;
                    ZohoBooksService::restoreRevenuePayment($p['id'], ...[...$p['args'], $invoice !== null ? (string) $invoice['zoho_invoice_id'] : null, $p['charges'],
                        PaymentDescription::buyerPayment((int) $p['order_id'], (string) $p['leg'], $invoice !== null ? (string) $invoice['zoho_invoice_number'] : null)]);
                    ZohoSyncLogRepository::log('revenue_payment', 'order', $p['order_id'], $p['leg'], 'success', $p['id'], self::RESTORED . "{$what} put back to " . number_format($p['amount'], 2, '.', '') . ' (Books had ' . number_format((float) $row['amount'], 2, '.', '') . ').', $triggeredBy, $userId);
                    $out['restored']++;
                }
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('revenue_payment', 'order', $p['order_id'], $p['leg'], 'error', $p['id'], "Could not check {$what} in Zoho Books: " . $e->getMessage(), $triggeredBy, $userId);
                $out['failed']++;
            }
        }
        return $out;
    }

    /**
     * Bills, vendor payments, order expenses and order costs.
     *
     * @return array{bills:int, payments:int, expenses:int, failed:int}
     */
    public static function purchases(?string $startDate, string $triggeredBy, ?int $userId): array
    {
        if (!self::due($triggeredBy)) {
            return ['bills' => 0, 'payments' => 0, 'expenses' => 0, 'failed' => 0];
        }
        $out = ['bills' => 0, 'payments' => 0, 'expenses' => 0, 'failed' => 0];
        $inScope = static fn (string $date): bool => $startDate === null || substr($date, 0, 10) >= $startDate;
        $vendors = [];
        $read = static function (?string $vendorId) use (&$vendors): array {
            return $vendorId === null ? ['bills' => [], 'vendorpayments' => []] : ($vendors[$vendorId] ??= ZohoBooksService::contactRecords($vendorId, true));
        };
        $log = static fn (string $type, int $orderId, string $leg, string $status, ?string $ref, string $message) => ZohoSyncLogRepository::log($type, 'order', $orderId, $leg, $status, $ref, $message, $triggeredBy, $userId);

        foreach (ZohoPurchaseRepository::sentBills() as $bill) {
            if (!$inScope((string) $bill['bill_date'])) {
                continue;
            }
            $what = "Bill {$bill['bill_no']} from {$bill['vendor_name']}";
            $zohoId = (string) $bill['zoho_bill_id'];
            try {
                $row = array_column($read($bill['vendor_zoho_contact_id'])['bills'], null, 'bill_id')[$zohoId] ?? ZohoBooksService::readRecord('bill', $zohoId);
                if ($row === null) {
                    if (self::locked((string) $bill['bill_date'], 'vendor_bill', (int) $bill['order_id'], 'bill', $zohoId, "{$what} was deleted in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    ZohoPurchaseRepository::forgetZohoRecord('bill', $zohoId);
                    ZohoPurchaseRepository::resetPaymentsAppliedTo((int) $bill['id']);
                    self::$notes["bill:{$bill['id']}"] = self::RECREATED;
                } elseif (abs((float) $row['total'] - (float) $bill['total_inr']) > 0.01) {
                    if (self::locked((string) $bill['bill_date'], 'vendor_bill', (int) $bill['order_id'], 'bill', $zohoId, "{$what} was changed in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    ZohoBooksService::pushBill($bill, $zohoId);
                    $log('vendor_bill', (int) $bill['order_id'], 'bill', 'success', $zohoId, self::RESTORED . "{$what} put back to INR " . number_format((float) $bill['total_inr'], 2, '.', '') . ' (Books had ' . number_format((float) $row['total'], 2, '.', '') . ').');
                    $out['bills']++;
                }
            } catch (\Throwable $e) {
                $log('vendor_bill', (int) $bill['order_id'], 'bill', 'error', $zohoId, "Could not check {$what} in Zoho Books: " . $e->getMessage());
                $out['failed']++;
            }
        }

        foreach (ZohoPurchaseRepository::sentPayments() as ['payment' => $payment, 'bill' => $bill]) {
            if (!$inScope((string) $payment['paid_at'])) {
                continue;
            }
            $what = "Payment {$payment['payment_ref']} to {$bill['vendor_name']}";
            $zohoId = (string) $payment['zoho_payment_id'];
            try {
                $row = array_column($read($bill['vendor_zoho_contact_id'])['vendorpayments'], null, 'payment_id')[$zohoId] ?? ZohoBooksService::readRecord('vendorpayment', $zohoId);
                if ($row === null) {
                    if (self::locked((string) $payment['paid_at'], 'vendor_payment', (int) $bill['order_id'], 'payment', $zohoId, "{$what} was deleted in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    ZohoPurchaseRepository::forgetZohoRecord('vendorpayment', $zohoId);
                    self::$notes["vpay:{$payment['id']}"] = self::RECREATED;
                } elseif (abs((float) $row['amount'] - (float) $payment['amount_inr']) > 0.01) {
                    if (self::locked((string) $payment['paid_at'], 'vendor_payment', (int) $bill['order_id'], 'payment', $zohoId, "{$what} was changed in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    $target = $payment['zoho_applied_at'] !== null ? ZohoPurchaseRepository::targetBillFor(ZohoPurchaseRepository::findBill((int) $bill['id']) ?? $bill) : null;
                    ZohoBooksService::restoreVendorPayment($payment, $bill, $target !== null ? (string) $target['zoho_bill_id'] : null);
                    $log('vendor_payment', (int) $bill['order_id'], 'payment', 'success', $zohoId, self::RESTORED . "{$what} put back to INR " . number_format((float) $payment['amount_inr'], 2, '.', '') . ' (Books had ' . number_format((float) $row['amount'], 2, '.', '') . ').');
                    $out['payments']++;
                }
            } catch (\Throwable $e) {
                $log('vendor_payment', (int) $bill['order_id'], 'payment', 'error', $zohoId, "Could not check {$what} in Zoho Books: " . $e->getMessage());
                $out['failed']++;
            }
        }

        $expenses = ZohoPurchaseRepository::sentExpenses();
        $costs = array_values(array_filter(ZohoPurchaseRepository::costEntriesToSync($startDate), static fn (array $c): bool => $c['zoho_expense_id'] !== null));
        $expenses = array_values(array_filter($expenses, static fn (array $e): bool => $inScope((string) $e['expense_date'])));
        if ($expenses === [] && $costs === []) {
            return $out;
        }
        try {
            $totals = ZohoBooksService::totalsById('expense');
        } catch (\Throwable $e) {
            ZohoSyncLogRepository::log('order_expense', null, null, null, 'error', null, 'Could not read the expenses in Zoho Books to check them: ' . $e->getMessage(), $triggeredBy, $userId);
            $out['failed']++;
            return $out;
        }
        foreach ($expenses as $expense) {
            $zohoId = (string) $expense['zoho_expense_id'];
            $what = "Expense \"{$expense['details']}\"";
            try {
                $total = $totals[$zohoId] ?? (($r = ZohoBooksService::readRecord('expense', $zohoId)) !== null ? (float) ($r['total'] ?? $r['amount'] ?? 0) : null);
                if ($total === null) {
                    if (self::locked((string) $expense['expense_date'], 'order_expense', (int) $expense['order_id'], 'expense', $zohoId, "{$what} was deleted in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    ZohoPurchaseRepository::forgetZohoRecord('expense', $zohoId);
                    self::$notes["exp:{$expense['id']}"] = self::RECREATED;
                } elseif (abs($total - (float) $expense['amount_inr']) > 0.01) {
                    if (self::locked((string) $expense['expense_date'], 'order_expense', (int) $expense['order_id'], 'expense', $zohoId, "{$what} was changed in Zoho Books", $triggeredBy, $userId)) {
                        continue;
                    }
                    ZohoBooksService::pushExpense($expense, $zohoId);
                    $log('order_expense', (int) $expense['order_id'], 'expense', 'success', $zohoId, self::RESTORED . "{$what} put back to INR " . number_format((float) $expense['amount_inr'], 2, '.', '') . ' (Books had ' . number_format($total, 2, '.', '') . ').');
                    $out['expenses']++;
                }
            } catch (\Throwable $e) {
                $log('order_expense', (int) $expense['order_id'], 'expense', 'error', $zohoId, "Could not check {$what} in Zoho Books: " . $e->getMessage());
                $out['failed']++;
            }
        }
        foreach ($costs as $cost) {
            $zohoId = (string) $cost['zoho_expense_id'];
            $what = 'Order cost "' . (\App\Repositories\OrderCostEntryRepository::CATEGORIES[$cost['category']] ?? $cost['category']) . '"';
            try {
                $total = $totals[$zohoId] ?? (($r = ZohoBooksService::readRecord('expense', $zohoId)) !== null ? (float) ($r['total'] ?? $r['amount'] ?? 0) : null);
                $deleted = $total === null;
                if (!$deleted && abs($total - (float) $cost['amount_inr']) <= 0.01) {
                    continue;
                }
                if (self::locked((string) $cost['cost_date'], 'order_cost', (int) $cost['order_id'], 'cost', $zohoId, $what . ($deleted ? ' was deleted in Zoho Books' : ' was changed in Zoho Books'), $triggeredBy, $userId)) {
                    continue;
                }
                if ($deleted) {
                    ZohoPurchaseRepository::forgetZohoRecord('expense', $zohoId);
                    self::$notes["cost:{$cost['id']}"] = self::RECREATED;
                } else {
                    Database::connection()->prepare('UPDATE order_cost_entries SET zoho_pushed_hash = NULL WHERE id = :id')->execute(['id' => $cost['id']]);
                    self::$notes["cost:{$cost['id']}"] = self::RESTORED . '(Books had ' . number_format($total, 2, '.', '') . ') ';
                }
            } catch (\Throwable $e) {
                $log('order_cost', (int) $cost['order_id'], 'cost', 'error', $zohoId, "Could not check {$what} in Zoho Books: " . $e->getMessage());
                $out['failed']++;
            }
        }
        return $out;
    }

    /**
     * Export benefit journals.
     *
     * @return array{failed:int}
     */
    public static function benefits(?string $startDate, string $triggeredBy, ?int $userId): array
    {
        if (!self::due($triggeredBy)) {
            return ['failed' => 0];
        }
        $out = ['failed' => 0];
        $sent = array_values(array_filter(CaExportBenefitRepository::forZohoSync($startDate), static fn (array $b): bool => $b['zoho_journal_id'] !== null && (float) ($b['received_amount'] ?? 0) > 0
            && ($startDate === null || (string) $b['received_at'] >= $startDate)));
        if ($sent === []) {
            return $out;
        }
        try {
            $totals = ZohoBooksService::totalsById('journal');
        } catch (\Throwable $e) {
            ZohoSyncLogRepository::log('export_benefit', null, null, null, 'error', null, 'Could not read the journals in Zoho Books to check them: ' . $e->getMessage(), $triggeredBy, $userId);
            return ['failed' => 1];
        }
        foreach ($sent as $b) {
            $zohoId = (string) $b['zoho_journal_id'];
            $orderId = $b['order_id'] !== null ? (int) $b['order_id'] : null;
            $what = "Export benefit {$b['scheme_name']} (claim #{$b['id']})";
            try {
                $total = $totals[$zohoId] ?? (($r = ZohoBooksService::readRecord('journal', $zohoId)) !== null ? (float) ($r['total'] ?? 0) : null);
                $deleted = $total === null;
                if (!$deleted && abs($total - (float) $b['received_amount']) <= 0.01) {
                    continue;
                }
                if (self::locked((string) $b['received_at'], 'export_benefit', $orderId, 'journal', $zohoId, $what . ($deleted ? ' was deleted in Zoho Books' : ' was changed in Zoho Books'), $triggeredBy, $userId)) {
                    continue;
                }
                if ($deleted) {
                    CaExportBenefitRepository::setZohoJournal((int) $b['id'], null, null);
                    self::$notes["ben:{$b['id']}"] = self::RECREATED;
                } else {
                    Database::connection()->prepare('UPDATE ca_export_benefits SET zoho_pushed_hash = NULL WHERE id = :id')->execute(['id' => $b['id']]);
                    self::$notes["ben:{$b['id']}"] = self::RESTORED . '(Books had ' . number_format($total, 2, '.', '') . ') ';
                }
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('export_benefit', 'order', $orderId, 'journal', 'error', $zohoId, "Could not check {$what} in Zoho Books: " . $e->getMessage(), $triggeredBy, $userId);
                $out['failed']++;
            }
        }
        return $out;
    }

    /**
     * A record dated in a financial year the CA has locked is never changed
     * automatically: it is logged (once — not again while the same message
     * is the record's latest) for the CA to decide. True when locked.
     */
    private static function locked(string $date, string $type, ?int $orderId, string $leg, ?string $zohoId, string $what, string $triggeredBy, ?int $userId): bool
    {
        $fy = FinancialYear::label(substr($date, 0, 10));
        if (!CaFyLockRepository::isLocked($fy)) {
            return false;
        }
        $message = "{$what}, but it is dated in FY {$fy}, which is locked: not restored — CA to decide.";
        $stmt = Database::connection()->prepare('SELECT message FROM zoho_sync_log WHERE sync_type = :t AND zoho_reference <=> :ref ORDER BY id DESC LIMIT 1');
        $stmt->execute(['t' => $type, 'ref' => $zohoId]);
        if ($stmt->fetchColumn() !== $message) {
            ZohoSyncLogRepository::log($type, 'order', $orderId, $leg, 'error', $zohoId, $message, $triggeredBy, $userId);
        }
        return true;
    }
}
