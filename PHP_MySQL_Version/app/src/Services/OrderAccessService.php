<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DocumentRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Repositories\OrderRepository;

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

    /**
     * QA Round 5, R5-24: the order a staff URL belongs to — every page and
     * action under /orders/{id}/, the order's Full Report, and the
     * document routes (/documents/{id}/...) — so SessionAuth can apply
     * denialReason() to all of them, GET and POST, not just the order page.
     */
    public static function orderIdForPath(string $path): ?int
    {
        if (preg_match('#^/(?:orders|reports/order)/(\d+)(?:/|$)#', $path, $m)) {
            return (int) $m[1];
        }
        if (preg_match('#^/documents/(\d+)(?:/|$)#', $path, $m)) {
            $document = DocumentRepository::find((int) $m[1]);
            return $document ? (int) $document['order_id'] : null;
        }
        return null;
    }

    /** @return string|null why this user may not open the order this path belongs to */
    public static function denialReasonForPath(string $path, int $userId, ?int $roleId): ?string
    {
        $orderId = self::orderIdForPath($path);
        $order = $orderId !== null ? OrderRepository::find($orderId) : null;
        return $order ? self::denialReason($order, $userId, $roleId) : null;
    }
}
