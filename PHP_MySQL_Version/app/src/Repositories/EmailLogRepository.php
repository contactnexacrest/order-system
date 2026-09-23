<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Spec Section 10 — "EMAIL & DEFERRED SEND SYSTEM", 2-level approval.
 * Level 1 (any user with send permission) creates a row here in
 * 'pending_approval'. Level 2 (a privileged approver) flips it to
 * 'approved'/'rejected'. A cron script (app/cron/dispatch_deferred_emails.php)
 * is what actually sends 'approved' rows once scheduled_at has passed —
 * see that script's own docblock for why this isn't done synchronously.
 */
final class EmailLogRepository
{
    public static function create(
        ?int $orderId,
        ?int $documentId,
        ?string $templateKey,
        string $recipientEmail,
        string $subject,
        string $bodySnapshot,
        ?string $scheduledAt,
        int $requestedBy
    ): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO email_log
                (order_id, document_id, template_key, recipient_email, subject, body_snapshot, scheduled_at, requested_by, status)
             VALUES
                (:order_id, :document_id, :template_key, :recipient_email, :subject, :body_snapshot, :scheduled_at, :requested_by, \'pending_approval\')'
        );
        $stmt->execute([
            'order_id'        => $orderId,
            'document_id'     => $documentId,
            'template_key'    => $templateKey,
            'recipient_email' => $recipientEmail,
            'subject'         => $subject,
            'body_snapshot'   => $bodySnapshot,
            'scheduled_at'    => $scheduledAt,
            'requested_by'    => $requestedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_log WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM email_log WHERE order_id = :order_id ORDER BY created_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    /**
     * True if this document already has a send in flight — either awaiting
     * Level-2 approval or approved and waiting for its scheduled dispatch.
     * Used to block a second Level-1 request for the same document while
     * one is still active, rather than letting the buyer end up with two
     * competing sends.
     */
    public static function hasActiveSendFor(int $documentId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM email_log WHERE document_id = :document_id AND status IN ('pending_approval', 'approved') LIMIT 1"
        );
        $stmt->execute(['document_id' => $documentId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Level 2 approval queue. */
    public static function pendingApproval(): array
    {
        $stmt = Database::connection()->query(
            "SELECT el.*, o.order_reference
             FROM email_log el LEFT JOIN orders o ON o.id = el.order_id
             WHERE el.status = 'pending_approval'
             ORDER BY el.created_at"
        );
        return $stmt->fetchAll();
    }

    /**
     * The full deferred window — awaiting Level-2 approval, or already
     * approved but not yet dispatched (scheduled_at still in the future or
     * the cron job hasn't ticked yet). This is everything a "Cancel" action
     * can still stop before it becomes 'sent'.
     */
    public static function awaitingDispatch(): array
    {
        $stmt = Database::connection()->query(
            "SELECT el.*, o.order_reference
             FROM email_log el LEFT JOIN orders o ON o.id = el.order_id
             WHERE el.status IN ('pending_approval', 'approved')
             ORDER BY el.created_at"
        );
        return $stmt->fetchAll();
    }

    public static function approve(int $id, int $approvedBy): void
    {
        Database::connection()->prepare(
            "UPDATE email_log SET status = 'approved', approved_by = :approved_by, approved_at = NOW() WHERE id = :id"
        )->execute(['approved_by' => $approvedBy, 'id' => $id]);
    }

    public static function reject(int $id, int $approvedBy, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE email_log SET status = 'rejected', approved_by = :approved_by, approved_at = NOW(), rejection_reason = :reason WHERE id = :id"
        )->execute(['approved_by' => $approvedBy, 'reason' => $reason, 'id' => $id]);
    }

    /** Approved rows due to send (cron dispatcher only). */
    public static function dueForSend(): array
    {
        $stmt = Database::connection()->query(
            "SELECT * FROM email_log WHERE status = 'approved' AND (scheduled_at IS NULL OR scheduled_at <= NOW())"
        );
        return $stmt->fetchAll();
    }

    public static function markSent(int $id): void
    {
        Database::connection()->prepare(
            "UPDATE email_log SET status = 'sent', sent_at = NOW() WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public static function markFailed(int $id): void
    {
        Database::connection()->prepare(
            "UPDATE email_log SET status = 'failed' WHERE id = :id"
        )->execute(['id' => $id]);
    }

    /**
     * Pulls a send back before it goes out — from 'pending_approval'
     * (Level-1 requester changed their mind before anyone reviewed it) or
     * from 'approved' (still sitting in its deferred window, not yet
     * picked up by the cron dispatcher). Once a row is 'sent' this can no
     * longer apply to it — see EmailDispatchService::cancelSend()'s status
     * check.
     */
    public static function cancel(int $id, int $cancelledBy, string $reason): void
    {
        Database::connection()->prepare(
            "UPDATE email_log SET status = 'cancelled', cancelled_by = :cancelled_by, cancelled_at = NOW(), cancellation_reason = :reason WHERE id = :id"
        )->execute(['cancelled_by' => $cancelledBy, 'reason' => $reason, 'id' => $id]);
    }
}
