<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * "Only one primary supplier per product" is NOT enforced here or by a
 * DB constraint — clearPrimaryForProduct() exists so ProductService can
 * clear the others and set the new primary inside a single transaction
 * (see ProductService::setPrimarySupplier). This repository is otherwise
 * a plain CRUD layer with no business logic of its own.
 */
final class ProductSupplierRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function forProduct(int $productId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM catalog_product_suppliers WHERE product_id = :product_id ORDER BY is_primary DESC, supplier_name'
        );
        $stmt->execute(['product_id' => $productId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM catalog_product_suppliers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data, int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO catalog_product_suppliers
                (product_id, supplier_name, location, contact_person, contact_phone, contact_email,
                 fob_source, fob_value, factory_cost, transportation_cost, packing_cost, loading_cost, cha_cost,
                 is_primary, notes, created_by, updated_by)
             VALUES
                (:product_id, :supplier_name, :location, :contact_person, :contact_phone, :contact_email,
                 :fob_source, :fob_value, :factory_cost, :transportation_cost, :packing_cost, :loading_cost, :cha_cost,
                 :is_primary, :notes, :created_by, :updated_by)'
        );
        $stmt->execute(self::bindParams($data) + [
            'product_id' => $data['product_id'],
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE catalog_product_suppliers SET
                supplier_name = :supplier_name,
                location = :location,
                contact_person = :contact_person,
                contact_phone = :contact_phone,
                contact_email = :contact_email,
                fob_source = :fob_source,
                fob_value = :fob_value,
                factory_cost = :factory_cost,
                transportation_cost = :transportation_cost,
                packing_cost = :packing_cost,
                loading_cost = :loading_cost,
                cha_cost = :cha_cost,
                is_primary = :is_primary,
                notes = :notes,
                updated_by = :updated_by
             WHERE id = :id'
        );
        $stmt->execute(self::bindParams($data) + [
            'updated_by' => $userId,
            'id'         => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM catalog_product_suppliers WHERE id = :id')->execute(['id' => $id]);
    }

    /** Clears is_primary on every supplier row for this product — call before setting a new primary. */
    public static function clearPrimaryForProduct(int $productId): void
    {
        Database::connection()
            ->prepare('UPDATE catalog_product_suppliers SET is_primary = 0 WHERE product_id = :product_id')
            ->execute(['product_id' => $productId]);
    }

    public static function setPrimary(int $id): void
    {
        Database::connection()
            ->prepare('UPDATE catalog_product_suppliers SET is_primary = 1 WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** @return array<string,mixed> */
    private static function bindParams(array $data): array
    {
        $fobSource = $data['fob_source'] ?? 'direct';
        return [
            'supplier_name'       => $data['supplier_name'],
            'location'            => $data['location'] ?? null,
            'contact_person'      => $data['contact_person'] ?? null,
            'contact_phone'       => $data['contact_phone'] ?? null,
            'contact_email'       => $data['contact_email'] ?? null,
            'fob_source'          => $fobSource,
            // fob_value is only meaningful for fob_source='direct' — for
            // 'computed' it is forced NULL here so a stale computed value
            // can never sit in this column (see schema.sql Section U).
            'fob_value'           => $fobSource === 'direct' ? ($data['fob_value'] ?? null) : null,
            'factory_cost'        => $data['factory_cost'] ?? null,
            'transportation_cost' => $data['transportation_cost'] ?? null,
            'packing_cost'        => $data['packing_cost'] ?? null,
            'loading_cost'        => $data['loading_cost'] ?? null,
            'cha_cost'            => $data['cha_cost'] ?? null,
            'is_primary'          => !empty($data['is_primary']) ? 1 : 0,
            'notes'               => $data['notes'] ?? null,
        ];
    }
}
