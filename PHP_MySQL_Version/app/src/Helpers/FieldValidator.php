<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * QA Round 5, R5-28/R5-29: the server checks the same simple rules the
 * browser checks (app.js fieldError()), so a request that bypasses the
 * browser — an old cached app.js, JavaScript off, a scripted post — gets a
 * clear message instead of a database error (HTTP 500) or a stored bad
 * value. Every check returns null when the value is fine, else the message
 * to show. A blank value is fine here; "required" stays with each form.
 * Mirrors fieldValidator.js on the Node stack word for word.
 */
final class FieldValidator
{
    /** Largest exchange rate the database keeps (DECIMAL(10,4)). */
    public const MAX_RATE = 999999.9999;
    /** Largest money amount the database keeps (DECIMAL(14,2)). */
    public const MAX_AMOUNT = 999999999999.99;

    /**
     * QA Round 6, R6-N22/N23: an exchange rate that survives being stored —
     * at least 0.0001 once rounded to 4 decimals, at most MAX_RATE.
     */
    public static function exchangeRate(float $rate, string $label = 'The exchange rate'): ?string
    {
        if (!is_finite($rate) || round($rate, 4) <= 0) {
            return "{$label} must be at least 0.0001 (₹ per 1 unit of the order currency).";
        }
        if ($rate > self::MAX_RATE) {
            return "{$label} is too large: it can be at most 999999.9999.";
        }
        return null;
    }

