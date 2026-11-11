<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\ProductCategoryRepository;

/**
 * Owner decision D-300 (point 12, 11 Nov 2026): one supplier price holds every
 * thickness — 20, 30, 40 mm … per m² and "50 mm and above" per CBM. Staff type
 * any of them; the others are CALCULATED from the category's price rules:
 *
 *   other thickness per m² = base (20 mm) per m² × (1 + its % / 100)   e.g. 30 mm +35 %, 40 mm +70 %
 *   50 mm and above per CBM = base (20 mm) per m² × K                  K = 40 by default
 *
 * It works in every direction: when only 40 mm or only the per-CBM price is
 * typed, the base is worked back from it (64,000 per CBM ÷ 40 = 1,600 per m²).
 * Only TYPED prices are stored; CALCULATED ones are worked out again each time,
 * so a change to a typed price — or to the rules — flows into them at once.
 *
 * CHA + transport is a price per container. What one container holds comes from
 * the category (8 CBM, at most 27 t; a product may override): a heavy stone
 * fills the weight first (27 t ÷ 3.5 t per CBM = 7.71 CBM), so the per-CBM cost
 * is the container price ÷ what really fits.
 *
 * Node twin: src/services/thicknessPrices.js (same maths, same rounding).
 */
final class ThicknessPrices
{
    /** thickness_mm 0 stands for "50 mm and above, per CBM". */
    public const CBM = 0;

    public const DEFAULT_K = 40.0;
    public const DEFAULT_CONTAINER_CBM = 8.0;
    public const DEFAULT_CONTAINER_MAX_T = 27.0;

    /**
     * The price rules a product follows: its category's, with the product's own values
     * where it has them (shown with an "own rule" badge).
     *
     * @return array{grid: bool, base_mm: int, rows: list<array{mm:int, pct:float, option:string}>, k: ?float,
     *               cbm_from: int, container_cbm: float, container_max_t: float, t_per_cbm: ?float,
     *               own: list<string>, category_id: ?int, category_name: ?string, unit: string}
     */
    public static function rules(array $product, ?array $settings = null): array
    {
        $settings ??= PricingService::settings();
        $categoryId = ProductSpecs::categoryIdFor($product);
        $category = $categoryId !== null ? ProductCategoryRepository::find($categoryId) : null;
        $cbmFrom = (int) ($product['cbm_from_mm'] ?? 0) > 0 ? (int) $product['cbm_from_mm'] : (int) $settings['cbm_from_mm'];
        $own = [];
        $num = static fn ($v): ?float => $v === null || $v === '' ? null : (float) $v;
        $k = $num($product['thick_k_factor'] ?? null);
        if ($k !== null) {
            $own[] = '× ' . self::fmt($k);
        }
        $k ??= $num($category['thick_k_factor'] ?? null);
        $cbm = $num($product['container_cbm'] ?? null);
        $maxT = $num($product['container_max_t'] ?? null);
        if ($cbm !== null || $maxT !== null) {
            $own[] = 'container';
        }
        $cbm ??= $num($category['container_cbm'] ?? null) ?? self::DEFAULT_CONTAINER_CBM;
        $maxT ??= $num($category['container_max_t'] ?? null) ?? self::DEFAULT_CONTAINER_MAX_T;
        $rows = [];
        $baseMm = null;
        foreach (ProductSpecs::offered($product) as $sp) {
            if ($sp['kind'] !== 'thickness') {
                continue;
            }
            foreach ($sp['options'] as $o) {
                if ($o['thickness_mm'] === null || $o['thickness_mm'] >= $cbmFrom || !in_array($o['change_type'], ['none', 'pct'], true)) {
                    continue;
                }
                if (($o['source'] ?? 'category') !== 'category') {
                    $own[] = $o['name'];
                }
                $rows[(int) $o['thickness_mm']] = ['mm' => (int) $o['thickness_mm'], 'pct' => $o['change_type'] === 'pct' ? (float) $o['value'] : 0.0, 'option' => (string) $o['name']];
                if ($o['is_base']) {
                    $baseMm = (int) $o['thickness_mm'];
                }
            }
        }
        ksort($rows);
        $rows = array_values($rows);
        if ($baseMm === null) {
            foreach ($rows as $r) {
                if (abs($r['pct']) < 1e-9) {
                    $baseMm = $r['mm'];
                    break;
                }
            }
        }
        $unit = strtoupper(trim((string) ($product['reference_unit'] ?? '')));
        $unitOk = $unit === '' || in_array(LineUnits::canonical($unit), ['CBM', 'M2', 'SQFT', 'TON'], true);
        return [
            'grid' => $k !== null && $k > 0 && $baseMm !== null && $unitOk,
            'base_mm' => $baseMm ?? 20, 'rows' => $rows, 'k' => $k, 'cbm_from' => $cbmFrom,
            'container_cbm' => $cbm, 'container_max_t' => $maxT, 't_per_cbm' => OrderLinePricing::weightPerCbm($product),
            'own' => array_values(array_unique($own)), 'category_id' => $categoryId, 'category_name' => $category['name'] ?? null,
            'unit' => $unit,
        ];
    }

