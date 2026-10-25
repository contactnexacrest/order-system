<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Order ownership / assignment visibility (docs/migrations/2026-10-24c-
 * order-multi-assignee.sql). One row per (order, assigned staff member) —
 * a pure membership table, unlike DocumentReviewRepository's reviews
 * (no per-row status). An order can have 0, 1, or more assignees at
 * once, e.g. an Export Executive handling office work and a Logistics
 * Executive handling CHA/factory work on the SAME order concurrently.
 * See OrderAssignmentService for the write path (audit log + notification).
 */
final class OrderAssigneeRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT oa.order_id, oa.user_id, oa.assigned_at, u.name, u.email, r.name AS role_name
             FROM order_assignees oa
             JOIN users u ON u.id = oa.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE oa.order_id = :order_id
             ORDER BY oa.assigned_at'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function isAssignedTo(int $orderId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM order_assignees WHERE order_id = :order_id AND user_id = :user_id'
        );
        $stmt->execute(['order_id' => $orderId, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return bool true if a new row was actually inserted (false = already assigned). */
    public static function add(int $orderId, int $userId, ?int $assignedBy): bool
    {
        if (self::isAssignedTo($orderId, $userId)) {
            return false;
        }
        Database::connection()->prepare(
            'INSERT INTO order_assignees (order_id, user_id, assigned_by) VALUES (:order_id, :user_id, :assigned_by)'
        )->execute(['order_id' => $orderId, 'user_id' => $userId, 'assigned_by' => $assignedBy]);
        return true;
    }

    public static function remove(int $orderId, int $userId): void
    {
        Database::connection()->prepare(
            'DELETE FROM order_assignees WHERE order_id = :order_id AND user_id = :user_id'
        )->execute(['order_id' => $orderId, 'user_id' => $userId]);
    }
}
