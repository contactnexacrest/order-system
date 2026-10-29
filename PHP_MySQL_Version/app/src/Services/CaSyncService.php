<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\CaRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderZohoInvoiceRepository;
use App\Repositories\ZohoPurchaseRepository;
use App\Repositories\ZohoSyncLogRepository;

/**
 * CA / Accounting module — orchestrates a Zoho Books sync run, from either
 * the manual "Sync Now" button (public_html/index.php's
 * ca_module_manage-gated route) or the scheduled cron job
 * (app/cron/zoho_sync.php). Phase 3 added the revenue push; Phase 4 the
 * expense import — runFullSync() runs both in one pass, each independent
 * (one failing never stops the other). Deliberately the single place that
 * decides "not configured" is a routine no-op, not an error — see point 5
 * of the CA module brief: "if zoho credentials are not present, then it
 * shouldn't be a blocker for our application."
 */
final class CaSyncService
{
    /** @return array{revenue: array{synced:int, failed:int, skipped:int, invoices:int, applied:int}, purchases: array{bills:int, payments:int, applied:int, expenses:int, failed:int, skipped:int}, expenses: array{imported:int, failed:int, skipped:int}} */
    public static function runFullSync(string $triggeredBy, ?int $triggeredByUserId): array
    {
        // The day's full check of the records already in Books (ZohoRestoreService::due()).
        $dailyCheck = $triggeredBy !== 'manual' && ZohoBooksService::isEnabled() && ZohoRestoreService::due($triggeredBy);
        $result = [
            'revenue' => self::syncPendingRevenue($triggeredBy, $triggeredByUserId),
            // Stage 3 runs before the expense import, so the order expenses it
            // sends are already marked and the import skips them.
            'purchases' => self::syncPurchases($triggeredBy, $triggeredByUserId),
            'expenses' => self::importPendingExpenses($triggeredBy, $triggeredByUserId),
            'benefits' => self::syncBenefits($triggeredBy, $triggeredByUserId),
        ];
        if ($dailyCheck) {
            ZohoRestoreService::markScheduledCheckDone();
        }
        return $result;
    }

    /**
     * Owner decision 2026-10-29: export benefits as Books journals when
     * received (CaExportBenefitRepository::forZohoSync()) — sent once,
     * updated when the receipt is corrected, removed from Books when the
     * receipt is cleared. Each logged on its own.
     *
     * @return array{journals:int, removed:int, failed:int, skipped:int}
     */
    public static function syncBenefits(string $triggeredBy, ?int $triggeredByUserId): array
    {
        $result = ['journals' => 0, 'removed' => 0, 'failed' => 0, 'skipped' => 0];
        if (!ZohoBooksService::isEnabled()) {
            ZohoSyncLogRepository::log('export_benefit', null, null, null, 'skipped', null, self::offReason() . ' — nothing synced.', $triggeredBy, $triggeredByUserId);
            $result['skipped'] = 1;
            return $result;
        }
        $startDate = ZohoBooksService::syncStartDate();
        if ($startDate === null && ZohoGuard::isProduction()) {
            ZohoSyncLogRepository::log('export_benefit', null, null, null, 'error', null, self::NO_START_DATE, $triggeredBy, $triggeredByUserId);
            $result['failed'] = 1;
            return $result;
        }
        // Owner decision 2026-10-29: put back what was deleted or changed in Books.
        $result['failed'] += ZohoRestoreService::benefits($startDate, $triggeredBy, $triggeredByUserId)['failed'];
        foreach (CaExportBenefitRepository::forZohoSync($startDate) as $benefit) {
            $id = (int) $benefit['id'];
            $journalId = $benefit['zoho_journal_id'] !== null ? (string) $benefit['zoho_journal_id'] : null;
            $received = $benefit['received_amount'] !== null && (float) $benefit['received_amount'] > 0;
            $what = "{$benefit['scheme_name']}" . ($received ? ' ' . number_format((float) $benefit['received_amount'], 2, '.', '') : '');
            try {
                if (!$received) {
                    ZohoBooksService::deleteRecord('journal', (string) $journalId);
                    CaExportBenefitRepository::setZohoJournal($id, null, null);
                    ZohoSyncLogRepository::log('export_benefit', 'order', $benefit['order_id'] !== null ? (int) $benefit['order_id'] : null, 'journal', 'success', $journalId, "Removed the Zoho Books journal of export benefit {$what} (claim #{$id}): its receipt was cleared in the app.", $triggeredBy, $triggeredByUserId);
                    $result['removed']++;
                    continue;
                }
                $hash = hash('sha256', (string) json_encode([$benefit['scheme_name'], round((float) $benefit['received_amount'], 2), (string) $benefit['received_at'], $benefit['reference_number'], $benefit['order_id']]));
                if ($journalId !== null && $benefit['zoho_pushed_hash'] === $hash) {
                    continue;
                }
                $newId = ZohoBooksService::pushBenefitJournal($benefit, $journalId);
                CaExportBenefitRepository::setZohoJournal($id, $newId, $hash);
                ZohoSyncLogRepository::log('export_benefit', 'order', $benefit['order_id'] !== null ? (int) $benefit['order_id'] : null, 'journal', 'success', $newId, ZohoRestoreService::note("ben:{$id}") . ($journalId !== null ? 'Updated' : 'Sent') . " export benefit {$what} (claim #{$id}) received on {$benefit['received_at']} as Zoho Books journal.", $triggeredBy, $triggeredByUserId);
                $result['journals']++;
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('export_benefit', 'order', $benefit['order_id'] !== null ? (int) $benefit['order_id'] : null, 'journal', 'error', $journalId, $e->getMessage(), $triggeredBy, $triggeredByUserId);
                $result['failed']++;
            }
        }
        if ($result['journals'] + $result['removed'] + $result['failed'] === 0) {
            ZohoSyncLogRepository::log('export_benefit', null, null, null, 'skipped', null, 'Nothing pending — every received export benefit is already in Zoho Books.', $triggeredBy, $triggeredByUserId);
            $result['skipped'] = 1;
        }
        return $result;
    }

