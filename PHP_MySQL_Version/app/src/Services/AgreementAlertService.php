<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AgreementRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;

/**
 * Owner decision 2 Nov 2026 (point 4): a reminder to everyone who manages agreements 30 and 7 days
 * before an agreement expires and on the expiry day — once per threshold, never repeated by a
 * re-run. Covers the register's own agreements and the client commercial agreements (which had no
 * reminder before). Associate agreements keep their own reminder to the associate and are included
 * here for staff too. Run from cron/check_alerts.php. Node twin: agreementAlertService.js.
 */
final class AgreementAlertService
{
    /** @return int reminders created */
    public static function run(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $recipients = PermissionRepository::usersWithPermission('manage_agreements');
        if ($recipients === []) {
            return 0;
        }
        $created = 0;
        foreach (AgreementRepository::all($today) as $row) {
            if (empty($row['expires_on']) || in_array($row['status'], ['terminated', 'renewed'], true) || $row['days_left'] === null) {
                continue;
            }
            $days = (int) $row['days_left'];
            $threshold = null;
            foreach (AgreementRepository::ALERT_DAYS as $t) {
                if ($days <= $t && $days >= 0) {
                    $threshold = $t; // the smallest threshold reached
                }
            }
            if ($threshold === null || AgreementRepository::alertAlreadySent((string) $row['key'], (string) $row['expires_on'], $threshold)) {
                continue;
            }
            $message = self::message($row, $days);
            // D-281: e-mailed to the responsible person and backup for agreements (Admin › E-mail settings).
            $created += count(NotificationRepository::notifyRouted($recipients, StaffRouting::companyItem('agreements'), 'agreement_expiry', null, $message));
            AgreementRepository::markAlertSent((string) $row['key'], (string) $row['expires_on'], $threshold);
        }
        return $created;
    }

    /** The reminder text, e.g. "Agreement expires in 7 day(s) (2026-11-09): Commercial NDA with Supplier X …". */
    public static function message(array $row, int $daysLeft): string
    {
        $what = $row['agreement_type'] . ' with ' . (AgreementRepository::PARTY_TYPES[$row['party_type']] ?? $row['party_type']) . ' ' . $row['party_name'];
        $when = $daysLeft === 0 ? 'expires TODAY (' . $row['expires_on'] . ')' : 'expires in ' . $daysLeft . ' day(s) (' . $row['expires_on'] . ')';
        return "Agreement {$when}: {$what}. Renew it or record that it ends — Agreements register.";
    }
}
