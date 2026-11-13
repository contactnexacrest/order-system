<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SpecRepository;

/**
 * Owner decision D-255 (5 Nov 2026): the specifications that change the price.
 *
 * A category has specifications (Grade, Thickness, Finish, Size, Edge, Quantity,
 * extras) and options. Each option changes the COST — a %, a USD amount per m²,
 * per running metre or per container — or, for thickness, switches the price to
 * per CBM (50 mm and above). A product follows its category; it may hide a
 * specification or an option, change an option's value (empty = follow the
 * category, so later category changes flow through), and add options or whole
 * specifications for itself only.
 *
 * How the options change the cost (then margin, incentive, commission and buffer
 * are added as usual — PricingEngine):
 *   % options multiply the cost → fixed USD amounts are added → the quantity
 *   discount comes last. Per-m² amounts on a CBM price are converted:
 *   USD per m² × 1000 ÷ thickness (one finished face). Per-running-metre amounts
 *   go into the unit price only for products sold per running metre; per-container
 *   amounts are never in the unit price — both are shown as separate charges.
 *
 * Node twin: src/services/productSpecs.js.
 */
final class ProductSpecs
{
    public const FORMS = ['block' => 'Blocks', 'slab' => 'Slabs', 'tile' => 'Tiles', 'kerb' => 'Kerbs', 'step' => 'Steps', 'cobble' => 'Cobbles'];

    public const CHANGE_TYPES = [
        'none' => 'No change',
        'pct' => '% of the price',
        'usd_m2' => 'USD per SQM',
        'usd_rm' => 'USD per running metre',
        'usd_container' => 'USD per container',
        // D-257 Phase 0B: price options for products sold per piece, per kg or per set.
        'usd_piece' => 'USD per piece',
        'usd_kg' => 'USD per kg',
        'usd_set' => 'USD per set',
        'cbm' => 'Priced per CBM',
    ];

    /** Selection value meaning "no thickness chosen — price the supplier's BASE" (Try a price). */
    public const SUPPLIER_BASE = 'base';

    public const KINDS = ['normal' => 'Normal', 'thickness' => 'Thickness (SQM or CBM)', 'quantity' => 'Quantity (applied last)'];

    /** Units that count as "per running metre" for per-rm amounts. */
    private const RM_UNITS = ['RM', 'RMT', 'LM', 'RUNNING METRE', 'RUNNING METER'];

    /**
     * D-257 Phase 0B: the units a per-piece / per-kg / per-set amount belongs
     * to. On a product sold in that unit the amount goes into the unit price;
     * otherwise it is listed as a separate charge, like per-rm amounts.
     */
    public const UNIT_TYPES = [
        'usd_piece' => ['label' => 'piece', 'units' => ['PCS', 'NOS', 'PC', 'PIECE', 'PIECES']],
        'usd_kg' => ['label' => 'kg', 'units' => ['KG', 'KGS']],
        'usd_set' => ['label' => 'set', 'units' => ['SET', 'SETS']],
    ];

    public static function categoryIdFor(array $product): ?int
    {
        return !empty($product['category_id']) ? (int) $product['category_id'] : SpecRepository::systemCategoryId();
    }

    /** @return list<string> the product's forms; [] = all forms */
    public static function productForms(array $product): array
    {
        return self::splitForms($product['forms'] ?? null);
    }