    /**
     * Stage 2 (2026-10-29): first the Commercial Invoices and Debit Notes
     * (created, or updated when a revised one was approved), then the
     * payments — each applied to its invoice straight away when that is in
     * Books (advance and balance to the CI, freight to the Debit Note) —
     * then any payment held unapplied (the advance, pushed before its CI
     * existed) is applied once its invoice is there. Every item is logged
     * on its own; one failing never stops the others.
     *
     * @return array{synced:int, failed:int, skipped:int, invoices:int, applied:int}
     */
    public static function syncPendingRevenue(string $triggeredBy, ?int $triggeredByUserId): array
    {
        if (!ZohoBooksService::isEnabled()) {
            ZohoSyncLogRepository::log('revenue_payment', null, null, null, 'skipped', null, self::offReason() . ' — nothing synced.', $triggeredBy, $triggeredByUserId);
            return ['synced' => 0, 'failed' => 0, 'skipped' => 1, 'invoices' => 0, 'applied' => 0];
        }
        $startDate = ZohoBooksService::syncStartDate();
        if ($startDate === null && ZohoGuard::isProduction()) {
            ZohoSyncLogRepository::log('revenue_payment', null, null, null, 'error', null, self::NO_START_DATE, $triggeredBy, $triggeredByUserId);
            return ['synced' => 0, 'failed' => 1, 'skipped' => 0, 'invoices' => 0, 'applied' => 0];
        }

        // Owner decision 2026-10-29: put back what was deleted or changed in Books.
        $restore = ZohoRestoreService::revenue($startDate, $triggeredBy, $triggeredByUserId);
        $failed = $restore['failed'];
        $invoices = 0;
        foreach (OrderZohoInvoiceRepository::documentsToSync($startDate) as $item) {
            $label = ZohoInvoiceData::KIND_LABELS[$item['kind']];
            try {
                $invoice = ZohoInvoiceData::build($item['order_id'], $item['kind'], $item['document_id']);
                $known = OrderZohoInvoiceRepository::find($item['order_id'], $item['kind']);
                if ($known !== null) {
                    // The rate is frozen once the invoice is in Books: a rate
                    // recorded later (the balance arriving) must not revalue
                    // an invoice that may sit in a filed GST month. Each
                    // payment still goes in at its own actual rate, and Books
                    // books the difference as exchange gain or loss.
                    $invoice['exchange_rate'] = (float) $known['exchange_rate'];
                }
                $hash = ZohoInvoiceData::fingerprint($invoice);
                if ($known !== null && $known['pushed_hash'] === $hash) {
                    continue;
                }
                $pushed = ZohoBooksService::pushInvoice($invoice);
                OrderZohoInvoiceRepository::save($item['order_id'], $item['kind'], $item['document_id'], [
                    'zoho_invoice_id' => $pushed['zoho_invoice_id'],
                    'zoho_invoice_number' => $pushed['zoho_invoice_number'],
                    'currency_code' => $invoice['currency_code'],
                    'total' => $invoice['total'],
                    'exchange_rate' => $invoice['exchange_rate'],
                    'pushed_hash' => $hash,
                ]);
                $what = $known === null && $pushed['created'] ? 'Sent' : 'Updated';
                ZohoSyncLogRepository::log('invoice', 'order', $item['order_id'], $item['kind'], 'success', $pushed['zoho_invoice_id'], ZohoRestoreService::note("invoice:{$item['order_id']}:{$item['kind']}") . "{$what} {$label} {$invoice['invoice_number']} as Zoho Books invoice ({$invoice['currency_code']} " . number_format($invoice['total'], 2, '.', '') . ' @ ' . $invoice['exchange_rate'] . ').', $triggeredBy, $triggeredByUserId);
                $invoices++;
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('invoice', 'order', $item['order_id'], $item['kind'], 'error', null, $e->getMessage(), $triggeredBy, $triggeredByUserId);
                $failed++;
            }
        }

        $synced = $restore['restored'];
        $applied = 0;
        foreach (CaRepository::legsPendingZohoSync() as $leg) {
            if ($startDate !== null && substr((string) $leg['cleared_at'], 0, 10) < $startDate) {
                continue; // before the go-live date: the CA books it as the opening position
            }
            $referenceNumber = $leg['buyer_inquiry_ref'] . '-' . $leg['leg'];
            $target = self::invoiceFor((int) $leg['order_id'], $leg['leg']);
            try {
                $result = ZohoBooksService::pushRevenuePayment(
                    $leg['client_id'],
                    $leg['company_legal_name'],
                    (string) ($leg['currency_code'] ?? 'INR'),
                    $leg['foreign_amount'] !== null ? (float) $leg['foreign_amount'] : null,
                    (float) $leg['inr_actual'],
                    substr((string) $leg['cleared_at'], 0, 10),
                    $referenceNumber,
                    $target['zoho_invoice_number'] ?? null
                );
                $zohoReference = $result['payment_id'];
                self::markSynced((int) $leg['order_id'], $leg['leg'], $zohoReference);
                if ($result['applied'] > 0) {
                    OrderPaymentStatusRepository::setLegZohoApplied((int) $leg['order_id'], $leg['leg']);
                    $applied++;
                }
                ZohoSyncLogRepository::log('revenue_payment', 'order_payment_status', (int) $leg['order_id'], $leg['leg'], 'success', $zohoReference, ZohoRestoreService::note("leg:{$leg['order_id']}:{$leg['leg']}") . "Pushed as Zoho Books customer payment {$zohoReference}" . self::appliedNote($leg['leg'], $target, $result['applied']), $triggeredBy, $triggeredByUserId);
                $synced++;
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('revenue_payment', 'order_payment_status', (int) $leg['order_id'], $leg['leg'], 'error', null, $e->getMessage(), $triggeredBy, $triggeredByUserId);
                $failed++;
            }
        }

        foreach (CaRepository::legsHeldInZoho() as $leg) {
            $target = self::invoiceFor((int) $leg['order_id'], $leg['leg']);
            if ($target === null) {
                continue; // still waiting for its invoice — nothing to do yet
            }
            try {
                $amount = ZohoBooksService::applyPaymentToInvoice(
                    (string) $leg['zoho_reference'],
                    $leg['client_id'],
                    $leg['company_legal_name'],
                    (string) ($leg['currency_code'] ?? 'INR'),
                    $leg['foreign_amount'] !== null ? (float) $leg['foreign_amount'] : null,
                    (float) $leg['inr_actual'],
                    substr((string) $leg['cleared_at'], 0, 10),
                    $leg['buyer_inquiry_ref'] . '-' . $leg['leg'],
                    $target['zoho_invoice_number']
                );
                OrderPaymentStatusRepository::setLegZohoApplied((int) $leg['order_id'], $leg['leg']);
                ZohoSyncLogRepository::log('payment_apply', 'order_payment_status', (int) $leg['order_id'], $leg['leg'], 'success', (string) $leg['zoho_reference'], "Applied " . number_format($amount, 2, '.', '') . " of payment {$leg['zoho_reference']} to " . ZohoInvoiceData::KIND_LABELS[self::LEG_INVOICE[$leg['leg']]] . " {$target['zoho_invoice_number']}.", $triggeredBy, $triggeredByUserId);
                $applied++;
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('payment_apply', 'order_payment_status', (int) $leg['order_id'], $leg['leg'], 'error', (string) $leg['zoho_reference'], $e->getMessage(), $triggeredBy, $triggeredByUserId);
                $failed++;
            }
        }

        // Owner decision 2026-10-29: the money the bank paid under an LC — a
        // customer payment applied to the CI (held until the CI is in Books,
        // like the advance), with the bank charges the bank kept.
        foreach (OrderLcDetailRepository::receiptsForZohoSync() as $lc) {
            if ($lc['lc_zoho_synced_at'] === null && $startDate !== null && (string) $lc['lc_received_at'] < $startDate) {
                continue; // before the go-live date: the CA books it as the opening position
            }
            $orderId = (int) $lc['order_id'];
            $reference = $lc['buyer_inquiry_ref'] . '-lc';
            $target = OrderZohoInvoiceRepository::find($orderId, 'ci');
            $args = [(int) $lc['client_id'], (string) $lc['company_legal_name'], (string) ($lc['currency_code'] ?? 'INR'), (float) $lc['lc_received_amount'], (float) $lc['lc_inr_credited'], (string) $lc['lc_received_at'], $reference];
            try {
                if ($lc['lc_zoho_synced_at'] === null) {
                    $result = ZohoBooksService::pushRevenuePayment(...[...$args, $target['zoho_invoice_number'] ?? null, (float) $lc['lc_bank_charges_inr']]);
                    OrderLcDetailRepository::setZohoSynced($orderId, $result['payment_id'], $result['applied'] > 0);
                    $applied += $result['applied'] > 0 ? 1 : 0;
                    ZohoSyncLogRepository::log('revenue_payment', 'order_lc_details', $orderId, 'lc', 'success', $result['payment_id'], ZohoRestoreService::note("lc:{$orderId}") . "Pushed LC payment as Zoho Books customer payment {$result['payment_id']}" . self::appliedNote('lc', $target, $result['applied']), $triggeredBy, $triggeredByUserId);
                    $synced++;
                } elseif ($target !== null) {
                    $amount = ZohoBooksService::applyPaymentToInvoice((string) $lc['lc_zoho_payment_id'], ...[...$args, $target['zoho_invoice_number'], (float) $lc['lc_bank_charges_inr']]);
                    OrderLcDetailRepository::setZohoApplied($orderId);
                    ZohoSyncLogRepository::log('payment_apply', 'order_lc_details', $orderId, 'lc', 'success', (string) $lc['lc_zoho_payment_id'], 'Applied ' . number_format($amount, 2, '.', '') . " of LC payment {$lc['lc_zoho_payment_id']} to Commercial Invoice {$target['zoho_invoice_number']}.", $triggeredBy, $triggeredByUserId);
                    $applied++;
                }
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log($lc['lc_zoho_synced_at'] === null ? 'revenue_payment' : 'payment_apply', 'order_lc_details', $orderId, 'lc', 'error', $lc['lc_zoho_payment_id'], $e->getMessage(), $triggeredBy, $triggeredByUserId);
                $failed++;
            }
        }

        if ($synced === 0 && $failed === 0 && $invoices === 0 && $applied === 0) {
            ZohoSyncLogRepository::log('revenue_payment', null, null, null, 'skipped', null, 'Nothing pending — every approved CI / Debit Note and every recorded INR actual is already in Zoho Books.', $triggeredBy, $triggeredByUserId);
            return ['synced' => 0, 'failed' => 0, 'skipped' => 1, 'invoices' => 0, 'applied' => 0];
        }

        return ['synced' => $synced, 'failed' => $failed, 'skipped' => 0, 'invoices' => $invoices, 'applied' => $applied];
    }

