<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Section BC — the small, order-scoped CRUD record backing the Bill of
 * Lading Endorsement print feature (see docs/schema.sql Section BC for
 * why these specific fields exist here rather than being read from
 * order_shipping/orders directly). One row per order, upserted on save.
 */
final class OrderBlEndorsementRepository
{
    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_bl_endorsements WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function upsert(int $orderId, array $data, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_bl_endorsements
                (order_id, bl_number, vessel_voyage, port_of_loading, port_of_discharge, date_of_endorsement, created_by, updated_by)
             VALUES (:order_id, :bl_number, :vessel_voyage, :port_of_loading, :port_of_discharge, :date_of_endorsement, :created_by, :updated_by)
             ON DUPLICATE KEY UPDATE
                bl_number = VALUES(bl_number), vessel_voyage = VALUES(vessel_voyage),
                port_of_loading = VALUES(port_of_loading), port_of_discharge = VALUES(port_of_discharge),
                date_of_endorsement = VALUES(date_of_endorsement), updated_by = VALUES(updated_by)'
        )->execute([
            'order_id'            => $orderId,
            'bl_number'           => $data['bl_number'] ?? null,
            'vessel_voyage'       => $data['vessel_voyage'] ?? null,
            'port_of_loading'     => $data['port_of_loading'] ?? null,
            'port_of_discharge'   => $data['port_of_discharge'] ?? null,
            'date_of_endorsement' => ($data['date_of_endorsement'] ?? null) ?: null,
            'created_by'          => $userId,
            'updated_by'          => $userId,
        ]);
    }
}
