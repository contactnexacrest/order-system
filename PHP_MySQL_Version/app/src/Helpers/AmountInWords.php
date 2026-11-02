<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Owner decision D-224 (D5, 2 Nov 2026, confirmed twice): the Commercial
 * Invoice prints its total in words instead of a blank line, e.g.
 * "US Dollars Twelve Thousand Five Hundred and Fifty Cents Only".
 * International numbering (thousand / million / billion). Node twin:
 * src/helpers/amountInWords.js — both must give identical text.
 */
final class AmountInWords
{
    /** currency code => [plural name, minor-unit name] */
    private const CURRENCIES = [
        'USD' => ['US Dollars', 'Cents'],
        'EUR' => ['Euros', 'Cents'],
        'GBP' => ['Pounds Sterling', 'Pence'],
        'AED' => ['UAE Dirhams', 'Fils'],
        'INR' => ['Indian Rupees', 'Paise'],
    ];

    private const ONES = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    private const SCALES = [1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand'];

    public static function format(float|int|string $amount, ?string $currencyCode): string
    {
        $cents = (int) round(abs((float) $amount) * 100);
        $whole = intdiv($cents, 100);
        $minor = $cents % 100;
        $code = strtoupper(trim((string) $currencyCode));
        [$major, $minorName] = self::CURRENCIES[$code] ?? [$code !== '' ? $code : 'Currency', 'Cents'];

        $text = $major . ' ' . self::words($whole);
        if ($minor > 0) {
            $text .= ' and ' . self::words($minor) . ' ' . $minorName;
        }
        return $text . ' Only';
    }

    public static function words(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        if ($n < 100) {
            return self::TENS[intdiv($n, 10)] . ($n % 10 ? '-' . self::ONES[$n % 10] : '');
        }
        if ($n < 1000) {
            return self::ONES[intdiv($n, 100)] . ' Hundred' . ($n % 100 ? ' ' . self::words($n % 100) : '');
        }
        foreach (self::SCALES as $size => $name) {
            if ($n >= $size) {
                return self::words(intdiv($n, $size)) . ' ' . $name . ($n % $size ? ' ' . self::words($n % $size) : '');
            }
        }
        return (string) $n;
    }
}
