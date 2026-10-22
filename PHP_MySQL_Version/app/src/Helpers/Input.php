<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * External QA report R-06/R-15: a scalar form field read as
 * `trim((string) ($_POST['name'] ?? ''))` never rejected array-shaped
 * input (e.g. a fuzzer — or a hand-crafted request — posting
 * `name[]=a&name[]=b`). PHP's `(string)` cast on an array emits an
 * "Array to string conversion" warning and evaluates to the literal
 * string "Array", which then sailed straight past the `=== ''`
 * empty-check and got stored as real data (a role literally named
 * "Array", a compliance task type literally named "Array"). This
 * helper rejects array input explicitly instead, returning $fallback
 * so the caller's own existing empty-value handling takes over —
 * exactly as if the field had been left blank.
 */
final class Input
{
    public static function scalarString(mixed $value, string $fallback = ''): string
    {
        if (is_array($value)) {
            return $fallback;
        }
        return trim((string) ($value ?? $fallback));
    }
}
