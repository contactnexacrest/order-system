<?php

declare(strict_types=1);

/**
 * Spec Section 10 — "AUTO-NOTIFICATIONS" (LUT/RCMC expiry, FDN payment
 * overdue) plus Section 16's dispute response timer. Run once daily on a
 * cPanel Cron Job (README has the exact setup).
 *
 * Every alert here also goes out by email — via NotificationRepository::
 * create()'s own built-in emailNotification() hook, which every in-app
 * notification already triggers. A compliance/financial deadline
 * (LUT/RCMC expiry, an overdue freight or dispute deadline) sitting
 * unread in a bell icon nobody happened to check is exactly the kind of
 * risk this alert system exists to prevent — email doesn't depend on
 * someone being logged in that day. External QA report N-09: this used
 * to ALSO send a second, separately-worded email directly via
 * EmailService::sendPlainText() right after create() — every recipient
 * of every alert type here got two emails for the same event, one
 * "NexaCrest — New Notification" and one "NexaCrest Alert: ...". Removed
 * — create()'s own email is enough, and existsToday()'s same-day dedup
 * already covers it, so this never sends more than one email per user
 * per condition per day.
 *
 * Usage: php /path/to/app/cron/check_alerts.php
 *
 * Wrapped in run() (mirroring the Node port's own run()/module.exports
 * shape, src/jobs/checkAlerts.js) so a test can require this file once
 * and call run() fresh per test case, instead of every assertion needing
 * its own PHP process.
 */

require __DIR__ . '/../bootstrap.php';

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\PermissionRepository;
use App\Services\WorkingDaysCalculator;

function notifyMdAndAdmin(array $userIds, string $type, string $message, ?int $relatedOrderId = null): int
{
    $count = 0;
    foreach ($userIds as $userId) {
        if (NotificationRepository::existsToday($userId, $type, $relatedOrderId)) {
            continue; // already alerted this same condition today — don't spam the bell (or the inbox)
        }
        NotificationRepository::create($userId, null, $type, $relatedOrderId, $message);
        $count++;
    }
    return $count;
}

