<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;

/**
 * Owner decision D-357 (12 Nov 2026, owner "confirm 1" and "confirm 2"; mockup approved):
 * ONE list of surface-finish names.
 *
 *  - Settings › Lists › surface_finish (dropdown_options, list_key 'surface_finish') is the
 *    master for the NAMES: name, sort order, default, active.
 *  - Pricing › Specifications › Surface finish keeps the PRICE only (base, change type,
 *    value). The specification is marked spec_definitions.list_key = 'surface_finish' and
 *    each of its options carries spec_options.dropdown_option_id, the Settings row it is.
 *  - A finish added in Settings appears in every linked specification at no price change;
 *    one switched off in Settings is switched off there (gone from Pick from Products).
 *  - A rename in Settings renames the linked options and the finish of order lines on
 *    orders whose first Quotation is not approved yet (orders.prices_fixed_at IS NULL,
 *    the D-342 rule). Orders past that point and issued documents never change.
 *  - A line picked from Products takes its finish from the chosen Surface finish option.
 *
 * Only the Surface finish specification is linked; no other specification or list.
 * Node twin: src/services/surfaceFinishList.js.
 */
final class SurfaceFinishList
{
    public const LIST_KEY = 'surface_finish';

    /** The longest name an option can carry (spec_options.name). */
    public const MAX_NAME = 80;

    public static function isLinkedSpec(?array $spec): bool
    {
        return $spec !== null && ($spec['list_key'] ?? null) === self::LIST_KEY;
    }

    /** @return list<array<string,mixed>> the Settings list, every row (active or not), in order */
    public static function settingsRows(): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM dropdown_options WHERE list_key = :k ORDER BY sort_order, id');
        $stmt->execute(['k' => self::LIST_KEY]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> the linked category specifications, with their category's name */
    public static function linkedSpecs(): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.*, c.name AS category_name FROM spec_definitions s LEFT JOIN product_categories c ON c.id = s.category_id
              WHERE s.list_key = :k AND s.product_id IS NULL ORDER BY c.is_system DESC, c.sort_order, s.id'
        );
        $stmt->execute(['k' => self::LIST_KEY]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Brings every linked specification in line with the Settings list: a Settings row with no
     * option yet gets one at no price change; names and sort order follow Settings; options of
     * the rows in $activeChanged (dropdown ids switched on or off just now) follow their state.
     */
    public static function sync(array $activeChanged = [], ?int $userId = null): void
    {
        $pdo = Database::connection();
        $rows = self::settingsRows();
        foreach (self::linkedSpecs() as $s) {
            $have = $pdo->prepare('SELECT dropdown_option_id FROM spec_options WHERE spec_id = :s AND product_id IS NULL AND dropdown_option_id IS NOT NULL');
            $have->execute(['s' => (int) $s['id']]);
            $linked = array_map('intval', $have->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($rows as $r) {
                if (in_array((int) $r['id'], $linked, true)) {
                    continue;
                }
                $pdo->prepare(
                    "INSERT INTO spec_options (spec_id, product_id, name, is_base, change_type, value, thickness_mm, sort_order, is_active, updated_by, dropdown_option_id)
                     VALUES (:s, NULL, :n, 0, 'none', NULL, NULL, :o, :a, :u, :d)"
                )->execute(['s' => (int) $s['id'], 'n' => mb_substr((string) $r['option_value'], 0, self::MAX_NAME), 'o' => (int) $r['sort_order'],
                    'a' => (int) $r['is_active'], 'u' => $userId, 'd' => (int) $r['id']]);
            }
        }
        $pdo->exec(
            'UPDATE spec_options o JOIN dropdown_options d ON d.id = o.dropdown_option_id
                SET o.name = LEFT(d.option_value, ' . self::MAX_NAME . '), o.sort_order = d.sort_order
              WHERE BINARY o.name <> BINARY LEFT(d.option_value, ' . self::MAX_NAME . ') OR o.sort_order <> d.sort_order'
        );
        $ids = array_values(array_filter(array_map('intval', $activeChanged), static fn (int $i): bool => $i > 0));
        if ($ids !== []) {
            $pdo->exec(
                'UPDATE spec_options o JOIN dropdown_options d ON d.id = o.dropdown_option_id SET o.is_active = d.is_active
                  WHERE o.dropdown_option_id IN (' . implode(',', $ids) . ')'
            );
        }
    }

    /**
     * A finish renamed in Settings: the finish of every line on an order whose first Quotation
     * is not approved yet (D-342: prices_fixed_at IS NULL) that reads exactly $old becomes $new,
     * each line audit-logged. Orders past that point, and documents already issued, keep $old.
     *
     * @return array{lines: int, orders: int, kept_orders: int}
     */
    public static function renameOnDraftOrders(string $old, string $new, ?int $userId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT op.id, op.order_id FROM order_products op JOIN orders o ON o.id = op.order_id
              WHERE o.prices_fixed_at IS NULL AND BINARY op.finish = BINARY :old ORDER BY op.order_id, op.id'
        );
        $stmt->execute(['old' => $old]);
        $orders = [];
        $lines = 0;
        $upd = $pdo->prepare('UPDATE order_products SET finish = :new WHERE id = :id');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $l) {
            $upd->execute(['new' => $new, 'id' => (int) $l['id']]);
            AuditLogRepository::log($userId, 'ORDER_LINE_FINISH_RENAMED', 'order_products', (int) $l['id'], 'finish', $old, $new,
                'D-357: surface finish renamed in Settings › Lists (order #' . (int) $l['order_id'] . ', quotation not yet approved)');
            $orders[(int) $l['order_id']] = true;
            $lines++;
        }
        $kept = $pdo->prepare(
            'SELECT COUNT(DISTINCT op.order_id) FROM order_products op JOIN orders o ON o.id = op.order_id
              WHERE o.prices_fixed_at IS NOT NULL AND BINARY op.finish = BINARY :old'
        );
        $kept->execute(['old' => $old]);
        return ['lines' => $lines, 'orders' => count($orders), 'kept_orders' => (int) $kept->fetchColumn()];
    }

