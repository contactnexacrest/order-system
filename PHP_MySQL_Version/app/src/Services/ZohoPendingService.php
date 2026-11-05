<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CaExportBenefitRepository;
use App\Repositories\CaRepository;
use App\Repositories\OrderZohoInvoiceRepository;
use App\Repositories\PaymentMilestoneRepository;
use App\Repositories\ZohoPurchaseRepository;

/**
 * Owner decision 2 Nov 2026 (D-233): "Waiting for Zoho: N". A record saved
 * in the app goes to Zoho Books at the next sync (hourly, or Sync now) —
 * this counts what that next sync would send, using the same selectors
 * CaSyncService pushes from, so the badge and the sync never disagree:
 * Commercial Invoices / Debit Notes not yet in Books (or a newer approved
 * revision), buyer payments and milestone receipts not yet sent or not yet
 * applied, tax-invoice bills, vendor payments (sent or held), order
 * expenses, order costs (new or changed), export benefit journals (new,
 * changed or to remove) and GST refund credits (app-wide only).
 * Nothing is counted while Zoho is off (the sync would send nothing).
 */
final class ZohoPendingService
{
    /**
     * Owner decision 2 Nov 2026 (D-239): while today's allowance of Zoho API
     * calls for this app is used up, what is waiting cannot go until tomorrow.
     */
    public static function pausedText(): ?string
    {
        return ZohoBooksService::isEnabled() && ZohoApiBudget::exhaustedToday()
            ? 'paused — daily Zoho allowance used, resumes tomorrow'
            : null;
    }

    /** @return array{enabled:bool, total:int, by_type: array<string,int>} */
    public static function counts(?int $orderId = null): array
    {
        $by = [];
        if (!ZohoBooksService::isEnabled()) {
            return ['enabled' => false, 'total' => 0, 'by_type' => []];
        }
        $start = ZohoBooksService::syncStartDate();
        if ($start === null && ZohoGuard::isProduction()) {
            return ['enabled' => true, 'total' => 0, 'by_type' => []];
        }
        // D-257: only the orders of the request's division(s).
        $in = static fn ($id): bool => ($orderId === null || ($id !== null && (int) $id === $orderId))
            && ($id === null || DivisionService::allowsOrderId((int) $id));
        $add = static function (string $type, int $n) use (&$by): void {
            if ($n > 0) {
                $by[$type] = ($by[$type] ?? 0) + $n;
            }
        };

        $n = 0;
        foreach (OrderZohoInvoiceRepository::documentsToSync($start) as $item) {
            if (!$in($item['order_id'])) {
                continue;
            }
            $known = OrderZohoInvoiceRepository::find($item['order_id'], $item['kind']);
            if ($known === null || (int) ($known['document_id'] ?? 0) !== (int) $item['document_id']) {
                $n++;
            }
        }
        $add('invoice', $n);
        $onOrAfter = static fn ($date): bool => $start === null || substr((string) $date, 0, 10) >= $start;
        $add('buyer_payment', count(array_filter(CaRepository::legsPendingZohoSync(), static fn (array $r): bool => $in($r['order_id']) && $onOrAfter($r['cleared_at'] ?? null))));
        $add('payment_to_apply', count(array_filter(CaRepository::legsHeldInZoho(), static fn (array $r): bool => $in($r['order_id']))));
        foreach (\App\Repositories\OrderLcDetailRepository::receiptsForZohoSync() as $lc) {
            if (!$in($lc['order_id'])) {
                continue;
            }
            if ($lc['lc_zoho_synced_at'] === null) {
                $add('buyer_payment', $onOrAfter($lc['lc_received_at']) ? 1 : 0);
            } else {
                $add('payment_to_apply', 1);
            }
        }
        $add('milestone_payment', count(array_filter(PaymentMilestoneRepository::pendingZohoSync(), static fn (array $r): bool => $in($r['order_id']) && $onOrAfter($r['cleared_on']))));
        $add('payment_to_apply', count(array_filter(PaymentMilestoneRepository::heldInZoho(), static fn (array $r): bool => $in($r['order_id']))));
        $add('vendor_bill', count(array_filter(ZohoPurchaseRepository::billsToSync($start), static fn (array $r): bool => $in($r['order_id']))));
        $add('vendor_payment', count(array_filter(ZohoPurchaseRepository::paymentsToSync($start), static fn (array $r): bool => $in($r['bill']['order_id'] ?? null))));
        $add('vendor_payment', count(array_filter(ZohoPurchaseRepository::heldPayments(), static fn (array $r): bool => $in($r['bill']['order_id'] ?? null))));
        $add('order_expense', count(array_filter(ZohoPurchaseRepository::expensesToSync($start), static fn (array $r): bool => $in($r['order_id']))));

        $n = 0;
        foreach (ZohoPurchaseRepository::costEntriesToSync($start) as $cost) {
            if (!$in($cost['order_id'])) {
                continue;
            }
            if (($cost['incentive_kind'] ?? null) === 'associate') {
                $n += $cost['zoho_expense_id'] === null ? 1 : 0;
                continue;
            }
            $hash = hash('sha256', (string) json_encode([$cost['category'], round((float) $cost['amount_inr'], 2), (string) $cost['cost_date'], $cost['paid_from'], $cost['description']]));
            if ($cost['zoho_expense_id'] === null || $cost['zoho_pushed_hash'] !== $hash) {
                $n++;
            }
        }
        $add('order_cost', $n);

        $n = 0;
        foreach (CaExportBenefitRepository::forZohoSync($start) as $b) {
            if (!$in($b['order_id'] ?? null)) {
                continue;
            }
            $received = $b['received_amount'] !== null && (float) $b['received_amount'] > 0;
            if (!$received) {
                $n++; // in Books, receipt cleared in the app: the sync removes the journal
                continue;
            }
            $hash = hash('sha256', (string) json_encode([$b['scheme_name'], round((float) $b['received_amount'], 2), (string) $b['received_at'], $b['reference_number'], $b['order_id']]));
            if ($b['zoho_journal_id'] === null || $b['zoho_pushed_hash'] !== $hash) {
                $n++;
            }
        }
        $add('export_benefit', $n);

        if ($orderId === null) {
            $add('gst_refund', count(\App\Repositories\GstRefundRepository::creditsForZohoSync($start)));
        }
        if ($orderId === null) {
            // D-257 Zoho: the new divisions' invoices, bills, journals and payments.
            foreach (DivisionZohoSync::pendingCounts($start) as $type => $n) {
                $add($type, $n);
            }
        }
        if ($orderId === null) {
            // Owner decision 2 Nov 2026 (D-238): clients / products, only while their switch is on.
            foreach (ZohoMasterDataSync::pendingCounts() as $type => $n) {
                $add($type, $n);
            }
        }
        return ['enabled' => true, 'total' => array_sum($by), 'by_type' => $by];
    }

