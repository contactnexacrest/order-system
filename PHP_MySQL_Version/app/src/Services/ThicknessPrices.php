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
 *
 * Owner decision D-335 (12 Nov 2026) — THE ONE RULE:
 *   1. a price the user TYPED always wins — no rule ever changes it;
 *   2. each supplier price row has a BASE thickness the user chose
 *      (catalog_product_suppliers.base_thickness_mm; 0 = 50 mm and above per CBM,
 *      NULL = the category's base thickness): the product-level price and order
 *      lines with no thickness use it;
 *   3. the rules are only a CALCULATOR: a thickness the user ticks while adding a
 *      price is STORED as CALCULATED ("from 20 mm") and can be typed over; nothing
 *      is recalculated in the background.
 * Rows saved before D-335 keep auto_fill = 1: a thickness without a stored price is
 * still worked out on the fly from the typed ones (so no existing price changes).
 * With auto_fill = 0 only stored prices exist; a thickness without one has "no
 * price" (an order line for it goes to the MD).
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
        $cbm = $num($product['container_cbm'] ?? null);
        $maxT = $num($product['container_max_t'] ?? null);
        if ($cbm !== null || $maxT !== null) {
            $own[] = 'container';
        }
        // D-359: product → category → global (Pricing settings) — PricingLevels; a product that
        // follows global skips its category.
        $levels = PricingLevels::resolve($product, $settings, !empty($product['pricing_follow_global']) ? null : $category);
        $k ??= $levels['thick_k_factor']['value'];
        $cbm ??= $levels['container_cbm']['value'] ?? self::DEFAULT_CONTAINER_CBM;
        $maxT ??= $levels['container_max_t']['value'] ?? self::DEFAULT_CONTAINER_MAX_T;
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
            'container_cbm' => $cbm, 'container_max_t' => $maxT, 't_per_cbm' => $levels['weight_t_per_cbm']['value'],
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
        $auto = self::autoFill($row);
        $done = self::values($rules, $typed, $auto);
        if (!$done['ok']) {
            return null;
        }
        $cha = self::chaPerCbm(self::chaPerContainer($row, $product), (float) $rules['container_cbm'], $rules['container_max_t'], $rules['t_per_cbm']);
        $packing = empty($row['includes_packing_loading']) ? (float) ($product['default_packing_cost'] ?? 0) + (float) ($product['default_loading_cost'] ?? 0) : 0.0;
        // D-335: the row's BASE (the user's choice) is what the product-level price uses.
        $baseMm = self::rowBase($row);
        if ($basis === 'cbm' || ($mm === null && $baseMm === self::CBM)) {
            $v = $done['values'][self::CBM] ?? null;
            if ($v === null) {
                return null;
            }
            $chaU = $cha['per_cbm'] ?? 0.0;
            if ($basis === 'cbm') {
                // D-338 (2b): a CALCULATED per-CBM price — from which thinner price it was worked out
                // (the stored calc_from_mm, else the row's base) — needs the MD's approval on an order line.
                return ['supplier' => $v['price'], 'cha' => $chaU, 'packing' => $packing, 'total' => $v['price'] + $chaU + $packing,
                    'unit' => 'CBM', 'mm' => $mm, 'how' => $v['how'], 'note' => null,
                    'calc_from_mm' => $v['how'] === 'calculated' ? (int) ($v['calc_from_mm'] ?? self::baseOf($row, $rules)) : null,
                    'cbm_from' => (int) $rules['cbm_from']];
            }
            // The base is the per-CBM price ("50 mm and above"): in the product's unit.
            $unit = $rules['unit'] !== '' ? $rules['unit'] : 'CBM';
            $per = self::cbmPerUnit($unit, (float) $rules['cbm_from'], $rules['t_per_cbm']);
            if ($per === null) {
                return null;
            }
            $supplier = (float) $v['price'] * $per;
            return ['supplier' => $supplier, 'cha' => $chaU * $per, 'packing' => $packing, 'total' => $supplier + $chaU * $per + $packing,
                'unit' => $unit, 'mm' => null, 'how' => $v['how'], 'note' => null, 'base' => self::CBM];
        }
        $mm ??= (float) ($baseMm ?? $rules['base_mm']);
        $v = $done['values'][(int) round($mm)] ?? null;
        if ($v === null) {
            if (!$auto) {
                // D-335: no stored price for this thickness — "no price" (the MD prices the line).
                return null;
            }
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

    // ------------------------------------------------------------- D-335: typed wins, a base, a calculator

    /** Rows saved before D-335 (auto_fill = 1, or no such field) still complete missing thicknesses on the fly. */
    public static function autoFill(array $row): bool
    {
        return !array_key_exists('auto_fill', $row) || $row['auto_fill'] === null || (int) $row['auto_fill'] === 1;
    }

    /** The row's BASE thickness the user chose (0 = 50 mm and above per CBM); null = the category's base. */
    public static function rowBase(array $row): ?int
    {
        $v = $row['base_thickness_mm'] ?? null;
        return $v === null || $v === '' ? null : (int) $v;
    }

    /** The base thickness in effect for a row (its own, else the category's). */
    public static function baseOf(array $row, array $rules): int
    {
        return self::rowBase($row) ?? (int) $rules['base_mm'];
    }

    /**
     * Stored prices in one shape: thickness_mm => [price, how, calc_from_mm, quoted_on, since].
     * A plain price (float) counts as TYPED.
     *
     * @param array<int, float|array<string,mixed>> $stored
     * @return array<int, array{price: float, how: string, calc_from_mm: ?int, quoted_on: ?string, since: ?string}>
     */
    public static function normalise(array $stored): array
    {
        $out = [];
        foreach ($stored as $mm => $s) {
            if (is_array($s)) {
                if ((float) ($s['price'] ?? 0) <= 0) {
                    continue;
                }
                $out[(int) $mm] = ['price' => (float) $s['price'], 'how' => ($s['how'] ?? 'typed') === 'calculated' ? 'calculated' : 'typed',
                    'calc_from_mm' => isset($s['calc_from_mm']) && $s['calc_from_mm'] !== '' ? (int) $s['calc_from_mm'] : null,
                    'quoted_on' => !empty($s['quoted_on']) ? substr((string) $s['quoted_on'], 0, 10) : null,
                    'since' => !empty($s['since']) ? substr((string) $s['since'], 0, 10) : null];
            } elseif ($s !== null && (float) $s > 0) {
                $out[(int) $mm] = ['price' => (float) $s, 'how' => 'typed', 'calc_from_mm' => null, 'quoted_on' => null, 'since' => null];
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Every thickness of a row as it is used: with auto_fill the old on-the-fly completion
     * (stored prices keep how they were set); without, only the stored prices.
     *
     * @param array<int, float|array<string,mixed>> $stored
     * @return array{ok: bool, error: ?string, base: ?float, values: array<int, array<string,mixed>>}
     */
    public static function values(array $rules, array $stored, bool $autoFill): array
    {
        $norm = self::normalise($stored);
        if ($autoFill) {
            $done = self::complete($rules, array_map(static fn (array $s): float => $s['price'], $norm));
            foreach ($done['values'] as $mm => &$v) {
                $v += ['calc_from_mm' => null, 'quoted_on' => null, 'since' => null, 'auto' => !isset($norm[$mm])];
                if (isset($norm[$mm])) {
                    $v = array_merge($v, $norm[$mm]);
                }
            }
            unset($v);
            return $done;
        }
        $values = [];
        foreach ($norm as $mm => $s) {
            $values[$mm] = ['price' => PricingEngine::round2($s['price']), 'how' => $s['how'], 'calc' => PricingEngine::round2($s['price']),
                'rule' => $s['how'] === 'calculated' && $s['calc_from_mm'] !== null ? 'from ' . self::label($s['calc_from_mm'], (int) $rules['cbm_from']) : '',
                'auto' => false] + $s;
        }
        return ['ok' => $values !== [], 'error' => $values === [] ? 'Enter at least one price.' : null, 'base' => null, 'values' => $values];
    }

    /** "4,600" or "4,600.50" — a short amount for the "how" texts. */
    public static function amount(float $v): string
    {
        $v = PricingEngine::round2($v);
        return abs($v - round($v)) < 0.005 ? number_format($v, 0) : number_format($v, 2);
    }

    /** Every thickness the rules know, in display order (the thicknesses, then 50 mm and above). @return list<int> */
    public static function thicknesses(array $rules): array
    {
        $out = array_map(static fn (array $r): int => (int) $r['mm'], $rules['rows']);
        if ($rules['k'] !== null && (float) $rules['k'] > 0) {
            $out[] = self::CBM;
        }
        return $out;
    }

    /**
     * D-335: the calculator — every OTHER thickness worked out from one entered price, with
     * how it was worked out ("= 4,600 − 20 %") and the rule ("−20 %"). From a price that is not
     * the base thickness, the base equivalent is worked back first (50 mm+: CBM ÷ K).
     *
     * @return array{ok: bool, error: ?string, base: ?float,
     *               values: array<int, array{price: float, calc_from_mm: int, how: string, change: string}>}
     */
    public static function calcFrom(array $rules, int $fromMm, float $price): array
    {
        $pct = [];
        foreach ($rules['rows'] as $r) {
            $pct[(int) $r['mm']] = (float) $r['pct'];
        }
        $k = $rules['k'] !== null ? (float) $rules['k'] : null;
        $baseMm = (int) $rules['base_mm'];
        $cbmFrom = (int) $rules['cbm_from'];
        if ($price <= 0) {
            return ['ok' => false, 'error' => 'Enter the price (above 0).', 'base' => null, 'values' => []];
        }
        if ($fromMm === self::CBM) {
            if ($k === null || $k <= 0) {
                return ['ok' => false, 'error' => 'This product has no "50 mm and above" rule.', 'base' => null, 'values' => []];
            }
            $base = $price / $k;
            $back = self::amount($price) . ' per CBM ÷ ' . self::fmt($k);
        } elseif (array_key_exists($fromMm, $pct)) {
            $f = 1 + $pct[$fromMm] / 100;
            if ($f <= 0) {
                return ['ok' => false, 'error' => 'The rule for this thickness cannot be worked back.', 'base' => null, 'values' => []];
            }
            $base = $price / $f;
            $back = $fromMm === $baseMm ? '' : self::amount($price) . ' ÷ ' . self::fmt($f);
        } else {
            return ['ok' => false, 'error' => 'Choose a thickness from the list.', 'base' => null, 'values' => []];
        }
        $base = PricingEngine::round2($base);
        $root = $fromMm === $baseMm ? self::amount($price) : $baseMm . ' mm price ₹' . self::amount($base) . ' (= ' . $back . ')';
        $values = [];
        foreach ($pct as $mm => $p) {
            if ($mm === $fromMm) {
                continue;
            }
            $change = $mm === $baseMm ? '' : ($p < 0 ? '−' : '+') . self::fmt(abs($p)) . ' %';
            $values[$mm] = ['price' => PricingEngine::round2($base * (1 + $p / 100)), 'calc_from_mm' => $fromMm,
                'how' => '= ' . $root . ($change !== '' ? ' ' . ($p < 0 ? '− ' : '+ ') . self::fmt(abs($p)) . ' %' : ''), 'change' => $change];
        }
        if ($k !== null && $k > 0 && $fromMm !== self::CBM) {
            $values[self::CBM] = ['price' => PricingEngine::round2($base * $k), 'calc_from_mm' => $fromMm,
                'how' => '= ' . $baseMm . ' mm price ₹' . self::amount($base) . ' per m² × ' . self::fmt($k) . ($fromMm !== $baseMm ? ' (' . $baseMm . ' mm = ' . $back . ')' : ''),
                'change' => '× ' . self::fmt($k)];
        }
        return ['ok' => true, 'error' => null, 'base' => $base, 'values' => $values];
    }

    /** "3,680.00" — a price for the history. */
    private static function priceText(int $mm, float $price): string
    {
        return number_format($price, 2);
    }

    private static function what(int $mm, int $cbmFrom): string
    {
        return self::label($mm, $cbmFrom) . ($mm === self::CBM ? ' per CBM' : ' per m²');
    }

    /**
     * D-335: "Add / update a price" — saves the TYPED thickness, the TICKED ones as CALCULATED
     * (from the typed one), leaves the unticked ones as they are, sets the base when asked and
     * switches the row to stored prices only (auto_fill 0). A row saved before D-335 first keeps
     * what it showed for every thickness (stored as CALCULATED), so nothing changes unasked.
     * Every change goes into the price history. Returns an error or null.
     *
     * @param list<int> $ticked
     */
    public static function addPrice(array $row, array $rules, int $fromMm, float $price, string $quotedOn, array $ticked, bool $makeBase, int $userId): ?string
    {
        $id = (int) $row['id'];
        $calc = self::calcFrom($rules, $fromMm, $price);
        if (!$calc['ok']) {
            return $calc['error'];
        }
        $pdo = Database::connection();
        $stored = self::storedFor($id);
        $before = self::values($rules, $stored, self::autoFill($row));
        $up = $pdo->prepare('INSERT INTO catalog_supplier_thickness_prices (product_supplier_id, thickness_mm, price_inr, how, calc_from_mm, quoted_on, updated_by)
            VALUES (:id, :mm, :p, :how, :cf, :q, :u)
            ON DUPLICATE KEY UPDATE price_inr = VALUES(price_inr), how = VALUES(how), calc_from_mm = VALUES(calc_from_mm), quoted_on = VALUES(quoted_on), updated_by = VALUES(updated_by)');
        $put = static function (int $mm, float $p, string $how, ?int $from, ?string $q) use ($up, $id, $userId): void {
            $up->execute(['id' => $id, 'mm' => $mm, 'p' => number_format($p, 2, '.', ''), 'how' => $how, 'cf' => $from, 'q' => $q, 'u' => $userId]);
        };
        $pdo->beginTransaction();
        try {
            if (self::autoFill($row) && $before['ok']) {
                $typedMms = array_keys(array_filter(self::normalise($stored), static fn (array $s): bool => $s['how'] === 'typed'));
                $from = in_array((int) $rules['base_mm'], $typedMms, true) ? (int) $rules['base_mm'] : ($typedMms[0] ?? null);
                foreach ($before['values'] as $mm => $v) {
                    if (!isset($stored[$mm])) {
                        $put((int) $mm, (float) $v['price'], 'calculated', $from, null);
                    }
                }
            }
            $put($fromMm, $price, 'typed', null, $quotedOn);
            foreach ($ticked as $mm) {
                if (isset($calc['values'][$mm])) {
                    $put((int) $mm, (float) $calc['values'][$mm]['price'], 'calculated', $fromMm, null);
                }
            }
            $oldBase = self::rowBase($row);
            $pdo->prepare('UPDATE catalog_product_suppliers SET auto_fill = 0' . ($makeBase ? ', base_thickness_mm = :b' : '') . ',
                quoted_on = CASE WHEN quoted_on IS NULL OR quoted_on < :q THEN :q2 ELSE quoted_on END WHERE id = :id')
                ->execute(($makeBase ? ['b' => $fromMm] : []) + ['q' => $quotedOn, 'q2' => $quotedOn, 'id' => $id]);
            $after = self::values($rules, self::storedFor($id), false);
            $cbmFrom = (int) $rules['cbm_from'];
            $keys = array_keys($after['values']);
            usort($keys, static fn (int $a, int $b): int => [$a === self::CBM ? 1 : 0, $a] <=> [$b === self::CBM ? 1 : 0, $b]);
            foreach ($keys as $mm) {
                $v = $after['values'][$mm];
                $old = $before['values'][$mm] ?? null;
                $changed = $old === null || abs((float) $old['price'] - (float) $v['price']) > 0.005 || $old['how'] !== $v['how']
                    || ($v['how'] === 'typed' && (int) $mm === $fromMm);
                if ($changed) {
                    self::history($id, self::what((int) $mm, $cbmFrom),
                        $old === null ? '—' : self::priceText((int) $mm, (float) $old['price']) . ' ' . $old['how'],
                        self::priceText((int) $mm, (float) $v['price']) . ' ' . $v['how'] . ($v['how'] === 'calculated' && $v['calc_from_mm'] !== null ? ' from ' . self::label((int) $v['calc_from_mm'], $cbmFrom) : ''),
                        $userId);
                }
            }
            if ($makeBase && $oldBase !== $fromMm) {
                self::history($id, 'Base', $oldBase === null ? self::label((int) $rules['base_mm'], $cbmFrom) . ' (category)' : self::label($oldBase, $cbmFrom), self::label($fromMm, $cbmFrom), $userId);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return null;
    }

    /** D-335: make one thickness (that has a price) the row's BASE for orders. Returns an error or null. */
    public static function setBase(array $row, array $rules, int $mm, int $userId): ?string
    {
        $done = self::values($rules, self::storedFor((int) $row['id']), self::autoFill($row));
        if (!isset($done['values'][$mm])) {
            return 'That thickness has no price yet — add one first.';
        }
        $old = self::rowBase($row);
        if ($old === $mm) {
            return null;
        }
        Database::connection()->prepare('UPDATE catalog_product_suppliers SET base_thickness_mm = :b WHERE id = :id')->execute(['b' => $mm, 'id' => (int) $row['id']]);
        $cbmFrom = (int) $rules['cbm_from'];
        self::history((int) $row['id'], 'Base', $old === null ? self::label((int) $rules['base_mm'], $cbmFrom) . ' (category)' : self::label($old, $cbmFrom), self::label($mm, $cbmFrom), $userId);
        return null;
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
        $stmt = Database::connection()->prepare("SELECT thickness_mm, price_inr FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id AND how = 'typed' ORDER BY thickness_mm");
        $stmt->execute(['id' => $productSupplierId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['thickness_mm']] = (float) $r['price_inr'];
        }
        return $out;
    }

    /**
     * D-335: every stored price of a row (TYPED and CALCULATED) with how it was set.
     *
     * @return array<int, array{price: float, how: string, calc_from_mm: ?int, quoted_on: ?string, since: ?string}>
     */
    public static function storedFor(int $productSupplierId): array
    {
        $stmt = Database::connection()->prepare('SELECT thickness_mm, price_inr, how, calc_from_mm, quoted_on, updated_at FROM catalog_supplier_thickness_prices WHERE product_supplier_id = :id ORDER BY thickness_mm');
        $stmt->execute(['id' => $productSupplierId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['thickness_mm']] = ['price' => (float) $r['price_inr'], 'how' => (string) $r['how'],
                'calc_from_mm' => $r['calc_from_mm'] !== null ? (int) $r['calc_from_mm'] : null,
                'quoted_on' => $r['quoted_on'] !== null ? (string) $r['quoted_on'] : null, 'since' => substr((string) $r['updated_at'], 0, 10)];
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
