<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Per-order status against the compliance_task_types list (docs/schema.sql
 * Section AR). Every active task type gets a real 'not_started' row for
 * every order, written at order creation (initializeForOrder(), called
 * from OrderRepository::insertRow() the same way OrderAssigneeRepository
 * auto-assigns the creator) — a report run directly against this table
 * must never see "no row" standing in for "not applicable," since that's
 * indistinguishable from "forgotten." Visibility and every write action
 * here is gated on the existing close_orders permission (checked in the
 * controller), not a new one — "the person who has permission to close
 * the order must able to see this otherwise no meaning for this."
 */
final class OrderComplianceTaskRepository
{
    public const STATUSES = [
        'not_started'      => 'Not Started',
        'pending_approval' => 'Pending Approval',
        'approved'         => 'Approved',
        'skipped'          => 'Skipped (Not Applicable)',
    ];

    /** Writes a real 'not_started' row for every active task type — called once, at order creation. */
    public static function initializeForOrder(int $orderId): void
    {
        $pdo = Database::connection();
        $taskTypeIds = $pdo->query('SELECT id FROM compliance_task_types WHERE is_active = 1')->fetchAll(\PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare(
            'INSERT INTO order_compliance_tasks (order_id, task_type_id, status)
             VALUES (:order_id, :task_type_id, \'not_started\')
             ON DUPLICATE KEY UPDATE status = status'
        );
        foreach ($taskTypeIds as $taskTypeId) {
            $stmt->execute(['order_id' => $orderId, 'task_type_id' => (int) $taskTypeId]);
        }
    }

    /** @return array<int, array<string,mixed>> one row per active task type, LEFT JOINed against this order's own status */
    public static function forOrder(int $orderId): array
    {
        $sql = 'SELECT
                    tt.id AS task_type_id,
                    tt.name AS task_type_name,
                    COALESCE(oct.status, \'not_started\') AS status,
                    oct.skip_reason,
                    oct.resolved_at,
                    u.name AS resolved_by_name
                FROM compliance_task_types tt
                LEFT JOIN order_compliance_tasks oct
                    ON oct.task_type_id = tt.id AND oct.order_id = :order_id
                LEFT JOIN users u ON u.id = oct.resolved_by
                WHERE tt.is_active = 1
                ORDER BY tt.name';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function setStatus(int $orderId, int $taskTypeId, string $status, ?string $skipReason, int $userId): void
    {
        $sql = 'INSERT INTO order_compliance_tasks (order_id, task_type_id, status, skip_reason, resolved_at, resolved_by)
                VALUES (:order_id, :task_type_id, :status, :skip_reason, NOW(), :resolved_by)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    skip_reason = VALUES(skip_reason),
                    resolved_at = VALUES(resolved_at),
                    resolved_by = VALUES(resolved_by)';
        Database::connection()->prepare($sql)->execute([
            'order_id'     => $orderId,
            'task_type_id' => $taskTypeId,
            'status'       => $status,
            'skip_reason'  => $skipReason,
            'resolved_by'  => $userId,
        ]);
    }

    /** @return array{total:int, approved:int, outstanding:int} outstanding = not_started + pending_approval (skipped counts as resolved, same as approved) */
    public static function summaryForOrder(int $orderId): array
    {
        $rows = self::forOrder($orderId);
        $total = count($rows);
        $approved = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'approved' || $r['status'] === 'skipped') {
                $approved++;
            }
        }
        return ['total' => $total, 'approved' => $approved, 'outstanding' => $total - $approved];
    }
}
