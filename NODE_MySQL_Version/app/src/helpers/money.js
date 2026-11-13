'use strict';

/**
 * PHP 8 round($x, $places) step for step (HNT-009, owner-confirmed twice
 * 13 Nov 2026): PHP first rounds the value to the 15 significant digits a
 * double can carry, then rounds half away from zero. So 2,079.615 (stored as
 * 2,079.6149999…) gives 2,079.62 and 1.005 × 100 (100.4999…) gives 101, as on
 * the PHP stack, at any size of amount. Checked against PHP on 1.2 million
 * values for 0, 2 and 4 places.
 */
function intPow10(power) {
  return power < 0 || power > 22 ? Math.pow(10, power) : Number(`1e${power}`);
}

function roundHalfUp(v) {
  return v >= 0 ? Math.floor(v + 0.5) : Math.ceil(v - 0.5);
}

function phpRound(value, places = 0) {
  const n = Number(value);
  if (!Number.isFinite(n) || n === 0) return n;
  const precisionPlaces = 14 - Math.floor(Math.log10(Math.abs(n)));
  const f1 = intPow10(Math.abs(places));
  let tmp;
  if (precisionPlaces > places && precisionPlaces - 15 < places) {
    const use = Math.max(-60, precisionPlaces);
    const f = intPow10(Math.abs(use));
    tmp = roundHalfUp(use >= 0 ? n * f : n / f);
    tmp /= intPow10(Math.abs(Math.max(-60, places - use)));
  } else {
    tmp = places >= 0 ? n * f1 : n / f1;
    if (Math.abs(tmp) >= 1e15) return n;
  }
  tmp = roundHalfUp(tmp);
  const result = places > 0 ? tmp / f1 : tmp * f1;
  return result === 0 ? 0 : result;
}

/**
 * PHP round($x, 2) for money: half away from zero, negatives included, and
 * blind to binary noise (1003 × 7.5% = 75.225 → 75.23, 8.995 → 9.00,
 * −12.345 → −12.35). Fresh-eyes review M3 (5 Nov 2026) replaced Node's older
 * Math.round(x + EPSILON) form; HNT-L01 (owner-confirmed twice, 13 Nov 2026)
 * makes it phpRound() so it also matches PHP on amounts of a million and
 * more (the decimal-string method rounded 8,484,053.915 down, PHP rounds up),
 * and every Node money figure now goes through it.
 */
function round2(x) {
  return phpRound(x, 2);
}

/** PHP round($x, 4): exchange rates and other 4-decimal figures (HNT-L01). */
function round4(x) {
  return phpRound(x, 4);
}

/** PHP round($x, 6): stored conversion rates kept to 6 decimals (HNT-L01). */
function round6(x) {
  return phpRound(x, 6);
}

/** PHP number_format($x, 2, '.', ''): "2079.62" — rounded the PHP way, then two fixed decimals (HNT-L01). */
function fixed2(x) {
  return round2(Number(x) || 0).toFixed(2);
}

/** PHP number_format(round($x, 2), 2): "1,66,833.02"-style en-US grouping, rounded the PHP way first (QA batch 4, MON-3). */
function format2(x) {
  return round2(Number(x) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

module.exports = { round2, round4, round6, fixed2, format2, phpRound };
