<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/** docs/schema.sql Section BV — export process step 14: client rating + comments, one row per order. */
final class OrderFeedbackRepository
{
    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_feedback WHERE order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function upsert(int $orderId, int $clientId, int $rating, ?string $comments): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_feedback (order_id, client_id, rating, comments)
             VALUES (:order_id, :client_id, :rating, :comments)
             ON DUPLICATE KEY UPDATE rating = :rating2, comments = :comments2, client_id = :client_id2'
        )->execute([
            'order_id'    => $orderId,
            'client_id'   => $clientId,
            'rating'      => $rating,
            'comments'    => $comments,
            'rating2'     => $rating,
            'comments2'   => $comments,
            'client_id2'  => $clientId,
        ]);
    }
}
