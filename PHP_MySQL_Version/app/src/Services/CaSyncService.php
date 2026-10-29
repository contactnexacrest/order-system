<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CaExpenseRepository;
use App\Repositories\CaRepository;
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
        return [
            'revenue' => self::syncPendingRevenue($triggeredBy, $triggeredByUserId),
            // Stage 3 runs before the expense import, so the order expenses it
            // sends are already marked and the import skips them.
            'purchases' => self::syncPurchases($triggeredBy, $triggeredByUserId),
            'expenses' => self::importPendingExpenses($triggeredBy, $triggeredByUserId),
        ];
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

        $failed = 0;
        $invoices = 0;
        foreach (OrderZohoInvoiceRepository::documentsToSync() as $item) {
            $label = ZohoInvoiceData::KIND_LABELS[$item['kind']];
            try {
                $invoice = ZohoInvoiceData::build($item['order_id'], $item['kind'], $item['document_id']);
                $hash = ZohoInvoiceData::fingerprint($invoice);
                $known = OrderZohoInvoiceRepository::find($item['order_id'], $item['kind']);
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
                ZohoSyncLogRepository::log('invoice', 'order', $item['order_id'], $item['kind'], 'success', $pushed['zoho_invoice_id'], "{$what} {$label} {$invoice['invoice_number']} as Zoho Books invoice ({$invoice['currency_code']} " . number_format($invoice['total'], 2, '.', '') . ' @ ' . $invoice['exchange_rate'] . ').', $triggeredBy, $triggeredByUserId);
                $invoices++;
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('invoice', 'order', $item['order_id'], $item['kind'], 'error', null, $e->getMessage(), $triggeredBy, $triggeredByUserId);
                $failed++;
            }
        }

        $synced = 0;
        $applied = 0;
        foreach (CaRepository::legsPendingZohoSync() as $leg) {
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
                ZohoSyncLogRepository::log('revenue_payment', 'order_payment_status', (int) $leg['order_id'], $leg['leg'], 'success', $zohoReference, "Pushed as Zoho Books customer payment {$zohoReference}" . self::appliedNote($leg['leg'], $target, $result['applied']), $triggeredBy, $triggeredByUserId);
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

        if ($synced === 0 && $failed === 0 && $invoices === 0 && $applied === 0) {
            ZohoSyncLogRepository::log('revenue_payment', null, null, null, 'skipped', null, 'Nothing pending — every approved CI / Debit Note and every recorded INR actual is already in Zoho Books.', $triggeredBy, $triggeredByUserId);
            return ['synced' => 0, 'failed' => 0, 'skipped' => 1, 'invoices' => 0, 'applied' => 0];
        }

        return ['synced' => $synced, 'failed' => $failed, 'skipped' => 0, 'invoices' => $invoices, 'applied' => $applied];
    }

    /** Which invoice each payment leg is paid against: the advance and the balance against the CI, freight against the Debit Note. */
    private const LEG_INVOICE = ['advance' => 'ci', 'balance' => 'ci', 'freight' => 'dn'];

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
        $log = static function (string $type, int $orderId, ?string $leg, string $status, ?string $reference, string $message) use ($triggeredBy, $triggeredByUserId): void {
            ZohoSyncLogRepository::log($type, 'order', $orderId, $leg, $status, $reference, $message, $triggeredBy, $triggeredByUserId);
        };

        foreach (ZohoPurchaseRepository::billsToSync() as $bill) {
            try {
                $zohoBillId = ZohoBooksService::pushBill($bill);
                ZohoPurchaseRepository::setBillSynced((int) $bill['id'], $zohoBillId);
                $log('vendor_bill', (int) $bill['order_id'], 'bill', 'success', $zohoBillId, "Sent bill {$bill['bill_no']} from {$bill['vendor_name']} (INR " . number_format((float) $bill['total_inr'], 2, '.', '') . ') as Zoho Books bill.');
                $result['bills']++;
            } catch (\Throwable $e) {
                $log('vendor_bill', (int) $bill['order_id'], 'bill', 'error', null, $e->getMessage());
                $result['failed']++;
            }
        }

        foreach (ZohoPurchaseRepository::paymentsToSync() as ['payment' => $payment, 'bill' => $bill]) {
            $target = ZohoPurchaseRepository::targetBillFor(ZohoPurchaseRepository::findBill((int) $bill['id']) ?? $bill);
            try {
                $pushed = ZohoBooksService::pushVendorPayment($payment, $bill, $target);
                ZohoPurchaseRepository::setPaymentSynced((int) $payment['id'], $pushed['payment_id'], $pushed['applied'] > 0);
                $note = $target === null
                    ? ", held unapplied until the tax invoice for proforma {$bill['bill_no']} is in Zoho Books."
                    : ($pushed['applied'] > 0 ? ', applied ' . number_format($pushed['applied'], 2, '.', '') . " to bill {$target['bill_no']}." : ", held unapplied: bill {$target['bill_no']} has nothing left to pay.");
                $log('vendor_payment', (int) $bill['order_id'], 'payment', 'success', $pushed['payment_id'], "Pushed payment {$payment['payment_ref']} (INR " . number_format((float) $payment['amount_inr'], 2, '.', '') . ") to {$bill['vendor_name']} as Zoho Books vendor payment{$note}");
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

        foreach (ZohoPurchaseRepository::expensesToSync() as $expense) {
            try {
                $zohoExpenseId = ZohoBooksService::pushExpense($expense);
                ZohoPurchaseRepository::setExpenseSynced((int) $expense['id'], $zohoExpenseId);
                $log('order_expense', (int) $expense['order_id'], 'expense', 'success', $zohoExpenseId, "Sent expense \"{$expense['details']}\" (INR " . number_format((float) $expense['amount_inr'], 2, '.', '') . ') as Zoho Books expense.');
                $result['expenses']++;
            } catch (\Throwable $e) {
                $log('order_expense', (int) $expense['order_id'], 'expense', 'error', null, $e->getMessage());
                $result['failed']++;
            }
        }

        if ($result['bills'] + $result['payments'] + $result['applied'] + $result['expenses'] + $result['failed'] === 0) {
            ZohoSyncLogRepository::log('vendor_bill', null, null, null, 'skipped', null, 'Nothing pending — every tax invoice, vendor payment and order expense is already in Zoho Books.', $triggeredBy, $triggeredByUserId);
            $result['skipped'] = 1;
        }
        return $result;
    }

    /**
     * @return array{imported:int, failed:int, skipped:int}
     */
    public static function importPendingExpenses(string $triggeredBy, ?int $triggeredByUserId): array
    {
        if (!ZohoBooksService::isEnabled()) {
            ZohoSyncLogRepository::log('expense_import', null, null, null, 'skipped', null, self::offReason() . ' — nothing imported.', $triggeredBy, $triggeredByUserId);
            return ['imported' => 0, 'failed' => 0, 'skipped' => 1];
        }

        try {
            $expenses = ZohoBooksService::listExpenses();
        } catch (\Throwable $e) {
            ZohoSyncLogRepository::log('expense_import', null, null, null, 'error', null, $e->getMessage(), $triggeredBy, $triggeredByUserId);
            return ['imported' => 0, 'failed' => 1, 'skipped' => 0];
        }

        $imported = 0;
        foreach ($expenses as $expense) {
            // Stage 3: an order expense this app sent to Books is already
            // here as that order expense — never import it a second time.
            if (CaExpenseRepository::existsByZohoId($expense['zohoExpenseId']) || ZohoPurchaseRepository::isPushedExpense($expense['zohoExpenseId'])) {
                continue;
            }
            $id = CaExpenseRepository::insert(
                $expense['zohoExpenseId'],
                $expense['category'],
                $expense['description'],
                $expense['vendorName'],
                $expense['amount'],
                $expense['currencyCode'],
                $expense['expenseDate']
            );
            ZohoSyncLogRepository::log(
                'expense_import',
                'ca_expenses',
                $id,
                null,
                'success',
                $expense['zohoExpenseId'],
                "Imported expense {$expense['zohoExpenseId']} ({$expense['category']}, {$expense['amount']} {$expense['currencyCode']}).",
                $triggeredBy,
                $triggeredByUserId
            );
            $imported++;
        }

        if ($imported === 0) {
            ZohoSyncLogRepository::log('expense_import', null, null, null, 'skipped', null, 'Nothing new — every expense in Zoho Books is already imported.', $triggeredBy, $triggeredByUserId);
            return ['imported' => 0, 'failed' => 0, 'skipped' => 1];
        }

        return ['imported' => $imported, 'failed' => 0, 'skipped' => 0];
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
