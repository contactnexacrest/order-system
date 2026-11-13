<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\IncentiveRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductSupplierRepository;

/**
 * Owner decision D-245 (4 Nov 2026), P1: reads the pricing settings and a
 * product's overrides, picks the supplier price "used for pricing", and runs
 * PricingEngine. Who may see what is decided here too:
 *  - view_price_buildup (MD, ED): cost, margin, minimum, reserve, every floor;
 *  - anyone who may see selling prices: only the quote price and their lowest price;
 *  - record_market_prices (Procurement): supplier prices only, never selling prices.
 * Node twin: src/services/pricingService.js.
 */
final class PricingService
{
    public const DEFAULTS = [
        // SUI-006 (QA 13 Nov 2026): the same as the installed values (seed.sql, D-347) — used only when a row is missing.
        'margin_pct' => '15.00',
        'other_cost_pct' => '5.50',
        'pricing_fx_rate' => '82.0000',
        'buffer_pct' => '3.00',
        'incentive_mode' => 'pct',
        'incentive_value' => '15.0000',
        'stale_days' => '60',
        'require_product_pick' => '1',
        // D-255 (5 Nov 2026)
        'cbm_from_mm' => '50',
        'price_request_daily_limit' => '10',
        'price_request_valid_days' => '7',
        // D-301 (11 Nov 2026): the exchange rate is fetched daily; pricing rate = market × (1 − cushion %).
        'fx_mode' => 'auto',
        'fx_cushion_pct' => '2.0',
        // D-359 (13 Nov 2026): global physical defaults — used when neither the product nor its category has a value.
        'weight_t_per_cbm' => '2.70',
        'container_max_t' => '27.00',
        'container_cbm' => '8.00',
        'thick_k_factor' => '40',
    ];

    /** Labels for the settings page and the audit log. */
    public const LABELS = [
        'margin_pct' => 'Minimum margin (% of price)',
        'other_cost_pct' => 'Other costs to FOB (% of supplier price)',
        'pricing_fx_rate' => 'Pricing exchange rate (₹ per USD)',
        'buffer_pct' => 'Negotiating buffer (%)',
        'incentive_mode' => 'Team incentive reserve type',
        'incentive_value' => 'Team incentive reserve value',
        'stale_days' => 'Supplier price is stale after (days)',
        'require_product_pick' => 'Goods lines must be picked from Products',
        'cbm_from_mm' => 'Price per CBM from (mm, this thickness and above)',
        'price_request_daily_limit' => 'Associate price requests per day',
        'price_request_valid_days' => 'An associate\'s price stays valid for (days)',
        'fx_mode' => 'Exchange rate: Automatic (fetched daily) or Manual',
        'fx_cushion_pct' => 'Cushion below the market rate (%)',
        // D-361 (13 Nov 2026, mockup p5): the global physical defaults.
        'weight_t_per_cbm' => 'Weight, tonnes per CBM',
        'container_max_t' => 'Container max tonnes',
        'container_cbm' => 'Container CBM',
        'thick_k_factor' => 'Thickness factor (50 mm and above per CBM = base per SQM × this)',
    ];

    /** @return array{margin_pct: float, other_cost_pct: float, pricing_fx_rate: float, buffer_pct: float, incentive_mode: string, incentive_value: float, stale_days: int} */
    public static function settings(): array
    {
        $raw = PricingRepository::settings() + self::DEFAULTS;
        return [
            'margin_pct' => (float) $raw['margin_pct'],
            'other_cost_pct' => (float) $raw['other_cost_pct'],
            // D-301: the rate in use — the fetched market rate less the cushion (Automatic), or the typed one (Manual).
            'pricing_fx_rate' => FxRateService::rate($raw),
            'pricing_fx_rate_manual' => (float) $raw['pricing_fx_rate'],
            'buffer_pct' => (float) $raw['buffer_pct'],
            'incentive_mode' => $raw['incentive_mode'] === 'usd' ? 'usd' : 'pct',
            'incentive_value' => (float) $raw['incentive_value'],
            'stale_days' => max(1, (int) $raw['stale_days']),
            'require_product_pick' => (string) $raw['require_product_pick'] === '1',
            'cbm_from_mm' => max(1, (int) $raw['cbm_from_mm']),
            'price_request_daily_limit' => max(0, (int) $raw['price_request_daily_limit']),
            'price_request_valid_days' => max(1, (int) $raw['price_request_valid_days']),
            // D-359: the global level of the physical values (PricingLevels).
            'weight_t_per_cbm' => (float) $raw['weight_t_per_cbm'],
            'container_max_t' => (float) $raw['container_max_t'],
            'container_cbm' => (float) $raw['container_cbm'],
            'thick_k_factor' => (float) $raw['thick_k_factor'],
        ];
    }

    /** Whether the team incentive program is on (then the reserve goes into the price). */
    public static function programOn(): bool
    {
        return (IncentiveRepository::settings()['program_enabled'] ?? '0') === '1';
    }

