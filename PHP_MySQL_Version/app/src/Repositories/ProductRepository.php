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
    /**
     * The list filter values (owner, 12 Nov 2026): active is the default. D-361 (13 Nov 2026):
     * also by where the product stands — not configured, no active supplier, no supplier yet
     * (these three across active and inactive products).
     */
    public const STATUSES = ['active', 'inactive', 'all', 'not_configured', 'no_active_supplier', 'no_supplier_yet'];

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
     * D-362 (13 Nov 2026, owner request): the tab to open. A status tab (or filter chip) with no
     * products opens the Active tab instead — or All when Active is empty too. A tab that has
     * products (and All, the last resort) is kept.
     *
     * @param array<string,int> $counts
     */
    public static function openTab(string $status, array $counts): string
    {
        if ((int) ($counts[$status] ?? 0) > 0 || $status === 'all') {
            return $status;
        }
        return (int) ($counts['active'] ?? 0) > 0 ? 'active' : 'all';
    }

    /** D-361: the SQL condition (alias p) for one status filter. */
    private static function statusSql(string $status): ?string
    {
        $o = \App\Services\SupplierOffers::class;
        $usable = 'EXISTS (SELECT 1 FROM catalog_product_suppliers cps WHERE cps.product_id = p.id AND ' . $o::USABLE_SQL . ')';
        return match ($status) {
            'active' => 'p.is_active = 1',
            'inactive' => 'p.is_active = 0',
            'not_configured' => 'NOT ' . $o::CONFIGURED_SQL . ' AND NOT ' . $o::NO_SUPPLIER_YET_SQL,
            'no_active_supplier' => $o::CONFIGURED_SQL . ' AND NOT ' . $o::NO_SUPPLIER_YET_SQL . ' AND NOT ' . $usable,
            'no_supplier_yet' => $o::NO_SUPPLIER_YET_SQL,
            default => null,
        };
    }

    /**
     * The Products list: active, inactive or all products (D-361: or not configured / no active
     * supplier / no supplier yet), optionally narrowed by a search term (name, HS code,
     * specification or — D-361 — a supplier's name).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function listing(string $status = 'active', ?string $term = null): array
    {
        [$where, $params] = self::listingWhere($term);
        if (($cond = self::statusSql($status)) !== null) {
            $where[] = $cond;
        }
        $stmt = Database::connection()->prepare('SELECT p.* FROM catalog_products p' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY p.name');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * The numbers on the filter chips — the same rows listing() returns.
     *
     * @return array<string,int>
     */
    public static function statusCounts(?string $term = null): array
    {
        [$where, $params] = self::listingWhere($term);
        $sums = [];
        foreach (self::STATUSES as $st) {
            $cond = self::statusSql($st);
            $sums[] = $cond === null ? 'COUNT(*) AS `all`' : "COALESCE(SUM({$cond}), 0) AS `{$st}`";
        }
        $stmt = Database::connection()->prepare('SELECT ' . implode(', ', $sums) . ' FROM catalog_products p' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)));
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
        $out = [];
        foreach (self::STATUSES as $st) {
            $out[$st] = (int) ($r[$st] ?? 0);
        }
        return $out;
    }

    /** @return array{0: list<string>, 1: array<string,string>} */
    private static function listingWhere(?string $term): array
    {
        $term = trim((string) $term);
        if ($term === '') {
            return [[], []];
        }
        // Distinct placeholders, not :term reused — PDO::ATTR_EMULATE_PREPARES is off (native
        // prepares), which doesn't allow binding one named parameter to multiple positions.
        // D-361: a supplier's name (typed, or the directory supplier's legal name) matches too.
        $needle = '%' . $term . '%';
        return [['(p.name LIKE :term1 OR p.hs_code LIKE :term2 OR p.specifications LIKE :term3 OR EXISTS (SELECT 1 FROM catalog_product_suppliers cs
            LEFT JOIN suppliers sx ON sx.id = cs.supplier_id WHERE cs.product_id = p.id AND (cs.supplier_name LIKE :term4 OR sx.supplier_legal_name LIKE :term5)))'],
            ['term1' => $needle, 'term2' => $needle, 'term3' => $needle, 'term4' => $needle, 'term5' => $needle]];
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

    /**
     * Active products for the "Pick from Products" list. D-361 (plan rule 4): only QUOTABLE ones —
     * configured with at least one active supplier offer, or a product from before migration 13b
     * that has no supplier yet.
     *
     * @return array<int, array{id:int,name:string,hs_code:string,specifications:string,reference_unit:?string}>
     */
    public static function forPicker(): array
    {
        return Database::connection()->query(
            'SELECT p.id, p.name, p.hs_code, p.specifications, p.reference_unit FROM catalog_products p WHERE p.is_active = 1 AND '
            . \App\Services\SupplierOffers::quotableSql() . ' ORDER BY p.name'
        )->fetchAll();
    }

    /**
     * D-361: products staff may look for but cannot pick yet — not configured, or no active
     * supplier — with the reason shown in the picker. A product deliberately deactivated (and
     * otherwise ready) is left out, as before.
     *
     * @return list<array{id:int,name:string,reason:string}>
     */
    public static function notQuotable(): array
    {
        $o = \App\Services\SupplierOffers::class;
        $rows = Database::connection()->query('SELECT p.id, p.name, ' . $o::CONFIGURED_SQL . ' AS configured FROM catalog_products p WHERE NOT '
            . $o::quotableSql() . ' ORDER BY p.name')->fetchAll();
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'],
            'reason' => (int) $r['configured'] === 1 ? 'no active supplier — ask Procurement' : 'not configured yet — ask the product manager'], $rows);
    }

    /** D-361: whether the product may be quoted now (active and quotable). */
    public static function isQuotable(int $id): bool
    {
        $st = Database::connection()->prepare('SELECT COUNT(*) FROM catalog_products p WHERE p.id = :id AND p.is_active = 1 AND ' . \App\Services\SupplierOffers::quotableSql());
        $st->execute(['id' => $id]);
        return (int) $st->fetchColumn() > 0;
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
