<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderProductionRepository
{
    public static function upsert(int $orderId, int $supplierId, ?string $startDate, ?string $expectedCompletion): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_production (order_id, supplier_id, production_start_date, expected_completion_date)
             VALUES (:order_id, :supplier_id, :start_date, :expected_completion)
             ON DUPLICATE KEY UPDATE supplier_id = VALUES(supplier_id),
                production_start_date = VALUES(production_start_date),
                expected_completion_date = VALUES(expected_completion_date)'
        )->execute([
            'order_id'   => $orderId,
            'supplier_id' => $supplierId,
            'start_date' => $startDate,
            'expected_completion' => $expectedCompletion,
        ]);
    }

    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_production WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function markComplete(int $orderId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE order_production SET production_complete_confirmed_at = NOW(), production_complete_confirmed_by = :user_id WHERE order_id = :order_id'
        )->execute(['user_id' => $userId, 'order_id' => $orderId]);
    }
}
