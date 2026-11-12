<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision D-339 (12 Nov 2026, owner confirmed twice; mockup screen 2): the product
 * page's read-only card "Prices in other units" — for every thickness the product offers,
 * its price per CBM, per SQM and per SQFT, worked out with the existing D-270 conversion
 * (LineUnits::factor: 1 SQM = thickness × 1 m², 1 SQM = 10.7639 SQFT). Nothing is stored.
 *
 * Who sees what (existing permissions): the supplier price — people who may see purchase
 * prices; the staff quote and floor — people who see selling prices; every associate level —
 * people who see the price build-up (MD / ED). A thickness with its own typed supplier price
 * (D-335) is marked "typed" and its per-CBM figure is not shown (it is not a per-CBM price).
 * An associate sees only their own price, in the other units, on their price request.
 *
 * Node twin: src/services/unitPrices.js.
 */
final class UnitPrices
{
    public const UNITS = ['CBM' => 'CBM', 'SQM' => 'SQM', 'SQFT' => 'SQFT'];

    /**
     * A price per $unit at thickness $mm, per CBM / SQM / SQFT (2 decimals); null where it
     * cannot be worked out (e.g. per SQM of a per-CBM price without a thickness).
     *
     * @return array{CBM: ?float, SQM: ?float, SQFT: ?float}
     */
    public static function convert(?float $price, ?string $unit, ?float $mm): array
    {
        $out = ['CBM' => null, 'SQM' => null, 'SQFT' => null];
        if ($price === null || trim((string) $unit) === '') {
            return $out;
        }
        $t = $mm !== null && $mm > 0 ? $mm / 10 : null;
        foreach (array_keys($out) as $target) {
            $f = LineUnits::factor($target, $unit, null, null, $t, null);
            if ($f['factor'] !== null && !$f['unconvertible']) {
                $out[$target] = round($price * $f['factor'], 2);
            }
        }
        return $out;
    }

    /**
     * The card: one row per thickness option (or one row for a product without a Thickness
     * specification), each with the price sets the viewer may see.
     *
     * @param array{supplier: bool, staff: bool, levels: bool} $may
     * @return array{rows: list<array<string,mixed>>, sets: list<array{key: string, label: string, currency: string}>, note: ?string}
     */
    public static function card(array $product, array $may): array
    {
        $sets = [];
        if ($may['staff'] || $may['levels']) {
            $sets[] = ['key' => 'staff_quote', 'label' => 'Staff quote', 'currency' => 'USD'];
            $sets[] = ['key' => 'staff_floor', 'label' => 'Staff floor (lowest)', 'currency' => 'USD'];
        }
        $choices = self::thicknessChoices($product);
        $rows = [];
        $note = null;
        foreach ($choices as $c) {
            $p = PricingService::forProduct($product, null, null, null, $c['selection']);
            if ($p['build'] === null) {
                $rows[] = ['label' => $c['label'], 'mm' => $c['mm'], 'typed' => false, 'prices' => [], 'missing' => (string) ($p['missing'] ?? 'No price yet.')];
                continue;
            }
            $unit = (string) ($p['unit'] ?? $product['reference_unit'] ?? '');
            $mm = $c['mm'] ?? ($p['spec']['adj']['thickness_mm'] ?? null);
            $mm = $mm !== null ? (float) $mm : null;
            $typed = ($p['supplier']['price_basis'] ?? '') === 'grid' && ($p['inr_parts']['how'] ?? '') === 'typed' && $mm !== null;
            $prices = [];
            if ($may['staff'] || $may['levels']) {
                $prices['staff_quote'] = self::convert((float) $p['build']['staff_quote'], $unit, $mm);
                $prices['staff_floor'] = self::convert((float) $p['build']['staff_floor'], $unit, $mm);
            }
            if ($may['levels']) {
                foreach ($p['levels'] as $l) {
                    $k = 'level_' . $l['id'];
                    if (!in_array($k . '_quote', array_column($sets, 'key'), true)) {
                        $sets[] = ['key' => $k . '_quote', 'label' => $l['name'] . ' — quote', 'currency' => 'USD'];
                        $sets[] = ['key' => $k . '_floor', 'label' => $l['name'] . ' — lowest', 'currency' => 'USD'];
                    }
                    $prices[$k . '_quote'] = self::convert((float) $l['quote'], $unit, $mm);
                    $prices[$k . '_floor'] = self::convert((float) $l['floor'], $unit, $mm);
                }
            }
            if ($may['supplier'] && isset($p['inr_parts']['supplier'])) {
                if (!in_array('supplier', array_column($sets, 'key'), true)) {
                    $sets[] = ['key' => 'supplier', 'label' => 'Supplier price', 'currency' => 'INR'];
                }
                $prices['supplier'] = self::convert((float) $p['inr_parts']['supplier'], $unit, $mm);
            }
            if ($typed) {
                // D-335: a typed thickness price is a price per its own unit, not per CBM.
                foreach ($prices as $k => $v) {
                    $prices[$k]['CBM'] = null;
                }
            }
            $rows[] = ['label' => $c['label'], 'mm' => $mm, 'typed' => $typed, 'prices' => $prices, 'missing' => null, 'unit' => $unit];
        }
        if ($choices === []) {
            $note = 'This product has no price yet.';
        }
        return ['rows' => $rows, 'sets' => $sets, 'note' => $note];
    }

    /**
     * The thickness options of a product as selections for PricingService::forProduct() —
     * or one "Base price" choice when it offers no Thickness specification.
     *
     * @return list<array{label: string, mm: ?int, selection: ?array<int,int>}>
     */
    public static function thicknessChoices(array $product): array
    {
        foreach (ProductSpecs::offered($product) as $s) {
            if ($s['kind'] !== 'thickness') {
                continue;
            }
            $out = [];
            foreach ($s['options'] as $o) {
                $out[] = ['label' => (string) $o['name'], 'mm' => $o['thickness_mm'], 'selection' => [(int) $s['id'] => (int) $o['id']]];
            }
            return $out;
        }
        return [['label' => 'Base price', 'mm' => null, 'selection' => null]];
    }

    /** The thickness (mm) chosen in a selection (JSON {spec id: option id}), or null. */
    public static function selectionThicknessMm(array $product, ?string $selectionJson): ?float
    {
        $sel = json_decode((string) $selectionJson, true);
        if (!is_array($sel)) {
            return null;
        }
        foreach (ProductSpecs::offered($product) as $s) {
            if ($s['kind'] !== 'thickness' || !isset($sel[$s['id']]) && !isset($sel[(string) $s['id']])) {
                continue;
            }
            $chosen = (int) ($sel[$s['id']] ?? $sel[(string) $s['id']]);
            foreach ($s['options'] as $o) {
                if ((int) $o['id'] === $chosen && $o['thickness_mm'] !== null) {
                    return (float) $o['thickness_mm'];
                }
            }
        }
        return null;
    }
}
