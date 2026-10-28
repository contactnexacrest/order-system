<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderAssigneeRepository;

/**
 * Who may see an order at all — the archived-order and order-assignment
 * rules the order page applies, in one place so every route that serves
 * something belonging to an order (its page, its files) uses the same
 * answer. Visibility only: it never decides who may act on a step.
 */
final class OrderAccessService
{
    /** @return string|null why this user may not see the order, or null when they may */
    public static function denialReason(array $order, int $userId, ?int $roleId): ?string
    {
        if ((int) ($order['is_archived'] ?? 0) === 1 && !PermissionService::can($userId, $roleId, 'view_archived_orders')) {
            return 'This order has been archived. You need the "View archived orders" permission to open it.';
        }
        if (!PermissionService::can($userId, $roleId, 'view_all_orders') && !OrderAssigneeRepository::isAssignedTo((int) $order['id'], $userId)) {
            return 'This order is assigned to a different user. You can only open orders assigned to you.';
        }
        return null;
    }
}
