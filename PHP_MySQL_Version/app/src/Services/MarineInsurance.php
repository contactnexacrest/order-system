<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\PricingRepository;

/**
 * Owner decision D-402 (21 Nov 2026, "approve A"): the indicative insurance on a CIF order is worked out
 * from the CIF value — the premium rate × the cover (110 %) × CIF value, where the CIF value is
 * FOB + freight + the insurance itself:
 *
 *     premium = rate × cover × (FOB + freight) ÷ (1 − rate × cover)
 *
 * The rate is the highest among the order's goods-line categories (Natural Stone 0.30 % incl. breakage),
 * else the default rate in Admin › Global pricing settings › Marine insurance. Optional minimum premium,
 * rounding up to the next whole unit (default), GST not added (NexaCrest's own cost; input tax credit).
 * Only a default: staff can change it on the order, and the buyer is billed the actual premium by Debit Note.
 * CFR and FOB orders have no insurance (D-049). Same rules in the Node twin (services/marineInsurance.js).
 */
final class MarineInsurance
{
    public const DEFAULTS = [
        'insurance_cover_pct' => '110.00',
        'insurance_rate_pct' => '0.2000',
        'insurance_min_premium' => '',
        'insurance_rounding' => 'up',
        'insurance_add_gst' => '0',
        'insurance_cover_wording' => 'ICC (A) all risks',
    ];

    public const LABELS = [
        'insurance_cover_pct' => 'Insurance cover (% of CIF value)',
        'insurance_rate_pct' => 'Default insurance premium rate (%)',
        'insurance_min_premium' => 'Minimum insurance premium per shipment',
        'insurance_rounding' => 'Insurance rounding',
        'insurance_add_gst' => 'GST on the insurance premium',
        'insurance_cover_wording' => 'Default cover wording on documents',
    ];

    public const GST_PCT = 18.0;

    /** @return array{cover_pct: float, rate_pct: float, min_premium: ?float, rounding: string, add_gst: bool, cover_wording: string} */
    public static function settings(?array $raw = null): array
    {
        $raw = ($raw ?? PricingRepository::settings()) + self::DEFAULTS;
        $min = trim((string) $raw['insurance_min_premium']);
        return [
            'cover_pct' => (float) $raw['insurance_cover_pct'],
            'rate_pct' => (float) $raw['insurance_rate_pct'],
            'min_premium' => $min !== '' && (float) $min > 0 ? (float) $min : null,
            'rounding' => (string) $raw['insurance_rounding'] === '2dp' ? '2dp' : 'up',
            'add_gst' => (string) $raw['insurance_add_gst'] === '1',
            'cover_wording' => trim((string) $raw['insurance_cover_wording']) !== '' ? trim((string) $raw['insurance_cover_wording']) : self::DEFAULTS['insurance_cover_wording'],
        ];
    }

    /**
     * The premium for a FOB value and freight at a rate.
     *
     * @param array{cover_pct: float, min_premium: ?float, rounding: string, add_gst: bool} $s
     */
    public static function premium(float $fob, float $freight, float $ratePct, array $s): float
    {
        $k = ($ratePct / 100) * ($s['cover_pct'] / 100);
        if ($fob + $freight <= 0 || $k <= 0 || $k >= 1) {
            return 0.0;
        }
        $p = $k * ($fob + $freight) / (1 - $k);
        if ($s['add_gst']) {
            $p *= 1 + self::GST_PCT / 100;
        }
        if ($s['min_premium'] !== null && $p < $s['min_premium']) {
            $p = $s['min_premium'];
        }
        $p = round($p, 6);
        return $s['rounding'] === '2dp' ? round($p, 2) : (float) ceil($p - 1e-9);
    }

    /**
     * The rate and cover wording for a set of category ids (null = the system category): the highest
     * category rate; a category without its own rate uses the default.
     *
     * @param list<?int> $categoryIds
     * @return array{rate_pct: float, wording: string, category: ?string}
     */
    public static function rateFor(array $categoryIds, ?array $settings = null): array
    {
        $s = $settings ?? self::settings();
        $best = ['rate_pct' => $s['rate_pct'], 'wording' => $s['cover_wording'], 'category' => null];
        $found = false;
        foreach (self::categories() as $c) {
            $id = (int) $c['id'];
            $wanted = in_array($id, $categoryIds, true) || ((int) $c['is_system'] === 1 && in_array(null, $categoryIds, true));
            if (!$wanted) {
                continue;
            }
            $rate = $c['insurance_rate_pct'] !== null && $c['insurance_rate_pct'] !== '' ? (float) $c['insurance_rate_pct'] : $s['rate_pct'];
            $wording = trim((string) ($c['insurance_cover_wording'] ?? '')) !== '' ? trim((string) $c['insurance_cover_wording']) : $s['cover_wording'];
            if (!$found || $rate > $best['rate_pct']) {
                $best = ['rate_pct' => $rate, 'wording' => $wording, 'category' => (string) $c['name']];
                $found = true;
            }
        }
        return $best;
    }

