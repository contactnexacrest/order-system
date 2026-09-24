<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section AE — the buyer's acknowledgment of the Order
 * Confirmation at the Stage 4->5 gate. One row per order; a resend of the
 * OC upserts a fresh sent_at/due_at and clears any prior acknowledgment,
 * since the buyer is being asked to confirm the version just sent.
 */
final class OrderOcAcknowledgmentRepository
{
    /** Called from EmailDispatchService::dispatch() the moment an OC document is actually emailed. */
    public static function recordSent(int $orderId, int $documentId, string $sentAt, string $dueAt): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO order_oc_acknowledgments (order_id, document_id, sent_at, due_at)
             VALUES (:order_id, :document_id, :sent_at, :due_at)
             ON DUPLICATE KEY UPDATE
                document_id = VALUES(document_id), sent_at = VALUES(sent_at), due_at = VALUES(due_at),
                acknowledged_at = NULL, acknowledged_via = NULL, acknowledged_note = NULL, recorded_by = NULL'
        );
        $stmt->execute(['order_id' => $orderId, 'document_id' => $documentId, 'sent_at' => $sentAt, 'due_at' => $dueAt]);
    }

    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_oc_acknowledgments WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function markAcknowledged(int $orderId, string $via, ?string $note, ?int $recordedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_oc_acknowledgments
             SET acknowledged_at = NOW(), acknowledged_via = :via, acknowledged_note = :note, recorded_by = :recorded_by
             WHERE order_id = :order_id'
        )->execute(['order_id' => $orderId, 'via' => $via, 'note' => $note, 'recorded_by' => $recordedBy]);
    }

    /** @return array<int, array<string,mixed>> unacknowledged rows past due — for the 48h auto-confirm cron */
    public static function dueForAutoConfirm(): array
    {
        return Database::connection()
            ->query("SELECT * FROM order_oc_acknowledgments WHERE acknowledged_at IS NULL AND due_at <= NOW()")
            ->fetchAll();
    }
}
