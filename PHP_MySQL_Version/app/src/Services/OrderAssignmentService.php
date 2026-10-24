<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Repositories\OrderRepository;

/**
 * Order ownership / assignment visibility: who's in orders.assigned_to's
 * successor, order_assignees (docs/migrations/2026-10-24c-order-multi-
 * assignee.sql). Visibility-only — this never touches the stage-gate or
 * action-permission system (manage_payments, clear_payments,
 * manage_shipping, etc. are completely unaffected). Reaching this service
 * at all already means the caller holds assign_order_owner (route-level
 * PermissionCheck); this class doesn't re-check it.
 */
final class OrderAssignmentService
{
    public static function addAssignee(int $orderId, int $userId, int $actingUserId): void
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        $inserted = OrderAssigneeRepository::add($orderId, $userId, $actingUserId);
        if (!$inserted) {
            // Already assigned — no-op, nothing to write or notify.
            return;
        }

        AuditLogRepository::log(
            $actingUserId,
            'ORDER_ASSIGNEE_ADDED',
            'orders',
            $orderId,
            'assignee',
            null,
            (string) $userId
        );

        NotificationRepository::create(
            $userId,
            null,
            'order_assigned',
            $orderId,
            "You've been added as an assignee on order {$order['order_reference']}."
        );
    }

    public static function removeAssignee(int $orderId, int $userId, int $actingUserId): void
    {
        $order = OrderRepository::find($orderId);
        if (!$order) {
            throw new \RuntimeException("Order {$orderId} not found");
        }

        OrderAssigneeRepository::remove($orderId, $userId);

        AuditLogRepository::log(
            $actingUserId,
            'ORDER_ASSIGNEE_REMOVED',
            'orders',
            $orderId,
            'assignee',
            (string) $userId,
            null
        );
    }
}