    public const TYPE_LABELS = [
        'invoice' => 'Commercial Invoice / Debit Note',
        'buyer_payment' => 'buyer payment',
        'milestone_payment' => 'milestone payment',
        'payment_to_apply' => 'payment to apply to its invoice',
        'vendor_bill' => 'supplier / partner bill',
        'vendor_payment' => 'vendor payment',
        'order_expense' => 'order expense',
        'order_cost' => 'order cost',
        'export_benefit' => 'export benefit journal',
        'gst_refund' => 'GST refund journal',
        'client' => 'client (customer)',
        'product' => 'product (item)',
    ] + DivisionZohoSync::TYPE_LABELS;

    /**
     * The menu badge: all records waiting, worked out at most once a minute
     * per session (the count reads every pending selector).
     */
    public static function menuCount(): ?int
    {
        $now = time();
        $cached = $_SESSION['zoho_waiting_badge'] ?? null;
        $scope = DivisionService::condition() ?? '';
        if (is_array($cached) && ($now - (int) $cached['at']) < 60 && ($cached['scope'] ?? '') === $scope) {
            return $cached['n'];
        }
        try {
            $c = self::counts(null);
            $n = $c['enabled'] ? $c['total'] : null;
        } catch (\Throwable $e) {
            $n = null;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['zoho_waiting_badge'] = ['at' => $now, 'n' => $n, 'scope' => $scope];
        }
        return $n;
    }

    /** "2 order costs, 1 buyer payment" */
    public static function summary(array $byType): string
    {
        $parts = [];
        foreach ($byType as $type => $n) {
            $label = self::TYPE_LABELS[$type] ?? $type;
            $parts[] = $n . ' ' . $label . ($n === 1 ? '' : 's');
        }
        return implode(', ', $parts);
    }
}