    /**
     * Validates the settings form. Returns [values as stored strings, errors].
     *
     * @param array<string,mixed> $post
     * @return array{0: array<string,string>, 1: list<string>}
     */
    public static function readSettingsForm(array $post): array
    {
        $errors = [];
        $num = static function (string $key, float $min, float $max, bool $minExclusive, bool $maxExclusive, int $decimals) use ($post, &$errors): string {
            $v = trim((string) ($post[$key] ?? ''));
            $ok = $v !== '' && is_numeric($v) && preg_match('/^\d+(\.\d{1,' . $decimals . '})?$/', $v) === 1;
            $f = $ok ? (float) $v : 0.0;
            if (!$ok || ($minExclusive ? $f <= $min : $f < $min) || ($maxExclusive ? $f >= $max : $f > $max)) {
                $errors[] = self::LABELS[$key] . ': enter a number ' . ($minExclusive ? 'above ' : 'from ') . rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.')
                    . ($maxExclusive ? ' and below ' : ' to ') . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . " with at most {$decimals} decimals.";
                return '';
            }
            return number_format($f, $decimals, '.', '');
        };
        $values = [
            'margin_pct' => $num('margin_pct', 0, 90, false, false, 2),
            'other_cost_pct' => $num('other_cost_pct', 0, 100, false, false, 2),
            'pricing_fx_rate' => $num('pricing_fx_rate', 0, 100000, true, false, 4),
            'buffer_pct' => $num('buffer_pct', 0, 100, false, false, 2),
        ];
        $mode = (string) ($post['incentive_mode'] ?? '');
        if (!in_array($mode, ['pct', 'usd'], true)) {
            $errors[] = 'Choose whether the team incentive reserve is a % of profit or a USD amount per unit.';
            $mode = 'pct';
        }
        $values['incentive_mode'] = $mode;
        $values['incentive_value'] = $mode === 'pct' ? $num('incentive_value', 0, 100, false, false, 4) : $num('incentive_value', 0, 1000000, false, false, 4);
        $days = filter_var($post['stale_days'] ?? '', FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 3650) {
            $errors[] = self::LABELS['stale_days'] . ': enter whole days from 1 to 3650.';
            $values['stale_days'] = '';
        } else {
            $values['stale_days'] = (string) $days;
        }
        // D-245 P2: only when the form carries it (a form without the field keeps the stored value).
        if (array_key_exists('require_product_pick', $post)) {
            $values['require_product_pick'] = (string) $post['require_product_pick'] === '1' ? '1' : '0';
        }
        // D-255: only when the form carries them (an older form keeps the stored values).
        foreach (['cbm_from_mm' => [1, 2000], 'price_request_daily_limit' => [0, 1000], 'price_request_valid_days' => [1, 365]] as $key => [$min, $max]) {
            if (!array_key_exists($key, $post)) {
                continue;
            }
            $n = filter_var($post[$key], FILTER_VALIDATE_INT);
            if ($n === false || $n < $min || $n > $max) {
                $errors[] = self::LABELS[$key] . ": enter a whole number from {$min} to {$max}.";
                $values[$key] = '';
            } else {
                $values[$key] = (string) $n;
            }
        }
        // D-361 (mockup p5): the global physical defaults (only when the form carries them) — used
        // when neither the product nor its category has a value of its own.
        foreach (['weight_t_per_cbm' => [0.1, 20, 3], 'container_max_t' => [1, 100, 2], 'container_cbm' => [0.5, 100, 2], 'thick_k_factor' => [1, 1000, 3]] as $key => [$min, $max, $dec]) {
            if (!array_key_exists($key, $post)) {
                continue;
            }
            $v = trim((string) $post[$key]);
            if (preg_match('/^\d{1,4}(\.\d{1,' . $dec . '})?$/', $v) !== 1 || (float) $v < $min || (float) $v > $max) {
                $errors[] = self::LABELS[$key] . ': enter a number from ' . ThicknessPrices::fmt((float) $min) . ' to ' . ThicknessPrices::fmt((float) $max) . " with at most {$dec} decimals.";
                $values[$key] = '';
            } else {
                // Stored as before: 2.70 / 27.00 / 8.00 / 40 (more decimals only when typed).
                $typed = str_contains($v, '.') ? strlen(rtrim(substr($v, strpos($v, '.') + 1), '0')) : 0;
                $values[$key] = number_format((float) $v, max($key === 'thick_k_factor' ? 0 : 2, $typed), '.', '');
            }
        }
        // D-301: the exchange-rate mode and cushion (only when the form carries them).
        [$fx, $fxErrors] = FxRateService::readForm($post);
        return [$values + $fx, array_merge($errors, $fxErrors)];
    }

    /**
     * Validates a product's overrides form (empty = use the global value).
     *
     * @param array<string,mixed> $post
     * @return array{0: array<string,mixed>, 1: list<string>}
     */
    public static function readOverridesForm(array $post): array
    {
        $errors = [];
        $opt = static function (string $key, string $label, float $max) use ($post, &$errors): ?string {
            $v = trim((string) ($post[$key] ?? ''));
            if ($v === '') {
                return null;
            }
            if (!is_numeric($v) || preg_match('/^\d+(\.\d{1,4})?$/', $v) !== 1 || (float) $v > $max) {
                $errors[] = "{$label}: enter a number from 0 to " . number_format($max, 0, '.', '') . ' with at most 4 decimals, or leave it empty for the global value.';
                return null;
            }
            return number_format((float) $v, 4, '.', '');
        };
        $fields = [
            'pricing_margin_pct' => $opt('pricing_margin_pct', 'Margin %', 90),
            'pricing_buffer_pct' => $opt('pricing_buffer_pct', 'Buffer %', 100),
        ];
        foreach (['other_cost' => 'Other costs to FOB', 'incentive' => 'Team incentive reserve'] as $k => $label) {
            $mode = (string) ($post["pricing_{$k}_mode"] ?? '');
            $value = $opt("pricing_{$k}_value", $label, $mode === 'pct' ? 100 : 1000000);
            if ($value === null) {
                $fields["pricing_{$k}_mode"] = null;
                $fields["pricing_{$k}_value"] = null;
                continue;
            }
            if (!in_array($mode, ['pct', 'usd'], true)) {
                $errors[] = "{$label}: choose % or USD per unit.";
                $mode = null;
            }
            $fields["pricing_{$k}_mode"] = $mode;
            $fields["pricing_{$k}_value"] = $mode === null ? null : $value;
        }
        if ($fields['pricing_margin_pct'] !== null && (float) $fields['pricing_margin_pct'] >= 90) {
            $errors[] = 'Margin %: enter a number below 90.';
        }
        return [$fields, $errors];
    }

