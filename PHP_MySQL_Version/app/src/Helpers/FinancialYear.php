<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * India's financial year (1 April - 31 March), as a small shared utility —
 * NOT a shared reporting engine. Both the order-pipeline Reports module and
 * the CA / Accounting module's own reports call this for FY bucketing, but
 * their reports, tables, and queries stay completely independent; sharing
 * this date-math avoids two copies of "which FY does this date fall in"
 * silently drifting apart, nothing more.
 */
final class FinancialYear
{
    /** 'YYYY-MM-DD...' -> '2026-27' (a date in April 2026 through March 2027). */
    public static function label(string $date): string
    {
        $dt = new \DateTimeImmutable($date);
        $year = (int) $dt->format('Y');
        $month = (int) $dt->format('n');
        $startYear = $month >= 4 ? $year : $year - 1;
        return $startYear . '-' . substr((string) ($startYear + 1), -2);
    }

    public static function current(): string
    {
        return self::label(date('Y-m-d'));
    }

    /** '2026-27' -> ['start' => '2026-04-01', 'end' => '2027-03-31']. */
    public static function bounds(string $fyLabel): array
    {
        $startYear = (int) substr($fyLabel, 0, 4);
        return [
            'start' => sprintf('%04d-04-01', $startYear),
            'end'   => sprintf('%04d-03-31', $startYear + 1),
        ];
    }

    /**
     * Every FY label with at least one row in $dates (each a date string),
     * newest first — for populating a "which year" picker from real data
     * instead of an arbitrary fixed range.
     *
     * @param array<int, string> $dates
     * @return array<int, string>
     */
    public static function labelsPresentIn(array $dates): array
    {
        $labels = [];
        foreach ($dates as $date) {
            $labels[self::label($date)] = true;
        }
        $result = array_keys($labels);
        rsort($result);
        return $result;
    }
}
