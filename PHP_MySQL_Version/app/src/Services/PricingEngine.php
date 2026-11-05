<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision D-245 (4 Nov 2026): the price build-up, as pure maths with
 * no database access, so PHP and Node give exactly the same figures.
 * Node twin: src/services/pricingEngine.js (same steps, same rounding).
 *
 *   cost          = supplier price (INR) ÷ pricing exchange rate + other costs to FOB
 *   minimum price = cost ÷ (1 − margin %)            — margin on the price, not markup
 *   reserve       = % of (minimum − cost), or a fixed USD amount; 0 when the program is off
 *   staff floor   = minimum + reserve
 *   associate floor = staff floor ÷ (1 − commission %), or + a fixed USD amount
 *   quote price   = floor × (1 + buffer %)
 *
 * Every step works on unrounded numbers; only the results are rounded to
 * 2 decimals (half up), at the end.
 */
final class PricingEngine
{
    /**
     * @param array{
     *   supplier_inr: float, fx_rate: float,
     *   other_mode: string, other_value: float,
     *   margin_pct: float,
     *   incentive_mode: string, incentive_value: float, program_on: bool,
     *   buffer_pct: float,
     *   adjust?: array{pct?: list<float>, usd?: float, qty_pct?: float}
     * } $in  other_mode / incentive_mode: 'pct' | 'usd'; adjust: D-255 specification options
     * @return array{supplier_usd: float, other_costs: float, cost: float, minimum: float, reserve: float, staff_floor: float, staff_quote: float, raw: array<string,float>}
     */
    public static function build(array $in): array
    {
        $fx = (float) $in['fx_rate'];
        if ($fx <= 0) {
            throw new \InvalidArgumentException('The pricing exchange rate must be above 0.');
        }
        $margin = (float) $in['margin_pct'];
        if ($margin < 0 || $margin >= 100) {
            throw new \InvalidArgumentException('The margin must be at least 0 and below 100 %.');
        }
        $supplierUsd = (float) $in['supplier_inr'] / $fx;
        $other = $in['other_mode'] === 'usd' ? (float) $in['other_value'] : $supplierUsd * (float) $in['other_value'] / 100;
        $cost = $supplierUsd + $other;
        // D-255 (5 Nov 2026): the chosen specification options change the cost — % options
        // multiply it, fixed USD amounts are added, the quantity discount comes last — so the
        // margin, incentive, commission and buffer are always added on top.
        $adjust = $in['adjust'] ?? null;
        if (is_array($adjust)) {
            foreach ((array) ($adjust['pct'] ?? []) as $p) {
                $cost *= 1 + (float) $p / 100;
            }
            $cost += (float) ($adjust['usd'] ?? 0);
            $cost *= 1 + (float) ($adjust['qty_pct'] ?? 0) / 100;
            if ($cost <= 0) {
                throw new \InvalidArgumentException('The chosen options bring the price to zero or below.');
            }
        }
        $minimum = $cost / (1 - $margin / 100);
        $reserve = 0.0;
        if ($in['program_on']) {
            $reserve = $in['incentive_mode'] === 'usd' ? (float) $in['incentive_value'] : ($minimum - $cost) * (float) $in['incentive_value'] / 100;
        }
        $floor = $minimum + $reserve;
        $quote = $floor * (1 + (float) $in['buffer_pct'] / 100);

        return [
            'supplier_usd' => self::round2($supplierUsd),
            'other_costs' => self::round2($other),
            'cost' => self::round2($cost),
            'minimum' => self::round2($minimum),
            'reserve' => self::round2($reserve),
            'staff_floor' => self::round2($floor),
            'staff_quote' => self::round2($quote),
            'raw' => ['cost' => $cost, 'minimum' => $minimum, 'staff_floor' => $floor, 'buffer_pct' => (float) $in['buffer_pct']],
        ];
    }

    /**
     * An associate's floor and quote price from the unrounded staff floor.
     *
     * @param array{staff_floor: float, buffer_pct: float} $raw  build()['raw']
     * @param string $kind 'pct' | 'fixed_usd'
     * @return array{floor: float, quote: float}
     */
    public static function associate(array $raw, string $kind, float $value): array
    {
        if ($kind === 'pct') {
            if ($value < 0 || $value >= 100) {
                throw new \InvalidArgumentException('A commission % must be at least 0 and below 100.');
            }
            $floor = $raw['staff_floor'] / (1 - $value / 100);
        } else {
            $floor = $raw['staff_floor'] + $value;
        }
        return ['floor' => self::round2($floor), 'quote' => self::round2($floor * (1 + $raw['buffer_pct'] / 100))];
    }

    /**
     * Half-up rounding to 2 decimals that ignores binary noise (1326.125 → 1326.13,
     * 1357.0249999999999 → 1357.03), done on the decimal string so Node matches exactly.
     */
    public static function round2(float $value): float
    {
        $negative = $value < 0;
        [$int, $frac] = explode('.', number_format(abs($value), 9, '.', ''));
        $cents = (int) $int * 100 + (int) substr($frac, 0, 2);
        if ((int) $frac[2] >= 5) {
            $cents++;
        }
        $result = $cents / 100;
        return $negative ? -$result : $result;
    }
}