    /**
     * The supplier row used for pricing: the one marked so (a base price, or D-300 a price for every thickness); otherwise none.
     * D-359: only while it is usable — the offer switched on and its supplier active (SupplierOffers::usable()).
     */
    public static function pricingSupplier(int $productId): ?array
    {
        foreach (ProductSupplierRepository::forProduct($productId) as $s) {
            if (!empty($s['is_primary']) && in_array($s['price_basis'] ?? 'unit', ['unit', 'grid'], true) && SupplierOffers::usable($s)) {
                return $s;
            }
        }
        return null;
    }

    /**
     * D-255: the per-CBM supplier row used for pricing thick stone (50 mm and above).
     * D-300: a price for every thickness used for pricing also holds the per-CBM price.
     */
    public static function cbmSupplier(int $productId, ?array $primary = null): ?array
    {
        // D-359: the row the engine prices from (a fixed configured base price may stand in for the supplier's).
        $primary ??= self::pricingSupplier($productId);
        if ($primary !== null && ($primary['price_basis'] ?? '') === 'grid') {
            return $primary;
        }
        foreach (ProductSupplierRepository::forProduct($productId) as $s) {
            if (($s['price_basis'] ?? 'unit') === 'cbm' && !empty($s['is_cbm_primary']) && SupplierOffers::usable($s)) {
                return $s;
            }
        }
        return null;
    }

    /** 'stale' | 'expired' | null for a supplier price (quoted on, else last changed). */
    public static function staleness(array $supplier, int $staleDays, ?string $today = null): ?string
    {
        $today ??= date('Y-m-d');
        if (!empty($supplier['valid_until']) && (string) $supplier['valid_until'] < $today) {
            return 'expired';
        }
        $date = self::priceDate($supplier);
        if ($date === null) {
            return 'stale';
        }
        return $date < date('Y-m-d', strtotime($today . " -{$staleDays} days")) ? 'stale' : null;
    }

    public static function priceDate(array $supplier): ?string
    {
        if (!empty($supplier['quoted_on'])) {
            return substr((string) $supplier['quoted_on'], 0, 10);
        }
        return !empty($supplier['updated_at']) ? substr((string) $supplier['updated_at'], 0, 10) : null;
    }

