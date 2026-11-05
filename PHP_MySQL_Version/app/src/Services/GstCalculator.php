<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): GST on a Domestic Trade (DOM)
 * sale. Pure maths, no database — the same figures as gstCalculator.js.
 *
 *  - Same state (the seller's GST state = the place of supply): CGST + SGST,
 *    each half of the rate. Another state: IGST at the full rate.
 *  - Each line: taxable = quantity × rate, then its tax, each rounded to the
 *    paisa (half away from zero). Totals are the sums of the lines.
 *  - The invoice total is rounded to the rupee; the difference is the
 *    "round off" line.
 *  - All money is worked in whole paise (integers), so 0.1 + 0.2 never goes
 *    wrong; amounts come back as strings with two decimals ("1234.50").
 *
 * GST rates and the rules are standard Indian rules — to be confirmed by the CA.
 */
final class GstCalculator
{
    private const GSTIN_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * @param list<array<string,mixed>> $lines each: quantity, rate_inr, gst_rate_pct, hsn_code (+ anything else, kept)
     * @return array<string,mixed>
     */
    public static function compute(array $lines, string $sellerStateCode, string $placeOfSupply): array
    {
        $intra = $sellerStateCode !== '' && $sellerStateCode === $placeOfSupply;
        $out = [];
        $hsn = [];
        $sum = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
        foreach ($lines as $line) {
            $taxable = self::roundDiv(self::scaled((string) $line['quantity'], 3) * self::scaled((string) $line['rate_inr'], 2), 1000);
            $rateBp = self::scaled((string) $line['gst_rate_pct'], 2); // 18.00 % => 1800
            if ($intra) {
                $cgst = self::roundDiv($taxable * $rateBp, 20000); // half the rate
                $sgst = $cgst;
                $igst = 0;
            } else {
                $cgst = 0;
                $sgst = 0;
                $igst = self::roundDiv($taxable * $rateBp, 10000);
            }
            $out[] = $line + [
                'taxable' => self::money($taxable),
                'cgst' => self::money($cgst),
                'sgst' => self::money($sgst),
                'igst' => self::money($igst),
                'line_total' => self::money($taxable + $cgst + $sgst + $igst),
            ];
            $key = (string) $line['hsn_code'] . '|' . self::money($rateBp);
            $hsn[$key] ??= ['hsn_code' => (string) $line['hsn_code'], 'gst_rate_pct' => self::money($rateBp), 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            $hsn[$key]['taxable'] += $taxable;
            $hsn[$key]['cgst'] += $cgst;
            $hsn[$key]['sgst'] += $sgst;
            $hsn[$key]['igst'] += $igst;
            $sum['taxable'] += $taxable;
            $sum['cgst'] += $cgst;
            $sum['sgst'] += $sgst;
            $sum['igst'] += $igst;
        }
        $gross = $sum['taxable'] + $sum['cgst'] + $sum['sgst'] + $sum['igst'];
        $rounded = self::roundDiv($gross, 100) * 100;
        $summary = [];
        foreach ($hsn as $row) {
            $summary[] = [
                'hsn_code' => $row['hsn_code'],
                'gst_rate_pct' => $row['gst_rate_pct'],
                'taxable' => self::money($row['taxable']),
                'cgst' => self::money($row['cgst']),
                'sgst' => self::money($row['sgst']),
                'igst' => self::money($row['igst']),
                'tax_total' => self::money($row['cgst'] + $row['sgst'] + $row['igst']),
            ];
        }
        return [
            'intra_state' => $intra,
            'lines' => $out,
            'hsn_summary' => $summary,
            'taxable_total' => self::money($sum['taxable']),
            'cgst_total' => self::money($sum['cgst']),
            'sgst_total' => self::money($sum['sgst']),
            'igst_total' => self::money($sum['igst']),
            'tax_total' => self::money($sum['cgst'] + $sum['sgst'] + $sum['igst']),
            'gross_total' => self::money($gross),
            'round_off' => self::money($rounded - $gross),
            'invoice_total' => self::money($rounded),
        ];
    }

    /** Whether a GSTIN is well formed: state code, PAN, entity number, Z, and the check character. */
    public static function isValidGstin(string $gstin): bool
    {
        $gstin = strtoupper(trim($gstin));
        if (preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin) !== 1) {
            return false;
        }
        return self::gstinCheckChar(substr($gstin, 0, 14)) === $gstin[14];
    }

    /** The 15th character of a GSTIN, from its first 14 (the GSTN's base-36 check). */
    public static function gstinCheckChar(string $first14): string
    {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $value = strpos(self::GSTIN_CHARS, $first14[$i]);
            $product = (int) $value * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + $product % 36;
        }
        return self::GSTIN_CHARS[(36 - $sum % 36) % 36];
    }

    /** "12.5" with 3 decimals => 12500, exactly (no floating point). */
    public static function scaled(string $value, int $decimals): int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $frac = substr(str_pad($frac, $decimals + 1, '0'), 0, $decimals + 1);
        $n = (int) ($whole === '' ? '0' : $whole) * (10 ** $decimals) + intdiv((int) $frac, 10);
        if ((int) $frac % 10 >= 5) {
            $n++; // a 4th decimal place rounds half away from zero
        }
        return $negative ? -$n : $n;
    }

    /** $a / $b rounded half away from zero ($b > 0). */
    public static function roundDiv(int $a, int $b): int
    {
        $q = intdiv(abs($a) * 2 + $b, 2 * $b);
        return $a < 0 ? -$q : $q;
    }

    /** Paise => "1234.50". */
    public static function money(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);
        return $sign . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "1234.5" => "1,234.50" in the Indian grouping (12,34,567.00). */
    public static function inr(string|float|int $amount): string
    {
        $paise = self::scaled((string) $amount, 2);
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);
        $whole = (string) intdiv($paise, 100);
        if (strlen($whole) > 3) {
            $last3 = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest . ',' . $last3;
        }
        return $sign . $whole . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }
}
