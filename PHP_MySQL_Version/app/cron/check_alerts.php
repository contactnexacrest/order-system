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

/**
 * Owner decision 4 Nov 2026 (alert job speed-up): the job used to ask the
 * database once per person and order "already alerted today?" and once
 * per order for its assignees — slow once there are thousands of orders.
 * It now reads today's notifications, the LC reminders already sent and
 * every order's assignees once per run, and keeps those sets up to date as
 * it adds notifications. The alerts sent are exactly the same.
 */
final class AlertRunCache
{
    /** @var array<string,true>|null "user|type|order" alerted today */
    public static ?array $today = null;
    /** @var array<string,true>|null "user|type|order|message" ever sent (LC reminders) */
    public static ?array $once = null;
    /** @var array<int,list<int>>|null order id => assignee user ids */
    public static ?array $assignees = null;

    public static function reset(): void
    {
        self::$today = null;
        self::$once = null;
        self::$assignees = null;
    }

    public static function todayKey(int $userId, string $type, ?int $orderId): string
    {
        return $userId . '|' . $type . '|' . ($orderId ?? 0);
    }

    /** @return array<string,true> */
    public static function today(): array
    {
        if (self::$today === null) {
            self::$today = [];
            foreach (Database::connection()->query('SELECT user_id, type, related_order_id FROM notifications WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY')->fetchAll() as $r) {
                self::$today[self::todayKey((int) $r['user_id'], (string) $r['type'], $r['related_order_id'] !== null ? (int) $r['related_order_id'] : null)] = true;
            }
        }
        return self::$today;
    }

    /** @return array<string,true> */
    public static function once(): array
    {
        if (self::$once === null) {
            self::$once = [];
            foreach (Database::connection()->query("SELECT user_id, type, related_order_id, message FROM notifications WHERE type IN ('lc_last_shipment_soon', 'lc_presentation_soon', 'lc_expiry_soon')")->fetchAll() as $r) {
                self::$once[$r['user_id'] . '|' . $r['type'] . '|' . (int) $r['related_order_id'] . '|' . self::onceMessageKey((string) $r['message'])] = true;
            }
        }
        return self::$once;
    }

    /**
     * QA batch 3 MAIL-3: a "once" reminder is once per date — the day count
     * in the message ("(5 day(s) away)") changes every day, so it is left out
     * of the dedupe key; the date itself stays in.
     */
    public static function onceMessageKey(string $message): string
    {
        return (string) preg_replace('/ \(\d+ day\(s\) away\)\.$/', '', $message);
    }

    /** @return list<int> */
    public static function assignees(int $orderId): array
    {
        if (self::$assignees === null) {
            self::$assignees = [];
            foreach (Database::connection()->query('SELECT oa.order_id, oa.user_id FROM order_assignees oa JOIN users u ON u.id = oa.user_id ORDER BY oa.order_id, oa.assigned_at')->fetchAll() as $r) {
                self::$assignees[(int) $r['order_id']][] = (int) $r['user_id'];
            }
        }
        return self::$assignees[$orderId] ?? [];
    }
}

function notifyMdAndAdmin(array $userIds, string $type, string $message, ?int $relatedOrderId = null): int
{
    $count = 0;
    $seen = AlertRunCache::today();
    foreach ($userIds as $userId) {
        $key = AlertRunCache::todayKey((int) $userId, $type, $relatedOrderId);
        if (isset($seen[$key])) {
            continue; // already alerted this same condition today — don't spam the bell (or the inbox)
        }
        NotificationRepository::create($userId, null, $type, $relatedOrderId, $message);
        AlertRunCache::$today[$key] = true;
        $seen[$key] = true;
        $count++;
    }
    return $count;
}

/**
 * Gap list G-4 (owner, 1 Nov 2026): a reminder that goes out once per
 * person, order and date (not once a day) — sent again only if the date
 * itself changes (e.g. after an LC amendment).
 */
function notifyOnce(array $userIds, string $type, string $message, int $relatedOrderId): int
{
    $count = 0;
    $sent = AlertRunCache::once();
    foreach (array_unique($userIds) as $userId) {
        $key = $userId . '|' . $type . '|' . $relatedOrderId . '|' . AlertRunCache::onceMessageKey($message);
        if (isset($sent[$key])) {
            continue;
        }
        NotificationRepository::create($userId, null, $type, $relatedOrderId, $message);
        AlertRunCache::$once[$key] = true;
        $sent[$key] = true;
        $count++;
    }
    return $count;
}

/** @return list<int> the order's assignees */
function orderAssignees(int $orderId): array
{
    return AlertRunCache::assignees($orderId);
}

