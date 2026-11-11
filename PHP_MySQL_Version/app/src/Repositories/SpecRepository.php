<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-255 (5 Nov 2026): the specifications that change the price —
 * per category, and a product's own changes (hidden specifications and options,
 * changed values, options and specifications for that product only).
 * Node twin: src/repositories/specRepository.js.
 */
final class SpecRepository
{
    /** The system category (Natural Stone): a product with no category uses it. */
    public static function systemCategoryId(): ?int
    {
        $id = Database::connection()->query('SELECT id FROM product_categories WHERE is_system = 1 ORDER BY id LIMIT 1')->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @return list<array<string,mixed>> a category's specifications (not the product-only ones), in order */
    public static function categorySpecs(int $categoryId, bool $activeOnly = false): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM spec_definitions WHERE category_id = :c AND product_id IS NULL' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id'
        );
        $stmt->execute(['c' => $categoryId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public static function productOnlySpecs(int $productId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM spec_definitions WHERE product_id = :p ORDER BY sort_order, id');
        $stmt->execute(['p' => $productId]);
        return $stmt->fetchAll();
    }

    public static function findSpec(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM spec_definitions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function findOption(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM spec_options WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Options of the given specifications: the category's options plus, for $productId,
     * that product's own options.
     *
     * @param list<int> $specIds
     * @return array<int, list<array<string,mixed>>> by spec id
     */
    public static function optionsFor(array $specIds, ?int $productId = null): array
    {
        $out = [];
        if ($specIds === []) {
            return $out;
        }
        $in = implode(',', array_map('intval', $specIds));
        $stmt = Database::connection()->prepare(
            "SELECT * FROM spec_options WHERE spec_id IN ({$in}) AND (product_id IS NULL" . ($productId !== null ? ' OR product_id = :p' : '') . ')
             ORDER BY sort_order, id'
        );
        $stmt->execute($productId !== null ? ['p' => $productId] : []);
        foreach ($stmt->fetchAll() as $o) {
            $out[(int) $o['spec_id']][] = $o;
        }
        return $out;
    }

    /** @return array<int,true> spec ids the product hides */
    public static function hiddenSpecs(int $productId): array
    {
        $stmt = Database::connection()->prepare('SELECT spec_id FROM product_spec_hidden WHERE product_id = :p');
        $stmt->execute(['p' => $productId]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $out[(int) $id] = true;
        }
        return $out;
    }

    /** @return array<int, array{is_hidden:int, value:?string}> by option id */
    public static function optionOverrides(int $productId): array
    {
        $stmt = Database::connection()->prepare('SELECT option_id, is_hidden, value FROM product_option_overrides WHERE product_id = :p');
        $stmt->execute(['p' => $productId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['option_id']] = ['is_hidden' => (int) $r['is_hidden'], 'value' => $r['value']];
        }
        return $out;
    }

    public static function createSpec(array $d, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO spec_definitions (category_id, product_id, name, kind, applies_to, sort_order, is_active, updated_by)
             VALUES (:c, :p, :n, :k, :a, :o, 1, :u)'
        )->execute(['c' => $d['category_id'] ?? null, 'p' => $d['product_id'] ?? null, 'n' => $d['name'], 'k' => $d['kind'],
            'a' => $d['applies_to'], 'o' => $d['sort_order'], 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateSpec(int $id, array $d, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE spec_definitions SET name = :n, kind = :k, applies_to = :a, sort_order = :o, updated_by = :u WHERE id = :id'
        )->execute(['n' => $d['name'], 'k' => $d['kind'], 'a' => $d['applies_to'], 'o' => $d['sort_order'], 'u' => $userId, 'id' => $id]);
    }

    public static function setSpecActive(int $id, bool $active, int $userId): void
    {
        Database::connection()->prepare('UPDATE spec_definitions SET is_active = :a, updated_by = :u WHERE id = :id')
            ->execute(['a' => $active ? 1 : 0, 'u' => $userId, 'id' => $id]);
    }

    public static function deleteSpec(int $id): void
    {
        Database::connection()->prepare('DELETE FROM spec_definitions WHERE id = :id')->execute(['id' => $id]);
    }

    public static function createOption(array $d, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO spec_options (spec_id, product_id, name, is_base, change_type, value, thickness_mm, sort_order, is_active, updated_by)
             VALUES (:s, :p, :n, :b, :t, :v, :mm, :o, 1, :u)'
        )->execute(['s' => $d['spec_id'], 'p' => $d['product_id'] ?? null, 'n' => $d['name'], 'b' => $d['is_base'], 't' => $d['change_type'],
            'v' => $d['value'], 'mm' => $d['thickness_mm'], 'o' => $d['sort_order'], 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateOption(int $id, array $d, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE spec_options SET name = :n, is_base = :b, change_type = :t, value = :v, thickness_mm = :mm, updated_by = :u WHERE id = :id'
        )->execute(['n' => $d['name'], 'b' => $d['is_base'], 't' => $d['change_type'], 'v' => $d['value'], 'mm' => $d['thickness_mm'], 'u' => $userId, 'id' => $id]);
    }

    /** Only one base option per specification (per product, for product-only options). */
    public static function clearBase(int $specId, ?int $productId, int $exceptId): void
    {
        Database::connection()->prepare(
            'UPDATE spec_options SET is_base = 0 WHERE spec_id = :s AND id <> :id AND ' . ($productId === null ? 'product_id IS NULL' : 'product_id = :p')
        )->execute(array_merge(['s' => $specId, 'id' => $exceptId], $productId === null ? [] : ['p' => $productId]));
    }

    public static function setOptionActive(int $id, bool $active, int $userId): void
    {
        Database::connection()->prepare('UPDATE spec_options SET is_active = :a, updated_by = :u WHERE id = :id')
            ->execute(['a' => $active ? 1 : 0, 'u' => $userId, 'id' => $id]);
    }

    public static function deleteOption(int $id): void
    {
        Database::connection()->prepare('DELETE FROM spec_options WHERE id = :id')->execute(['id' => $id]);
    }

    public static function setSpecHidden(int $productId, int $specId, bool $hidden): void
    {
        $pdo = Database::connection();
        if ($hidden) {
            $pdo->prepare('INSERT IGNORE INTO product_spec_hidden (product_id, spec_id) VALUES (:p, :s)')->execute(['p' => $productId, 's' => $specId]);
        } else {
            $pdo->prepare('DELETE FROM product_spec_hidden WHERE product_id = :p AND spec_id = :s')->execute(['p' => $productId, 's' => $specId]);
        }
    }

    /** Sets or clears a product's change to a category option (hidden, and/or its own value). */
    public static function setOptionOverride(int $productId, int $optionId, bool $hidden, ?string $value): void
    {
        $pdo = Database::connection();
        if (!$hidden && $value === null) {
            $pdo->prepare('DELETE FROM product_option_overrides WHERE product_id = :p AND option_id = :o')->execute(['p' => $productId, 'o' => $optionId]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO product_option_overrides (product_id, option_id, is_hidden, value) VALUES (:p, :o, :h, :v)
             ON DUPLICATE KEY UPDATE is_hidden = VALUES(is_hidden), value = VALUES(value)'
        )->execute(['p' => $productId, 'o' => $optionId, 'h' => $hidden ? 1 : 0, 'v' => $value]);
    }

    /** Clears a product's hidden flag and option overrides for one category specification (its own options are handled by the caller). */
    public static function resetSpecForProduct(int $productId, int $specId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM product_spec_hidden WHERE product_id = :p AND spec_id = :s')->execute(['p' => $productId, 's' => $specId]);
        $pdo->prepare('DELETE ov FROM product_option_overrides ov JOIN spec_options o ON o.id = ov.option_id WHERE ov.product_id = :p AND o.spec_id = :s')
            ->execute(['p' => $productId, 's' => $specId]);
    }

    public static function setProductFields(int $productId, ?int $categoryId, ?string $forms, ?int $cbmFrom, ?string $weightPerCbm = null): void
    {
        Database::connection()->prepare('UPDATE catalog_products SET category_id = :c, forms = :f, cbm_from_mm = :m, weight_t_per_cbm = :w WHERE id = :id')
            ->execute(['c' => $categoryId, 'f' => $forms, 'm' => $cbmFrom, 'w' => $weightPerCbm, 'id' => $productId]);
    }

    /** Whether an option (or any option of a specification) has been used on an order line or a price request. */
    public static function optionUsed(int $optionId): bool
    {
        $pdo = Database::connection();
        $needle = '%":' . $optionId . '}%';
        $needle2 = '%":' . $optionId . ',%';
        $stmt = $pdo->prepare('SELECT 1 FROM price_requests WHERE selection LIKE :a OR selection LIKE :b LIMIT 1');
        $stmt->execute(['a' => $needle, 'b' => $needle2]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM order_products WHERE spec_selection LIKE :a OR spec_selection LIKE :b LIMIT 1');
        $stmt->execute(['a' => $needle, 'b' => $needle2]);
        return $stmt->fetchColumn() !== false;
    }

    public static function specUsed(int $specId): bool
    {
        $pdo = Database::connection();
        $needle = '%"' . $specId . '":%';
        foreach (['SELECT 1 FROM price_requests WHERE selection LIKE :a LIMIT 1', 'SELECT 1 FROM order_products WHERE spec_selection LIKE :a LIMIT 1'] as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['a' => $needle]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }
}
