<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * The product catalogue (see docs/schema.sql Section U). Products are picked
 * on order lines (order_products.catalog_product_id) and priced through
 * associate price requests (price_requests.product_id); usage() counts those
 * links so a used product is never deleted, only deactivated.
 */
final class ProductRepository
{
    /** The list filter values (owner, 12 Nov 2026): active is the default. */
    public const STATUSES = ['active', 'inactive', 'all'];

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

    /** A status filter value from the query string — anything unknown is "active". */
    public static function statusFilter(mixed $value): string
    {
        return in_array($value, self::STATUSES, true) ? (string) $value : 'active';
    }

    /**
     * The Products list: active, inactive or all products, optionally narrowed by a search term
     * (name, HS code or specification).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function listing(string $status = 'active', ?string $term = null): array
    {
        [$where, $params] = self::listingWhere($term);
        if ($status === 'active') {
            $where[] = 'is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'is_active = 0';
        }
        $stmt = Database::connection()->prepare('SELECT * FROM catalog_products' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY name');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * The numbers on the "Active (n) · Inactive (n) · All (n)" chips — the same rows listing() returns.
     *
     * @return array{active:int, inactive:int, all:int}
     */
    public static function statusCounts(?string $term = null): array
    {
        [$where, $params] = self::listingWhere($term);
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS inactive, COUNT(*) AS total
             FROM catalog_products' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        );
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
        return ['active' => (int) ($r['active'] ?? 0), 'inactive' => (int) ($r['inactive'] ?? 0), 'all' => (int) ($r['total'] ?? 0)];
    }

    /** @return array{0: list<string>, 1: array<string,string>} */
    private static function listingWhere(?string $term): array
    {
        $term = trim((string) $term);
        if ($term === '') {
            return [[], []];
        }
        // Three distinct placeholders, not :term reused three times —
        // PDO::ATTR_EMULATE_PREPARES is off (native prepares), which
        // doesn't allow binding one named parameter to multiple positions
        // in the same query (throws "Invalid parameter number").
        $needle = '%' . $term . '%';
        return [['(name LIKE :term1 OR hs_code LIKE :term2 OR specifications LIKE :term3)'], ['term1' => $needle, 'term2' => $needle, 'term3' => $needle]];
    }

    /** @return array<int, array<string,mixed>> products matching the term (active ones unless a status is given) */
    public static function search(string $term, string $status = 'active'): array
    {
        return self::listing($status, $term);
    }

    /**
     * Where a product is used (owner, 12 Nov 2026): the orders whose lines were picked from it
     * (removed lines included — the order history keeps them), the associate price requests for
     * it, and the procurement lines (purchase requirements) bought for those order lines.
     * Its own supplier prices, images, misc charges and specifications are not "use" — they
     * belong to the product and are deleted with it.
     *
     * @return array{orders:int, order_lines:int, price_requests:int, procurement:int, total:int}
     */
    public static function usage(int $id): array
    {
        $pdo = Database::connection();
        $one = static function (string $sql) use ($pdo, $id): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            return (int) $stmt->fetchColumn();
        };
        $u = [
            'orders'         => $one('SELECT COUNT(DISTINCT order_id) FROM order_products WHERE catalog_product_id = :id'),
            'order_lines'    => $one('SELECT COUNT(*) FROM order_products WHERE catalog_product_id = :id'),
            'price_requests' => $one('SELECT COUNT(*) FROM price_requests WHERE product_id = :id'),
            'procurement'    => $one('SELECT COUNT(*) FROM purchase_requirements pr JOIN order_products op ON op.id = pr.order_product_id WHERE op.catalog_product_id = :id'),
        ];
        $u['total'] = $u['orders'] + $u['price_requests'] + $u['procurement'];
        return $u;
    }

    /** "3 orders · 1 price request" — or "not used anywhere". */
    public static function usageText(array $usage): string
    {
        $parts = [];
        foreach (['orders' => ['order', 'orders'], 'price_requests' => ['price request', 'price requests'], 'procurement' => ['procurement line', 'procurement lines']] as $k => [$one, $many]) {
            $n = (int) ($usage[$k] ?? 0);
            if ($n > 0) {
                $parts[] = $n . ' ' . ($n === 1 ? $one : $many);
            }
        }
        return $parts === [] ? 'not used anywhere' : implode(' · ', $parts);
    }

    /** Why this product may not be deleted, or null when it may (inactive and not used anywhere). */
    public static function deleteRefusal(array $product, array $usage): ?string
    {
        if (!empty($product['is_active'])) {
            return 'This product is active. Deactivate it first — only an inactive product that is not used anywhere can be deleted.';
        }
        if ((int) ($usage['total'] ?? 0) > 0) {
            $what = (int) ($usage['orders'] ?? 0) > 0 ? 'those orders' : ((int) ($usage['price_requests'] ?? 0) > 0 ? 'those price requests' : 'those procurement lines');
            return 'Used on ' . self::usageText($usage) . ' — kept so ' . $what . ' stay complete.';
        }
        return null;
    }

    /** Deactivate or reactivate a product. */
    public static function setActive(int $id, bool $active, int $userId): void
    {
        Database::connection()->prepare('UPDATE catalog_products SET is_active = :a, updated_by = :u WHERE id = :id')
            ->execute(['a' => $active ? 1 : 0, 'u' => $userId, 'id' => $id]);
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
     * Hard delete. ON DELETE CASCADE removes the product's own rows (images,
     * supplier prices and their price history, misc charges, specifications).
     * Callers must check deleteRefusal() first: an active or used product is
     * never deleted (owner, 12 Nov 2026).
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
