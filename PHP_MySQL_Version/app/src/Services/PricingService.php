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
        'margin_pct' => '20.00',
        'other_cost_pct' => '2.50',
        'pricing_fx_rate' => '82.0000',
        'buffer_pct' => '3.00',
        'incentive_mode' => 'pct',
        'incentive_value' => '15.0000',
        'stale_days' => '60',
        'require_product_pick' => '1',
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
    ];

    /** @return array{margin_pct: float, other_cost_pct: float, pricing_fx_rate: float, buffer_pct: float, incentive_mode: string, incentive_value: float, stale_days: int} */
    public static function settings(): array
    {
        $raw = PricingRepository::settings() + self::DEFAULTS;
        return [
            'margin_pct' => (float) $raw['margin_pct'],
            'other_cost_pct' => (float) $raw['other_cost_pct'],
            'pricing_fx_rate' => (float) $raw['pricing_fx_rate'],
            'buffer_pct' => (float) $raw['buffer_pct'],
            'incentive_mode' => $raw['incentive_mode'] === 'usd' ? 'usd' : 'pct',
            'incentive_value' => (float) $raw['incentive_value'],
            'stale_days' => max(1, (int) $raw['stale_days']),
            'require_product_pick' => (string) $raw['require_product_pick'] === '1',
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
        return [$values, $errors];
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

    /** The supplier row used for pricing: the one marked so; otherwise none. */
    public static function pricingSupplier(int $productId): ?array
    {
        foreach (ProductSupplierRepository::forProduct($productId) as $s) {
            if (!empty($s['is_primary'])) {
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
    public static function forProduct(array $product, ?array $levels = null, ?array $settings = null, ?bool $programOn = null): array
    {
        $settings ??= self::settings();
        $programOn ??= self::programOn();
        $supplier = self::pricingSupplier((int) $product['id']);
        $effective = [
            'margin_pct' => $product['pricing_margin_pct'] !== null ? (float) $product['pricing_margin_pct'] : $settings['margin_pct'],
            'other_mode' => $product['pricing_other_cost_mode'] ?? 'pct',
            'other_value' => $product['pricing_other_cost_mode'] !== null ? (float) $product['pricing_other_cost_value'] : $settings['other_cost_pct'],
            'incentive_mode' => $product['pricing_incentive_mode'] ?? $settings['incentive_mode'],
            'incentive_value' => $product['pricing_incentive_mode'] !== null ? (float) $product['pricing_incentive_value'] : $settings['incentive_value'],
            'buffer_pct' => $product['pricing_buffer_pct'] !== null ? (float) $product['pricing_buffer_pct'] : $settings['buffer_pct'],
        ];
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
            'build' => null, 'levels' => [], 'lowest' => null, 'staleness' => null, 'price_date' => null, 'missing' => null];
        if ($supplier === null) {
            $out['missing'] = 'No supplier price is marked "used for pricing" yet.';
            return $out;
        }
        $inr = (new ProductService())->effectiveFobForSupplier($supplier, $product);
        if ($inr === null || $inr <= 0) {
            $out['missing'] = 'The supplier price used for pricing has no value yet.';
            return $out;
        }
        $out['supplier_inr'] = $inr;
        $out['staleness'] = self::staleness($supplier, $settings['stale_days']);
        $out['price_date'] = self::priceDate($supplier);
        $build = PricingEngine::build([
            'supplier_inr' => $inr, 'fx_rate' => $settings['pricing_fx_rate'],
            'other_mode' => $effective['other_mode'], 'other_value' => $effective['other_value'],
            'margin_pct' => $effective['margin_pct'],
            'incentive_mode' => $effective['incentive_mode'], 'incentive_value' => $effective['incentive_value'], 'program_on' => $programOn,
            'buffer_pct' => $effective['buffer_pct'],
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
