<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CaExpenseRepository;
use App\Repositories\CaRepository;
use App\Repositories\OrderPaymentStatusRepository;
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
    /** @return array{revenue: array{synced:int, failed:int, skipped:int}, expenses: array{imported:int, failed:int, skipped:int}} */
    public static function runFullSync(string $triggeredBy, ?int $triggeredByUserId): array
    {
        return [
            'revenue' => self::syncPendingRevenue($triggeredBy, $triggeredByUserId),
            'expenses' => self::importPendingExpenses($triggeredBy, $triggeredByUserId),
        ];
    }

    /** @return array{synced:int, failed:int, skipped:int} */
    public static function syncPendingRevenue(string $triggeredBy, ?int $triggeredByUserId): array
    {
        if (!ZohoBooksService::isEnabled()) {
            ZohoSyncLogRepository::log('revenue_payment', null, null, null, 'skipped', null, 'Zoho Books is not enabled/configured — nothing synced.', $triggeredBy, $triggeredByUserId);
            return ['synced' => 0, 'failed' => 0, 'skipped' => 1];
        }

        $synced = 0;
        $failed = 0;
        foreach (CaRepository::legsPendingZohoSync() as $leg) {
            $referenceNumber = $leg['buyer_inquiry_ref'] . '-' . $leg['leg'];
            try {
                $zohoReference = ZohoBooksService::pushRevenuePayment(
                    $leg['client_id'],
                    $leg['company_legal_name'],
                    (float) $leg['inr_actual'],
                    substr((string) $leg['cleared_at'], 0, 10),
                    $referenceNumber
                );
                self::markSynced((int) $leg['order_id'], $leg['leg'], $zohoReference);
                ZohoSyncLogRepository::log('revenue_payment', 'order_payment_status', (int) $leg['order_id'], $leg['leg'], 'success', $zohoReference, "Pushed as Zoho Books customer payment {$zohoReference}.", $triggeredBy, $triggeredByUserId);
                $synced++;
            } catch (\Throwable $e) {
                ZohoSyncLogRepository::log('revenue_payment', 'order_payment_status', (int) $leg['order_id'], $leg['leg'], 'error', null, $e->getMessage(), $triggeredBy, $triggeredByUserId);
                $failed++;
            }
        }

        if ($synced === 0 && $failed === 0) {
            ZohoSyncLogRepository::log('revenue_payment', null, null, null, 'skipped', null, 'Nothing pending — every recorded INR actual is already synced.', $triggeredBy, $triggeredByUserId);
            return ['synced' => 0, 'failed' => 0, 'skipped' => 1];
        }

        return ['synced' => $synced, 'failed' => $failed, 'skipped' => 0];
    }

    /**
     * @return array{imported:int, failed:int, skipped:int}
     */
    public static function importPendingExpenses(string $triggeredBy, ?int $triggeredByUserId): array
    {
        if (!ZohoBooksService::isEnabled()) {
            ZohoSyncLogRepository::log('expense_import', null, null, null, 'skipped', null, 'Zoho Books is not enabled/configured — nothing imported.', $triggeredBy, $triggeredByUserId);
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
            if (CaExpenseRepository::existsByZohoId($expense['zohoExpenseId'])) {
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
}