    /** In production nothing goes to Books until the go-live date is set. */
    private const NO_START_DATE = 'Nothing sent: set the Zoho Books go-live date (zoho_books_sync_start_date) in Admin -> Settings first. Only records dated on or after it go to Zoho Books; the CA books everything earlier as the opening position.';

    /** Which invoice each payment leg is paid against: the advance and the balance against the CI, freight against the Debit Note. */
    private const LEG_INVOICE = ['advance' => 'ci', 'balance' => 'ci', 'freight' => 'dn', 'lc' => 'ci'];

    /** The leg's invoice, when it is already in Zoho Books. */
    private static function invoiceFor(int $orderId, string $leg): ?array
    {
        return OrderZohoInvoiceRepository::find($orderId, self::LEG_INVOICE[$leg]);
    }

    private static function appliedNote(string $leg, ?array $target, float $applied): string
    {
        $label = ZohoInvoiceData::KIND_LABELS[self::LEG_INVOICE[$leg]];
        if ($target === null) {
            return ", held unapplied until the {$label} is in Zoho Books.";
        }
        return $applied > 0
            ? ', applied ' . number_format($applied, 2, '.', '') . " to {$label} {$target['zoho_invoice_number']}."
            : ", held unapplied: {$label} {$target['zoho_invoice_number']} has nothing left to pay.";
    }

