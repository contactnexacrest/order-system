<?php

declare(strict_types=1);

/**
 * Spec Section 10 — "AUTO-NOTIFICATIONS" (LUT/RCMC expiry, FDN payment
 * overdue) plus Section 16's dispute response timer. Run once daily on a
 * cPanel Cron Job (README has the exact setup).
 *
 * Scope note: this creates in-app notifications (the notifications table
 * — what a logged-in user sees) for every condition the spec names. It
 * does NOT also email those alerts out — the spec's "escalation to MD"
 * language could mean either a notification or an email, and building a
 * second templated-email path for six different alert types, on top of
 * the buyer-facing deferred-send pipeline this phase already delivers,
 * was cut to keep this phase bounded. Every alert an MD would want to see
 * shows up in their notification bell the same day; wiring the same
 * conditions to outbound email is a small, mechanical follow-up against
 * EmailService::sendPlainText() whenever that's wanted.
 *
 * Usage: php /path/to/app/cron/check_alerts.php
 */

require __DIR__ . '/../bootstrap.php';

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;

$pdo = Database::connection();
$today = new DateTimeImmutable('today');
$created = 0;

$mdAndAdminIds = array_map(
    static fn(array $u): int => (int) $u['id'],
    array_filter(UserRepository::listActive(), static fn(array $u): bool => in_array($u['role_name'], ['Admin', 'Managing Director'], true))
);

function notifyMdAndAdmin(array $userIds, string $type, string $message, ?int $relatedOrderId = null): int
{
    $count = 0;
    foreach ($userIds as $userId) {
        if (NotificationRepository::existsToday($userId, $type, $relatedOrderId)) {
            continue; // already alerted this same condition today — don't spam the bell
        }
        NotificationRepository::create($userId, null, $type, $relatedOrderId, $message);
        $count++;
    }
    return $count;
}

// --- LUT expiry ---
$lutExpiry = CompanySettingsRepository::get('lut_expiry_date');
if ($lutExpiry) {
    $daysLeft = $today->diff(new DateTimeImmutable($lutExpiry))->days * (new DateTimeImmutable($lutExpiry) >= $today ? 1 : -1);
    $alertDays = (int) (CompanySettingsRepository::get('lut_alert_days_x') ?? '30');
    $escalationDays = (int) (CompanySettingsRepository::get('lut_escalation_days_y') ?? '15');
    if ($daysLeft <= $escalationDays && $daysLeft >= 0) {
        $created += notifyMdAndAdmin($mdAndAdminIds, 'lut_escalation', "ESCALATION: LUT expires in {$daysLeft} day(s) ({$lutExpiry}). Renew before 1 April or document generation will be blocked.");
    } elseif ($daysLeft <= $alertDays && $daysLeft >= 0) {
        $created += notifyMdAndAdmin($mdAndAdminIds, 'lut_expiry', "LUT expires in {$daysLeft} day(s) ({$lutExpiry}).");
    }
}

// --- RCMC expiry ---
$rcmcExpiry = CompanySettingsRepository::get('rcmc_valid_until');
if ($rcmcExpiry) {
    $daysLeft = $today->diff(new DateTimeImmutable($rcmcExpiry))->days * (new DateTimeImmutable($rcmcExpiry) >= $today ? 1 : -1);
    $alertDays = (int) (CompanySettingsRepository::get('rcmc_alert_days_a') ?? '60');
    $escalationDays = (int) (CompanySettingsRepository::get('rcmc_escalation_days_b') ?? '30');
    if ($daysLeft <= $escalationDays && $daysLeft >= 0) {
        $created += notifyMdAndAdmin($mdAndAdminIds, 'rcmc_escalation', "ESCALATION: RCMC certificate expires in {$daysLeft} day(s) ({$rcmcExpiry}). Renew with CAPEXIL.");
    } elseif ($daysLeft <= $alertDays && $daysLeft >= 0) {
        $created += notifyMdAndAdmin($mdAndAdminIds, 'rcmc_expiry', "RCMC certificate expires in {$daysLeft} day(s) ({$rcmcExpiry}).");
    }
}

// --- FDN payment overdue (freight generated, not yet cleared, C working days elapsed) ---
$fdnOverdueDays = (int) (CompanySettingsRepository::get('fdn_overdue_days_c') ?? '7');
$stmt = $pdo->prepare(
    "SELECT o.id AS order_id, o.order_reference, d.generated_at
     FROM order_freight of_
     JOIN documents d ON d.id = of_.fdn_document_id
     JOIN orders o ON o.id = of_.order_id
     LEFT JOIN order_payment_status ps ON ps.order_id = o.id
     WHERE of_.fdn_document_id IS NOT NULL
       AND (ps.freight_cleared_at IS NULL)
       AND DATE(d.generated_at) <= DATE_SUB(CURDATE(), INTERVAL :days DAY)"
);
$stmt->execute(['days' => $fdnOverdueDays]);
foreach ($stmt->fetchAll() as $row) {
    $created += notifyMdAndAdmin($mdAndAdminIds, 'fdn_overdue', "Freight payment overdue on order {$row['order_reference']} — FDN issued {$row['generated_at']}, still not cleared.", (int) $row['order_id']);
}

// --- Dispute response overdue ---
$stmt = $pdo->query(
    "SELECT d.id, d.order_id, d.response_due_date, d.assigned_to, o.order_reference
     FROM disputes d JOIN orders o ON o.id = d.order_id
     WHERE d.status IN ('Open', 'Under Review')
       AND d.response_due_date IS NOT NULL
       AND d.response_due_date < CURDATE()"
);
foreach ($stmt->fetchAll() as $row) {
    $targets = $mdAndAdminIds;
    if ($row['assigned_to']) {
        $targets[] = (int) $row['assigned_to'];
    }
    $created += notifyMdAndAdmin(array_unique($targets), 'dispute_overdue', "Dispute response overdue on order {$row['order_reference']} — was due {$row['response_due_date']}.", (int) $row['order_id']);
}

echo "[check_alerts] created {$created} notification(s)." . PHP_EOL;