    /**
     * Fills in every thickness from the typed ones.
     *
     * @param array{base_mm:int, rows: list<array{mm:int,pct:float}>, k: ?float} $rules
     * @param array<int,float> $typed thickness_mm (0 = per CBM) => typed price; 0 / empty = not typed
     * @return array{ok: bool, error: ?string, base: ?float,
     *               values: array<int, array{price: float, how: string, calc: float, rule: string}>}
     */
    public static function complete(array $rules, array $typed): array
    {
        $typed = array_filter($typed, static fn ($v): bool => $v !== null && (float) $v > 0);
        $pct = [];
        foreach ($rules['rows'] as $r) {
            $pct[$r['mm']] = (float) $r['pct'];
        }
        $k = $rules['k'] !== null ? (float) $rules['k'] : null;
        $baseMm = (int) $rules['base_mm'];
        $base = null;
        if (isset($typed[$baseMm])) {
            $base = (float) $typed[$baseMm];
        } else {
            foreach ($pct as $mm => $p) {
                if (isset($typed[$mm]) && $mm !== self::CBM) {
                    $base = (float) $typed[$mm] / (1 + $p / 100);
                    break;
                }
            }
            if ($base === null && isset($typed[self::CBM]) && $k !== null && $k > 0) {
                $base = (float) $typed[self::CBM] / $k;
            }
        }
        if ($base === null || $base <= 0) {
            return ['ok' => false, 'error' => 'Enter at least one price.', 'base' => null, 'values' => []];
        }
        $values = [];
        foreach ($pct as $mm => $p) {
            $calc = PricingEngine::round2($base * (1 + $p / 100));
            $rule = $mm === $baseMm ? 'base' : $baseMm . ' mm ' . ($p < 0 ? '− ' : '+ ') . self::fmt(abs($p)) . '%';
            $values[$mm] = isset($typed[$mm])
                ? ['price' => PricingEngine::round2((float) $typed[$mm]), 'how' => 'typed', 'calc' => $calc, 'rule' => $rule]
                : ['price' => $calc, 'how' => 'calculated', 'calc' => $calc, 'rule' => $rule];
        }
        if ($k !== null && $k > 0) {
            $calc = PricingEngine::round2($base * $k);
            $rule = $baseMm . ' mm price × ' . self::fmt($k);
            $values[self::CBM] = isset($typed[self::CBM])
                ? ['price' => PricingEngine::round2((float) $typed[self::CBM]), 'how' => 'typed', 'calc' => $calc, 'rule' => $rule]
                : ['price' => $calc, 'how' => 'calculated', 'calc' => $calc, 'rule' => $rule];
        }
        return ['ok' => true, 'error' => null, 'base' => $base, 'values' => $values];
    }