    /**
     * Stage 3 (2026-10-29): what the app pays out. First the suppliers' and
     * logistics partners' tax invoices (as Books bills, under the vendor,
     * created on first use), then each payment made to them — applied to
     * its bill when that is in Books; a payment on a proforma is held as
     * an advance until the tax invoice that replaces it is in Books — then
     * held payments whose bill has now arrived, then the order's expenses.
     * Every item is logged on its own; one failing never stops the others.
     *
     * @return array{bills:int, payments:int, applied:int, expenses:int, failed:int, skipped:int}
     */
    public static function syncPurchases(string $triggeredBy, ?int $triggeredByUserId): array
    {
        $result = ['bills' => 0, 'payments' => 0, 'applied' => 0, 'expenses' => 0, 'failed' => 0, 'skipped' => 0];
        if (!ZohoBooksService::isEnabled()) {
            ZohoSyncLogRepository::log('vendor_bill', null, null, null, 'skipped', null, self::offReason() . ' — no bills, vendor payments or expenses sent.', $triggeredBy, $triggeredByUserId);
            $result['skipped'] = 1;
            return $result;
        }
        $startDate = ZohoBooksService::syncStartDate();
        if ($startDate === null && ZohoGuard::isProduction()) {
            ZohoSyncLogRepository::log('vendor_bill', null, null, null, 'error', null, self::NO_START_DATE, $triggeredBy, $triggeredByUserId);
            $result['failed'] = 1;
            return $result;
        }
        $log = static function (string $type, int $orderId, ?string $leg, string $status, ?string $reference, string $message) use ($triggeredBy, $triggeredByUserId): void {
            ZohoSyncLogRepository::log($type, 'order', $orderId, $leg, $status, $reference, $message, $triggeredBy, $triggeredByUserId);
        };
        // Owner decision 2026-10-29: put back what was deleted or changed in Books.
        foreach (ZohoRestoreService::purchases($startDate, $triggeredBy, $triggeredByUserId) as $key => $count) {
            $result[$key] += $count;
        }

        foreach (ZohoPurchaseRepository::billsToSync($startDate) as $bill) {
            try {
                $zohoBillId = ZohoBooksService::pushBill($bill);
                ZohoPurchaseRepository::setBillSynced((int) $bill['id'], $zohoBillId);
                $log('vendor_bill', (int) $bill['order_id'], 'bill', 'success', $zohoBillId, ZohoRestoreService::note("bill:{$bill['id']}") . "Sent bill {$bill['bill_no']} from {$bill['vendor_name']} (INR " . number_format((float) $bill['total_inr'], 2, '.', '') . (!empty($bill['gst_reverse_charge']) ? ', reverse charge' : '') . ') as Zoho Books bill.');
                $result['bills']++;
            } catch (\Throwable $e) {
                $log('vendor_bill', (int) $bill['order_id'], 'bill', 'error', null, $e->getMessage());
                $result['failed']++;
            }
        }

        foreach (ZohoPurchaseRepository::paymentsToSync($startDate) as ['payment' => $payment, 'bill' => $bill]) {
            $target = ZohoPurchaseRepository::targetBillFor(ZohoPurchaseRepository::findBill((int) $bill['id']) ?? $bill);
            try {
                $pushed = ZohoBooksService::pushVendorPayment($payment, $bill, $target);
                ZohoPurchaseRepository::setPaymentSynced((int) $payment['id'], $pushed['payment_id'], $pushed['applied'] > 0);
                $note = $target === null
                    ? ", held unapplied until the tax invoice for proforma {$bill['bill_no']} is in Zoho Books."
                    : ($pushed['applied'] > 0 ? ', applied ' . number_format($pushed['applied'], 2, '.', '') . " to bill {$target['bill_no']}." : ", held unapplied: bill {$target['bill_no']} has nothing left to pay.");
                $log('vendor_payment', (int) $bill['order_id'], 'payment', 'success', $pushed['payment_id'], ZohoRestoreService::note("vpay:{$payment['id']}") . "Pushed payment {$payment['payment_ref']} (INR " . number_format((float) $payment['amount_inr'], 2, '.', '') . ") to {$bill['vendor_name']} as Zoho Books vendor payment{$note}");
                $result['payments']++;
                $result['applied'] += $pushed['applied'] > 0 ? 1 : 0;
            } catch (\Throwable $e) {
                $log('vendor_payment', (int) $bill['order_id'], 'payment', 'error', null, $e->getMessage());
                $result['failed']++;
            }
        }

        foreach (ZohoPurchaseRepository::heldPayments() as ['payment' => $payment, 'bill' => $bill]) {
            $target = ZohoPurchaseRepository::targetBillFor($bill);
            if ($target === null) {
                continue; // its tax invoice is not in Books yet — nothing to do
            }
            try {
                $amount = ZohoBooksService::applyVendorPayment($payment, $bill, $target);
                ZohoPurchaseRepository::setPaymentApplied((int) $payment['id']);
                $log('vendor_payment_apply', (int) $bill['order_id'], 'payment', 'success', (string) $payment['zoho_payment_id'], 'Applied ' . number_format($amount, 2, '.', '') . " of payment {$payment['payment_ref']} to bill {$target['bill_no']}.");
                $result['applied']++;
            } catch (\Throwable $e) {
                $log('vendor_payment_apply', (int) $bill['order_id'], 'payment', 'error', (string) $payment['zoho_payment_id'], $e->getMessage());
                $result['failed']++;
            }
        }

        foreach (ZohoPurchaseRepository::expensesToSync($startDate) as $expense) {
            try {
                $zohoExpenseId = ZohoBooksService::pushExpense($expense);
                ZohoPurchaseRepository::setExpenseSynced((int) $expense['id'], $zohoExpenseId);
                $log('order_expense', (int) $expense['order_id'], 'expense', 'success', $zohoExpenseId, ZohoRestoreService::note("exp:{$expense['id']}") . "Sent expense \"{$expense['details']}\" (INR " . number_format((float) $expense['amount_inr'], 2, '.', '') . ') as Zoho Books expense.');
                $result['expenses']++;
            } catch (\Throwable $e) {
                $log('order_expense', (int) $expense['order_id'], 'expense', 'error', null, $e->getMessage());
                $result['failed']++;
            }
        }

        // Owner decision 2026-10-29: the order's real paid cost entries, as
        // Books expenses — sent once, and updated when the amount, date or
        // "paid from" changes afterwards. Counted with the expenses.
        foreach (ZohoPurchaseRepository::costEntriesToSync($startDate) as $cost) {
            $hash = hash('sha256', (string) json_encode([$cost['category'], round((float) $cost['amount_inr'], 2), (string) $cost['cost_date'], $cost['paid_from'], $cost['description']]));
            if ($cost['zoho_expense_id'] !== null && $cost['zoho_pushed_hash'] === $hash) {
                continue;
            }
            $label = \App\Repositories\OrderCostEntryRepository::CATEGORIES[$cost['category']] ?? (string) $cost['category'];
            try {
                $zohoExpenseId = ZohoBooksService::pushCostEntry($cost, $cost['zoho_expense_id'] !== null ? (string) $cost['zoho_expense_id'] : null);
                ZohoPurchaseRepository::setCostEntrySynced((int) $cost['id'], $zohoExpenseId, $hash);
                $log('order_cost', (int) $cost['order_id'], 'cost', 'success', $zohoExpenseId, ZohoRestoreService::note("cost:{$cost['id']}") . ($cost['zoho_expense_id'] !== null ? 'Updated' : 'Sent') . " order cost \"{$label}\" (INR " . number_format((float) $cost['amount_inr'], 2, '.', '') . ') as Zoho Books expense.');
                $result['expenses']++;
            } catch (\Throwable $e) {
                $log('order_cost', (int) $cost['order_id'], 'cost', 'error', null, $e->getMessage());
                $result['failed']++;
            }
        }

        if ($result['bills'] + $result['payments'] + $result['applied'] + $result['expenses'] + $result['failed'] === 0) {
            ZohoSyncLogRepository::log('vendor_bill', null, null, null, 'skipped', null, 'Nothing pending — every tax invoice, vendor payment, order expense and order cost is already in Zoho Books.', $triggeredBy, $triggeredByUserId);
            $result['skipped'] = 1;
        }
        return $result;
    }

