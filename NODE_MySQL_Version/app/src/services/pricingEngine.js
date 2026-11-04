'use strict';

/**
 * Port of App\Services\PricingEngine — owner decision D-245 (4 Nov 2026): the
 * price build-up as pure maths, identical to PHP step for step.
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

/**
 * Half-up rounding to 2 decimals that ignores binary noise (1326.125 → 1326.13,
 * 1357.0249999999999 → 1357.03), done on the decimal string exactly like PHP.
 */
function round2(value) {
  const negative = value < 0;
  const [int, frac] = Math.abs(value).toFixed(9).split('.');
  let cents = Number(int) * 100 + Number(frac.slice(0, 2));
  if (Number(frac[2]) >= 5) cents += 1;
  const result = cents / 100;
  return negative ? -result : result;
}

function build(input) {
  const fx = Number(input.fx_rate);
  if (!(fx > 0)) throw new Error('The pricing exchange rate must be above 0.');
  const margin = Number(input.margin_pct);
  if (margin < 0 || margin >= 100) throw new Error('The margin must be at least 0 and below 100 %.');
  const supplierUsd = Number(input.supplier_inr) / fx;
  const other = input.other_mode === 'usd' ? Number(input.other_value) : supplierUsd * Number(input.other_value) / 100;
  const cost = supplierUsd + other;
  const minimum = cost / (1 - margin / 100);
  let reserve = 0;
  if (input.program_on) {
    reserve = input.incentive_mode === 'usd' ? Number(input.incentive_value) : (minimum - cost) * Number(input.incentive_value) / 100;
  }
  const floor = minimum + reserve;
  const quote = floor * (1 + Number(input.buffer_pct) / 100);
  return {
    supplier_usd: round2(supplierUsd),
    other_costs: round2(other),
    cost: round2(cost),
    minimum: round2(minimum),
    reserve: round2(reserve),
    staff_floor: round2(floor),
    staff_quote: round2(quote),
    raw: { cost, minimum, staff_floor: floor, buffer_pct: Number(input.buffer_pct) },
  };
}

/** An associate's floor and quote price from the unrounded staff floor (kind 'pct' | 'fixed_usd'). */
function associate(raw, kind, value) {
  let floor;
  if (kind === 'pct') {
    if (value < 0 || value >= 100) throw new Error('A commission % must be at least 0 and below 100.');
    floor = raw.staff_floor / (1 - value / 100);
  } else {
    floor = raw.staff_floor + value;
  }
  return { floor: round2(floor), quote: round2(floor * (1 + raw.buffer_pct / 100)) };
}

module.exports = { build, associate, round2 };
