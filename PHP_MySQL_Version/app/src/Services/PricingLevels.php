<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\ProductCategoryRepository;

/**
 * Owner decision D-359 (13 Nov 2026, "go with recommendation"): every pricing value comes
 * from three levels — the PRODUCT's own value if it has one, else its CATEGORY's (Price
 * rules), else the GLOBAL value (Pricing settings). A product marked "follow global"
 * (catalog_products.pricing_follow_global = 1, set by "Reset all to global values") skips
 * its category, so later changes to the global values still reach it.
 *
 *   margin %, other costs, buffer %           product → category (NEW, empty = global) → global
 *   weight t/CBM, container CBM, container
 *   max t, thickness factor K                 product → category → global (NEW physical defaults)
 *   team incentive reserve                    product → global (no category level)
 *
 * Only reading and resetting live here — no maths. PricingService::effective() and
 * ThicknessPrices::rules() use resolve(); the Configure screen (D-361) uses
 * resetPreview() / resetToCategory() / resetToGlobal().
 * Node twin: src/services/pricingLevels.js.
 */
final class PricingLevels
{
    /** value key => [product column, category column, global settings key] */
    public const PHYSICAL = [
        'weight_t_per_cbm' => 'Weight (t per CBM)',
        'container_cbm' => 'Container (CBM)',
        'container_max_t' => 'Container max (t)',
        'thick_k_factor' => 'Thickness factor (50 mm and above = base per m² × K)',
    ];

    /** The product's own pricing values that "Reset all" clears. */
    public const PRODUCT_OVERRIDES = [
        'pricing_margin_pct', 'pricing_other_cost_mode', 'pricing_other_cost_value', 'pricing_buffer_pct',
        'weight_t_per_cbm', 'container_cbm', 'container_max_t', 'thick_k_factor',
    ];

    /** The category row a product follows (null when it follows global or has none). */
    public static function category(array $product): ?array
    {
        if (!empty($product['pricing_follow_global'])) {
            return null;
        }
        $id = ProductSpecs::categoryIdFor($product);
        return $id !== null ? ProductCategoryRepository::find($id) : null;
    }

    private static function num(mixed $v, bool $positive = false): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $f = (float) $v;
        return $positive && $f <= 0 ? null : $f;
    }

    /**
     * Every value with where it comes from ('product' | 'category' | 'global').
     * $category: pass a preloaded category() row to save a query; false = load it here.
     *
     * @return array<string, array{value: mixed, from: string, product: mixed, category: mixed, global: mixed}>
     */
    public static function resolve(array $product, ?array $settings = null, array|false|null $category = false): array
    {
        $settings ??= PricingService::settings();
        if ($category === false) {
            $category = self::category($product);
        }
        $pick = static function (mixed $p, mixed $c, mixed $g): array {
            if ($p !== null) {
                return ['value' => $p, 'from' => 'product', 'product' => $p, 'category' => $c, 'global' => $g];
            }
            if ($c !== null) {
                return ['value' => $c, 'from' => 'category', 'product' => null, 'category' => $c, 'global' => $g];
            }
            return ['value' => $g, 'from' => 'global', 'product' => null, 'category' => null, 'global' => $g];
        };
        $out = [
            'margin_pct' => $pick(self::num($product['pricing_margin_pct'] ?? null), self::num($category['margin_pct'] ?? null), (float) $settings['margin_pct']),
            'buffer_pct' => $pick(self::num($product['pricing_buffer_pct'] ?? null), self::num($category['buffer_pct'] ?? null), (float) $settings['buffer_pct']),
        ];
        // Other costs: the product may set a % or a USD amount; the category and global levels are a %.
        $pOther = ($product['pricing_other_cost_mode'] ?? null) !== null
            ? ['mode' => (string) $product['pricing_other_cost_mode'], 'value' => (float) $product['pricing_other_cost_value']] : null;
        $cOther = self::num($category['other_cost_pct'] ?? null);
        $out['other_cost'] = $pick($pOther, $cOther !== null ? ['mode' => 'pct', 'value' => $cOther] : null, ['mode' => 'pct', 'value' => (float) $settings['other_cost_pct']]);
        $out['incentive'] = ($product['pricing_incentive_mode'] ?? null) !== null
            ? ['value' => ['mode' => (string) $product['pricing_incentive_mode'], 'value' => (float) $product['pricing_incentive_value']], 'from' => 'product', 'product' => null, 'category' => null, 'global' => null]
            : ['value' => ['mode' => (string) $settings['incentive_mode'], 'value' => (float) $settings['incentive_value']], 'from' => 'global', 'product' => null, 'category' => null, 'global' => null];
        foreach (array_keys(self::PHYSICAL) as $key) {
            $out[$key] = $pick(self::num($product[$key] ?? null, true), self::num($category[$key] ?? null, true), self::num($settings[$key] ?? null, true));
        }
        return $out;
    }

    /**
     * What "Reset all to category values" / "Reset all to global values" would change, for the
     * confirm box: one line per value whose figure in use changes, or that stops being the
     * product's own.
     *
     * @param string $to 'category' | 'global'
     * @return list<array{key: string, label: string, now: string, after: string, from_now: string, from_after: string}>
     */
    public static function resetPreview(array $product, string $to, ?array $settings = null): array
    {
        $settings ??= PricingService::settings();
        $now = self::resolve($product, $settings);
        $after = $product;
        foreach (self::PRODUCT_OVERRIDES as $col) {
            $after[$col] = null;
        }
        $after['pricing_follow_global'] = $to === 'global' ? 1 : 0;
        $then = self::resolve($after, $settings);
        $labels = ['margin_pct' => 'Margin %', 'other_cost' => 'Other costs', 'buffer_pct' => 'Buffer %'] + self::PHYSICAL;
        $out = [];
        foreach ($labels as $key => $label) {
            $a = self::text($key, $now[$key]['value']);
            $b = self::text($key, $then[$key]['value']);
            if ($a !== $b || $now[$key]['from'] !== $then[$key]['from']) {
                $out[] = ['key' => $key, 'label' => $label, 'now' => $a, 'after' => $b, 'from_now' => $now[$key]['from'], 'from_after' => $then[$key]['from']];
            }
        }
        return $out;
    }

    /** "15%", "USD 2.00 per unit", "2.7", "—". */
    public static function text(string $key, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_array($value)) {
            return PricingService::modeLabel((string) $value['mode'], (float) $value['value']);
        }
        return in_array($key, ['margin_pct', 'buffer_pct'], true) ? PricingService::pct((float) $value) : ThicknessPrices::fmt((float) $value);
    }

    /** "Reset all to category values": clears the product's own values; it follows its category again. */
    public static function resetToCategory(int $productId, int $userId): void
    {
        self::reset($productId, false, $userId);
    }

    /** "Reset all to global values": clears the product's own values and makes it skip its category. */
    public static function resetToGlobal(int $productId, int $userId): void
    {
        self::reset($productId, true, $userId);
    }

    private static function reset(int $productId, bool $global, int $userId): void
    {
        $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = NULL", self::PRODUCT_OVERRIDES));
        Database::connection()->prepare("UPDATE catalog_products SET {$sets}, pricing_follow_global = :g, updated_by = :u WHERE id = :id")
            ->execute(['g' => $global ? 1 : 0, 'u' => $userId, 'id' => $productId]);
        AuditLogRepository::log($userId, 'PRODUCT_PRICING_RESET', 'catalog_products', $productId, 'pricing_follow_global', null, $global ? 'reset to global values' : 'reset to category values');
    }
}