    /** @return list<array<string,mixed>> */
    public static function categories(): array
    {
        return Database::connection()->query(
            'SELECT id, name, is_system, insurance_rate_pct, insurance_cover_wording FROM product_categories ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<?int> the categories of an order's active goods lines (null = the system category) */
    public static function orderCategoryIds(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT category_id FROM order_products WHERE order_id = :o AND line_type = 'goods' AND is_active = 1"
        );
        $stmt->execute(['o' => $orderId]);
        $ids = array_map(static fn ($v) => $v === null ? null : (int) $v, $stmt->fetchAll(\PDO::FETCH_COLUMN));
        return $ids === [] ? [null] : $ids;
    }

    /** The order's FOB value (the same figure the documents print), or null when nothing is priced yet. */
    public static function orderFob(int $orderId): ?float
    {
        $fob = round(\App\Repositories\OrderProductRepository::totalFobValue($orderId), 2);
        return $fob > 0 ? $fob : null;
    }

    /**
     * The calculated premium for an order, or null when it cannot be worked out yet (no priced lines or no freight).
     */
    public static function forOrder(int $orderId, mixed $freight): ?float
    {
        $fob = self::orderFob($orderId);
        if ($fob === null || $fob <= 0 || !is_numeric($freight)) {
            return null;
        }
        $s = self::settings();
        return self::premium($fob, (float) $freight, self::rateFor(self::orderCategoryIds($orderId), $s)['rate_pct'], $s);
    }

    /**
     * A CIF order with freight but no insurance figure gets the calculated one (server side, so a form
     * saved without the browser's calculator still gets it). Any figure staff typed is kept.
     */
    public static function fillIfEmpty(int $orderId): void
    {
        $stmt = Database::connection()->prepare('SELECT incoterm_code, indicative_freight_amount, indicative_insurance_amount FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $o = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($o === false || strtoupper((string) $o['incoterm_code']) !== 'CIF'
            || ($o['indicative_insurance_amount'] !== null && trim((string) $o['indicative_insurance_amount']) !== '')) {
            return;
        }
        $p = self::forOrder($orderId, $o['indicative_freight_amount']);
        if ($p !== null && $p > 0) {
            Database::connection()->prepare('UPDATE orders SET indicative_insurance_amount = :p WHERE id = :id')
                ->execute(['p' => number_format($p, 2, '.', ''), 'id' => $orderId]);
        }
    }

    /**
     * The same for the New Order form's lines (before the order exists).
     *
     * @param list<array{quantity: mixed, unit_price: mixed, category_id: ?int}> $lines
     */
    public static function forLines(array $lines, mixed $freight): ?float
    {
        $fob = 0.0;
        $cats = [];
        foreach ($lines as $l) {
            if (is_numeric($l['quantity'] ?? null) && is_numeric($l['unit_price'] ?? null)) {
                $fob += (float) $l['quantity'] * (float) $l['unit_price'];
            }
            $cats[] = $l['category_id'] ?? null;
        }
        if ($fob <= 0 || !is_numeric($freight)) {
            return null;
        }
        $s = self::settings();
        return self::premium(round($fob, 2), (float) $freight, self::rateFor($cats === [] ? [null] : $cats, $s)['rate_pct'], $s);
    }

    /**
     * The data the order forms' calculator reads (data-ins-* attributes on the freight/insurance fieldset).
     *
     * @return array<string,string>
     */
    public static function formAttributes(?int $orderId = null): array
    {
        $s = self::settings();
        $cats = [];
        foreach (self::categories() as $c) {
            $cats[(int) $c['id']] = [
                $c['insurance_rate_pct'] !== null && $c['insurance_rate_pct'] !== '' ? (float) $c['insurance_rate_pct'] : null,
                trim((string) ($c['insurance_cover_wording'] ?? '')),
                (string) $c['name'],
                (int) $c['is_system'],
            ];
        }
        $products = [];
        foreach (Database::connection()->query('SELECT id, category_id FROM catalog_products WHERE category_id IS NOT NULL')->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $products[(int) $p['id']] = (int) $p['category_id'];
        }
        $attrs = [
            'data-ins-cover' => (string) $s['cover_pct'],
            'data-ins-rate' => (string) $s['rate_pct'],
            'data-ins-wording' => $s['cover_wording'],
            'data-ins-min' => $s['min_premium'] !== null ? (string) $s['min_premium'] : '',
            'data-ins-round' => $s['rounding'],
            'data-ins-gst' => $s['add_gst'] ? '1' : '0',
            'data-ins-categories' => (string) json_encode($cats),
            'data-ins-products' => (string) json_encode($products),
        ];
        if ($orderId !== null) {
            $fob = self::orderFob($orderId);
            $attrs['data-ins-fob'] = $fob !== null ? (string) $fob : '';
            $attrs['data-ins-order-categories'] = (string) json_encode(self::orderCategoryIds($orderId));
        }
        return $attrs;
    }

    /** The attributes as an HTML attribute string. */
    public static function formAttributeHtml(?int $orderId = null): string
    {
        // The calculator shows the order's FOB value: only for users who may see selling prices (money matrix).
        // For anyone else the form has no calculator and the server fills an empty figure on save (fillIfEmpty).
        if (!MoneyVisibility::can(MoneyVisibility::SELLING)) {
            return '';
        }
        $out = '';
        foreach (self::formAttributes($orderId) as $k => $v) {
            $out .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES) . '"';
        }
        return $out;
    }
}
