<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * User-reported batch item 12 — docs/schema.sql Section BL. A supplier's
 * own registered address (suppliers.address) is a single field; this is
 * a separate one-to-many list of factory/delivery addresses the same
 * supplier may have, offered as the Supplier PO Terms form's Delivery
 * Location dropdown once a supplier is selected.
 */
final class SupplierFactoryAddressRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function forSupplier(int $supplierId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM supplier_factory_addresses WHERE supplier_id = :supplier_id ORDER BY id'
        );
        $stmt->execute(['supplier_id' => $supplierId]);
        return $stmt->fetchAll();
    }

    /**
     * Every factory address for every supplier, grouped by supplier_id —
     * used to embed the full set once on the order page so the Delivery
     * Location dropdown can repopulate client-side as staff change the
     * selected supplier, without a round trip per selection.
     *
     * @return array<int, array<int, array<string,mixed>>>
     */
    public static function allGroupedBySupplier(): array
    {
        $rows = Database::connection()->query('SELECT * FROM supplier_factory_addresses ORDER BY id')->fetchAll();
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['supplier_id']][] = $row;
        }
        return $grouped;
    }

    public static function create(int $supplierId, string $address, ?string $label): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO supplier_factory_addresses (supplier_id, label, address) VALUES (:supplier_id, :label, :address)'
        );
        $stmt->execute(['supplier_id' => $supplierId, 'label' => $label, 'address' => $address]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM supplier_factory_addresses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM supplier_factory_addresses WHERE id = :id')->execute(['id' => $id]);
    }
}
