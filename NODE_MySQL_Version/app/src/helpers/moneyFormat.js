'use strict';

/**
 * Owner decision D-343 (12 Nov 2026, owner "1 yes, 2 yes, 3 yes" and "confirm 2"):
 * money on the generated documents of an order in Indian Rupees uses Indian digit
 * grouping (lakh / crore: the last three digits, then groups of two) —
 * 1,75,000.00 · 15,40,175.00 · 6,16,070.00. Every other currency keeps the
 * international grouping (1,540,175.00). Two decimals and the minus sign as before.
 * Only money: quantities, measures, weights, rates and percentages are not formatted here.
 *
 * PHP twin: src/Helpers/MoneyFormat.php (same results).
 */

/** PHP round($x, $d): half away from zero, blind to binary noise. */
function roundHalfUp(x, decimals) {
  const n = Number(x);
  const negative = n < 0;
  const [int, frac = ''] = Math.abs(n).toFixed(9).split('.');
  const padded = (frac + '000000000').slice(0, 9);
  let units = BigInt(int) * 10n ** BigInt(decimals) + BigInt(padded.slice(0, decimals) || '0');
  if (Number(padded[decimals]) >= 5) units += 1n;
  return { negative, units };
}

/** True for the currencies whose documents print Indian digit grouping (INR). */
function usesIndianGrouping(currency) {
  return String(currency || '').trim().toUpperCase() === 'INR';
}

/** Indian digit grouping: 1540175 → "15,40,175.00", -123456789.5 → "-12,34,56,789.50". */
function indian(value, decimals = 2) {
  const { negative, units } = roundHalfUp(Number(value) || 0, decimals);
  const digits = units.toString().padStart(decimals + 1, '0');
  const int = decimals > 0 ? digits.slice(0, -decimals) : digits;
  const frac = decimals > 0 ? digits.slice(-decimals) : null;
  let grouped = int;
  if (int.length > 3) {
    const head = int.slice(0, -3);
    const pairs = [];
    for (let i = head.length; i > 0; i -= 2) pairs.unshift(head.slice(Math.max(0, i - 2), i));
    grouped = `${pairs.join(',')},${int.slice(-3)}`;
  }
  return (negative && units !== 0n ? '-' : '') + grouped + (frac !== null ? `.${frac}` : '');
}

/** A money amount as the documents of an order in `currency` print it: "1,75,000.00" (INR), "175,000.00" (others). */
function amount(value, currency, decimals = 2) {
  if (usesIndianGrouping(currency)) return indian(value, decimals);
  return Number(value).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

module.exports = { usesIndianGrouping, indian, amount };
