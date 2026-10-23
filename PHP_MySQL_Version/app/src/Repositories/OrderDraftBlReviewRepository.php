<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section BS — export process steps 9h-9j: the CHA
 * draft BL review loop. Each row is one round (one draft the CHA sent);
 * round_number is per-order, starting at 1, so the UI can show "Round 2
 * — revision requested" etc. At most one round per order may be
 * 'pending' at a time — the next draft can't be logged until the
 * current one has been approved or sent back for revision.
 */
final class OrderDraftBlReviewRepository
{
    /** @return array<int, array<string,mixed>> newest round first */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.name AS uploaded_by_name, rv.name AS reviewed_by_name
             FROM order_draft_bl_reviews r
             JOIN users u ON u.id = r.uploaded_by
             LEFT JOIN users rv ON rv.id = r.reviewed_by
             WHERE r.order_id = :order_id
             ORDER BY r.round_number DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function latestForOrder(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_draft_bl_reviews WHERE order_id = :order_id ORDER BY round_number DESC LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_draft_bl_reviews WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Creates the next round for this order (round_number = previous max + 1). */
    public static function createRound(int $orderId, int $draftFileId, int $uploadedBy): int
    {
        $pdo = Database::connection();
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(round_number), 0) FROM order_draft_bl_reviews WHERE order_id = :order_id');
        $maxStmt->execute(['order_id' => $orderId]);
        $nextRound = 1 + (int) $maxStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO order_draft_bl_reviews (order_id, round_number, draft_file_id, uploaded_by)
             VALUES (:order_id, :round_number, :draft_file_id, :uploaded_by)'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'round_number' => $nextRound,
            'draft_file_id' => $draftFileId,
            'uploaded_by' => $uploadedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param 'approved'|'revision_requested' $status */
    public static function review(int $id, string $status, ?string $notes, int $reviewedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_draft_bl_reviews
             SET status = :status, review_notes = :notes, reviewed_by = :reviewed_by, reviewed_at = NOW()
             WHERE id = :id'
        )->execute(['status' => $status, 'notes' => $notes, 'reviewed_by' => $reviewedBy, 'id' => $id]);
    }
}
