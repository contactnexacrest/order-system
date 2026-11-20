<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\OrderCancellationRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;

/**
 * "Mark as Lost" in one place (owner decision D-394, 20 Nov 2026): the order
 * page's Mark Lost button (OrderController::markLost) and the daily
 * quick-quote prospect expiry (ProspectService::expireDue) use the same
 * refusal rules and the same write — status "lost" (the buyer sees "Did not
 * go ahead"), the order locked, its buyer / payment links ended, an
 * ORDER_MARKED_LOST audit row. Nothing is deleted. Node twin:
 * services/orderLostService.js.
 */
final class OrderLostService
{
    /**
     * Why this order may not be marked lost, or null when it may.
     * The same rules, in the same order, as Mark Lost has always had.
     *
     * @param array<string,mixed> $order
     */
    public static function refusal(array $order): ?string
    {
        $orderId = (int) $order['id'];
        if ($order['status'] !== 'active') {
            return "Only an active order can be marked lost (this one is already \"{$order['status']}\").";
        }
        // 90-item backlog Phase 6 Item 3 — once a real Bill of Lading exists, the goods have shipped.
        $shipping = OrderShippingRepository::find($orderId);
        if ($shipping && !empty($shipping['bl_number'])) {
            return 'This order already has a Bill of Lading on file — the goods have shipped, so it can no longer be marked "lost". Use Close Order instead.';
        }
        // QA batch 2 (ORD-04): "lost" is the walk-away before any money.
        if (OrderCancellationRepository::findPendingForOrder($orderId)) {
            return 'This order has a cancellation request awaiting a decision — approve or reject it instead of marking the order lost.';
        }
        $lc = OrderLcDetailRepository::find($orderId);
        if (PartPaymentService::orderReceivedTotal($orderId) > 0 || ($lc !== null && !empty($lc['lc_confirmed_at']))) {
            return 'The buyer has already paid on this order, so it cannot be marked lost. Use Request Cancellation: a second person approves it and the refund is recorded.';
        }
        return null;
    }

    /**
     * Marks the order lost (locks it), with its audit row. $userId null = the
     * system (the daily prospect expiry). $category is saved only when given.
     */
    public static function markLost(int $orderId, string $reason, ?int $userId, ?string $category = null): void
    {
        $order = OrderRepository::find($orderId);
        OrderRepository::markLost($orderId, $reason, $userId); // lost_by NULL = the system
        AuditLogRepository::log($userId, 'ORDER_MARKED_LOST', 'orders', $orderId, 'status', (string) ($order['status'] ?? 'active'), 'lost', $reason);
        if ($category !== null) {
            OrderRepository::setLostCategory($orderId, $category);
            AuditLogRepository::log($userId, 'ORDER_LOST_CATEGORY_SET', 'orders', $orderId, 'lost_category', null, $category, $reason);
        }
    }
}
