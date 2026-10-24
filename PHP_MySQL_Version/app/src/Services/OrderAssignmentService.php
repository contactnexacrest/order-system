<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;

/**
 * Order ownership / assignment visibility: changing orders.assigned_to,
 * the single staff "owner" of an order. Visibility-only — this never
 * touches the stage-gate or action-permission system (manage_payments,
 * clear_payments, manage_shipping, etc. are completely unaffected).
 * Reaching this service at all already means the caller holds
 * assign_order_owner (route-level PermissionCheck); this class doesn't
 * re-check it.
 */
final class OrderAssignmentService
{
    public static function reassign(int $orderId, int $newOwnerId, int $actingUserId): void
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        $previousOwnerId = $order['assigned_to'] !== null ? (int) $order['assigned_to'] : null;
        if ($previousOwnerId === $newOwnerId) {
            // No-op reassignment (picked the current owner again) — nothing
            // to write, nothing to notify.
            return;
        }

        OrderRepository::setAssignedTo($orderId, $newOwnerId);

        AuditLogRepository::log(
            $actingUserId,
            'ORDER_OWNER_REASSIGNED',
            'orders',
            $orderId,
            'assigned_to',
            $previousOwnerId !== null ? (string) $previousOwnerId : null,
            (string) $newOwnerId
        );

        NotificationRepository::create(
            $newOwnerId,
            null,
            'order_assigned',
            $orderId,
            "You've been assigned as the owner of order {$order['order_reference']}."
        );
    }
}
