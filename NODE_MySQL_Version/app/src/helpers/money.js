'use strict';

/**
 * PHP round($x, 2) for money: half away from zero, negatives included, and
 * blind to binary noise (1003 × 7.5% = 75.225 → 75.23, 8.995 → 9.00,
 * −12.345 → −12.35). Fresh-eyes review M3 (5 Nov 2026): the older
 * Math.round(x + EPSILON) form rounded some halves down and every negative
 * half towards zero, so Node and PHP could differ by a paisa on commission,
 * incentive, TDS and CAFIN figures. Same decimal-string method as
 * pricingEngine.round2.
 */
function round2(x) {
  const n = Number(x);
  if (!Number.isFinite(n)) return n;
  const negative = n < 0;
  const [int, frac] = Math.abs(n).toFixed(9).split('.');
  let cents = Number(int) * 100 + Number(frac.slice(0, 2));
  if (Number(frac[2]) >= 5) cents += 1;
  const result = cents / 100;
  return negative && result !== 0 ? -result : result;
}

module.exports = { round2 };
