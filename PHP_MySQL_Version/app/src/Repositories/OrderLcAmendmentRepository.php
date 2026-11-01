<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/** Gap list G-4 (owner, 1 Nov 2026): every amendment to an order's Letter of Credit, oldest first. */
final class OrderLcAmendmentRepository
{
    public static function add(int $orderId, string $amendedOn, string $reference, string $changeText, ?int $fileId, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO order_lc_amendments (order_id, amended_on, reference, change_text, file_id, recorded_by) VALUES (:o, :d, :r, :c, :f, :u)')
            ->execute(['o' => $orderId, 'd' => $amendedOn, 'r' => $reference, 'c' => $changeText, 'f' => $fileId, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*, u.name AS recorded_by_name FROM order_lc_amendments a LEFT JOIN users u ON u.id = a.recorded_by WHERE a.order_id = :o ORDER BY a.amended_on, a.id'
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll();
    }
}