function run(): int
{
    $pdo = Database::connection();
    $today = new DateTimeImmutable('today');
    $created = 0;
    AlertRunCache::reset();

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

    // --- Agreements expiring (owner decision 2 Nov 2026, point 4): 30 / 7 days before and on the day, once each ---
    $created += \App\Services\AgreementAlertService::run($today->format('Y-m-d'));
    // D-257 Phase 4: Merchant Trade's RBI checks (due soon / passed), once per deal, check and level.
    $created += \App\Services\MerchantTradeService::runAlerts($today->format('Y-m-d'));

    // --- TDS on associate commission (owner decision 2 Nov 2026, D-235): deposit by the 7th, Form 26Q after each quarter ---
    $payIncentiveIds = PermissionRepository::usersWithPermission('pay_incentives');
    foreach (\App\Services\AssociateTds::reminders($today->format('Y-m-d')) as $reminder) {
        $created += notifyMdAndAdmin($payIncentiveIds, $reminder['type'], $reminder['message'], null);
    }
    // Owner decision (2 Nov 2026, gap-list Q-6): eBRC / ECGC / incentive-claim reminders, each once.
    $created += \App\Services\ComplianceTrackingService::runReminders($today->format('Y-m-d'));

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
    // Owner decision (2026-10-28): the clause, the FDN and every other buyer
    // payment deadline now read "Calendar Days", so the overdue check counts
    // calendar days too — the alert fires the day after the date printed on
    // the FDN, not later.
    $fdnOverdueDays = (int) (CompanySettingsRepository::get('fdn_overdue_days_c') ?? '3');
    // QA batch 3 MAIL-1: the FDN the buyer was actually issued (the latest
    // released revision — sent, or approved while e-mail is off), counted from
    // the date printed on it; not for a locked, cancelled or lost order.
    $stmt = $pdo->query(
        "SELECT o.id AS order_id, o.order_reference, d.fdn_date
         FROM (" . \App\Repositories\DashboardRepository::releasedFdnSql() . ") d
         JOIN orders o ON o.id = d.order_id
         LEFT JOIN order_payment_status ps ON ps.order_id = o.id
         WHERE ps.freight_cleared_at IS NULL
           AND o.is_locked = 0 AND o.status NOT IN ('cancelled', 'lost')"
    );
    foreach ($stmt->fetchAll() as $row) {
        $dueDate = (new DateTimeImmutable((string) $row['fdn_date']))->modify("+{$fdnOverdueDays} days")->format('Y-m-d');
        if ($dueDate < $today->format('Y-m-d')) {
            $issued = (new DateTimeImmutable((string) $row['fdn_date']))->format('d F Y');
            $created += notifyMdAndAdmin($mdAndAdminIds, 'fdn_overdue', "Freight payment overdue on order {$row['order_reference']} — FDN dated {$issued}, still not cleared.", (int) $row['order_id']);
        }
    }

    // --- Balance payment overdue (gap list G-2, owner 1 Nov 2026) ---
    // Same daily pattern as the freight alert above, to Admin/MD and the
    // order's assignees, from the day after the balance due date.
    $stmt = $pdo->query(
        "SELECT o.id AS order_id, o.order_reference, ps.balance_due_date
         FROM order_payment_status ps
         JOIN orders o ON o.id = ps.order_id
         WHERE ps.balance_due_date IS NOT NULL
           AND ps.balance_due_date < CURDATE()
           AND ps.balance_cleared_at IS NULL
           AND o.is_locked = 0
           AND o.status NOT IN ('cancelled', 'lost')"
    );
    foreach ($stmt->fetchAll() as $row) {
        $created += notifyMdAndAdmin(array_values(array_unique(array_merge($mdAndAdminIds, orderAssignees((int) $row['order_id'])))), 'balance_overdue',
            "Balance payment overdue on order {$row['order_reference']} — was due {$row['balance_due_date']}, still not cleared.", (int) $row['order_id']);
    }

    // --- Letter of Credit dates (gap list G-4, owner 1 Nov 2026) ---
    // Once each, 7 days before the LC's last shipment date, deadline to
    // present documents and expiry, to Admin/MD and the order's assignees,
    // while the bank has not yet paid under the LC.
    $stmt = $pdo->query(
        "SELECT o.id AS order_id, o.order_reference, lc.lc_number, lc.lc_last_shipment_date, lc.lc_presentation_deadline, lc.lc_expiry_date
         FROM order_lc_details lc
         JOIN orders o ON o.id = lc.order_id
         WHERE o.payment_method = 'LC'
           AND o.is_locked = 0
           AND o.status NOT IN ('cancelled', 'lost')
           AND lc.lc_received_at IS NULL"
    );
    $lcDates = ['lc_last_shipment_date' => ['lc_last_shipment_soon', 'last shipment date'], 'lc_presentation_deadline' => ['lc_presentation_soon', 'deadline to present the documents'], 'lc_expiry_date' => ['lc_expiry_soon', 'expiry date']];
    foreach ($stmt->fetchAll() as $row) {
        foreach ($lcDates as $col => [$type, $label]) {
            if (empty($row[$col])) {
                continue;
            }
            $daysLeft = (int) $today->diff(new DateTimeImmutable((string) $row[$col]))->format('%r%a');
            if ($daysLeft < 0 || $daysLeft > 7) {
                continue;
            }
            $lc = !empty($row['lc_number']) ? "LC {$row['lc_number']}" : 'the LC';
            $created += notifyOnce(array_merge($mdAndAdminIds, orderAssignees((int) $row['order_id'])), $type,
                "Order {$row['order_reference']}: {$lc} — {$label} is {$row[$col]} ({$daysLeft} day(s) away).", (int) $row['order_id']);
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
