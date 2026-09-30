<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/** docs/schema.sql Section AO — a dispute's own reply thread (not order_comments). */
final class DisputeReplyRepository
{
    public static function create(int $disputeId, int $authorUserId, string $body): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO dispute_replies (dispute_id, author_user_id, body) VALUES (:dispute_id, :author_user_id, :body)'
        );
        $stmt->execute(['dispute_id' => $disputeId, 'author_user_id' => $authorUserId, 'body' => $body]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * A dispute's replies, oldest first, with the author's display name.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function forDispute(int $disputeId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.name AS author_name
             FROM dispute_replies r
             JOIN users u ON u.id = r.author_user_id
             WHERE r.dispute_id = :dispute_id
             ORDER BY r.created_at ASC, r.id ASC'
        );
        $stmt->execute(['dispute_id' => $disputeId]);
        return $stmt->fetchAll();
    }
}