    /**
     * The full build-up for a product, or a reason it cannot be worked out.
     *
     * @param array<string,mixed> $product catalog_products row
     * @param list<array<string,mixed>>|null $levels active commission levels (loaded when null)
     * @return array<string,mixed>
     */
    public static function forProduct(array $product, ?array $levels = null, ?array $settings = null, ?bool $programOn = null, ?array $selection = null, ?float $lineThicknessMm = null): array
    {
        $settings ??= self::settings();
        $programOn ??= self::programOn();
        // D-255: the chosen specification options (spec id => option id); null = the base product.
        $spec = null;
        if ($selection !== null) {
            $offered = ProductSpecs::offered($product);
            [$map, $specErrors] = ProductSpecs::normalize($offered, $selection);
            $adj = ProductSpecs::adjustments($product, $offered, $map, $settings['cbm_from_mm'], [], $lineThicknessMm);
            $spec = ['map' => $map, 'offered' => $offered, 'adj' => $adj, 'text' => ProductSpecs::describe($offered, $map),
                'errors' => $specErrors];
        }
        // D-256: an exact supplier price for the chosen combination is the cost for the options it names.
        $exact = null;
        if ($spec !== null && $spec['errors'] === [] && $spec['adj']['manual'] === null) {
            $exact = self::exactPrice($product, $spec['map'], $spec['adj']['basis'], $settings, $spec['offered']);
            if ($exact !== null) {
                $spec['adj'] = ProductSpecs::adjustments($product, $spec['offered'], $spec['map'], $settings['cbm_from_mm'], array_keys($exact['named']), $lineThicknessMm);
                $spec['exact'] = $exact['text'];
            }
        }
        // D-359: the configured base price (SupplierOffers::pricingRow) — the usable supplier price
        // "used for pricing" while the product follows it, as before.
        $primary = $exact === null ? SupplierOffers::pricingRow($product, self::pricingSupplier((int) $product['id']), $settings) : null;
        $supplier = $exact !== null ? $exact['supplier']
            : ($spec !== null && $spec['adj']['basis'] === 'cbm' ? self::cbmSupplier((int) $product['id'], $primary) : $primary);
        // D-300 (point 12): a price for every thickness already holds the Thickness option's % —
        // the chosen thickness is priced from the grid, so the % is not applied again.
        $gridRules = null;
        if ($supplier !== null && ($supplier['price_basis'] ?? '') === 'grid') {
            $gridRules = ThicknessPrices::rules($product, $settings);
            if ($spec !== null && $spec['errors'] === [] && $spec['adj']['manual'] === null && $spec['adj']['basis'] === 'unit') {
                $thicknessSpec = null;
                foreach ($spec['offered'] as $sp) {
                    if ($sp['kind'] === 'thickness' && isset($spec['map'][$sp['id']])) {
                        $thicknessSpec = (int) $sp['id'];
                    }
                }
                if ($thicknessSpec !== null) {
                    $spec['adj'] = ProductSpecs::adjustments($product, $spec['offered'], $spec['map'], $settings['cbm_from_mm'], [$thicknessSpec], $lineThicknessMm);
                    foreach ($spec['adj']['lines'] as &$__l) {
                        if ($__l['kind'] === 'exact') {
                            $__l['kind'] = 'included';
                        }
                    }
                    unset($__l);
                }
            }
        }
        $effective = self::effective($product, $settings);
        $overrides = [];
        if ($product['pricing_margin_pct'] !== null) {
            $overrides[] = 'margin ' . self::pct($effective['margin_pct']);
        }
        if ($product['pricing_other_cost_mode'] !== null) {
            $overrides[] = 'other costs ' . self::modeLabel($effective['other_mode'], $effective['other_value']);
        }
        if ($product['pricing_incentive_mode'] !== null) {
            $overrides[] = 'incentive ' . self::modeLabel($effective['incentive_mode'], $effective['incentive_value']);
        }
        if ($product['pricing_buffer_pct'] !== null) {
            $overrides[] = 'buffer ' . self::pct($effective['buffer_pct']);
        }
        $out = ['supplier' => $supplier, 'effective' => $effective, 'overrides' => $overrides, 'settings' => $settings, 'program_on' => $programOn,
            'exact' => $exact !== null ? $exact['text'] : null,
            'build' => null, 'levels' => [], 'lowest' => null, 'staleness' => null, 'price_date' => null, 'missing' => null,
            'spec' => $spec, 'manual' => false, 'unit' => $spec['adj']['unit'] ?? ((string) ($product['reference_unit'] ?? '') ?: null)];
        if ($spec !== null && $spec['errors'] !== []) {
            $out['missing'] = implode(' ', $spec['errors']);
            return $out;
        }
        if ($spec !== null && $spec['adj']['manual'] !== null) {
            $out['missing'] = $spec['adj']['manual'];
            $out['manual'] = true;
            return $out;
        }
        if ($supplier === null) {
            if ($spec !== null && $spec['adj']['basis'] === 'cbm') {
                $out['missing'] = 'No price for 50 mm and above (per CBM) yet — add a 50 mm+ supplier price, or set a manual price.';
                $out['manual'] = true;
                return $out;
            }
            $marked = SupplierOffers::markedForPricing((int) $product['id']);
            $out['missing'] = $marked !== null && !SupplierOffers::usable($marked)
                ? 'The supplier price behind this product is switched off (inactive supplier or offer) — choose another supplier price in Configure.'
                : 'Not configured yet — set the base price in Configure.';
            return $out;
        }
        // D-300: FOB cost (₹) = supplier price + CHA and transport per unit (+ packing and loading
        // when the supplier's price does not include them).
        $parts = self::fobParts($product, $supplier, $spec !== null ? (string) $spec['adj']['basis'] : 'unit',
            $spec !== null && $spec['adj']['thickness_mm'] !== null ? (float) $spec['adj']['thickness_mm'] : null, $settings, $gridRules);
        $inr = $parts['total'] ?? null;
        if ($inr === null || $inr <= 0) {
            $out['missing'] = 'The supplier price used for pricing has no value yet.';
            return $out;
        }
        $out['supplier_inr'] = $inr;
        $out['inr_parts'] = $parts;
        $out['staleness'] = self::staleness($supplier, $settings['stale_days']);
        $out['price_date'] = self::priceDate($supplier);
        $build = PricingEngine::build([
            'supplier_inr' => $inr, 'fx_rate' => $settings['pricing_fx_rate'],
            'other_mode' => $effective['other_mode'], 'other_value' => $effective['other_value'],
            'margin_pct' => $effective['margin_pct'],
            'incentive_mode' => $effective['incentive_mode'], 'incentive_value' => $effective['incentive_value'], 'program_on' => $programOn,
            'buffer_pct' => $effective['buffer_pct'],
            'adjust' => $spec !== null ? ['pct' => $spec['adj']['pct'], 'usd' => $spec['adj']['usd'], 'qty_pct' => $spec['adj']['qty_pct']] : null,
        ]);
        $out['build'] = $build;
        foreach ($levels ?? PricingRepository::levels(true) as $l) {
            $a = PricingEngine::associate($build['raw'], (string) $l['kind'], (float) $l['value']);
            $out['levels'][] = ['id' => (int) $l['id'], 'name' => (string) $l['name'], 'label' => self::levelLabel($l)] + $a;
            if ($out['lowest'] === null || $a['floor'] < $out['lowest']['floor']) {
                $out['lowest'] = end($out['levels']);
            }
        }
        return $out;
    }

    /**
     * The margin, other costs, incentive and buffer that apply to a product: its own, else
     * (D-359) its category's, else the pricing settings.
     *
     * @return array{margin_pct: float, other_mode: string, other_value: float, incentive_mode: string, incentive_value: float, buffer_pct: float}
     */
    public static function effective(array $product, ?array $settings = null): array
    {
        $settings ??= self::settings();
        // D-359: product → category → global (PricingLevels); the incentive is product → global.
        $l = PricingLevels::resolve($product, $settings);
        return [
            'margin_pct' => (float) $l['margin_pct']['value'],
            'other_mode' => (string) $l['other_cost']['value']['mode'],
            'other_value' => (float) $l['other_cost']['value']['value'],
            'incentive_mode' => (string) $l['incentive']['value']['mode'],
            'incentive_value' => (float) $l['incentive']['value']['value'],
            'buffer_pct' => (float) $l['buffer_pct']['value'],
        ];
    }

