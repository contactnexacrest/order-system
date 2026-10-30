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
        // QA Round 6, R6-N08: routes keyed by a record that belongs to an
        // order (an amendment, dispute, complaint, queued email, PI-stage
        // submission, reorder request, document review or export benefit)
        // follow the same rule as the order itself.
        foreach (self::CHILD_ROUTES as $pattern => $sql) {
            if (preg_match($pattern, $path, $m)) {
                $stmt = \App\Config\Database::connection()->prepare($sql);
                $stmt->execute(['id' => (int) $m[1]]);
                $orderId = $stmt->fetchColumn();
                return $orderId !== false && $orderId !== null ? (int) $orderId : null;
            }
        }
        return null;
    }

    /** @var array<string, string> path pattern => query returning the owning order id */
    private const CHILD_ROUTES = [
        '#^/amendments/(\d+)(?:/|$)#'       => 'SELECT order_id FROM amendments WHERE id = :id',
        '#^/disputes/(\d+)(?:/|$)#'         => 'SELECT order_id FROM disputes WHERE id = :id',
        '#^/complaints/(\d+)(?:/|$)#'       => 'SELECT order_id FROM order_complaints WHERE id = :id',
        '#^/email-log/(\d+)(?:/|$)#'        => 'SELECT order_id FROM email_log WHERE id = :id',
        '#^/pi-intake-review/(\d+)(?:/|$)#' => 'SELECT order_id FROM pi_intake_submissions WHERE id = :id',
        '#^/reorder-requests/(\d+)(?:/|$)#' => 'SELECT source_order_id FROM order_reorder_requests WHERE id = :id',
        '#^/reviews/(\d+)(?:/|$)#'          => 'SELECT d.order_id FROM document_reviews r JOIN documents d ON d.id = r.document_id WHERE r.id = :id',
        '#^/ca/export-benefits/(\d+)(?:/|$)#' => 'SELECT order_id FROM ca_export_benefits WHERE id = :id',
    ];

    /**
     * QA Round 6, R6-N04: the "not found" answer for a staff URL whose
     * order, document or order-linked record does not exist — so an action
     * on it never reports success or writes an audit row. Null when the
     * path is not one of these, or its record exists.
     */
    public static function missingRecordForPath(string $path): ?string
    {
        $pdo = \App\Config\Database::connection();
        if (preg_match('#^/(?:orders|reports/order)/(\d+)(?:/|$)#', $path, $m)) {
            $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE id = :id');
            $stmt->execute(['id' => (int) $m[1]]);
            return $stmt->fetchColumn() ? null : 'Order not found.';
        }
        $tables = ['#^/documents/(\d+)(?:/|$)#' => 'documents'] + array_combine(array_keys(self::CHILD_ROUTES), array_map(
            static fn (string $sql): string => (string) preg_replace('#^.*?FROM (\w+).*$#s', '$1', $sql),
            self::CHILD_ROUTES
        ));
        foreach ($tables as $pattern => $table) {
            if (preg_match($pattern, $path, $m)) {
                $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id");
                $stmt->execute(['id' => (int) $m[1]]);
                return $stmt->fetchColumn() ? null : 'Not found.';
            }
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
        return self::currentUserMaySeeOrderId($orderId);
    }

    /**
     * The archived and assignment rules alone (no page permission): whether
     * a record belonging to this order may appear in a list the user can
     * open, e.g. the Disputes and Complaints lists (QA Round 6, R6-N09).
     */
    public static function currentUserMaySeeOrderId(int $orderId): bool
    {
        $user = AuthService::currentUser();
        if (!$user) {
            return false;
        }
        $userId = (int) $user['id'];
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
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

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>> the rows whose order the user may see
     */
    public static function visibleRows(array $rows, string $orderIdKey = 'order_id'): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => self::currentUserMaySeeOrderId((int) $r[$orderIdKey])));
    }

    /**
     * QA Round 6, R6-N01 / Q-R6-4 (owner decision D-118): the SQL condition
     * limiting a query on `orders {$alias}` to the orders the signed-in user
     * may see — nothing for holders of view_all_orders (and of
     * view_archived_orders), else their assigned orders, without archived
     * ones unless they may see those. Reports and client pages use it, so
     * their rows and totals cover exactly what the user can open.
     *
     * @return array{0: string, 1: array<string, int>} [" AND …" or "", params]
     */
    public static function visibilitySql(string $alias = 'o'): array
    {
        $user = AuthService::currentUser();
        if (!$user) {
            return ['', []];
        }
        $userId = (int) $user['id'];
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $sql = '';
        $params = [];
        if (!PermissionService::can($userId, $roleId, 'view_all_orders')) {
            $sql .= " AND {$alias}.id IN (SELECT vis_oa.order_id FROM order_assignees vis_oa WHERE vis_oa.user_id = :vis_user_id)";
            $params['vis_user_id'] = $userId;
        }
        if (!PermissionService::can($userId, $roleId, 'view_archived_orders')) {
            $sql .= " AND {$alias}.is_archived = 0";
        }
        return [$sql, $params];
    }

    public static function resetCache(): void
    {
        self::$openableCache = null;
    }
}
