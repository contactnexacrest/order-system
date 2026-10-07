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

    /**
     * docs/schema.sql Section AU: the staff "buyer acknowledged by email
     * reply" override used to only work once recordSent() had already run
     * (i.e. only after an OC email had actually, successfully gone out) —
     * if the send was itself stuck (SMTP misconfigured, still awaiting
     * Level-2 approval, etc.) there was no row here to act on at all, and
     * staff had no way to move the order past Stage 4. This idempotently
     * guarantees a row exists (document_id/sent_at/due_at are NOT NULL and
     * FK-constrained, so a bare order_id-only row isn't possible) WITHOUT
     * touching any existing row's state — a concurrent genuine send
     * recording itself via recordSent() and this call race safely, since
     * neither overwrites the other's already-landed values.
     */
    public static function ensureRowExists(int $orderId, int $documentId): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO order_oc_acknowledgments (order_id, document_id, sent_at, due_at)
             VALUES (:order_id, :document_id, NOW(), NOW())
             ON DUPLICATE KEY UPDATE order_id = order_id'
        );
        $stmt->execute(['order_id' => $orderId, 'document_id' => $documentId]);
    }

    /**
     * QA-5 EML-06 (same overlapping-cron-run defect class, folded in while
     * fixing the email dispatcher): the three callers of this method
     * (staff-recorded, client-portal, and the 48h auto-confirm cron) each
     * used to pre-check `acknowledged_at IS NULL` and then write
     * unconditionally — a classic check-then-act race if two of those
     * paths land at the same moment (e.g. the buyer clicks "acknowledge"
     * in the portal in the same instant the 48h cron fires). Restricting
     * the UPDATE itself to `acknowledged_at IS NULL` makes the write the
     * atomic claim, so only the first caller's write actually lands.
     *
     * @return bool true if THIS call recorded the acknowledgment; false if
     *   another path already had (the caller must skip its own follow-up
     *   side effects — stage-gate pass, audit log, notification — in that case).
     */
    public static function markAcknowledged(int $orderId, string $via, ?string $note, ?int $recordedBy): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE order_oc_acknowledgments
             SET acknowledged_at = NOW(), acknowledged_via = :via, acknowledged_note = :note, recorded_by = :recorded_by
             WHERE order_id = :order_id AND acknowledged_at IS NULL'
        );
        $stmt->execute(['order_id' => $orderId, 'via' => $via, 'note' => $note, 'recorded_by' => $recordedBy]);
        return $stmt->rowCount() > 0;
    }

    /** @return array<int, array<string,mixed>> unacknowledged rows past due — for the 48h auto-confirm cron */
    public static function dueForAutoConfirm(): array
    {
        return Database::connection()
            ->query("SELECT * FROM order_oc_acknowledgments WHERE acknowledged_at IS NULL AND due_at <= NOW()")
            ->fetchAll();
    }
}
