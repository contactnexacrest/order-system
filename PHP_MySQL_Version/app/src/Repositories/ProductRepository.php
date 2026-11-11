<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * The Product Interface / internal product catalog (see docs/schema.sql
 * Section U). Deliberately standalone — no foreign keys to orders/
 * order_products anywhere in this repository or the tables it reads.
 */
final class ProductRepository
{
    /** @return array<int, array<string,mixed>> */
    public static function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM catalog_products';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name';
        return Database::connection()->query($sql)->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function search(string $term): array
    {
        // Three distinct placeholders, not :term reused three times —
        // PDO::ATTR_EMULATE_PREPARES is off (native prepares), which
        // doesn't allow binding one named parameter to multiple positions
        // in the same query (throws "Invalid parameter number").
        $stmt = Database::connection()->prepare(
            'SELECT * FROM catalog_products
             WHERE is_active = 1
               AND (name LIKE :term1 OR hs_code LIKE :term2 OR specifications LIKE :term3)
             ORDER BY name'
        );
        $needle = '%' . $term . '%';
        $stmt->execute(['term1' => $needle, 'term2' => $needle, 'term3' => $needle]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array{id:int,name:string,hs_code:string,specifications:string,reference_unit:?string}> active products for the "Pick from Products" list */
    public static function forPicker(): array
    {
        return Database::connection()->query(
            'SELECT id, name, hs_code, specifications, reference_unit FROM catalog_products WHERE is_active = 1 ORDER BY name'
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM catalog_products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * D-300 (point 12): the product's CHA + transport default (₹ per container) and its own
     * price rules — × K, container CBM and maximum tonnes (null = the category's).
     *
     * @param array<string,?string> $f
     */
    public static function setPriceRuleFields(int $id, array $f): void
    {
        $cols = array_values(array_intersect(array_keys($f), ['cha_transport_per_container', 'thick_k_factor', 'container_cbm', 'container_max_t']));
        if ($cols === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", $cols));
        $params = ['id' => $id];
        foreach ($cols as $c) {
            $params[$c] = $f[$c];
        }
        Database::connection()->prepare("UPDATE catalog_products SET {$sets} WHERE id = :id")->execute($params);
    }

    public static function create(array $data, int $userId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO catalog_products
                (name, specifications, hs_code, origin,
                 default_factory_cost, default_transportation_cost, default_packing_cost,
                 default_loading_cost, default_cha_cost, reference_unit,
                 is_active, created_by, updated_by)
             VALUES
                (:name, :specifications, :hs_code, :origin,
                 :default_factory_cost, :default_transportation_cost, :default_packing_cost,
                 :default_loading_cost, :default_cha_cost, :reference_unit,
                 :is_active, :created_by, :updated_by)'
        );
        $stmt->execute([
            'name'                        => $data['name'],
            'specifications'              => $data['specifications'],
            'hs_code'                     => $data['hs_code'],
            'origin'                      => $data['origin'] ?? null,
            'default_factory_cost'        => $data['default_factory_cost'] ?? null,
            'default_transportation_cost' => $data['default_transportation_cost'] ?? null,
            'default_packing_cost'        => $data['default_packing_cost'] ?? null,
            'default_loading_cost'        => $data['default_loading_cost'] ?? null,
            'default_cha_cost'            => $data['default_cha_cost'] ?? null,
            'reference_unit'              => $data['reference_unit'] ?? null,
            'is_active'                   => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_by'                  => $userId,
            'updated_by'                  => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $data, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE catalog_products SET
                name = :name,
                specifications = :specifications,
                hs_code = :hs_code,
                origin = :origin,
                default_factory_cost = :default_factory_cost,
                default_transportation_cost = :default_transportation_cost,
                default_packing_cost = :default_packing_cost,
                default_loading_cost = :default_loading_cost,
                default_cha_cost = :default_cha_cost,
                reference_unit = :reference_unit,
                is_active = :is_active,
                updated_by = :updated_by
             WHERE id = :id'
        )->execute([
            'name'                        => $data['name'],
            'specifications'              => $data['specifications'],
            'hs_code'                     => $data['hs_code'],
            'origin'                      => $data['origin'] ?? null,
            'default_factory_cost'        => $data['default_factory_cost'] ?? null,
            'default_transportation_cost' => $data['default_transportation_cost'] ?? null,
            'default_packing_cost'        => $data['default_packing_cost'] ?? null,
            'default_loading_cost'        => $data['default_loading_cost'] ?? null,
            'default_cha_cost'            => $data['default_cha_cost'] ?? null,
            'reference_unit'              => $data['reference_unit'] ?? null,
            'is_active'                   => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'updated_by'                  => $userId,
            'id'                          => $id,
        ]);
    }

    /**
     * Hard delete. ON DELETE CASCADE on catalog_product_images/
     * catalog_product_suppliers/catalog_product_misc_charges removes their
     * rows for this product too — there is nothing else in the schema that
     * references catalog_products.id (it is deliberately never linked into
     * the order pipeline).
     */
    public static function delete(int $id): void
    {
        // Owner decision 2 Nov 2026 (D-238): a product already in Zoho Books is marked inactive there at the next sync.
        $product = self::find($id);
        if ($product !== null) {
            \App\Services\ZohoMasterDataSync::rememberDeletedProduct($product);
        }
        Database::connection()->prepare('DELETE FROM catalog_products WHERE id = :id')->execute(['id' => $id]);
    }
}