function run(): int
{
    $pdo = Database::connection();
    $today = new DateTimeImmutable('today');
    $created = 0;

    // Whoever can manage company settings is who these compliance-deadline
    // escalations are for — checked by permission, not a hardcoded role name,
    // since roles can be renamed via /admin/roles.
    $mdAndAdminIds = PermissionRepository::usersWithPermission('manage_company_settings');

    // --- LUT expiry ---
    $lutExpiry = CompanySettingsRepository::get('lut_expiry_date');
    if ($lutExpiry) {
        $daysLeft = $today->diff(new DateTimeImmutable($lutExpiry))->days * (new DateTimeImmutable($lutExpiry) >= $today ? 1 : -1);
        $alertDays = (int) (CompanySettingsRepository::get('lut_alert_days_x') ?? '30');
        $escalationDays = (int) (CompanySettingsRepository::get('lut_escalation_days_y') ?? '15');
        if ($daysLeft <= $escalationDays && $daysLeft >= 0) {
            $created += notifyMdAndAdmin($mdAndAdminIds, 'lut_escalation', "ESCALATION: LUT expires in {$daysLeft} day(s) ({$lutExpiry}). Renew before 1 April or document generation will be blocked.", null);
        } elseif ($daysLeft <= $alertDays && $daysLeft >= 0) {
            $created += notifyMdAndAdmin($mdAndAdminIds, 'lut_expiry', "LUT expires in {$daysLeft} day(s) ({$lutExpiry}).", null);
        }
    }

    // --- RCMC expiry ---
    $rcmcExpiry = CompanySettingsRepository::get('rcmc_valid_until');
    if ($rcmcExpiry) {
        $daysLeft = $today->diff(new DateTimeImmutable($rcmcExpiry))->days * (new DateTimeImmutable($rcmcExpiry) >= $today ? 1 : -1);
        $alertDays = (int) (CompanySettingsRepository::get('rcmc_alert_days_a') ?? '60');
        $escalationDays = (int) (CompanySettingsRepository::get('rcmc_escalation_days_b') ?? '30');
        if ($daysLeft <= $escalationDays && $daysLeft >= 0) {
            $created += notifyMdAndAdmin($mdAndAdminIds, 'rcmc_escalation', "ESCALATION: RCMC certificate expires in {$daysLeft} day(s) ({$rcmcExpiry}). Renew with CAPEXIL.", null);
        } elseif ($daysLeft <= $alertDays && $daysLeft >= 0) {
            $created += notifyMdAndAdmin($mdAndAdminIds, 'rcmc_expiry', "RCMC certificate expires in {$daysLeft} day(s) ({$rcmcExpiry}).", null);
        }
    }

    // --- FDN payment overdue (freight generated, not yet cleared, C WORKING days
    // elapsed) --- Real bug this closes: the CFR/CIF Indicative Freight Validity
    // clause (seeded on QT and PI) is a written promise to the buyer that
    // freight "must be received BEFORE shipment booking is confirmed... payable
    // within 3 working days of issue" — but this check used plain calendar-day
    // SQL arithmetic against fdn_overdue_days_c=7, so the alert didn't fire
    // until 7 calendar days after issue. For the 4+ days between the buyer
    // actually breaching their 3-WORKING-day written commitment and this alert
    // finally firing, nothing in the system showed the payment as overdue at
    // all. Aligned fdn_overdue_days_c to 3 (the clause's own figure) and
    // switched the comparison to WorkingDaysCalculator, matching the same
    // working-days-not-calendar-days fix already applied to the dispute
    // response deadline.
    $fdnOverdueDays = (int) (CompanySettingsRepository::get('fdn_overdue_days_c') ?? '3');
    $stmt = $pdo->query(
        "SELECT o.id AS order_id, o.order_reference, d.generated_at
         FROM order_freight of_
         JOIN documents d ON d.id = of_.fdn_document_id
         JOIN orders o ON o.id = of_.order_id
         LEFT JOIN order_payment_status ps ON ps.order_id = o.id
         WHERE of_.fdn_document_id IS NOT NULL
           AND ps.freight_cleared_at IS NULL"
    );
    foreach ($stmt->fetchAll() as $row) {
        $dueDate = WorkingDaysCalculator::addWorkingDays(substr((string) $row['generated_at'], 0, 10), $fdnOverdueDays);
        if ($dueDate < $today->format('Y-m-d')) {
            $created += notifyMdAndAdmin($mdAndAdminIds, 'fdn_overdue', "Freight payment overdue on order {$row['order_reference']} — FDN issued {$row['generated_at']}, still not cleared.", (int) $row['order_id']);
        }
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

    // --- Quotation/PI validity expired, order still stuck at that stage ---
    // 90-item backlog "expiry alerts": orders.quotation_valid_until and
    // pi_valid_until (quotation_date/pi_date + their company_settings-driven
    // validity span) have existed since the Quotation/PI documents
    // themselves — rendered on every QT/PI as the buyer-facing "valid for N
    // days" disclaimer — but nothing ever alerted STAFF when one actually
    // lapsed with the order still sitting there, unconfirmed. A buyer who
    // comes back to accept stale pricing/terms after the window closes is
    // exactly the kind of risk this alert system exists to catch, same as
    // every other deadline here — this was simply missing. Checked against
    // is_locked = 0 (excludes complete/lost orders, which have nothing left
    // to confirm) and the order's own progress past the stage where that
    // validity still matters: a quotation stops mattering once a PI is
    // issued (pi_date set); a PI stops mattering once Order Confirmation
    // (Stage 4) is reached.
    $stmt = $pdo->query(
        "SELECT o.id AS order_id, o.order_reference, o.quotation_valid_until
         FROM orders o
         WHERE o.is_locked = 0
           AND o.quotation_valid_until IS NOT NULL
           AND o.quotation_valid_until < CURDATE()
           AND o.pi_date IS NULL"
    );
    foreach ($stmt->fetchAll() as $row) {
        $created += notifyMdAndAdmin($mdAndAdminIds, 'quotation_expired', "Quotation validity expired on order {$row['order_reference']} — was valid until {$row['quotation_valid_until']}, still no PI issued.", (int) $row['order_id']);
    }

    $stmt = $pdo->query(
        "SELECT o.id AS order_id, o.order_reference, o.pi_valid_until
         FROM orders o
         LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
         WHERE o.is_locked = 0
           AND o.pi_valid_until IS NOT NULL
           AND o.pi_valid_until < CURDATE()
           AND o.pi_date IS NOT NULL
           AND (sm.stage_number IS NULL OR sm.stage_number < 4)"
    );
    foreach ($stmt->fetchAll() as $row) {
        $created += notifyMdAndAdmin($mdAndAdminIds, 'pi_expired', "Proforma Invoice validity expired on order {$row['order_reference']} — was valid until {$row['pi_valid_until']}, Order Confirmation not yet reached.", (int) $row['order_id']);
    }

    // --- Export process step 8a: 7-day pre-shipment inspection reminder ---
    // Fires every day the estimated shipment date is within the next 7
    // days (inclusive, not yet passed) for any non-locked order that
    // still has a "Pre-Shipment Inspection" compliance task NOT marked
    // skipped (not applicable) — reusing that existing checklist row as
    // the "does this order even need 3rd-party inspection" signal, rather
    // than a second, duplicate flag. Targets whoever manages orders
    // (production/ops), not just Admin/MD, since this is squarely an
    // operations coordination reminder.
    $orderManagerIds = PermissionRepository::usersWithPermission('manage_orders');
    $stmt = $pdo->query(
        "SELECT o.id AS order_id, o.order_reference, o.est_shipment_date
         FROM orders o
         WHERE o.is_locked = 0
           AND o.est_shipment_date IS NOT NULL
           AND o.est_shipment_date >= CURDATE()
           AND o.est_shipment_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           AND EXISTS (
             SELECT 1 FROM compliance_task_types tt
             LEFT JOIN order_compliance_tasks oct ON oct.task_type_id = tt.id AND oct.order_id = o.id
             WHERE tt.name = 'Pre-Shipment Inspection' AND tt.is_active = 1
               AND COALESCE(oct.status, 'not_started') != 'skipped'
           )"
    );
    foreach ($stmt->fetchAll() as $row) {
        $daysLeft = $today->diff(new DateTimeImmutable($row['est_shipment_date']))->days;
        $created += notifyMdAndAdmin($orderManagerIds, 'pre_shipment_inspection_reminder', "Pre-shipment inspection due soon on order {$row['order_reference']} — estimated shipment {$row['est_shipment_date']} ({$daysLeft} day(s) away). Confirm production completion and prepare for 3rd-party inspection.", (int) $row['order_id']);
    }

    return $created;
}

// Runnable directly (`php cron/check_alerts.php`) for a cPanel Cron Job,
// but not when required by a test — $argv[0] is phpunit's own script
// path in that case, never this file's.
if (($argv[0] ?? null) && realpath($argv[0]) === __FILE__) {
    $created = run();
    echo "[check_alerts] created {$created} notification(s)." . PHP_EOL;
}
