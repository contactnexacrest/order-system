<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-245 (4 Nov 2026), P1: pricing settings, product pricing
 * overrides, market-price dates, commission levels and the ladder.
 * Node twin: src/repositories/pricingRepository.js.
 */
final class PricingRepository
{
    /** @return array<string,string> */
    public static function settings(): array
    {
        // D-245 P5: read once per report run (BatchCache scope only).
        return \App\Helpers\BatchCache::memo('pricing_settings', static fn () => self::settingsUncached());
    }

        private static function settingsUncached(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT setting_key, setting_value FROM pricing_settings')->fetchAll() as $row) {
            $out[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $out;
    }

    public static function setSetting(string $key, string $value, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO pricing_settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        )->execute(['k' => $key, 'v' => $value, 'u' => $userId]);
    }

    /** @param array<string,mixed> $fields pricing_* columns of catalog_products */
    public static function saveProductOverrides(int $productId, array $fields): void
    {
        Database::connection()->prepare(
            'UPDATE catalog_products SET pricing_margin_pct = :m, pricing_other_cost_mode = :om, pricing_other_cost_value = :ov,
                    pricing_incentive_mode = :im, pricing_incentive_value = :iv, pricing_buffer_pct = :b
              WHERE id = :id'
        )->execute([
            'm' => $fields['pricing_margin_pct'], 'om' => $fields['pricing_other_cost_mode'], 'ov' => $fields['pricing_other_cost_value'],
            'im' => $fields['pricing_incentive_mode'], 'iv' => $fields['pricing_incentive_value'], 'b' => $fields['pricing_buffer_pct'], 'id' => $productId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function levels(bool $activeOnly = false): array
    {
        return Database::connection()->query(
            'SELECT * FROM commission_levels' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, value, id'
        )->fetchAll();
    }

    public static function findLevel(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM commission_levels WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function levelNameTaken(string $name, ?int $exceptId): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM commission_levels WHERE name = :n AND id <> :id');
        $stmt->execute(['n' => $name, 'id' => $exceptId ?? 0]);
        return $stmt->fetch() !== false;
    }

    public static function createLevel(string $name, string $kind, string $value, int $userId): int
    {
        $pdo = Database::connection();
        $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM commission_levels')->fetchColumn();
        $pdo->prepare('INSERT INTO commission_levels (name, kind, value, is_active, sort_order, created_by, updated_by) VALUES (:n, :k, :v, 1, :s, :u, :u2)')
            ->execute(['n' => $name, 'k' => $kind, 'v' => $value, 's' => $sort, 'u' => $userId, 'u2' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function updateLevel(int $id, string $name, string $kind, string $value, int $userId): void
    {
        Database::connection()->prepare('UPDATE commission_levels SET name = :n, kind = :k, value = :v, updated_by = :u WHERE id = :id')
            ->execute(['n' => $name, 'k' => $kind, 'v' => $value, 'u' => $userId, 'id' => $id]);
    }

    public static function setLevelActive(int $id, bool $active, int $userId): void
    {
        Database::connection()->prepare('UPDATE commission_levels SET is_active = :a, updated_by = :u WHERE id = :id')
            ->execute(['a' => $active ? 1 : 0, 'u' => $userId, 'id' => $id]);
    }

    public static function deleteLevel(int $id): void
    {
        Database::connection()->prepare('DELETE FROM commission_levels WHERE id = :id')->execute(['id' => $id]);
    }

    /** How many associates and ladder steps use the level (a used level can only be deactivated). */
    public static function levelUse(int $id): array
    {
        $pdo = Database::connection();
        $a = $pdo->prepare('SELECT COUNT(*) FROM associates WHERE commission_level_id = :id');
        $a->execute(['id' => $id]);
        $s = $pdo->prepare('SELECT COUNT(*) FROM commission_ladder_steps WHERE level_id = :id');
        $s->execute(['id' => $id]);
        return ['associates' => (int) $a->fetchColumn(), 'steps' => (int) $s->fetchColumn()];
    }

    /** @return array<int,int> level id => associates on it */
    public static function associatesPerLevel(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT commission_level_id AS id, COUNT(*) AS n FROM associates WHERE commission_level_id IS NOT NULL GROUP BY commission_level_id')->fetchAll() as $r) {
            $out[(int) $r['id']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> steps with their level, in order */
    public static function ladder(): array
    {
        return Database::connection()->query(
            'SELECT s.id, s.from_order, s.to_order, s.level_id, l.name AS level_name, l.kind, l.value, l.is_active
               FROM commission_ladder_steps s JOIN commission_levels l ON l.id = s.level_id
              ORDER BY s.from_order'
        )->fetchAll();
    }

    /** @param list<array{from:int,to:?int,level_id:int}> $steps */
    public static function replaceLadder(array $steps, int $userId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM commission_ladder_steps');
            $ins = $pdo->prepare('INSERT INTO commission_ladder_steps (from_order, to_order, level_id, updated_by) VALUES (:f, :t, :l, :u)');
            foreach ($steps as $s) {
                $ins->execute(['f' => $s['from'], 't' => $s['to'], 'l' => $s['level_id'], 'u' => $userId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function setAssociateCommission(int $associateId, string $mode, ?int $levelId): void
    {
        Database::connection()->prepare('UPDATE associates SET commission_mode = :m, commission_level_id = :l WHERE id = :id')
            ->execute(['m' => $mode, 'l' => $levelId, 'id' => $associateId]);
    }

    /** Market price dates on a supplier row. */
    public static function setSupplierDates(int $supplierId, ?string $quotedOn, ?string $validUntil, ?int $quoteId = null): void
    {
        Database::connection()->prepare('UPDATE catalog_product_suppliers SET quoted_on = :q, valid_until = :v, purchase_quote_id = COALESCE(:pq, purchase_quote_id) WHERE id = :id')
            ->execute(['q' => $quotedOn, 'v' => $validUntil, 'pq' => $quoteId, 'id' => $supplierId]);
    }

    /**
     * Supplier quotes recorded in Procurement for order lines picked from this product.
     *
     * @return list<array<string,mixed>>
     */
    public static function purchaseQuotesForProduct(int $productId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT q.id, q.unit_price_inr, q.valid_until, q.quote_reference, q.recorded_at, s.supplier_legal_name, r.unit
               FROM purchase_quotes q
               JOIN purchase_requirements r ON r.id = q.requirement_id
               JOIN order_products op ON op.id = r.order_product_id
               JOIN suppliers s ON s.id = q.supplier_id
              WHERE op.catalog_product_id = :p
              ORDER BY q.recorded_at DESC, q.id DESC
              LIMIT 20'
        );
        $stmt->execute(['p' => $productId]);
        return $stmt->fetchAll();
    }

    public static function purchaseQuote(int $quoteId, int $productId): ?array
    {
        foreach (self::purchaseQuotesForProduct($productId) as $q) {
            if ((int) $q['id'] === $quoteId) {
                return $q;
            }
        }
        return null;
    }
}
