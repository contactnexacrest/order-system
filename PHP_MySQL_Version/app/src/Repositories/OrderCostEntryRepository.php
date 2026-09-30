<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Order Profitability Sheet (docs/schema.sql Section AP) — manually
 * recorded cost lines not already captured elsewhere in the schema. See
 * OrderProfitabilityService for how these combine with the
 * automatically-pulled supplier/freight/insurance costs into a total.
 */
final class OrderCostEntryRepository
{
    public const CATEGORIES = [
        'ecgc_insurance'   => 'ECGC Insurance',
        'due_diligence'    => 'Buyer Due Diligence',
        'inland_transport' => 'Inland Transportation',
        'cha_charges'      => 'CHA Charges',
        'documentation'    => 'Documentation',
        'port_charges'     => 'Port Charges',
        'bank_charges'     => 'Bank Charges',
        'commission'       => 'Commission',
        'packing_crates'   => 'Packing / Wooden Crates',
        'other'            => 'Other',
    ];

    public static function create(int $orderId, string $category, ?string $description, float $amountInr, ?string $incurredAt, int $recordedBy): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by)
             VALUES (:order_id, :category, :description, :amount_inr, :incurred_at, :recorded_by)'
        )->execute([
            'order_id'    => $orderId,
            'category'    => $category,
            'description' => $description,
            'amount_inr'  => $amountInr,
            'incurred_at' => $incurredAt,
            'recorded_by' => $recordedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> newest first, with the recording user's name joined in */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT oce.*, u.name AS recorded_by_name
             FROM order_cost_entries oce
             JOIN users u ON u.id = oce.recorded_by
             WHERE oce.order_id = :order_id
             ORDER BY oce.incurred_at DESC, oce.id DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function totalForOrder(int $orderId): float
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(amount_inr), 0) FROM order_cost_entries WHERE order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);
        return (float) $stmt->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_cost_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM order_cost_entries WHERE id = :id')->execute(['id' => $id]);
    }
}
