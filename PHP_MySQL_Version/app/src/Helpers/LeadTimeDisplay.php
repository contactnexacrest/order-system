<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * 90-item backlog item 16: formats a lead-time day-count range for
 * display as either days (the stored unit, unchanged) or weeks, per the
 * `lead_time_display_unit` company setting. Display only — every stored
 * value and every order form's input field stays in days regardless of
 * this setting; only the Order Detail page's rendered text changes.
 */
final class LeadTimeDisplay
{
    public static function formatRange(?int $minDays, ?int $maxDays, string $unit): string
    {
        if ($minDays === null && $maxDays === null) {
            return '—';
        }

        if ($unit === 'weeks') {
            $min = $minDays !== null ? self::daysToWeeks($minDays) : '?';
            $max = $maxDays !== null ? self::daysToWeeks($maxDays) : '?';
            return "{$min}–{$max}";
        }

        $min = $minDays !== null ? (string) $minDays : '?';
        $max = $maxDays !== null ? (string) $maxDays : '?';
        return "{$min}–{$max}";
    }

    /** Rounds to 1 decimal, then drops a trailing ".0" for a cleaner whole-week display. */
    private static function daysToWeeks(int $days): string
    {
        $weeks = sprintf('%.1f', round($days / 7, 1));
        return str_ends_with($weeks, '.0') ? substr($weeks, 0, -2) : $weeks;
    }
}
