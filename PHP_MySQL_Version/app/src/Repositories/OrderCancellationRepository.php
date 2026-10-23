<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * External QA report M-09: "Formal order cancellation workflow (reason,
 * approval, money/refund handling, audit) distinct from 'mark lost'."
 * Request/approve-or-reject, closest in shape to AmendmentRepository's
 * own request/MD-approve/reject pattern — see docs/migrations/
 * 2026-10-23c-order-cancellation-workflow.sql for the schema.
 */
final class OrderCancellationRepository
{
    public static function create(int $orderId, string $reason, int $requestedBy): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_cancellations (order_id, reason, requested_by) VALUES (:order_id, :reason, :requested_by)'
        );
        $stmt->execute(['order_id' => $orderId, 'reason' => $reason, 'requested_by' => $requestedBy]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_cancellations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findPendingForOrder(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM order_cancellations WHERE order_id = :order_id AND status = 'pending' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> most recent first, requester/decider names joined in for display */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT oc.*, ru.name AS requested_by_name, du.name AS decided_by_name
             FROM order_cancellations oc
             LEFT JOIN users ru ON ru.id = oc.requested_by
             LEFT JOIN users du ON du.id = oc.decided_by
             WHERE oc.order_id = :order_id
             ORDER BY oc.id DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function approve(
        int $id,
        int $decidedBy,
        ?string $decisionNotes,
        ?float $refundAmount,
        string $refundStatus
    ): void {
        Database::connection()->prepare(
            "UPDATE order_cancellations
             SET status = 'approved', decided_by = :decided_by, decided_at = NOW(), decision_notes = :decision_notes,
                 refund_amount = :refund_amount, refund_status = :refund_status
             WHERE id = :id"
        )->execute([
            'decided_by'     => $decidedBy,
            'decision_notes' => $decisionNotes,
            'refund_amount'  => $refundAmount,
            'refund_status'  => $refundStatus,
            'id'             => $id,
        ]);
    }

    public static function reject(int $id, int $decidedBy, string $decisionNotes): void
    {
        Database::connection()->prepare(
            "UPDATE order_cancellations SET status = 'rejected', decided_by = :decided_by, decided_at = NOW(), decision_notes = :decision_notes WHERE id = :id"
        )->execute(['decided_by' => $decidedBy, 'decision_notes' => $decisionNotes, 'id' => $id]);
    }

    /** Recording that the refund money has actually moved — a separate, lower-bar step from the approval decision itself, since the bank transfer happens outside this system on its own timeline. */
    public static function completeRefund(int $id, int $recordedBy, ?string $refundNotes): void
    {
        Database::connection()->prepare(
            "UPDATE order_cancellations SET refund_status = 'completed', refund_notes = :refund_notes, refund_recorded_by = :recorded_by, refund_recorded_at = NOW() WHERE id = :id"
        )->execute(['refund_notes' => $refundNotes, 'recorded_by' => $recordedBy, 'id' => $id]);
    }
}
