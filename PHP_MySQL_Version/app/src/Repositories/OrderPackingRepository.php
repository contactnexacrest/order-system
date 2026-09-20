<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderPackingRepository
{
    public static function upsert(int $orderId, array $data): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_packing
                (order_id, actual_quantity_packed, crate_count, total_net_weight_kg, total_gross_weight_kg,
                 total_cbm, packing_date, shortfall_pct)
             VALUES (:order_id, :qty, :crates, :net, :gross, :cbm, :packing_date, :shortfall_pct)
             ON DUPLICATE KEY UPDATE
                actual_quantity_packed = VALUES(actual_quantity_packed),
                crate_count = VALUES(crate_count),
                total_net_weight_kg = VALUES(total_net_weight_kg),
                total_gross_weight_kg = VALUES(total_gross_weight_kg),
                total_cbm = VALUES(total_cbm),
                packing_date = VALUES(packing_date),
                shortfall_pct = VALUES(shortfall_pct)'
        )->execute([
            'order_id'      => $orderId,
            'qty'           => $data['actual_quantity_packed'] ?: null,
            'crates'        => $data['crate_count'] ?: null,
            'net'           => $data['total_net_weight_kg'] ?: null,
            'gross'         => $data['total_gross_weight_kg'] ?: null,
            'cbm'           => $data['total_cbm'] ?: null,
            'packing_date'  => $data['packing_date'] ?: null,
            'shortfall_pct' => $data['shortfall_pct'] ?: null,
        ]);
    }

    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_packing WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function markComplete(int $orderId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE order_packing SET packing_complete_confirmed_at = NOW(), packing_complete_confirmed_by = :user_id WHERE order_id = :order_id'
        )->execute(['user_id' => $userId, 'order_id' => $orderId]);
    }
}