    /**
     * The expenses the CA enters in Zoho Books come into the app's Expenses
     * register. Owner decisions 2026-10-29 — Books is the source of truth
     * for these: an expense changed in Books is changed here too, and one
     * deleted in Books is removed here (with the old values in the audit
     * log). Not automatically, but logged for the CA: one dated in a locked
     * financial year, and one matched to a bank statement line (unmatch it
     * first). A new expense whose Reference # names an order (its buyer
     * enquiry or order reference) is linked to that order. Expenses the app
     * itself sent are never imported.
     *
     * @return array{imported:int, updated:int, removed:int, failed:int, skipped:int}
     */
    public static function importPendingExpenses(string $triggeredBy, ?int $triggeredByUserId): array
    {
        $none = ['imported' => 0, 'updated' => 0, 'removed' => 0, 'failed' => 0, 'skipped' => 0];
        if (!ZohoBooksService::isEnabled()) {
            ZohoSyncLogRepository::log('expense_import', null, null, null, 'skipped', null, self::offReason() . ' — nothing imported.', $triggeredBy, $triggeredByUserId);
            return ['skipped' => 1] + $none;
        }

        try {
            $expenses = ZohoBooksService::listExpenses();
        } catch (\Throwable $e) {
            ZohoSyncLogRepository::log('expense_import', null, null, null, 'error', null, $e->getMessage(), $triggeredBy, $triggeredByUserId);
            return ['failed' => 1] + $none;
        }

        $result = $none;
        $log = static fn (?int $id, string $status, ?string $zohoId, string $message) => ZohoSyncLogRepository::log('expense_import', 'ca_expenses', $id, null, $status, $zohoId, $message, $triggeredBy, $triggeredByUserId);
        $locked = static function (string $date): ?string {
            $fy = \App\Helpers\FinancialYear::label(substr($date, 0, 10));
            return \App\Repositories\CaFyLockRepository::isLocked($fy) ? $fy : null;
        };
        $logOnce = static function (?int $id, ?string $zohoId, string $message) use ($log): void {
            $stmt = \App\Config\Database::connection()->prepare("SELECT message FROM zoho_sync_log WHERE sync_type = 'expense_import' AND zoho_reference <=> :ref ORDER BY id DESC LIMIT 1");
            $stmt->execute(['ref' => $zohoId]);
            if ($stmt->fetchColumn() !== $message) {
                $log($id, 'error', $zohoId, $message);
            }
        };
        $known = CaExpenseRepository::allByZohoId();
        $seen = [];
        foreach ($expenses as $expense) {
            $zohoId = $expense['zohoExpenseId'];
            $seen[$zohoId] = true;
            // Stage 3: an order expense this app sent to Books is already
            // here as that order expense — never import it a second time.
            if (ZohoPurchaseRepository::isPushedExpense($zohoId)) {
                continue;
            }
            $mine = $known[$zohoId] ?? null;
            if ($mine === null) {
                $id = CaExpenseRepository::insert($zohoId, $expense['category'], $expense['description'], $expense['vendorName'], $expense['amount'], $expense['currencyCode'], $expense['expenseDate']);
                $orderId = CaExpenseRepository::orderIdForReference($expense['referenceNumber'] ?? null);
                if ($orderId !== null) {
                    CaExpenseRepository::linkToOrder($id, $orderId);
                }
                $log($id, 'success', $zohoId, "Imported expense {$zohoId} ({$expense['category']}, {$expense['amount']} {$expense['currencyCode']})" . ($orderId !== null ? ", linked to order #{$orderId} by its Reference #" : '') . '.');
                $result['imported']++;
                continue;
            }
            $changes = [];
            foreach (['category' => 'category', 'description' => 'description', 'vendor_name' => 'vendorName', 'currency_code' => 'currencyCode', 'expense_date' => 'expenseDate'] as $col => $field) {
                if ((string) ($mine[$col] ?? '') !== (string) ($expense[$field] ?? '')) {
                    $changes[$col] = [(string) ($mine[$col] ?? ''), (string) ($expense[$field] ?? '')];
                }
            }
            if (abs((float) $mine['amount'] - (float) $expense['amount']) > 0.004) {
                $changes['amount'] = [number_format((float) $mine['amount'], 2, '.', ''), number_format((float) $expense['amount'], 2, '.', '')];
            }
            if ($changes === []) {
                continue;
            }
            $id = (int) $mine['id'];
            $what = implode(', ', array_map(static fn (string $col, array $v): string => "{$col} {$v[0]} → {$v[1]}", array_keys($changes), $changes));
            $fy = $locked((string) $mine['expense_date']) ?? $locked((string) $expense['expenseDate']);
            if ($fy !== null) {
                $logOnce($id, $zohoId, "Expense {$zohoId} was changed in Zoho Books ({$what}), but it is dated in FY {$fy}, which is locked: not changed here — CA to decide.");
                continue;
            }
            CaExpenseRepository::updateFromZoho($id, $expense['category'], $expense['description'], $expense['vendorName'], (float) $expense['amount'], $expense['currencyCode'], $expense['expenseDate']);
            if ($mine['order_id'] === null && ($orderId = CaExpenseRepository::orderIdForReference($expense['referenceNumber'] ?? null)) !== null) {
                CaExpenseRepository::linkToOrder($id, $orderId);
            }
            AuditLogRepository::log($triggeredByUserId, 'CA_EXPENSE_UPDATED_FROM_ZOHO', 'ca_expenses', $id, implode(',', array_keys($changes)), (string) json_encode(array_map(static fn (array $v): string => $v[0], $changes)), (string) json_encode(array_map(static fn (array $v): string => $v[1], $changes)), 'Changed in Zoho Books');
            $log($id, 'success', $zohoId, "Updated expense {$zohoId} from Zoho Books: {$what}.");
            $result['updated']++;
        }

        // Deleted in Books. A whole list gone at once is far more likely a
        // wrong organisation or a Books problem than the CA's deletions:
        // nothing is removed then, and the CA is told.
        $gone = array_diff_key($known, $seen);
        if ($gone !== [] && count($gone) > 10 && count($gone) > count($known) / 2) {
            ZohoSyncLogRepository::log('expense_import', null, null, null, 'error', null, count($gone) . ' of the ' . count($known) . ' imported expenses are no longer in Zoho Books. Nothing was removed: check that the app is connected to the right Books organisation, then ask the CA.', $triggeredBy, $triggeredByUserId);
            $result['failed']++;
            $gone = [];
        }
        foreach ($gone as $zohoId => $mine) {
            $id = (int) $mine['id'];
            $label = "Expense {$zohoId} ({$mine['category']}, {$mine['amount']} {$mine['currency_code']}, {$mine['expense_date']})";
            if (($fy = $locked((string) $mine['expense_date'])) !== null) {
                $logOnce($id, (string) $zohoId, "{$label} was deleted in Zoho Books, but it is dated in FY {$fy}, which is locked: kept here — CA to decide.");
                continue;
            }
            if (CaExpenseRepository::isBankMatched($id)) {
                $logOnce($id, (string) $zohoId, "{$label} was deleted in Zoho Books, but it is matched to a bank statement line: kept here — unmatch it in Bank Statement first, then it is removed on the next sync.");
                continue;
            }
            CaExpenseRepository::delete($id);
            AuditLogRepository::log($triggeredByUserId, 'CA_EXPENSE_REMOVED_FROM_ZOHO', 'ca_expenses', $id, null, (string) json_encode($mine), null, 'Deleted in Zoho Books');
            $log($id, 'success', (string) $zohoId, "Removed {$label}: it was deleted in Zoho Books.");
            $result['removed']++;
        }

        if ($result['imported'] + $result['updated'] + $result['removed'] + $result['failed'] === 0) {
            ZohoSyncLogRepository::log('expense_import', null, null, null, 'skipped', null, 'Nothing new — every expense in Zoho Books is already imported and up to date.', $triggeredBy, $triggeredByUserId);
            $result['skipped'] = 1;
        }
        return $result;
    }

    private static function markSynced(int $orderId, string $leg, string $zohoReference): void
    {
        match ($leg) {
            'advance' => OrderPaymentStatusRepository::setAdvanceZohoSync($orderId, $zohoReference),
            'balance' => OrderPaymentStatusRepository::setBalanceZohoSync($orderId, $zohoReference),
            'freight' => OrderPaymentStatusRepository::setFreightZohoSync($orderId, $zohoReference),
        };
    }

    private static function offReason(): string
    {
        return ZohoGuard::integrationEnabled()
            ? 'Zoho Books is not enabled/configured'
            : 'Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED in .env)';
    }
}
