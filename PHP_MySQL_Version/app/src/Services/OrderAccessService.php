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
    /** @var array{user: int, ids: array<int, true>, archived: array<int, true>}|null per-request cache for currentUserMayOpenOrderId() */
    private static ?array $openableCache = null;

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

    /**
     * Whether the signed-in user may open /orders/{id} — for list pages
     * (the CA hub, reports, ...) deciding whether an order reference is a
     * link or plain text. Same answer as the route: manage_orders or
     * view_orders, plus denialReason()'s archived and assignment rules.
     * Two queries per request at most, whatever the number of rows.
     */
    public static function currentUserMayOpenOrderId(int $orderId): bool
    {
        $user = AuthService::currentUser();
        if (!$user) {
            return false;
        }
        $userId = (int) $user['id'];
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!PermissionService::can($userId, $roleId, 'manage_orders') && !PermissionService::can($userId, $roleId, 'view_orders')) {
            return false;
        }
        if (self::$openableCache === null || self::$openableCache['user'] !== $userId) {
            $pdo = \App\Config\Database::connection();
            $assigned = $pdo->prepare('SELECT order_id FROM order_assignees WHERE user_id = :u');
            $assigned->execute(['u' => $userId]);
            self::$openableCache = [
                'user'     => $userId,
                'ids'      => array_fill_keys(array_map('intval', $assigned->fetchAll(\PDO::FETCH_COLUMN)), true),
                'archived' => array_fill_keys(array_map('intval', $pdo->query('SELECT id FROM orders WHERE is_archived = 1')->fetchAll(\PDO::FETCH_COLUMN)), true),
            ];
        }
        if (isset(self::$openableCache['archived'][$orderId]) && !PermissionService::can($userId, $roleId, 'view_archived_orders')) {
            return false;
        }
        return PermissionService::can($userId, $roleId, 'view_all_orders') || isset(self::$openableCache['ids'][$orderId]);
    }

    public static function resetCache(): void
    {
        self::$openableCache = null;
    }
}
