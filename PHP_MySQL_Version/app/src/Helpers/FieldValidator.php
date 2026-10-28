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
    public static function wholeNumber(string $value, string $label, int $min = 0): ?string
    {
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $value) || (int) $value < $min) {
            return "{$label} must be a whole number of {$min} or more.";
        }
        return null;
    }

    public static function decimal(string $value, string $label, float $min = 0.0, ?float $max = null): ?string
    {
        if ($value === '') {
            return null;
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
    public static function orderNumbers(array $post): ?string
    {
        $value = static fn (string $name): string => Input::scalarString($post[$name] ?? '');
        foreach ([
            'lead_time_min_days' => 'Production lead time (min days)',
            'lead_time_max_days' => 'Production lead time (max days)',
            'sailing_lead_time_min_days' => 'Sailing time (min days)',
            'sailing_lead_time_max_days' => 'Sailing time (max days)',
        ] as $name => $label) {
            if ($error = self::wholeNumber($value($name), $label)) {
                return $error;
            }
        }
        foreach ([
            'estimated_total_cbm' => 'Total CBM',
            'estimated_gross_weight_kg' => 'Gross weight (kg)',
            'estimated_net_weight_kg' => 'Net weight (kg)',
            'indicative_freight_amount' => 'Indicative freight',
            'indicative_insurance_amount' => 'Indicative insurance',
        ] as $name => $label) {
            if ($error = self::decimal($value($name), $label)) {
                return $error;
            }
        }
        return null;
    }

    private static function plain(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
