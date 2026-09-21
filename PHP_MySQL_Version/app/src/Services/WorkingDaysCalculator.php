<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CompanyHolidayRepository;
use App\Repositories\CompanySettingsRepository;

/**
 * A "working day" is any calendar day that is neither a configured weekly
 * off-day (company_settings.weekly_off_days — NexaCrest's Mon-Sat week
 * means Sunday only, by default) nor a specific date in company_holidays.
 * Used anywhere the business genuinely means "N working days" rather than
 * "N calendar days" — e.g. the Dispute Resolution clause's contractual
 * "ten (10) working days" response deadline. Counting a Sunday or a
 * national holiday as a working day would understate a legal deadline
 * that's printed, word-for-word, on buyer-facing documents.
 */
final class WorkingDaysCalculator
{
    /**
     * The date $days working days after $startDate — $startDate itself is
     * never counted (mirrors how a "10 working days from notice" deadline
     * is read in the seeded T&C clause: the notice day itself doesn't
     * count as day one of the response window).
     */
    public static function addWorkingDays(string $startDate, int $days): string
    {
        $offDays = self::weeklyOffDayNumbers();
        $cursor = new \DateTimeImmutable($startDate);

        // Pull the whole holiday window in one query instead of one query
        // per candidate day — a 10-working-day span is at most ~14
        // calendar days, but this keeps the same shape correct even for a
        // much longer span.
        $lookahead = $cursor->modify('+' . ($days * 3 + 30) . ' days');
        $holidays = array_flip(CompanyHolidayRepository::datesBetween(
            $cursor->format('Y-m-d'),
            $lookahead->format('Y-m-d')
        ));

        $remaining = $days;
        while ($remaining > 0) {
            $cursor = $cursor->modify('+1 day');
            $weekday = strtolower($cursor->format('l'));
            if (in_array($weekday, $offDays, true)) {
                continue;
            }
            if (isset($holidays[$cursor->format('Y-m-d')])) {
                continue;
            }
            $remaining--;
        }

        return $cursor->format('Y-m-d');
    }

    public static function isWorkingDay(string $date): bool
    {
        $offDays = self::weeklyOffDayNumbers();
        $d = new \DateTimeImmutable($date);
        if (in_array(strtolower($d->format('l')), $offDays, true)) {
            return false;
        }
        $holidays = CompanyHolidayRepository::datesBetween($date, $date);
        return count($holidays) === 0;
    }

    /** @return array<int, string> lowercase weekday names, e.g. ['sunday'] */
    private static function weeklyOffDayNumbers(): array
    {
        $raw = CompanySettingsRepository::get('weekly_off_days') ?? 'sunday';
        $days = array_map('trim', explode(',', strtolower($raw)));
        return array_filter($days, static fn(string $d): bool => $d !== '');
    }
}