    /**
     * D-335: the price build-up from one supplier-price row's FOB cost (₹ per the product's
     * unit) — supplier USD, cost, minimum and quote — for the "Product price (from the base)"
     * line on Market prices. Null when the FOB cost is not above 0.
     *
     * @return array<string,mixed>|null
     */
    public static function buildFromFob(array $product, float $fobInr, ?array $settings = null, ?bool $programOn = null): ?array
    {
        $settings ??= self::settings();
        if ($fobInr <= 0) {
            return null;
        }
        $e = self::effective($product, $settings);
        return PricingEngine::build([
            'supplier_inr' => $fobInr, 'fx_rate' => $settings['pricing_fx_rate'],
            'other_mode' => $e['other_mode'], 'other_value' => $e['other_value'], 'margin_pct' => $e['margin_pct'],
            'incentive_mode' => $e['incentive_mode'], 'incentive_value' => $e['incentive_value'], 'program_on' => $programOn ?? self::programOn(),
            'buffer_pct' => $e['buffer_pct'],
        ]) + ['margin_pct' => $e['margin_pct'], 'buffer_pct' => $e['buffer_pct'], 'fx_rate' => (float) $settings['pricing_fx_rate']];
    }

    /**
     * D-300 (point 12): the FOB cost in ₹ per the product's unit (per CBM for 50 mm and above)
     * from one supplier-price row — supplier price + CHA and transport per unit, + packing and
     * loading when the price does not include them.
     *
     * @return array{supplier: float, cha: float, packing: float, total: float, unit: string, mm: ?float, how: string, note: ?string}|null
     */
    public static function fobParts(array $product, array $supplier, string $basis, ?float $mm, ?array $settings = null, ?array $gridRules = null): ?array
    {
        $settings ??= self::settings();
        if (($supplier['price_basis'] ?? '') === 'grid') {
            $gridRules ??= ThicknessPrices::rules($product, $settings);
            // D-359: a configured base price carries its own prices (_stored); a supplier row reads its stored ones.
            return ThicknessPrices::cost($gridRules, $supplier['_stored'] ?? ThicknessPrices::storedFor((int) $supplier['id']), $supplier, $product, $basis, $mm);
        }
        $base = (new ProductService())->effectiveFobForSupplier($supplier, $product);
        if ($base === null) {
            return null;
        }
        $parts = ['supplier' => $base, 'cha' => 0.0, 'packing' => 0.0, 'total' => $base, 'unit' => $basis === 'cbm' ? 'CBM' : (string) ($product['reference_unit'] ?? ''), 'mm' => $mm, 'how' => 'typed', 'note' => null];
        $perContainer = ThicknessPrices::chaPerContainer($supplier, $product);
        if ($perContainer !== null) {
            $rules = $gridRules ?? ThicknessPrices::rules($product, $settings);
            $cha = ThicknessPrices::chaPerCbm($perContainer, (float) $rules['container_cbm'], $rules['container_max_t'], $rules['t_per_cbm']);
            $cbmPerUnit = $basis === 'cbm' ? 1.0 : ThicknessPrices::cbmPerUnit((string) ($product['reference_unit'] ?? ''), $mm ?? (float) $rules['base_mm'], $rules['t_per_cbm']);
            if ($cha['per_cbm'] !== null && $cbmPerUnit !== null) {
                $parts['cha'] = $cha['per_cbm'] * $cbmPerUnit;
            } elseif ($cha['per_cbm'] !== null) {
                $parts['note'] = 'CHA + transport is not added: the product\'s unit cannot be turned into CBM.';
            }
        }
        if (array_key_exists('includes_packing_loading', $supplier) && empty($supplier['includes_packing_loading'])) {
            $parts['packing'] = (float) ($product['default_packing_cost'] ?? 0) + (float) ($product['default_loading_cost'] ?? 0);
        }
        $parts['total'] = $parts['supplier'] + $parts['cha'] + $parts['packing'];
        return $parts;
    }

    /**
     * D-256: the exact supplier price that matches a choice of options — the most specific one
     * (most named options), then the most recently quoted. Stale or expired ones are ignored.
     *
     * @param array<int,int> $map spec id => chosen option id
     * @return array{supplier: array<string,mixed>, named: array<int,int>, text: string}|null
     */
    public static function exactPrice(array $product, array $map, string $basis, ?array $settings = null, ?array $offered = null): ?array
    {
        $settings ??= self::settings();
        $rows = array_values(array_filter(ProductSupplierRepository::forProduct((int) $product['id']),
            static fn (array $s): bool => ($s['price_basis'] ?? '') === 'combo' && (int) ($s['combo_in_use'] ?? 0) === 1 && (string) ($s['combo_per'] ?? 'unit') === $basis && SupplierOffers::usable($s)));
        if ($rows === []) {
            return null;
        }
        $named = PricingRepository::comboOptions(array_map(static fn (array $s): int => (int) $s['id'], $rows));
        $best = null;
        foreach ($rows as $s) {
            $n = $named[(int) $s['id']] ?? [];
            if ($n === [] || self::staleness($s, $settings['stale_days']) !== null) {
                continue;
            }
            foreach ($n as $specId => $optionId) {
                if (($map[$specId] ?? null) !== $optionId) {
                    continue 2;
                }
            }
            $key = [count($n), (string) (self::priceDate($s) ?? ''), (int) $s['id']];
            if ($best === null || $key > $best['key']) {
                $best = ['supplier' => $s, 'named' => $n, 'key' => $key];
            }
        }
        if ($best === null) {
            return null;
        }
        $offered ??= ProductSpecs::offered($product);
        return ['supplier' => $best['supplier'], 'named' => $best['named'], 'text' => self::comboText($offered, $best['named'])];
    }