    /**
     * QA Round 6, R6-N14/N15/N19/N21: a money amount that survives being
     * stored — at least 0.01 once rounded to 2 decimals, at most MAX_AMOUNT.
     */
    /**
     * Owner rule D-212 (2026-10-31): money that moved through a bank carries
     * its bank reference (UTR / SWIFT / FIRC / e-scrip / cheque no.), so the
     * record can be matched to the bank statement years later.
     */
    public static function bankReference(string $value, string $label = 'The bank reference'): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return "{$label} is required (UTR / SWIFT / bank reference as on the bank's advice).";
        }
        if (mb_strlen($value) < 4 || mb_strlen($value) > 100) {
            return "{$label} must be 4 to 100 characters.";
        }
        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\/_#:()-]*$/u', $value) !== 1) {
            return "{$label} may contain letters, digits, spaces and . / - _ # : ( ) only.";
        }
        return null;
    }

    public static function moneyAmount(float $amount, string $label): ?string
    {
        if (!is_finite($amount) || round($amount, 2) <= 0) {
            return "{$label} must be at least 0.01.";
        }
        if ($amount > self::MAX_AMOUNT) {
            return "{$label} is too large: it can be at most 999999999999.99.";
        }
        return null;
    }

    /**
     * QA Round 6, R6-N19/N20/N21: a payment the buyer reports (portal or
     * e-mailed link) — the amount, if given, above 0 and storable; the date,
     * if given, a real day that is not in the future.
     */
    /** HNT-005: the earliest payment date a buyer may report (also the date field's min attribute). */
    public const EARLIEST_PAYMENT_DATE = '2000-01-01';

    public static function reportedPayment(string $amount, string $date): ?string
    {
        $amount = trim($amount);
        $date = trim($date);
        if ($amount !== '') {
            if (!is_numeric($amount)) {
                return 'The amount paid must be a number.';
            }
            if ($error = self::moneyAmount((float) $amount, 'The amount paid')) {
                return $error;
            }
        }
        if ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $date > date('Y-m-d'))) {
            return 'Enter the date you paid (today or earlier).';
        }
        // HNT-005: a year like 0000 or 1900 is a typing slip, not a payment date.
        if ($date !== '' && $date < self::EARLIEST_PAYMENT_DATE) {
            return 'Enter the date you paid — it cannot be before ' . date('j M Y', (int) strtotime(self::EARLIEST_PAYMENT_DATE)) . '.';
        }
        return null;
    }

    public static function wholeNumber(string $value, string $label, int $min = 0, ?int $max = null): ?string
    {
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $value) || (int) $value < $min || ($max !== null && (strlen(ltrim($value, '-0')) > 9 || (int) $value > $max))) {
            return $max === null ? "{$label} must be a whole number of {$min} or more." : "{$label} must be a whole number from {$min} to {$max}.";
        }
        return null;
    }

    public static function decimal(string $value, string $label, float $min = 0.0, ?float $max = null, ?int $maxDecimals = null): ?string
    {
        if ($value === '') {
            return null;
        }
        // R10-N03: more decimals than the column keeps would be rounded away silently.
        if ($maxDecimals !== null && preg_match('/^-?\d*\.(\d+)$/', $value, $m) && strlen(rtrim($m[1], '0')) > $maxDecimals) {
            return "{$label}: use at most {$maxDecimals} decimal" . ($maxDecimals === 1 ? '' : 's') . '.';
        }
        if (!preg_match('/^-?\d*\.?\d+$/', $value) || (float) $value < $min || ($max !== null && (float) $value > $max)) {
            return $max === null
                ? "{$label} must be a number of " . self::plain($min) . ' or more (digits and a decimal point only).'
                : "{$label} must be a number from " . self::plain($min) . ' to ' . self::plain($max) . '.';
        }
        return null;
    }

    public static function email(string $value, string $label): ?string
    {
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value)) {
            return "{$label} must be a valid e-mail address.";
        }
        return null;
    }

    /** The number fields on the New Order and Edit Order forms — the first problem found, or null. */
    /** R10-N03: upper limits of the order form's numbers (generous business limits, well inside the columns). */
    public const MAX_LEAD_DAYS = 365;
    public const MAX_CBM = 10000.0;
    public const MAX_WEIGHT_KG = 1000000.0;
    public const MAX_ORDER_CHARGE = 10000000.0;

    public static function orderNumbers(array $post): ?string
    {
        $value = static fn (string $name): string => Input::scalarString($post[$name] ?? '');
        // QA Round 10, R10-N03: each number has an upper limit (a huge value was a server error); the same limits
        // are on the form fields (max / data-max-value / data-max-decimals) and in the Node twin.
        foreach ([
            'lead_time_min_days' => 'Production lead time (min days)',
            'lead_time_max_days' => 'Production lead time (max days)',
            'sailing_lead_time_min_days' => 'Sailing time (min days)',
            'sailing_lead_time_max_days' => 'Sailing time (max days)',
        ] as $name => $label) {
            if ($error = self::wholeNumber($value($name), $label, 0, self::MAX_LEAD_DAYS)) {
                return $error;
            }
        }
        foreach ([
            'estimated_total_cbm' => ['Total CBM', self::MAX_CBM, 3],
            'estimated_gross_weight_kg' => ['Gross weight (kg)', self::MAX_WEIGHT_KG, 2],
            'estimated_net_weight_kg' => ['Net weight (kg)', self::MAX_WEIGHT_KG, 2],
            'indicative_freight_amount' => ['Indicative freight', self::MAX_ORDER_CHARGE, 2],
            'indicative_insurance_amount' => ['Indicative insurance', self::MAX_ORDER_CHARGE, 2],
        ] as $name => [$label, $max, $decimals]) {
            if ($error = self::decimal($value($name), $label, 0.0, $max, $decimals)) {
                return $error;
            }
        }
        // QA Round 6, R6-N28: a range's minimum is not above its maximum,
        // and the net weight is not above the gross weight.
        foreach ([
            ['lead_time_min_days', 'lead_time_max_days', 'The production lead time minimum cannot be more than its maximum.'],
            ['sailing_lead_time_min_days', 'sailing_lead_time_max_days', 'The sailing time minimum cannot be more than its maximum.'],
            ['estimated_net_weight_kg', 'estimated_gross_weight_kg', 'The net weight cannot be more than the gross weight.'],
        ] as [$low, $high, $message]) {
            if ($value($low) !== '' && $value($high) !== '' && (float) $value($low) > (float) $value($high)) {
                return $message;
            }
        }
        return null;
    }

    private static function plain(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