    /** @return list<string> */
    public static function splitForms(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $f): bool => isset(self::FORMS[$f])));
    }

    public static function formsLabel(?string $value): string
    {
        $forms = self::splitForms($value);
        return $forms === [] ? 'all' : implode(', ', array_map(static fn (string $f): string => strtolower(self::FORMS[$f]), $forms));
    }

    /** Whether a specification applies to a product with these forms. */
    public static function appliesTo(?string $appliesTo, array $productForms): bool
    {
        $specForms = self::splitForms($appliesTo);
        if ($specForms === [] || $productForms === []) {
            return true;
        }
        return array_intersect($specForms, $productForms) !== [];
    }

    /**
     * The product's specifications, with the source of each value.
     *
     * Each spec: id, name, kind, applies_to, source ('category'|'product'), hidden, changed,
     * options: [id, name, is_base, change_type, value (effective), category_value, thickness_mm,
     * source ('category'|'changed'|'product'), hidden].
     *
     * @return list<array<string,mixed>>
     */
    public static function tree(array $product, bool $includeHidden = false): array
    {
        $productId = (int) $product['id'];
        $categoryId = self::categoryIdFor($product);
        $forms = self::productForms($product);
        $specs = [];
        foreach ($categoryId !== null ? SpecRepository::categorySpecs($categoryId, true) : [] as $s) {
            if (self::appliesTo($s['applies_to'], $forms)) {
                $s['source'] = 'category';
                $specs[] = $s;
            }
        }
        foreach (SpecRepository::productOnlySpecs($productId) as $s) {
            if ((int) $s['is_active'] === 1) {
                $s['source'] = 'product';
                $specs[] = $s;
            }
        }
        $hiddenSpecs = SpecRepository::hiddenSpecs($productId);
        $overrides = SpecRepository::optionOverrides($productId);
        $options = SpecRepository::optionsFor(array_map(static fn (array $s): int => (int) $s['id'], $specs), $productId);
        $out = [];
        foreach ($specs as $s) {
            $sid = (int) $s['id'];
            $hidden = isset($hiddenSpecs[$sid]);
            if ($hidden && !$includeHidden) {
                continue;
            }
            $opts = [];
            $changed = false;
            foreach ($options[$sid] ?? [] as $o) {
                if ((int) $o['is_active'] !== 1) {
                    continue;
                }
                $oid = (int) $o['id'];
                $own = $o['product_id'] !== null;
                $ov = $overrides[$oid] ?? null;
                $oHidden = !$own && $ov !== null && $ov['is_hidden'] === 1;
                $oValue = !$own && $ov !== null && $ov['value'] !== null ? (float) $ov['value'] : ($o['value'] !== null ? (float) $o['value'] : null);
                $source = $own ? 'product' : (($ov !== null && $ov['value'] !== null) ? 'changed' : 'category');
                if ($own || $oHidden || $source === 'changed') {
                    $changed = true;
                }
                if ($oHidden && !$includeHidden) {
                    continue;
                }
                $opts[] = [
                    'id' => $oid, 'name' => (string) $o['name'], 'is_base' => (int) $o['is_base'] === 1,
                    'change_type' => (string) $o['change_type'], 'value' => $oValue,
                    'category_value' => $own ? null : ($o['value'] !== null ? (float) $o['value'] : null),
                    'thickness_mm' => $o['thickness_mm'] !== null ? (int) $o['thickness_mm'] : null,
                    'source' => $source, 'hidden' => $oHidden,
                ];
            }
            $out[] = [
                'id' => $sid, 'name' => (string) $s['name'], 'kind' => (string) $s['kind'], 'applies_to' => $s['applies_to'],
                'source' => (string) $s['source'], 'hidden' => $hidden, 'changed' => $changed || $hidden, 'options' => $opts,
                'list_key' => $s['list_key'] ?? null, // D-357: 'surface_finish' = the names come from Settings › Lists
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> the specifications a buyer can choose from (no hidden ones, at least one option) */
    public static function offered(array $product): array
    {
        return array_values(array_filter(self::tree($product), static fn (array $s): bool => $s['options'] !== []));
    }

    /**
     * Checks a choice of options against what the product offers. A specification left
     * out takes its base option (or its first option).
     *
     * @param array<int|string, mixed> $raw spec id => option id
     * @return array{0: array<int,int>, 1: list<string>} [spec id => option id, errors]
     */
    public static function normalize(array $offered, array $raw): array
    {
        $map = [];
        $errors = [];
        foreach ($offered as $s) {
            $sid = $s['id'];
            $chosen = $raw[$sid] ?? $raw[(string) $sid] ?? null;
            // "Try a price": the supplier's base price — the thickness is left out, so the
            // supplier row's own BASE is priced (D-335), not the category's base option.
            if ($chosen === self::SUPPLIER_BASE && $s['kind'] === 'thickness') {
                continue;
            }
            if ($chosen === null || $chosen === '' || $chosen === self::SUPPLIER_BASE) {
                $base = null;
                foreach ($s['options'] as $o) {
                    if ($o['is_base']) {
                        $base = $o['id'];
                        break;
                    }
                }
                $map[$sid] = $base ?? $s['options'][0]['id'];
                continue;
            }
            $ok = false;
            foreach ($s['options'] as $o) {
                if ((string) $o['id'] === (string) $chosen) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = "{$s['name']}: that option is not offered for this product.";
                continue;
            }
            $map[$sid] = (int) $chosen;
        }
        return [$map, $errors];
    }

    /** The chosen options in words, base options left out, e.g. "Standard · 30 mm · Leather". */
    public static function describe(array $offered, array $map, bool $includeBase = false): string
    {
        $parts = [];
        foreach ($offered as $s) {
            foreach ($s['options'] as $o) {
                if (($map[$s['id']] ?? null) === $o['id'] && ($includeBase || !$o['is_base'])) {
                    $parts[] = $o['name'];
                }
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * What the chosen options do to the cost.
     *
     * @return array{basis: string, unit: string, thickness_mm: ?int, pct: list<float>, usd: float, qty_pct: float,
     *               lines: list<array{spec:string, option:string, kind:string, value:float, usd_effect: ?float}>, charges: list<string>, manual: ?string}
     */
    public static function adjustments(array $product, array $offered, array $map, int $cbmFrom, array $exactSpecIds = [], ?float $lineThicknessMm = null): array
    {
        $out = ['basis' => 'unit', 'unit' => (string) ($product['reference_unit'] ?? '') ?: 'unit', 'thickness_mm' => null,
            'pct' => [], 'usd' => 0.0, 'qty_pct' => 0.0, 'lines' => [], 'charges' => [], 'manual' => null];
        $chosen = [];
        foreach ($offered as $s) {
            foreach ($s['options'] as $o) {
                if (($map[$s['id']] ?? null) === $o['id']) {
                    $chosen[] = [$s, $o];
                }
            }
        }
        $cbmFrom = (int) ($product['cbm_from_mm'] ?? 0) > 0 ? (int) $product['cbm_from_mm'] : $cbmFrom;
        foreach ($chosen as [$s, $o]) {
            if ($s['kind'] !== 'thickness') {
                continue;
            }
            $out['thickness_mm'] = $o['thickness_mm'];
            if ($o['change_type'] === 'cbm' || ($o['thickness_mm'] !== null && $o['thickness_mm'] >= $cbmFrom)) {
                $out['basis'] = 'cbm';
                $out['unit'] = 'CBM';
                // D-270 (point 25): the line's own thickness (e.g. 13 cm) on a "50 mm and above" option
                // is thick stone priced per CBM — no manual price needed.
                if ($o['thickness_mm'] === null && $lineThicknessMm !== null && $lineThicknessMm >= $cbmFrom) {
                    $out['thickness_mm'] = $lineThicknessMm;
                }
                if ($out['thickness_mm'] === null) {
                    $out['manual'] = 'A thickness of ' . $cbmFrom . ' mm or more that is not listed needs a manual price.';
                }
            }
        }
        $isRm = in_array(strtoupper(trim((string) ($product['reference_unit'] ?? ''))), self::RM_UNITS, true);
        foreach ($chosen as [$s, $o]) {
            $v = (float) ($o['value'] ?? 0);
            $type = $o['change_type'];
            // D-256: an exact supplier price already covers the options it names.
            if (in_array((int) $s['id'], array_map('intval', $exactSpecIds), true)) {
                if ($type !== 'none' || !$o['is_base']) {
                    $out['lines'][] = ['spec' => $s['name'], 'option' => $o['name'], 'kind' => 'exact', 'value' => 0.0, 'usd_effect' => null];
                }
                continue;
            }
            // D-256: an option worth 0 is included in the base price.
            if (in_array($type, ['pct', 'usd_m2', 'usd_rm', 'usd_container', 'usd_piece', 'usd_kg', 'usd_set'], true) && $v == 0.0) {
                $out['lines'][] = ['spec' => $s['name'], 'option' => $o['name'], 'kind' => 'included', 'value' => 0.0, 'usd_effect' => null];
                continue;
            }
            if ($type === 'none' || $type === 'cbm' || $v == 0.0) {
                continue;
            }
            // On a CBM price, a thickness % (a per-m² rule) no longer applies.
            if ($type === 'pct' && $s['kind'] === 'thickness' && $out['basis'] === 'cbm') {
                continue;
            }
            $line = ['spec' => $s['name'], 'option' => $o['name'], 'kind' => $type, 'value' => $v, 'usd_effect' => null];
            if ($type === 'pct') {
                if ($s['kind'] === 'quantity') {
                    $out['qty_pct'] += $v;
                } else {
                    $out['pct'][] = $v;
                }
            } elseif ($type === 'usd_m2') {
                if ($out['basis'] === 'cbm') {
                    if ($out['thickness_mm'] === null || $out['thickness_mm'] <= 0) {
                        $out['manual'] ??= 'A per-SQM option on a thickness that is not listed needs a manual price.';
                        continue;
                    }
                    $conv = $v * 1000 / $out['thickness_mm'];
                    $line['usd_effect'] = $conv;
                    $line['converted'] = true;
                    $out['usd'] += $conv;
                } else {
                    $line['usd_effect'] = $v;
                    $out['usd'] += $v;
                }
            } elseif ($type === 'usd_rm') {
                if ($isRm) {
                    $line['usd_effect'] = $v;
                    $out['usd'] += $v;
                } else {
                    $out['charges'][] = $o['name'] . ': USD ' . number_format($v, 2) . ' per running metre, charged separately';
                    continue;
                }
            } elseif ($type === 'usd_container') {
                $out['charges'][] = $o['name'] . ': USD ' . number_format($v, 2) . ' per container, charged separately';
                continue;
            } elseif (isset(self::UNIT_TYPES[$type])) {
                $unitType = self::UNIT_TYPES[$type];
                if (in_array(strtoupper(trim((string) ($product['reference_unit'] ?? ''))), $unitType['units'], true)) {
                    $line['usd_effect'] = $v;
                    $out['usd'] += $v;
                } else {
                    $out['charges'][] = $o['name'] . ': USD ' . number_format($v, 2) . ' per ' . $unitType['label'] . ', charged separately';
                    continue;
                }
            }
            $out['lines'][] = $line;
        }
        return $out;
    }

    /** "−8%", "+USD 4.00 per m²" … for the admin pages. */
    public static function changeLabel(string $type, ?float $value): string
    {
        if ($type === 'none') {
            return 'base';
        }
        if ($type === 'cbm') {
            return 'priced per CBM';
        }
        $v = (float) $value;
        if ($v == 0.0) {
            return 'Included in base price'; // D-256
        }
        $sign = $v < 0 ? '−' : '+';
        if ($type === 'pct') {
            return $sign . rtrim(rtrim(number_format(abs($v), 2, '.', ''), '0'), '.') . '%';
        }
        $per = ['usd_m2' => '/SQM', 'usd_rm' => '/rm', 'usd_container' => '/container', 'usd_piece' => '/piece', 'usd_kg' => '/kg', 'usd_set' => '/set'][$type] ?? '';
        return $sign . '$' . rtrim(rtrim(number_format(abs($v), 2, '.', ''), '0'), '.') . $per;
    }

    /**
     * Validates a specification form.
     *
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    public static function readSpecForm(array $post): array
    {
        $errors = [];
        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = 'Give the specification a name (up to 80 characters).';
        }
        $kind = (string) ($post['kind'] ?? 'normal');
        if (!isset(self::KINDS[$kind])) {
            $errors[] = 'Choose what kind of specification this is.';
            $kind = 'normal';
        }
        $forms = [];
        foreach ((array) ($post['applies_to'] ?? []) as $f) {
            if (isset(self::FORMS[(string) $f])) {
                $forms[] = (string) $f;
            }
        }
        $order = filter_var($post['sort_order'] ?? '0', FILTER_VALIDATE_INT);
        if ($order === false || $order < 0 || $order > 999) {
            $errors[] = 'Order: a whole number from 0 to 999.';
            $order = 0;
        }
        return [['name' => $name, 'kind' => $kind, 'applies_to' => $forms === [] ? null : implode(',', $forms), 'sort_order' => $order], $errors];
    }

    /**
     * Validates an option form.
     *
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    public static function readOptionForm(array $post): array
    {
        $errors = [];
        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors[] = 'Give the option a name (up to 80 characters).';
        }
        $type = (string) ($post['change_type'] ?? 'none');
        if (!isset(self::CHANGE_TYPES[$type])) {
            $errors[] = 'Choose how the option changes the price.';
            $type = 'none';
        }
        $value = null;
        if (!in_array($type, ['none', 'cbm'], true)) {
            $v = trim((string) ($post['value'] ?? ''));
            if ($v === '' || !preg_match('/^-?\d+(\.\d{1,4})?$/', $v) || (float) $v == 0.0) {
                $errors[] = 'Enter the change as a number other than 0 (e.g. -8 for −8%, or 4.00 for +USD 4.00), with at most 4 decimals.';
            } elseif ($type === 'pct' && ((float) $v <= -100 || (float) $v > 500)) {
                $errors[] = 'A % change must be above −100 and at most 500.';
            } elseif ($type !== 'pct' && abs((float) $v) > 1000000) {
                $errors[] = 'That amount is too large.';
            } else {
                $value = number_format((float) $v, 4, '.', '');
            }
        }
        $mm = null;
        $t = trim((string) ($post['thickness_mm'] ?? ''));
        if ($t !== '') {
            $mmv = filter_var($t, FILTER_VALIDATE_INT);
            if ($mmv === false || $mmv < 1 || $mmv > 2000) {
                $errors[] = 'Thickness: whole millimetres from 1 to 2000, or leave it empty.';
            } else {
                $mm = $mmv;
            }
        }
        $isBase = !empty($post['is_base']) ? 1 : 0;
        if ($isBase === 1 && !in_array($type, ['none', 'cbm'], true)) {
            $errors[] = 'The base option changes nothing — set its change to "No change".';
        }
        $order = filter_var($post['sort_order'] ?? '0', FILTER_VALIDATE_INT);
        return [['name' => $name, 'change_type' => $type, 'value' => $value, 'thickness_mm' => $mm, 'is_base' => $isBase,
            'sort_order' => $order === false ? 0 : max(0, min(999, $order))], $errors];
    }
}
