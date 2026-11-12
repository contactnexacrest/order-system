<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Owner decision D-343 (12 Nov 2026, owner "1 yes, 2 yes, 3 yes" and "confirm 2"):
 * money on the generated documents of an order in Indian Rupees uses Indian digit
 * grouping (lakh / crore: the last three digits, then groups of two) —
 * 1,75,000.00 · 15,40,175.00 · 6,16,070.00. Every other currency keeps the
 * international grouping (1,540,175.00). Two decimals and the minus sign as before.
 * Only money: quantities, measures, weights, rates and percentages are not formatted here.
 *
 * Node twin: src/helpers/moneyFormat.js (same results).
 */
final class MoneyFormat
{
    /** True for the currencies whose documents print Indian digit grouping (INR). */
    public static function usesIndianGrouping(?string $currency): bool
    {
        return strtoupper(trim((string) $currency)) === 'INR';
    }

    /** A money amount as the documents of an order in $currency print it: "1,75,000.00" (INR), "175,000.00" (others). */
    public static function amount(float|int|string $value, ?string $currency, int $decimals = 2): string
    {
        return self::usesIndianGrouping($currency) ? self::indian($value, $decimals) : number_format((float) $value, $decimals);
    }

    /** Indian digit grouping: 1540175 → "15,40,175.00", -123456789.5 → "-12,34,56,789.50". */
    public static function indian(float|int|string $value, int $decimals = 2): string
    {
        $v = (float) $value;
        $plain = number_format(abs($v), $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $plain, 2), 2, null);
        $grouped = $int;
        if (strlen($int) > 3) {
            $head = substr($int, 0, -3);
            $grouped = ltrim(strrev(implode(',', str_split(strrev($head), 2))), ',') . ',' . substr($int, -3);
        }
        $negative = $v < 0 && (float) $plain !== 0.0;
        return ($negative ? '-' : '') . $grouped . ($frac !== null ? '.' . $frac : '');
    }
}