    /**
     * Reads the grid from a form: price[mm] and how[mm] ("typed" / "calculated").
     * A box counts as TYPED when it holds a price above 0 and was typed (how = typed),
     * or — without the browser script — differs from what would be calculated. 0 or
     * empty = calculated again on save.
     *
     * @return array{0: array<int,float>, 1: ?string} [typed prices, error]
     */
    public static function readForm(array $post, array $rules): array
    {
        $prices = is_array($post['price'] ?? null) ? $post['price'] : [];
        $how = is_array($post['how'] ?? null) ? $post['how'] : [];
        $allowed = array_map(static fn (array $r): int => $r['mm'], $rules['rows']);
        if ($rules['k'] !== null) {
            $allowed[] = self::CBM;
        }
        $raw = [];
        foreach ($allowed as $mm) {
            $v = trim(str_replace(',', '', (string) ($prices[$mm] ?? $prices[(string) $mm] ?? '')));
            if ($v === '' || (is_numeric($v) && (float) $v == 0.0)) {
                continue;
            }
            if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
                return [[], ($mm === self::CBM ? '50 mm and above' : "{$mm} mm") . ': enter the price in ₹ (at most 2 decimals), or leave it empty to calculate it.'];
            }
            $raw[$mm] = ['v' => (float) $v, 'how' => (string) ($how[$mm] ?? $how[(string) $mm] ?? '')];
        }
        $typed = [];
        foreach ($raw as $mm => $r) {
            if ($r['how'] !== 'calculated') {
                $typed[$mm] = $r['v'];
            }
        }
        if ($typed === []) {
            // Without the browser script every filled box is a typed price.
            foreach ($raw as $mm => $r) {
                $typed[$mm] = $r['v'];
            }
        } else {
            // A box sent as "calculated" whose value differs from the calculation was typed over.
            $done = self::complete($rules, $typed);
            foreach ($raw as $mm => $r) {
                if ($r['how'] === 'calculated' && $done['ok'] && abs(($done['values'][$mm]['calc'] ?? -1) - $r['v']) > 0.005) {
                    $typed[$mm] = $r['v'];
                }
            }
        }
        if ($typed === []) {
            return [[], 'Enter at least one price.'];
        }
        ksort($typed);
        return [$typed, null];
    }

    /**
     * What one container really carries and the CHA + transport per CBM.
     *
     * @return array{per_cbm: ?float, fits_cbm: float, by_weight: bool, weight_t: ?float}
     */
    public static function chaPerCbm(?float $perContainer, float $containerCbm, ?float $maxT, ?float $tPerCbm): array
    {
        $fits = $containerCbm;
        $byWeight = false;
        if ($maxT !== null && $maxT > 0 && $tPerCbm !== null && $tPerCbm > 0 && $containerCbm * $tPerCbm > $maxT) {
            $fits = $maxT / $tPerCbm;
            $byWeight = true;
        }
        return [
            'per_cbm' => $perContainer !== null && $perContainer > 0 && $fits > 0 ? $perContainer / $fits : null,
            'fits_cbm' => $fits, 'by_weight' => $byWeight,
            'weight_t' => $tPerCbm !== null && $tPerCbm > 0 ? $containerCbm * $tPerCbm : null,
        ];
    }

    /** A per-m² price at a thickness, per CBM (1,600 per m² at 20 mm = 80,000 per CBM). */
    public static function m2ToCbm(float $perM2, float $mm): float
    {
        return $perM2 / ($mm / 1000);
    }

    /** CBM in one unit of the product's reference unit at this thickness (null when it cannot be worked out). */
    public static function cbmPerUnit(string $unit, float $mm, ?float $tPerCbm): ?float
    {
        if (trim($unit) === '') {
            return null;
        }
        return LineUnits::cbmPerUnit($unit, null, null, $mm / 10, $tPerCbm);
    }

    /**
     * The supplier's cost per the product's reference unit for one thickness (or per CBM for
     * 50 mm and above), from a supplier-price row: supplier price + CHA + transport per unit
     * (+ packing and loading when the price does not include them). Null when the row has
     * no usable price.
     *
     * @param array<int,float> $typed the row's typed thickness prices
     * @return array{supplier: float, cha: float, packing: float, total: float, unit: string, mm: ?float, how: string, note: ?string}|null
     */
    public static function cost(array $rules, array $typed, array $row, array $product, string $basis, ?float $mm): ?array
    {
        $done = self::complete($rules, $typed);
        if (!$done['ok']) {
            return null;
        }
        $cha = self::chaPerCbm(self::chaPerContainer($row, $product), (float) $rules['container_cbm'], $rules['container_max_t'], $rules['t_per_cbm']);
        $packing = empty($row['includes_packing_loading']) ? (float) ($product['default_packing_cost'] ?? 0) + (float) ($product['default_loading_cost'] ?? 0) : 0.0;
        if ($basis === 'cbm') {
            $v = $done['values'][self::CBM] ?? null;
            if ($v === null) {
                return null;
            }
            $chaU = $cha['per_cbm'] ?? 0.0;
            return ['supplier' => $v['price'], 'cha' => $chaU, 'packing' => $packing, 'total' => $v['price'] + $chaU + $packing,
                'unit' => 'CBM', 'mm' => $mm, 'how' => $v['how'], 'note' => null];
        }
        $mm ??= (float) $rules['base_mm'];
        $v = $done['values'][(int) round($mm)] ?? null;
        if ($v === null) {
            // A thickness that is not in the rules (e.g. 25 mm): the base price, no %.
            $v = ['price' => PricingEngine::round2((float) $done['base']), 'how' => 'calculated'];
        }
        $unit = $rules['unit'] !== '' ? $rules['unit'] : 'SQM';
        $perCbm = self::m2ToCbm((float) $v['price'], $mm);
        $cbmPerUnit = self::cbmPerUnit($unit, $mm, $rules['t_per_cbm']);
        if ($cbmPerUnit === null) {
            return null;
        }
        $supplier = $perCbm * $cbmPerUnit;
        $chaU = ($cha['per_cbm'] ?? 0.0) * $cbmPerUnit;
        return ['supplier' => $supplier, 'cha' => $chaU, 'packing' => $packing, 'total' => $supplier + $chaU + $packing,
            'unit' => $unit, 'mm' => $mm, 'how' => $v['how'], 'note' => null];
    }

    /** The row's CHA + transport per container, else the product's default (₹). */
    public static function chaPerContainer(array $row, array $product): ?float
    {
        $v = $row['cha_transport_per_container'] ?? null;
        if ($v === null || $v === '') {
            $v = $product['cha_transport_per_container'] ?? null;
        }
        return $v === null || $v === '' ? null : (float) $v;
    }

    /** "35", "2.5", "−5". */
    public static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
    }

    /** "20 mm" or "50 mm and above". */
    public static function label(int $mm, int $cbmFrom = 50): string
    {
        return $mm === self::CBM ? $cbmFrom . ' mm and above' : $mm . ' mm';
    }

    // ------------------------------------------------------------- storage

    /** @return array<int,float> the typed prices of a supplier-price row */
    public static function typedFor(int $productSupplierId): array
    {
        $stmt = Database::connection()->prepare('SELECT thickness_mm, price_inr FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id ORDER BY thickness_mm');
        $stmt->execute(['id' => $productSupplierId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['thickness_mm']] = (float) $r['price_inr'];
        }
        return $out;
    }

    /**
     * Saves the typed prices of a row (replacing the old ones) and records every
     * change in the price history (who, when, old → new).
     *
     * @param array<int,float> $typed
     */
    public static function saveTyped(int $productSupplierId, array $typed, array $rules, int $userId): void
    {
        $pdo = Database::connection();
        $before = self::complete($rules, self::typedFor($productSupplierId));
        $after = self::complete($rules, $typed);
        $pdo->prepare('DELETE FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id')->execute(['id' => $productSupplierId]);
        $ins = $pdo->prepare('INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr, updated_by) VALUES (:id, :mm, :p, :u)');
        foreach ($typed as $mm => $price) {
            $ins->execute(['id' => $productSupplierId, 'mm' => (int) $mm, 'p' => number_format((float) $price, 2, '.', ''), 'u' => $userId]);
        }
        foreach ($after['values'] as $mm => $v) {
            $old = $before['values'][$mm] ?? null;
            $oldText = $old === null ? null : number_format($old['price'], 2, '.', '') . ' ' . $old['how'];
            $newText = number_format($v['price'], 2, '.', '') . ' ' . $v['how'];
            if ($oldText !== $newText) {
                self::history($productSupplierId, self::label((int) $mm, (int) $rules['cbm_from']) . ((int) $mm === self::CBM ? ' per CBM' : ' per m²'), $oldText, $newText, $userId);
            }
        }
    }

    public static function history(int $productSupplierId, string $what, ?string $old, ?string $new, int $userId): void
    {
        Database::connection()->prepare('INSERT INTO catalog_supplier_price_history (product_supplier_id, what, old_value, new_value, changed_by) VALUES (:id, :w, :o, :n, :u)')
            ->execute(['id' => $productSupplierId, 'w' => mb_substr($what, 0, 80), 'o' => $old !== null ? mb_substr($old, 0, 80) : null, 'n' => $new !== null ? mb_substr($new, 0, 80) : null, 'u' => $userId]);
    }

    /** @return list<array<string,mixed>> newest first, with the name of who changed it */
    public static function historyFor(int $productSupplierId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT h.*, u.name AS changed_by_name FROM catalog_supplier_price_history h LEFT JOIN users u ON u.id = h.changed_by
             WHERE h.product_supplier_id = :id ORDER BY h.changed_at DESC, h.id DESC'
        );
        $stmt->execute(['id' => $productSupplierId]);
        return $stmt->fetchAll();
    }
}