    /** The two lines of the Settings page's success box after a rename (mockup section 1). */
    public static function renameMessage(string $old, string $new, array $r): array
    {
        $lines = $r['lines'] === 1 ? '1 line' : $r['lines'] . ' lines';
        $orders = $r['orders'] === 1 ? '1 draft order' : $r['orders'] . ' draft orders';
        $kept = $r['kept_orders'] === 1 ? '1 order' : $r['kept_orders'] . ' orders';
        return [
            'saved' => "\"{$old}\" is now \"{$new}\" everywhere: Pick from Products, Pricing › Specifications, and {$lines} on {$orders} (quotation not yet approved).",
            'kept' => "Not changed: {$kept} with an approved quotation or later, and every document already issued — they keep \"{$old}\".",
        ];
    }

    /** The finish name of the Surface finish option chosen in $selection ({spec id: option id}), or null. */
    public static function finishForSelection(?int $catalogProductId, ?array $selection): ?string
    {
        if ($catalogProductId === null || $catalogProductId <= 0 || $selection === null || $selection === []) {
            return null;
        }
        $optionIds = array_values(array_filter(array_map('intval', $selection), static fn (int $i): bool => $i > 0));
        if ($optionIds === []) {
            return null;
        }
        $rows = Database::connection()->query(
            "SELECT o.id, o.spec_id, o.name FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id
              WHERE s.list_key = '" . self::LIST_KEY . "' AND o.id IN (" . implode(',', $optionIds) . ')'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $o) {
            if ((int) ($selection[(int) $o['spec_id']] ?? 0) === (int) $o['id']) {
                return (string) $o['name'];
            }
        }
        return null;
    }

    /** A line picked from Products: its finish is the chosen Surface finish option's name (when one is chosen). */
    public static function applyToLine(int $lineId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT catalog_product_id, spec_selection FROM order_products WHERE id = :id');
        $stmt->execute(['id' => $lineId]);
        $line = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$line) {
            return;
        }
        $name = self::finishForSelection($line['catalog_product_id'] !== null ? (int) $line['catalog_product_id'] : null, OrderLinePricing::lineSelection($line));
        if ($name !== null) {
            $pdo->prepare('UPDATE order_products SET finish = :n WHERE id = :id AND (finish IS NULL OR BINARY finish <> BINARY :n2)')
                ->execute(['n' => $name, 'n2' => $name, 'id' => $lineId]);
        }
    }

    /**
     * The Settings page's "Price change (from Pricing › Specifications)" column: per Settings row,
     * one entry per linked category option.
     *
     * @return array<int, list<array{category: string, text: string, unset: bool, link: string}>>
     */
    public static function priceColumn(): array
    {
        $specs = self::linkedSpecs();
        $out = [];
        if ($specs === []) {
            return $out;
        }
        $byId = [];
        foreach ($specs as $s) {
            $byId[(int) $s['id']] = $s;
        }
        $rows = Database::connection()->query(
            'SELECT id, spec_id, dropdown_option_id, is_base, change_type, value FROM spec_options
              WHERE product_id IS NULL AND dropdown_option_id IS NOT NULL AND spec_id IN (' . implode(',', array_keys($byId)) . ') ORDER BY spec_id, sort_order, id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $o) {
            $s = $byId[(int) $o['spec_id']];
            [$text, $unset] = self::priceText($o);
            $out[(int) $o['dropdown_option_id']][] = [
                'category' => count($specs) > 1 ? (string) ($s['category_name'] ?? '') : '',
                'text' => $text, 'unset' => $unset,
                'link' => '/products/categories/' . (int) $s['category_id'] . '?tab=options&edit=' . (int) $s['id'] . '#spec-' . (int) $s['id'],
            ];
        }
        return $out;
    }

    /** @return array{0: string, 1: bool} "base price" / "+1.00 USD / m²" / "no price change yet" (true: not set) */
    public static function priceText(array $o): array
    {
        if ((int) $o['is_base'] === 1) {
            return ['base price', false];
        }
        $type = (string) $o['change_type'];
        if ($type === 'cbm') {
            return ['priced per CBM', false];
        }
        $v = $o['value'] !== null ? (float) $o['value'] : 0.0;
        if ($type === 'none' || $v == 0.0) {
            return ['no price change yet', true];
        }
        $sign = $v < 0 ? '−' : '+';
        if ($type === 'pct') {
            return [$sign . number_format(abs($v), 2) . ' %', false];
        }
        $per = ['usd_m2' => 'USD / SQM', 'usd_rm' => 'USD / running m', 'usd_container' => 'USD / container', 'usd_piece' => 'USD / piece', 'usd_kg' => 'USD / kg', 'usd_set' => 'USD / set'][$type] ?? 'USD';
        return [$sign . number_format(abs($v), 2) . ' ' . $per, false];
    }

    /** The Settings row a product's own option on a linked specification is chosen from, or an error. */
    public static function settingsRowByName(string $name): ?array
    {
        foreach (self::settingsRows() as $r) {
            if ((int) $r['is_active'] === 1 && mb_strtolower(trim((string) $r['option_value'])) === mb_strtolower(trim($name))) {
                return $r;
            }
        }
        return null;
    }
}