    /** "30 mm · Leather" for named options (in the product's specification order). */
    public static function comboText(array $offered, array $named): string
    {
        $parts = [];
        foreach ($offered as $sp) {
            foreach ($sp['options'] as $o) {
                if (($named[$sp['id']] ?? null) === $o['id']) {
                    $parts[] = $o['name'];
                }
            }
        }
        if (count($parts) < count($named)) {
            // An option no longer offered for this product: name it from the option table.
            foreach ($named as $specId => $optionId) {
                $opt = \App\Repositories\SpecRepository::findOption($optionId);
                if ($opt !== null && !in_array((string) $opt['name'], $parts, true)) {
                    $parts[] = (string) $opt['name'];
                }
            }
        }
        return implode(' · ', $parts);
    }

    /**
     * D-256: "Pricing ready?" — what a product still needs before it can be priced.
     * D-365 (owner go, 13 Nov 2026, mockups plist_r / plist2_t3): the "supplier price per unit …
     * used for pricing" line is gone — the configured base price replaces it ('base'); "At least
     * one active supplier" is added ('suppliers'; information only for a "No supplier yet"
     * product); "Product open to associates" is information only while the system has no active
     * associate at all. Every line that is not done has a button that opens the form to fix it.
     * Each item: key, ok, state ('ok' | 'fix' | 'info'), label, detail, link, link_text, supplier
     * (a line Procurement may see), and for 'cbm' the extra button (link2 / link2_text).
     *
     * @return array{items: list<array<string,mixed>>, to_fix: int}
     */
    public static function readiness(array $product, ?array $settings = null): array
    {
        $settings ??= self::settings();
        $id = (int) $product['id'];
        $market = "/products/{$id}/market-prices";
        $byKey = [];
        $unit = self::pricingSupplier($id);
        $offers = SupplierOffers::offers($id);
        $standing = SupplierOffers::status($product, $offers);
        $offered = ProductSpecs::offered($product);
        $cbmFrom = (int) ($product['cbm_from_mm'] ?? 0) > 0 ? (int) $product['cbm_from_mm'] : $settings['cbm_from_mm'];

        // The configured base price (D-361) — what every selling price is worked out from.
        $cfg = SupplierOffers::isConfigured($product);
        $byKey['base'] = ['key' => 'base', 'ok' => $cfg, 'supplier' => false,
            'label' => 'Configured base price set' . ($cfg ? ' (' . SupplierOffers::configuredShort($product, (int) ThicknessPrices::rules($product, $settings)['cbm_from']) . ')' : ''),
            'detail' => $cfg ? '' : 'not configured yet — no selling price is worked out or offered until it is',
            'link' => "/products/{$id}/configure", 'link_text' => $cfg ? 'Edit' : 'Configure'];

        // At least one active supplier (a usable offer).
        $names = [];
        foreach ($offers as $o) {
            if (!empty($o['usable']) && ($o['price_basis'] ?? 'unit') !== 'combo') {
                $names[trim((string) $o['supplier_name'])] = true;
            }
        }
        $names = array_keys(array_filter($names, static fn ($v, $k): bool => $k !== '', ARRAY_FILTER_USE_BOTH));
        $nameText = count($names) > 3 ? implode(', ', array_slice($names, 0, 3)) . ' +' . (count($names) - 3) . ' more' : implode(', ', $names);
        if ($standing['usable'] > 0) {
            $byKey['suppliers'] = ['key' => 'suppliers', 'ok' => true, 'supplier' => true,
                'label' => 'At least one active supplier' . ($nameText !== '' ? " ({$nameText})" : ''), 'detail' => '',
                'link' => $market, 'link_text' => 'Market prices'];
        } elseif ($standing['no_supplier_yet']) {
            $byKey['suppliers'] = ['key' => 'suppliers', 'ok' => true, 'info' => true, 'supplier' => true,
                'label' => 'No supplier linked yet — a product from before the Suppliers directory',
                'detail' => 'information only — it keeps working until a supplier is linked',
                'link' => "/products/{$id}/link-supplier", 'link_text' => 'Link a supplier'];
        } else {
            $byKey['suppliers'] = ['key' => 'suppliers', 'ok' => false, 'supplier' => true,
                'label' => 'At least one active supplier',
                'detail' => $standing['paused'] > 0 ? 'none active — ' . $standing['paused'] . ' paused' : 'no supplier price yet',
                'link' => $market . '#add-price', 'link_text' => 'Add a supplier price'];
        }

        // A price for 50 mm and above (per CBM), when the product is sold that thick.
        $needsCbm = false;
        $thickSpec = null;
        foreach ($offered as $sp) {
            if ($sp['kind'] !== 'thickness') {
                continue;
            }
            foreach ($sp['options'] as $o) {
                if ($o['change_type'] === 'cbm' || ($o['thickness_mm'] !== null && $o['thickness_mm'] >= $cbmFrom)) {
                    $needsCbm = true;
                    $thickSpec ??= (int) $sp['id'];
                }
            }
        }
        $cbm = self::cbmSupplier($id);
        if ($needsCbm) {
            // "Add a 50 mm+ price": Edit price at 50 mm and above for a price for every thickness;
            // otherwise Market prices › Add a price, "per CBM", for the same supplier.
            $target = $unit;
            if ($target === null) {
                foreach ($offers as $o) {
                    if (!empty($o['usable']) && in_array($o['price_basis'] ?? 'unit', ['unit', 'grid'], true)) {
                        $target = $o;
                        break;
                    }
                }
            }
            $addCbm = $target !== null && ($target['price_basis'] ?? '') === 'grid'
                ? '/products/suppliers/' . (int) $target['id'] . '/edit-price?thickness_mm=' . ThicknessPrices::CBM
                : $market . '?basis=cbm' . ($target !== null && ($target['supplier_id'] ?? null) !== null ? '&supplier_id=' . (int) $target['supplier_id'] : '') . '#add-price';
            $byKey['cbm'] = ['key' => 'cbm', 'ok' => $cbm !== null, 'supplier' => true,
                'label' => $cbm !== null ? "Supplier price per CBM (needed: sold at {$cbmFrom} mm and above)" : "No price for {$cbmFrom} mm and above (per CBM)",
                'detail' => $cbm !== null ? '₹' . number_format((float) (self::fobParts($product, $cbm, 'cbm', null, $settings)['total'] ?? 0), 2) . ' per CBM'
                    : "needed because this product is sold at {$cbmFrom} mm and above — orders at {$cbmFrom} mm+ cannot be priced",
                'link' => $cbm !== null ? $market : $addCbm, 'link_text' => $cbm !== null ? 'Market prices' : "Add a {$cbmFrom} mm+ price"];
            if ($cbm === null && $thickSpec !== null) {
                // The existing per-product switch: untick the 50 mm+ thicknesses as "offered" for this product.
                $byKey['cbm']['link2'] = "/products/{$id}/specifications?edit={$thickSpec}#spec-{$thickSpec}";
                $byKey['cbm']['link2_text'] = "Not sold at {$cbmFrom} mm+";
            }
        }

        $pdo = \App\Config\Database::connection();
        $count = static function (string $sql) use ($pdo, $id): int {
            $st = $pdo->prepare($sql);
            $st->execute(['p' => $id]);
            return (int) $st->fetchColumn();
        };
        $changes = $count('SELECT COUNT(*) FROM product_spec_hidden WHERE product_id = :p')
            + $count('SELECT COUNT(*) FROM product_option_overrides WHERE product_id = :p')
            + $count('SELECT COUNT(*) FROM spec_definitions WHERE product_id = :p')
            + $count('SELECT COUNT(*) FROM spec_options WHERE product_id = :p');
        $categoryId = ProductSpecs::categoryIdFor($product);
        $categoryName = null;
        if ($categoryId !== null) {
            $st = $pdo->prepare('SELECT name FROM product_categories WHERE id = :c');
            $st->execute(['c' => $categoryId]);
            $categoryName = $st->fetchColumn() ?: null;
        }
        $byKey['specs'] = ['key' => 'specs', 'ok' => $offered !== [], 'supplier' => false,
            'label' => $offered !== [] ? 'Specifications checked (follows ' . ($categoryName ?? 'its category') . ', ' . $changes . ' change' . ($changes === 1 ? '' : 's') . ')' : 'Specifications: none offered for this product',
            'detail' => count($offered) . ' specification' . (count($offered) === 1 ? '' : 's'),
            'link' => "/products/{$id}/specifications", 'link_text' => $offered !== [] ? 'View' : 'Set the specifications'];
        $stale = [];
        foreach (['SQM/unit' => $unit, 'CBM' => $needsCbm ? $cbm : null] as $label => $s) {
            if ($s !== null && ($st = self::staleness($s, $settings['stale_days'])) !== null) {
                $stale[] = "{$label} price {$st}";
            }
        }
        $current = $unit !== null && $stale === [];
        $byKey['current'] = ['key' => 'current', 'ok' => $current, 'supplier' => true,
            'label' => 'Supplier price is current (under ' . $settings['stale_days'] . ' days old)',
            'detail' => $unit === null ? 'no price yet' : ($stale !== [] ? implode(', ', $stale) : (!empty($unit['valid_until']) ? 'valid until ' . date('d M', (int) strtotime((string) $unit['valid_until'])) : 'no valid-until date')),
            'link' => $current ? $market : ($unit !== null ? '/products/suppliers/' . (int) $unit['id'] . '/edit-price' : $market . '#add-price'),
            'link_text' => $current ? 'Market prices' : ($unit !== null ? 'Edit price' : 'Add a supplier price')];
        $combos = array_values(array_filter(ProductSupplierRepository::forProduct($id), static fn (array $s): bool => ($s['price_basis'] ?? '') === 'combo' && (int) ($s['combo_in_use'] ?? 0) === 1));
        if ($combos !== []) {
            $ignored = count(array_filter($combos, fn (array $s): bool => self::staleness($s, $settings['stale_days']) !== null));
            $byKey['exact'] = ['key' => 'exact', 'ok' => $ignored === 0, 'supplier' => true,
                'label' => 'Exact supplier prices for specific combinations are current',
                'detail' => count($combos) . ' in use' . ($ignored > 0 ? ", {$ignored} stale or expired — ignored until renewed" : ''),
                'link' => $market, 'link_text' => $ignored > 0 ? 'Renew in Market prices' : 'Market prices'];
        }
        $forms = ProductSpecs::productForms($product);
        $open = [];
        if ($categoryId !== null) {
            $st = $pdo->prepare('SELECT apa.forms FROM associate_price_access apa JOIN associates a ON a.id = apa.associate_id WHERE apa.category_id = :c AND a.status = \'active\'');
            $st->execute(['c' => $categoryId]);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $f) {
                $allowed = ProductSpecs::splitForms($f);
                $hit = $allowed === [] ? ($forms === [] ? ['all forms'] : $forms) : ($forms === [] ? $allowed : array_values(array_intersect($forms, $allowed)));
                foreach ($hit as $h) {
                    $open[$h] = true;
                }
            }
        }
        $active = array_map('intval', $pdo->query("SELECT id FROM associates WHERE status = 'active' ORDER BY id LIMIT 2")->fetchAll(\PDO::FETCH_COLUMN));
        if ($active === []) {
            // No active associate in the system at all: information only, not "to fix".
            $byKey['associates'] = ['key' => 'associates', 'ok' => true, 'info' => true, 'supplier' => false,
                'label' => 'Not open to associates yet — no associate in the system',
                'detail' => 'information only — does not stop quoting',
                'link' => '/associates/access-levels', 'link_text' => 'Associates › Access levels'];
        } else {
            $byKey['associates'] = ['key' => 'associates', 'ok' => $open !== [], 'supplier' => false,
                'label' => 'Product open to associates' . ($open !== [] ? ' (' . implode(', ', array_map('ucfirst', array_keys($open))) . ')' : ''),
                'detail' => $open !== [] ? '' : 'no associate may price it yet',
                'link' => $open === [] && count($active) === 1 ? '/associates/' . $active[0] . '/price-access' : '/associates',
                'link_text' => $open !== [] ? 'Change' : 'Give price access'];
        }
        $items = [];
        foreach (['base', 'suppliers', 'current', 'specs', 'cbm', 'exact', 'associates'] as $k) {
            if (isset($byKey[$k])) {
                $i = $byKey[$k];
                $i['info'] = !empty($i['info']);
                $i['state'] = $i['info'] ? 'info' : ($i['ok'] ? 'ok' : 'fix');
                $items[] = $i;
            }
        }
        return ['items' => $items, 'to_fix' => self::countToFix($items)];
    }

    /** D-365: the lines still to fix (information lines never count). @param list<array<string,mixed>> $items */
    public static function countToFix(array $items): int
    {
        return count(array_filter($items, static fn (array $i): bool => !$i['ok'] && empty($i['info'])));
    }

    public static function levelLabel(array $level): string
    {
        return $level['kind'] === 'pct' ? self::pct((float) $level['value']) : 'USD ' . number_format((float) $level['value'], 2) . ' per unit';
    }

    public static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') . '%';
    }

    public static function modeLabel(string $mode, float $value): string
    {
        return $mode === 'pct' ? self::pct($value) : 'USD ' . number_format($value, 2) . ' per unit';
    }

    /**
     * Validates the ladder form rows (blank rows and rows ticked "remove" are skipped):
     * steps start at order 1, run on without gaps or overlaps, only the last is open-ended.
     *
     * @param array<string,mixed> $post from[], to[], level_id[], remove[]
     * @param array<int,array<string,mixed>> $levelsById
     * @return array{0: list<array{from:int,to:?int,level_id:int}>, 1: list<string>}
     */
    public static function readLadderForm(array $post, array $levelsById): array
    {
        $errors = [];
        $steps = [];
        $from = (array) ($post['from'] ?? []);
        $to = (array) ($post['to'] ?? []);
        $level = (array) ($post['level_id'] ?? []);
        $remove = (array) ($post['remove'] ?? []);
        foreach ($from as $i => $f) {
            $f = trim((string) $f);
            $t = trim((string) ($to[$i] ?? ''));
            $l = trim((string) ($level[$i] ?? ''));
            if (!empty($remove[$i]) || ($f === '' && $t === '' && $l === '')) {
                continue;
            }
            $fi = filter_var($f, FILTER_VALIDATE_INT);
            $ti = $t === '' ? null : filter_var($t, FILTER_VALIDATE_INT);
            $li = filter_var($l, FILTER_VALIDATE_INT);
            if ($fi === false || $fi < 1 || $ti === false || ($ti !== null && $ti < $fi) || $li === false || !isset($levelsById[$li])) {
                $errors[] = 'Every step needs a "from" order number (1 or more), a "to" number not below it (or empty for "onward") and a commission level.';
                continue;
            }
            $steps[] = ['from' => $fi, 'to' => $ti, 'level_id' => $li];
        }
        if ($errors !== []) {
            return [[], array_values(array_unique($errors))];
        }
        if ($steps === []) {
            return [[], ['Keep at least one step in the ladder.']];
        }
        usort($steps, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);
        $expect = 1;
        foreach ($steps as $i => $s) {
            if ($s['from'] !== $expect) {
                $errors[] = $s['from'] > $expect
                    ? "The ladder has a gap: no step covers order {$expect}" . ($s['from'] - 1 > $expect ? '–' . ($s['from'] - 1) : '') . '.'
                    : "Steps overlap at order {$s['from']}.";
                break;
            }
            $last = $i === count($steps) - 1;
            if ($s['to'] === null && !$last) {
                $errors[] = 'Only the last step may run "onward" (empty "to").';
                break;
            }
            if ($last && $s['to'] !== null) {
                $errors[] = 'Leave the last step\'s "to" empty so it runs onward.';
                break;
            }
            $expect = ($s['to'] ?? 0) + 1;
        }
        return [$errors === [] ? $steps : [], $errors];
    }
}
