<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SalesTargetRepository;

/**
 * Owner decision D-378 (15 Nov 2026): sales targets — the rules.
 * Build spec: docs/decisions/D-378-sales-targets-spec.md.
 *
 *  - Periods: month; quarter (Jan–Mar …); half (Apr–Sep / Oct–Mar); year (Apr–Mar, Indian FY).
 *  - An order counts for its salesperson when it is CONFIRMED: the earliest of the first advance
 *    received, the LC received, or (no-advance terms only) the buyer PO received AND the PI approved
 *    (the later of the two). Containers = the order's estimated containers, at least 1.
 *  - sync() keeps a snapshot (sales_target_credits) of each confirmed order's dates. Corrections are
 *    dated events: −confirmed when the order is cancelled / lost later, (shipped − confirmed) when
 *    the CI shows another number of containers. A period's count is the sum of the events dated in
 *    it, so a closed period is never rewritten.
 *  - Credit: orders.sales_credit_user_id (moved by the MD / ED), else the order's Sales & Export
 *    owner, else Unassigned — resolved when read. Associates: the order's earning associate.
 *  - Company target (money): (monthly costs + profit) × months; earned = profit after incentive of
 *    the orders shipped in the period; containers needed from the average margin per container of
 *    the last 3 months' shipments.
 *  - Personal targets: own, else the designation default, with a build-up for new joiners.
 *  - Daily alerts (check_alerts): behind, review due, order with no salesperson.
 *
 * This service only READS order data. It never changes a price, incentive, commission, payout,
 * document, Zoho record or order gate.
 *
 * Node twin: NODE_MySQL_Version/app/src/services/salesTargetService.js
 */
final class SalesTargetService
{
    public const PERMISSION = 'manage_sales_targets';

    /** Setting key => default value (stored NULL recipients = these defaults). */
    public const DEFAULTS = [
        'period' => 'quarter',
        'multiple' => '3',
        'buildup_months' => '3',
        'review_months' => '12',
        'alert_pct' => '60',
        'alert_behind_recipients' => null,
        'alert_review_recipients' => null,
        'alert_no_owner_recipients' => null,
    ];

    /** Period type => label for the settings drop-down. */
    public const PERIOD_TYPES = [
        'month' => 'Month',
        'quarter' => 'Quarter (Jan–Mar, Apr–Jun, Jul–Sep, Oct–Dec)',
        'half' => 'Half-year (Apr–Sep, Oct–Mar)',
        'year' => 'Year (Apr–Mar)',
    ];

    /** Mark Lost: the optional category (stored key => label). */
    public const LOST_CATEGORIES = [
        'price' => 'Price',
        'quality' => 'Quality',
        'delivery_time' => 'Delivery time',
        'competitor' => 'Competitor',
        'buyer_postponed' => 'Buyer postponed',
        'other' => 'Other',
    ];

    /** Alert kind => [settings key, label on Admin › E-mail settings, default roles]. */
    public const ALERTS = [
        'behind' => ['alert_behind_recipients', 'Sales target — the company is behind its target', ['Managing Director', 'Executive Director']],
        'review' => ['alert_review_recipients', "Sales target — a person's target is due for review", ['Managing Director', 'Executive Director']],
        'no_owner' => ['alert_no_owner_recipients', 'Sales target — a confirmed order has no salesperson', ['Managing Director', 'Sales/Export Manager']],
    ];

    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** @var array<int, float>|null order id => profit after incentive (one request) */
    private static ?array $profitCache = null;

    public static function resetCache(): void
    {
        self::$profitCache = null;
    }

    // ================================================================ who sees what

    /** Whether the signed-in person may open the Targets page, its settings and move a credit. */
    public static function canManage(): bool
    {
        return self::currentCan(self::PERMISSION);
    }

    /** Money figures (₹, margin, average margin per container): manage_sales_targets AND view_price_buildup. */
    public static function canSeeMoney(): bool
    {
        return self::canManage() && self::currentCan('view_price_buildup');
    }

    private static function currentCan(string $key): bool
    {
        $u = AuthService::currentUser();
        return $u !== null && PermissionService::can((int) $u['id'], $u['role_id'] !== null ? (int) $u['role_id'] : null, $key);
    }

    // ================================================================ settings

    /** @return array<string, ?string> every setting, stored value or default */
    public static function settings(): array
    {
        $stored = [];
        try {
            $stored = SalesTargetRepository::settings();
        } catch (\Throwable $e) {
            // A database before migration 2026-11-15a: defaults.
        }
        $out = self::DEFAULTS;
        foreach ($stored as $k => $v) {
            if (array_key_exists($k, $out)) {
                $out[$k] = $v;
            }
        }
        if (!isset(self::PERIOD_TYPES[(string) $out['period']])) {
            $out['period'] = 'quarter';
        }
        return $out;
    }

    /**
     * Checks the settings form. Empty company costs and profit together switch the company target off.
     *
     * @param array<string, mixed> $post
     * @return array{0: array<string, mixed>, 1: list<string>} [clean values, errors]
     */
    public static function validateSettings(array $post, bool $withMoney): array
    {
        $errors = [];
        $v = [];
        $period = (string) ($post['period'] ?? '');
        if (!isset(self::PERIOD_TYPES[$period])) {
            $errors[] = 'Choose the target period.';
        }
        $v['period'] = $period;
        $multiple = trim((string) ($post['multiple'] ?? ''));
        if (!preg_match('/^\d{1,2}(\.\d)?$/', $multiple) || (float) $multiple < 1 || (float) $multiple > 10) {
            $errors[] = 'The target multiple is a number from 1 to 10 with at most one decimal (e.g. 3 or 2.5).';
        }
        $v['multiple'] = $multiple;
        foreach (['buildup_months' => [0, 6, 'Build-up months'], 'review_months' => [3, 24, 'Review every … months'], 'alert_pct' => [1, 100, 'Alert below … %']] as $k => [$min, $max, $label]) {
            $raw = trim((string) ($post[$k] ?? ''));
            if (!ctype_digit($raw) || (int) $raw < $min || (int) $raw > $max) {
                $errors[] = "{$label}: enter a whole number from {$min} to {$max}.";
            }
            $v[$k] = $raw;
        }
        if ($withMoney) {
            foreach (['costs_monthly' => 'Company costs per month', 'profit_monthly' => 'Profit wanted per month'] as $k => $label) {
                $raw = str_replace(',', '', trim((string) ($post[$k] ?? '')));
                if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 999999999999.99)) {
                    $errors[] = "{$label}: enter an amount in ₹ (0 or more), or leave it empty.";
                }
                $v[$k] = $raw === '' ? null : round((float) $raw, 2);
            }
            $from = trim((string) ($post['effective_from'] ?? ''));
            if ($from !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $from)) {
                $errors[] = 'Effective from: choose a month.';
            }
            $v['effective_from'] = $from === '' ? null : $from . '-01';
            if (($v['costs_monthly'] !== null || $v['profit_monthly'] !== null) && $v['effective_from'] === null) {
                $errors[] = 'Effective from: choose the month the company target starts.';
            }
        }
        // Designation defaults: designation[<id>][containers|new_buyers|quotations], empty = none.
        $v['designations'] = [];
        foreach ((array) ($post['designation'] ?? []) as $id => $row) {
            if (!ctype_digit((string) $id) || !is_array($row)) {
                continue;
            }
            $c = trim((string) ($row['containers'] ?? ''));
            $nb = trim((string) ($row['new_buyers'] ?? ''));
            $q = trim((string) ($row['quotations'] ?? ''));
            if ($c !== '' && (!preg_match('/^\d{1,4}(\.\d)?$/', $c))) {
                $errors[] = 'Designation containers: a number with at most one decimal (e.g. 6 or 4.5).';
            }
            foreach ([$nb, $q] as $n) {
                if ($n !== '' && (!ctype_digit($n) || (int) $n > 9999)) {
                    $errors[] = 'Designation new buyers and quotations: whole numbers.';
                    break;
                }
            }
            $v['designations'][(int) $id] = [
                'containers' => $c === '' ? null : round((float) $c, 1),
                'new_buyers' => $nb === '' ? null : (int) $nb,
                'quotations' => $q === '' ? null : (int) $q,
            ];
        }
        return [$v, array_values(array_unique($errors))];
    }

    /**
     * Saves the settings form (values already validated). Every change is in the Audit Log as
     * SALES_TARGET_SETTINGS_CHANGED, with the reason.
     *
     * @return list<string> what changed
     */
    public static function saveSettings(array $v, bool $withMoney, int $userId, string $reason): array
    {
        $changes = [];
        $current = self::settings();
        foreach (['period', 'multiple', 'buildup_months', 'review_months', 'alert_pct'] as $k) {
            $new = (string) $v[$k];
            if ($k === 'multiple') {
                $new = rtrim(rtrim(number_format((float) $new, 1, '.', ''), '0'), '.');
            }
            if ((string) $current[$k] === $new) {
                continue;
            }
            SalesTargetRepository::saveSetting($k, $new, $userId);
            AuditLogRepository::log($userId, 'SALES_TARGET_SETTINGS_CHANGED', 'sales_target_settings', null, $k, (string) $current[$k], $new, $reason);
            $changes[] = $k;
        }
        if ($withMoney) {
            $row = SalesTargetRepository::latestCompanyRow();
            $old = $row === null ? null : [
                substr((string) $row['effective_from'], 0, 10),
                $row['costs_monthly'] !== null ? round((float) $row['costs_monthly'], 2) : null,
                $row['profit_monthly'] !== null ? round((float) $row['profit_monthly'], 2) : null,
            ];
            $new = [$v['effective_from'], $v['costs_monthly'], $v['profit_monthly']];
            $emptyNew = $new[1] === null && $new[2] === null;
            if (($old === null && !$emptyNew) || ($old !== null && $old !== $new && !($emptyNew && $old[1] === null && $old[2] === null))) {
                $from = $new[0] ?? date('Y-m-01');
                $id = SalesTargetRepository::insertCompanyRow($from, $new[1], $new[2], $reason, $userId);
                $fmt = static fn (?array $r): ?string => $r === null ? null : "from {$r[0]}: costs " . ($r[1] ?? '—') . ', profit ' . ($r[2] ?? '—') . ' per month';
                AuditLogRepository::log($userId, 'SALES_TARGET_SETTINGS_CHANGED', 'sales_target_company', $id, 'company_target', $fmt($old), $fmt([$from, $new[1], $new[2]]), $reason);
                $changes[] = 'company target';
            }
        }
        $designations = SalesTargetRepository::targetsByScope('designation');
        foreach ($v['designations'] as $id => $d) {
            $cur = $designations[$id] ?? null;
            $curVals = [
                'containers' => $cur !== null && $cur['containers'] !== null ? round((float) $cur['containers'], 1) : null,
                'new_buyers' => $cur !== null && $cur['new_buyers'] !== null ? (int) $cur['new_buyers'] : null,
                'quotations' => $cur !== null && $cur['quotations'] !== null ? (int) $cur['quotations'] : null,
            ];
            if ($curVals === $d) {
                continue;
            }
            if ($cur === null && $d['containers'] === null && $d['new_buyers'] === null && $d['quotations'] === null) {
                continue;
            }
            SalesTargetRepository::saveTarget('designation', $id, $d + ['reason' => $reason], $userId);
            AuditLogRepository::log($userId, 'SALES_TARGET_SETTINGS_CHANGED', 'sales_targets', $id, 'designation_default',
                json_encode($curVals), json_encode($d), $reason);
            $changes[] = 'designation default';
        }
        return array_values(array_unique($changes));
    }

    // ================================================================ periods

    /**
     * The period of $type that holds $date.
     *
     * @return array{type:string, start:string, end:string, months:int, label:string, key:string}
     */
    public static function period(string $type, string $date): array
    {
        $d = new \DateTimeImmutable(substr($date, 0, 10));
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n');
        switch ($type) {
            case 'month':
                [$sy, $sm, $len] = [$y, $m, 1];
                break;
            case 'half':
                [$sy, $sm, $len] = $m >= 4 && $m <= 9 ? [$y, 4, 6] : [$m >= 10 ? $y : $y - 1, 10, 6];
                break;
            case 'year':
                [$sy, $sm, $len] = [$m >= 4 ? $y : $y - 1, 4, 12];
                break;
            case 'quarter':
            default:
                $type = 'quarter';
                [$sy, $sm, $len] = [$y, intdiv($m - 1, 3) * 3 + 1, 3];
                break;
        }
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $sy, $sm));
        $last = $start->modify('+' . ($len - 1) . ' months');
        $end = $last->modify('last day of this month');
        $label = $len === 1
            ? self::MONTHS[$sm] . ' ' . $sy
            : ($start->format('Y') === $last->format('Y')
                ? self::MONTHS[$sm] . '–' . self::MONTHS[(int) $last->format('n')] . ' ' . $sy
                : self::MONTHS[$sm] . ' ' . $sy . '–' . self::MONTHS[(int) $last->format('n')] . ' ' . $last->format('Y'));
        return ['type' => $type, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'months' => $len, 'label' => $label, 'key' => $start->format('Y-m-d')];
    }

    /** The period just before $period. */
    public static function previous(array $period): array
    {
        return self::period($period['type'], (new \DateTimeImmutable($period['start']))->modify('-1 day')->format('Y-m-d'));
    }

    /** @return list<array> the current period and the $count − 1 before it, newest first */
    public static function recentPeriods(string $type, string $today, int $count = 4): array
    {
        $out = [self::period($type, $today)];
        while (count($out) < $count) {
            $out[] = self::previous($out[count($out) - 1]);
        }
        return $out;
    }

    /** @return list<string> the first day of each month of the period */
    public static function monthsOf(array $period): array
    {
        $out = [];
        $d = new \DateTimeImmutable($period['start']);
        for ($i = 0; $i < $period['months']; $i++) {
            $out[] = $d->modify("+{$i} months")->format('Y-m-d');
        }
        return $out;
    }

    // ================================================================ confirmation and sync

    /**
     * When (and how) an order became confirmed, from the facts SalesTargetRepository::orderFacts()
     * reads: the earliest of advance, LC and (no-advance terms only) PO + PI approved.
     *
     * @return array{0: ?string, 1: ?string} [date, 'advance'|'lc'|'po_pi'], or [null, null]
     */
    public static function confirmation(array $f): array
    {
        $cands = [];
        $adv = $f['advance_receipt_on'] ?? null;
        if ($adv === null || $adv === '') {
            $adv = $f['advance_status_on'] ?? null;
        }
        if ($adv !== null && $adv !== '') {
            $cands['advance'] = substr((string) $adv, 0, 10);
        }
        if (!empty($f['lc_received_on'])) {
            $cands['lc'] = substr((string) $f['lc_received_on'], 0, 10);
        }
        if ((float) ($f['advance_pct'] ?? 0) == 0.0 && !empty($f['buyer_po_received_on']) && !empty($f['frozen_on'])) {
            $cands['po_pi'] = max(substr((string) $f['buyer_po_received_on'], 0, 10), substr((string) $f['frozen_on'], 0, 10));
        }
        $best = [null, null];
        foreach ($cands as $how => $date) {
            if ($best[0] === null || $date < $best[0]) {
                $best = [$date, $how];
            }
        }
        return $best;
    }

    /** The order's estimated containers from its goods lines, at least 1. */
    public static function estimatedContainers(int $orderId): int
    {
        $totals = OrderLinePricing::totals(OrderProductRepository::forOrder($orderId), OrderLinePricing::forOrder($orderId)['lines']);
        return max(1, (int) $totals['containers']);
    }

    /**
     * Brings sales_target_credits up to date (daily job, and before a page reads it). Never writes
     * order data. Existing rows keep their dates: a confirmed order keeps its confirmed_on; a
     * cancellation / loss adds reversed_on once; the CI adds shipped_on once.
     *
     * @return array{inserted:int, shipped:int, reversed:int}
     */
    public static function sync(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $credits = SalesTargetRepository::credits();
        $done = ['inserted' => 0, 'shipped' => 0, 'reversed' => 0];
        foreach (SalesTargetRepository::orderFacts() as $f) {
            try {
                self::syncOne($f, $credits[(int) $f['id']] ?? null, $today, $done);
            } catch (\Throwable $e) {
                // One order's odd data never stops the daily job; it is tried again on the next run.
                error_log('D-378 sales target sync skipped order ' . (int) $f['id'] . ': ' . $e->getMessage());
            }
        }
        SalesTargetRepository::refreshNewBuyers();
        return $done;
    }

    /** sync() for one order: insert its credit row once, then add a reversal / shipment once each. */
    private static function syncOne(array $f, ?array $row, string $today, array &$done): void
    {
        $id = (int) $f['id'];
        $status = (string) $f['status'];
        $stopped = in_array($status, ['lost', 'cancelled'], true);
        $stopOn = $status === 'lost' ? ($f['lost_on'] ?? null) : ($status === 'cancelled' ? ($f['cancelled_on'] ?? null) : null);
        $stopOn = $stopOn !== null && $stopOn !== '' ? substr((string) $stopOn, 0, 10) : null;
        if ($row === null) {
            [$confirmedOn, $how] = self::confirmation($f);
            if ($confirmedOn === null) {
                return;
            }
            // Lost / cancelled before it was confirmed: never counts.
            if ($stopped && $stopOn !== null && $stopOn < $confirmedOn) {
                return;
            }
            $row = [
                'order_id' => $id, 'client_id' => (int) $f['client_id'], 'confirmed_on' => $confirmedOn, 'how' => $how,
                'containers_confirmed' => self::estimatedContainers($id),
                'shipped_on' => null, 'containers_shipped' => null,
                'reversed_on' => $stopped ? max($stopOn ?? $today, $confirmedOn) : null,
                'reversed_why' => $stopped ? $status : null,
            ];
            SalesTargetRepository::insertCredit($row);
            $done['inserted']++;
        } elseif ($row['reversed_on'] === null && $stopped) {
            $row['reversed_on'] = max($stopOn ?? $today, (string) $row['confirmed_on']);
            SalesTargetRepository::markReversed($id, $row['reversed_on'], $status);
            $done['reversed']++;
        }
        if ($row['shipped_on'] === null && $row['reversed_on'] === null) {
            $ci = SalesTargetRepository::latestIssuedCi($id);
            if ($ci !== null) {
                $count = SalesTargetRepository::shippingContainerCount($id);
                $shipped = max(1, $count !== null && $count > 0 ? $count : self::estimatedContainers($id));
                SalesTargetRepository::markShipped($id, substr(DocumentRepository::printedDate($ci), 0, 10), $shipped);
                $done['shipped']++;
            }
        }
    }

    // ================================================================ counting

    /**
     * The dated events of one credit row: +confirmed, −confirmed (reversal), shipped − confirmed.
     *
     * @return list<array{date:string, kind:string, containers:int}>
     */
    public static function events(array $c): array
    {
        $cc = (int) $c['containers_confirmed'];
        $out = [['date' => (string) $c['confirmed_on'], 'kind' => 'confirmed', 'containers' => $cc]];
        if (!empty($c['reversed_on'])) {
            $out[] = ['date' => (string) $c['reversed_on'], 'kind' => (string) ($c['reversed_why'] ?: 'reversed'), 'containers' => -$cc];
        } elseif (!empty($c['shipped_on']) && $c['containers_shipped'] !== null && (int) $c['containers_shipped'] !== $cc) {
            $out[] = ['date' => (string) $c['shipped_on'], 'kind' => 'shipped_difference', 'containers' => (int) $c['containers_shipped'] - $cc];
        }
        return $out;
    }

    /**
     * A period's numbers over some credit rows.
     *  counted     = sum of the events dated in the period (what the target is measured on)
     *  confirmed   = containers confirmed in the period (before corrections)
     *  shipped     = of the orders confirmed in the period and still standing, containers shipped
     *  awaiting    = of those, containers not shipped yet
     *  corrections = the correction events dated in the period (reversals, shipped differences)
     *  new_buyers  = first orders of a client confirmed in the period
     *
     * @param array<int, array> $credits
     * @return array{counted:int, confirmed:int, shipped:int, awaiting:int, corrections:int, new_buyers:int, rows:list<array>, correction_rows:list<array>}
     */
    public static function periodCounts(array $credits, string $from, string $to): array
    {
        $out = ['counted' => 0, 'confirmed' => 0, 'shipped' => 0, 'awaiting' => 0, 'corrections' => 0, 'new_buyers' => 0, 'rows' => [], 'correction_rows' => []];
        foreach ($credits as $c) {
            $in = static fn (?string $d): bool => $d !== null && $d !== '' && $d >= $from && $d <= $to;
            foreach (self::events($c) as $e) {
                if (!$in($e['date'])) {
                    continue;
                }
                $out['counted'] += $e['containers'];
                if ($e['kind'] === 'confirmed') {
                    $out['confirmed'] += $e['containers'];
                } else {
                    $out['corrections'] += $e['containers'];
                    $out['correction_rows'][] = $e + ['order_id' => (int) $c['order_id']];
                }
            }
            if ($in((string) $c['confirmed_on'])) {
                $out['rows'][] = $c;
                if ((int) $c['is_new_buyer'] === 1) {
                    $out['new_buyers']++;
                }
                if (empty($c['reversed_on'])) {
                    if (!empty($c['shipped_on'])) {
                        $out['shipped'] += (int) $c['containers_shipped'];
                    } else {
                        $out['awaiting'] += (int) $c['containers_confirmed'];
                    }
                }
            }
        }
        return $out;
    }

    /** @return array<int, array> credit rows credited (resolved now) to $userId; null = Unassigned */
    public static function creditsFor(?int $userId, ?array $credits = null, ?array $owners = null): array
    {
        $credits ??= SalesTargetRepository::credits();
        $owners ??= SalesTargetRepository::creditUsers(array_keys($credits));
        return array_filter($credits, static fn (array $c): bool => ($owners[(int) $c['order_id']] ?? null) === $userId);
    }

    // ================================================================ personal targets

    /** Build-up factor for month k since joining (joining month = 1) with N build-up months. */
    public static function buildupFactor(int $k, int $n): float
    {
        if ($k < 1) {
            return 0.0; // a month before joining
        }
        if ($n <= 1) {
            return 1.0;
        }
        if ($k === 1) {
            return 0.0;
        }
        return $k < $n ? 0.5 : 1.0;
    }

    /** Months since joining, the joining month being 1 (0 or less = before joining). */
    public static function monthsSinceJoining(string $joinedOn, string $monthStart): int
    {
        $j = new \DateTimeImmutable(substr($joinedOn, 0, 10));
        $m = new \DateTimeImmutable(substr($monthStart, 0, 10));
        return ((int) $m->format('Y') * 12 + (int) $m->format('n')) - ((int) $j->format('Y') * 12 + (int) $j->format('n')) + 1;
    }

    /**
     * A person's target for a period: own (scope user) values, else the designation default;
     * containers × the average build-up factor of the period's months.
     *
     * @return array<string, mixed>
     */
    public static function personalTarget(int $userId, array $period, ?array $settings = null, ?array $user = null): array
    {
        $settings ??= self::settings();
        $user ??= SalesTargetRepository::usersByIds([$userId])[$userId] ?? ['joined_on' => null];
        $own = SalesTargetRepository::target('user', $userId);
        $designation = SalesTargetRepository::designationFor($userId);
        $default = $designation !== null ? SalesTargetRepository::target('designation', $designation['id']) : null;
        $pick = static function (string $col) use ($own, $default): ?string {
            if ($own !== null && $own[$col] !== null) {
                return (string) $own[$col];
            }
            return $default !== null && $default[$col] !== null ? (string) $default[$col] : null;
        };
        $base = $pick('containers');
        $buildupOn = $own === null || $own['buildup_enabled'] === null || (int) $own['buildup_enabled'] === 1;
        $n = (int) $settings['buildup_months'];
        $factors = [];
        foreach (self::monthsOf($period) as $month) {
            $factors[$month] = ($buildupOn && !empty($user['joined_on']))
                ? self::buildupFactor(self::monthsSinceJoining((string) $user['joined_on'], $month), $n)
                : 1.0;
        }
        $avg = $factors === [] ? 1.0 : array_sum($factors) / count($factors);
        $inBuildup = min($factors) < 1.0;
        $ownMultiple = $own !== null && $own['multiple'] !== null ? (float) $own['multiple'] : null;
        return [
            'containers' => $base === null ? 0.0 : round((float) $base * $avg, 1),
            'base_containers' => $base === null ? null : (float) $base,
            'new_buyers' => ($nb = $pick('new_buyers')) === null ? null : (int) $nb,
            'quotations' => $default !== null && $default['quotations'] !== null ? (int) $default['quotations'] : null,
            'own_multiple' => $ownMultiple,
            'multiple' => $ownMultiple ?? (float) $settings['multiple'],
            'buildup_enabled' => $buildupOn,
            'factors' => $factors,
            'factor' => round($avg, 3),
            'in_buildup' => $inBuildup,
            'source' => $own !== null && $own['containers'] !== null ? 'own' : ($default !== null && $default['containers'] !== null ? 'designation' : null),
            'designation' => $designation,
            'reviewed_at' => $own['reviewed_at'] ?? null,
            'own' => $own,
            'joined_on' => $user['joined_on'] ?? null,
        ];
    }

    // ================================================================ company target

    /** Profit after incentive of one order (₹), cached for the request. */
    private static function profit(int $orderId): float
    {
        self::$profitCache ??= [];
        if (!array_key_exists($orderId, self::$profitCache)) {
            self::$profitCache[$orderId] = (float) (OrderProfitabilityService::computeForOrder($orderId)['profit_after_incentive_inr'] ?? 0.0);
        }
        return self::$profitCache[$orderId];
    }

    /**
     * Average margin per container: profit after incentive of the orders shipped in the 3 months up to
     * $today ÷ their containers shipped; null when nothing shipped ("not enough history yet").
     */
    public static function avgMarginPerContainer(string $today, ?array $credits = null): ?float
    {
        $credits ??= SalesTargetRepository::credits();
        $from = (new \DateTimeImmutable($today))->modify('-3 months')->format('Y-m-d');
        $profit = 0.0;
        $containers = 0;
        foreach ($credits as $c) {
            if (!empty($c['reversed_on']) || empty($c['shipped_on']) || $c['shipped_on'] <= $from || $c['shipped_on'] > $today) {
                continue;
            }
            $profit += self::profit((int) $c['order_id']);
            $containers += (int) $c['containers_shipped'];
        }
        return $containers > 0 ? round($profit / $containers, 2) : null;
    }

    /**
     * The company target for a period. 'on' false when no row applies or costs and profit are both empty / 0.
     *
     * @return array<string, mixed>
     */
    public static function companyTarget(array $period, string $today, ?array $credits = null): array
    {
        $credits ??= SalesTargetRepository::credits();
        $row = null;
        try {
            $row = SalesTargetRepository::companyRowFor($period['start']);
        } catch (\Throwable $e) {
            $row = null;
        }
        $costs = $row !== null && $row['costs_monthly'] !== null ? (float) $row['costs_monthly'] : 0.0;
        $profitWanted = $row !== null && $row['profit_monthly'] !== null ? (float) $row['profit_monthly'] : 0.0;
        $on = $row !== null && ($costs > 0 || $profitWanted > 0);
        $months = (int) $period['months'];
        $earned = 0.0;
        $shipped = 0;
        $awaiting = 0;
        foreach ($credits as $c) {
            if (!empty($c['reversed_on'])) {
                continue;
            }
            if (!empty($c['shipped_on']) && $c['shipped_on'] >= $period['start'] && $c['shipped_on'] <= $period['end']) {
                $shipped += (int) $c['containers_shipped'];
                if ($on) {
                    $earned += self::profit((int) $c['order_id']);
                }
            } elseif (empty($c['shipped_on']) && $c['confirmed_on'] <= $period['end']) {
                $awaiting += (int) $c['containers_confirmed'];
            }
        }
        $avg = $on ? self::avgMarginPerContainer($today, $credits) : null;
        return [
            'on' => $on,
            'period' => $period,
            'costs_monthly' => $costs,
            'profit_monthly' => $profitWanted,
            'earned' => round($earned, 2),
            'counted' => self::periodCounts($credits, $period['start'], $period['end'])['counted'],
            'shipped' => $shipped,
            'awaiting' => $awaiting,
        ] + self::companyMath($costs, $profitWanted, $months, $avg, $on);
    }

    /**
     * needed = (costs + profit) × months; containers needed = ceil(needed ÷ average margin);
     * break-even = ceil(costs × months ÷ average margin). Null containers without a usable average
     * ("not enough history yet").
     *
     * @return array{needed: float, avg_margin: ?float, containers_needed: ?int, breakeven_containers: ?int}
     */
    public static function companyMath(float $costsMonthly, float $profitMonthly, int $months, ?float $avgMargin, bool $on = true): array
    {
        $needed = ($costsMonthly + $profitMonthly) * $months;
        $usable = $on && $avgMargin !== null && $avgMargin > 0;
        return [
            'needed' => round($needed, 2),
            'avg_margin' => $avgMargin,
            'containers_needed' => $usable ? (int) ceil(round($needed / $avgMargin, 6)) : null,
            'breakeven_containers' => $usable ? (int) ceil(round($costsMonthly * $months / $avgMargin, 6)) : null,
        ];
    }

    // ================================================================ pages

    /**
     * The Targets page (manage_sales_targets): team table, Unassigned, associates, total and the check
     * line against the company's containers needed.
     *
     * @return array<string, mixed>
     */
    public static function teamData(array $period, string $today): array
    {
        $settings = self::settings();
        $credits = SalesTargetRepository::credits();
        $pipeline = SalesTargetRepository::pipelineOrders();
        $owners = SalesTargetRepository::creditUsers(array_merge(array_keys($credits), array_map(static fn (array $o): int => (int) $o['id'], $pipeline)));
        $inPeriod = self::periodCounts($credits, $period['start'], $period['end']);
        $periodOrderIds = array_unique(array_merge(
            array_map(static fn (array $c): int => (int) $c['order_id'], $inPeriod['rows']),
            array_column($inPeriod['correction_rows'], 'order_id')
        ));
        $people = SalesTargetRepository::salesMembers();
        foreach (SalesTargetRepository::targetsByScope('user') as $uid => $t) {
            $people[] = $uid;
        }
        foreach ($periodOrderIds as $oid) {
            if (($owners[$oid] ?? null) !== null) {
                $people[] = $owners[$oid];
            }
        }
        foreach ($pipeline as $o) {
            if (($owners[(int) $o['id']] ?? null) !== null) {
                $people[] = $owners[(int) $o['id']];
            }
        }
        $users = SalesTargetRepository::usersByIds($people);
        $quotations = SalesTargetRepository::quotationsSent($period['start'], $period['end']);
        $rows = [];
        $teamTarget = 0.0;
        foreach ($users as $uid => $u) {
            $mine = array_filter($credits, static fn (array $c): bool => ($owners[(int) $c['order_id']] ?? null) === $uid);
            $counts = self::periodCounts($mine, $period['start'], $period['end']);
            $target = self::personalTarget($uid, $period, $settings, $u);
            $pipe = array_values(array_filter($pipeline, static fn (array $o): bool => ($owners[(int) $o['id']] ?? null) === $uid));
            if ((int) $u['is_active'] !== 1 && $counts['counted'] === 0 && $counts['rows'] === [] && $pipe === []) {
                continue;
            }
            $qt = 0;
            foreach ($quotations as $oid => $n) {
                if (($owners[$oid] ?? null) === $uid) {
                    $qt += $n;
                }
            }
            $teamTarget += $target['containers'];
            $rows[] = [
                'user' => $u, 'target' => $target, 'counts' => $counts, 'pipeline' => count($pipe), 'quotations_sent' => $qt,
                'progress' => $target['containers'] > 0 ? (int) round(100 * $counts['counted'] / $target['containers']) : null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['user']['name'], (string) $b['user']['name']));
        $unassignedIds = array_values(array_filter($periodOrderIds, static fn (int $oid): bool => ($owners[$oid] ?? null) === null));
        $unassigned = self::periodCounts(array_intersect_key($credits, array_flip($unassignedIds)), $period['start'], $period['end']);
        $company = self::companyTarget($period, $today, $credits);
        return [
            'settings' => $settings,
            'period' => $period,
            'rows' => $rows,
            'unassigned' => ['counts' => $unassigned, 'orders' => SalesTargetRepository::ordersByIds($unassignedIds)],
            'associates' => self::associateRows($period, $credits, $periodOrderIds),
            'total' => $inPeriod,
            'team_target' => round($teamTarget, 1),
            'company' => $company,
            'credited_orders' => SalesTargetRepository::ordersByIds($periodOrderIds),
            'owners' => $owners,
            'staff' => SalesTargetRepository::activeStaff(),
        ];
    }

    /** Associates: the order's earning associate is credited too (containers only). */
    private static function associateRows(array $period, array $credits, array $orderIds): array
    {
        $by = [];
        foreach ($orderIds as $oid) {
            $order = OrderRepository::find($oid);
            $a = $order !== null ? AssociateCommission::earningAssociate($order) : null;
            if ($a === null) {
                continue;
            }
            $aid = (int) $a['id'];
            $by[$aid] ??= ['associate' => $a, 'credits' => []];
            $by[$aid]['credits'][$oid] = $credits[$oid];
        }
        foreach (SalesTargetRepository::targetsByScope('associate') as $aid => $t) {
            if (!isset($by[$aid])) {
                $a = \App\Repositories\AssociateRepository::find($aid);
                if ($a !== null) {
                    $by[$aid] = ['associate' => $a, 'credits' => []];
                }
            }
        }
        $out = [];
        foreach ($by as $aid => $x) {
            $t = SalesTargetRepository::target('associate', $aid);
            $out[] = [
                'associate' => $x['associate'],
                'target' => $t !== null && $t['containers'] !== null ? (float) $t['containers'] : 0.0,
                'reviewed_at' => $t['reviewed_at'] ?? null,
                'counts' => self::periodCounts($x['credits'], $period['start'], $period['end']),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['associate']['name'], (string) $b['associate']['name']));
        return $out;
    }

    /**
     * The salesperson's own "Your target" box on the Dashboard, or null when they have no target
     * (containers > 0). Containers only; the order's USD FOB only when $seesSelling.
     *
     * @return array<string, mixed>|null
     */
    public static function dashboardFor(int $userId, string $today, bool $seesSelling): ?array
    {
        $settings = self::settings();
        $period = self::period((string) $settings['period'], $today);
        try {
            $target = self::personalTarget($userId, $period, $settings);
        } catch (\Throwable $e) {
            return null; // a database before migration 2026-11-15a
        }
        if ($target['containers'] <= 0) {
            return null;
        }
        $credits = SalesTargetRepository::credits();
        $pipeline = SalesTargetRepository::pipelineOrders();
        $owners = SalesTargetRepository::creditUsers(array_merge(array_keys($credits), array_map(static fn (array $o): int => (int) $o['id'], $pipeline)));
        $mine = array_filter($credits, static fn (array $c): bool => ($owners[(int) $c['order_id']] ?? null) === $userId);
        $counts = self::periodCounts($mine, $period['start'], $period['end']);
        $pipe = array_values(array_filter($pipeline, static fn (array $o): bool => ($owners[(int) $o['id']] ?? null) === $userId));
        $ids = array_merge(array_map(static fn (array $c): int => (int) $c['order_id'], $counts['rows']), array_column($counts['correction_rows'], 'order_id'));
        $qt = 0;
        if ($target['in_buildup']) {
            foreach (SalesTargetRepository::quotationsSent($period['start'], $period['end']) as $oid => $n) {
                if (($owners[$oid] ?? null) === $userId) {
                    $qt += $n;
                }
            }
        }
        return [
            'period' => $period,
            'target' => $target,
            'counts' => $counts,
            'still' => max(0.0, round($target['containers'] - $counts['counted'], 1)),
            'pipeline' => $pipe,
            'progress' => (int) round(100 * $counts['counted'] / $target['containers']),
            'orders' => SalesTargetRepository::ordersByIds($ids),
            'fob' => $seesSelling ? SalesTargetRepository::fobValues($ids) : [],
            'quotations_sent' => $qt,
        ];
    }

    /**
     * The cockpit box (company target ON and manage_sales_targets): this period and the 2 before.
     *
     * @return array<string, mixed>|null
     */
    public static function cockpitBox(string $today): ?array
    {
        try {
            $settings = self::settings();
            $credits = SalesTargetRepository::credits();
        } catch (\Throwable $e) {
            return null;
        }
        $periods = self::recentPeriods((string) $settings['period'], $today, 3);
        $current = self::companyTarget($periods[0], $today, $credits);
        if (!$current['on']) {
            return null;
        }
        return [
            'current' => $current,
            'previous' => [self::companyTarget($periods[1], $today, $credits), self::companyTarget($periods[2], $today, $credits)],
            'elapsed_pct' => self::elapsedPct($periods[0], $today),
        ];
    }

    /** How much of the period has passed on $today, in %. */
    public static function elapsedPct(array $period, string $today): int
    {
        $start = new \DateTimeImmutable($period['start']);
        $end = new \DateTimeImmutable($period['end']);
        $days = (int) $start->diff($end)->days + 1;
        $gone = (int) $start->diff(new \DateTimeImmutable($today))->days + 1;
        if ($today < $period['start']) {
            return 0;
        }
        return (int) min(100, floor(100 * $gone / $days));
    }

    // ================================================================ Sales Performance — by salesperson

    /**
     * The "By salesperson" table of Sales Performance ($salesperson 'all' or a user id): quotes sent,
     * won (PI), lost before / after PI, win rate, average days QT → PI, top lost category — orders
     * credited to that person. Same date rules as the report: QT e-mail sent date, pi_date, lost_at.
     *
     * @return list<array<string, mixed>>
     */
    public static function performanceBySalesperson(?string $from, ?string $to, string $salesperson, int $isTestData = 0): array
    {
        $range = static function (string $col, string $p) use ($from, $to, &$params): string {
            $sql = '';
            if ($from) {
                $sql .= " AND {$col} >= :{$p}_f";
                $params["{$p}_f"] = $from;
            }
            if ($to) {
                $sql .= " AND {$col} <= :{$p}_t";
                $params["{$p}_t"] = $to;
            }
            return $sql;
        };
        $params = ['t' => $isTestData];
        $qtRange = $range('DATE(el.sent_at)', 'qt');
        $sql = "SELECT o.id, o.status, o.pi_date, DATE(o.lost_at) AS lost_on, o.lost_category,
                       EXISTS (SELECT 1 FROM documents d JOIN document_types dt ON dt.id = d.document_type_id WHERE dt.code = 'PI' AND d.order_id = o.id) AS has_pi,
                       (SELECT DATE(MIN(el2.sent_at)) FROM email_log el2 JOIN documents d2 ON d2.id = el2.document_id JOIN document_types dt2 ON dt2.id = d2.document_type_id
                         WHERE dt2.code = 'QT' AND el2.status = 'sent' AND d2.order_id = o.id) AS first_qt_on,
                       (SELECT COUNT(DISTINCT el.document_id) FROM email_log el JOIN documents d ON d.id = el.document_id JOIN document_types dt ON dt.id = d.document_type_id
                         WHERE dt.code = 'QT' AND el.status = 'sent' AND d.order_id = o.id{$qtRange}) AS qt_sent
                  FROM orders o
                 WHERE o.is_test_data = :t" . DivisionService::sql('o.division_id');
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $in = static fn (?string $d): bool => $d !== null && $d !== '' && (!$from || $d >= $from) && (!$to || $d <= $to);
        $orders = [];
        foreach ($stmt->fetchAll() as $o) {
            $won = (int) $o['has_pi'] === 1 && $in($o['pi_date']);
            $lost = $o['status'] === 'lost' && $in($o['lost_on']);
            if ((int) $o['qt_sent'] === 0 && !$won && !$lost) {
                continue;
            }
            $orders[(int) $o['id']] = $o + ['won' => $won, 'lost' => $lost];
        }
        $owners = SalesTargetRepository::creditUsers(array_keys($orders));
        $by = [];
        foreach ($orders as $oid => $o) {
            $uid = $owners[$oid] ?? null;
            if ($salesperson !== 'all' && (string) $uid !== $salesperson) {
                continue;
            }
            $k = $uid ?? 0;
            $by[$k] ??= ['user_id' => $uid, 'quotes' => 0, 'won' => 0, 'lost_before_pi' => 0, 'lost_after_pi' => 0, 'days' => [], 'categories' => []];
            $by[$k]['quotes'] += (int) $o['qt_sent'];
            if ($o['won']) {
                $by[$k]['won']++;
                if (!empty($o['first_qt_on']) && !empty($o['pi_date'])) {
                    $by[$k]['days'][] = (int) (new \DateTimeImmutable((string) $o['first_qt_on']))->diff(new \DateTimeImmutable((string) $o['pi_date']))->format('%r%a');
                }
            }
            if ($o['lost']) {
                $by[$k][(int) $o['has_pi'] === 1 ? 'lost_after_pi' : 'lost_before_pi']++;
                if (isset(self::LOST_CATEGORIES[(string) $o['lost_category']])) {
                    $by[$k]['categories'][(string) $o['lost_category']] = ($by[$k]['categories'][(string) $o['lost_category']] ?? 0) + 1;
                }
            }
        }
        $users = SalesTargetRepository::usersByIds(array_filter(array_column($by, 'user_id')));
        $out = [];
        foreach ($by as $r) {
            arsort($r['categories']);
            $top = array_key_first($r['categories']);
            $out[] = [
                'user_id' => $r['user_id'],
                'name' => $r['user_id'] === null ? 'Unassigned' : (string) ($users[$r['user_id']]['name'] ?? ('User #' . $r['user_id'])),
                'quotes' => $r['quotes'],
                'won' => $r['won'],
                'lost_before_pi' => $r['lost_before_pi'],
                'lost_after_pi' => $r['lost_after_pi'],
                'win_rate_pct' => $r['quotes'] > 0 ? round(100 * $r['won'] / $r['quotes'], 1) : null,
                'avg_days_qt_to_pi' => $r['days'] !== [] ? round(array_sum($r['days']) / count($r['days']), 1) : null,
                'top_lost_category' => $top !== null ? self::LOST_CATEGORIES[$top] : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['user_id'] === null ? 1 : 0, $a['name']] <=> [$b['user_id'] === null ? 1 : 0, $b['name']]);
        return $out;
    }

    // ================================================================ editing (MD / ED)

    /**
     * Saves a person's own target (POST /sales-targets/person/{id}); reviewed_at = now.
     *
     * @return string|null an error, or null when saved
     */
    public static function savePerson(int $personId, array $post, int $userId): ?string
    {
        $reason = trim((string) ($post['reason'] ?? ''));
        if ($error = \App\Helpers\ReasonValidator::check($reason)) {
            return $error;
        }
        $c = trim((string) ($post['containers'] ?? ''));
        $nb = trim((string) ($post['new_buyers'] ?? ''));
        $mult = trim((string) ($post['multiple'] ?? ''));
        if ($c !== '' && !preg_match('/^\d{1,4}(\.\d)?$/', $c)) {
            return 'Containers: a number with at most one decimal (e.g. 6 or 4.5), or empty for the designation default.';
        }
        if ($nb !== '' && (!ctype_digit($nb) || (int) $nb > 9999)) {
            return 'New buyers: a whole number, or empty for the designation default.';
        }
        if ($mult !== '' && (!preg_match('/^\d{1,2}(\.\d)?$/', $mult) || (float) $mult < 1 || (float) $mult > 10)) {
            return 'Multiple: a number from 1 to 10 with at most one decimal, or empty for the company setting.';
        }
        $old = SalesTargetRepository::target('user', $personId);
        $fields = [
            'containers' => $c === '' ? null : round((float) $c, 1),
            'new_buyers' => $nb === '' ? null : (int) $nb,
            'multiple' => $mult === '' ? null : round((float) $mult, 1),
            'buildup_enabled' => isset($post['buildup_present']) ? (!empty($post['buildup_enabled']) ? 1 : 0) : ($old['buildup_enabled'] ?? null),
            'reason' => $reason,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ];
        SalesTargetRepository::saveTarget('user', $personId, $fields, $userId);
        $show = static fn (?array $r): string => $r === null ? '—' : sprintf(
            'containers %s, new buyers %s, multiple %s, build-up %s',
            $r['containers'] ?? '—', $r['new_buyers'] ?? '—', $r['multiple'] ?? '—',
            ($r['buildup_enabled'] ?? null) === null || (int) $r['buildup_enabled'] === 1 ? 'on' : 'off'
        );
        AuditLogRepository::log($userId, 'SALES_TARGET_CHANGED', 'users', $personId, 'sales_target', $show($old), $show($fields), $reason);
        return null;
    }

    /** POST /sales-targets/associate/{id}: the associate's containers target. */
    public static function saveAssociate(int $associateId, array $post, int $userId): ?string
    {
        $reason = trim((string) ($post['reason'] ?? ''));
        if ($error = \App\Helpers\ReasonValidator::check($reason)) {
            return $error;
        }
        $c = trim((string) ($post['containers'] ?? ''));
        if ($c !== '' && !preg_match('/^\d{1,4}(\.\d)?$/', $c)) {
            return 'Containers: a number with at most one decimal (e.g. 2 or 1.5), or empty for none.';
        }
        $old = SalesTargetRepository::target('associate', $associateId);
        $new = $c === '' ? null : round((float) $c, 1);
        SalesTargetRepository::saveTarget('associate', $associateId, ['containers' => $new, 'reason' => $reason, 'reviewed_at' => date('Y-m-d H:i:s')], $userId);
        AuditLogRepository::log($userId, 'SALES_TARGET_CHANGED', 'associates', $associateId, 'containers', $old['containers'] ?? null, $new === null ? null : (string) $new, $reason);
        return null;
    }

    /**
     * POST /sales-targets/credit/{orderId}: who gets the order's target credit. 'auto' = back to the
     * Sales & Export owner. Logged as ORDER_SALES_CREDIT_MOVED. Changes nothing else on the order.
     */
    public static function moveCredit(int $orderId, string $to, string $reason, int $userId): ?string
    {
        if ($error = \App\Helpers\ReasonValidator::check($reason)) {
            return $error;
        }
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return 'Order not found.';
        }
        if ($to === 'auto') {
            $new = null;
        } else {
            $new = ctype_digit($to) ? (int) $to : 0;
            $u = $new > 0 ? (SalesTargetRepository::usersByIds([$new])[$new] ?? null) : null;
            if ($u === null || (int) $u['is_active'] !== 1) {
                return 'Choose an active staff member to credit.';
            }
        }
        $old = $order['sales_credit_user_id'] ?? null;
        if ((string) ($old ?? '') === (string) ($new ?? '')) {
            return null;
        }
        SalesTargetRepository::setCreditUser($orderId, $new);
        AuditLogRepository::log($userId, 'ORDER_SALES_CREDIT_MOVED', 'orders', $orderId, 'sales_credit_user_id',
            $old !== null ? (string) $old : null, $new !== null ? (string) $new : null, $reason);
        return null;
    }

    // ================================================================ alerts and their recipients

    /** @return list<int> who is told about this alert kind (stored ids, the defaults, or the MD). */
    public static function alertRecipients(string $kind, ?array $settings = null): array
    {
        $settings ??= self::settings();
        [$key, , $roles] = self::ALERTS[$kind];
        $stored = $settings[$key] ?? null;
        if ($stored === null) {
            $ids = SalesTargetRepository::usersWithRoles($roles);
        } else {
            $wanted = array_values(array_filter(array_map('intval', explode(',', (string) $stored))));
            $users = SalesTargetRepository::usersByIds($wanted);
            $ids = array_values(array_filter($wanted, static fn (int $id): bool => isset($users[$id]) && (int) $users[$id]['is_active'] === 1));
        }
        return $ids !== [] ? $ids : StaffRouting::managingDirectors();
    }

    /** @return array<string, list<int>> kind => the ticked people on Admin › E-mail settings */
    public static function alertRecipientsForForm(): array
    {
        $settings = self::settings();
        $out = [];
        foreach (self::ALERTS as $kind => [$key, , $roles]) {
            $stored = $settings[$key] ?? null;
            $out[$kind] = $stored === null
                ? SalesTargetRepository::usersWithRoles($roles)
                : array_values(array_filter(array_map('intval', explode(',', (string) $stored))));
        }
        return $out;
    }

    /**
     * Saves the 3 rows of Admin › E-mail settings section E (target_alerts[kind][] = user ids). A row
     * left exactly at its defaults stays "defaults" (NULL); nobody ticked is stored as '' (= the MD).
     *
     * @return list<string> labels of the rows that changed
     */
    public static function saveAlertRecipients(array $posted, int $userId, string $reason): array
    {
        $settings = self::settings();
        $active = array_column(SalesTargetRepository::activeStaff(), 'id');
        $changes = [];
        foreach (self::ALERTS as $kind => [$key, $label, $roles]) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($posted[$kind] ?? [])), static fn (int $id): bool => in_array($id, $active, true))));
            sort($ids);
            $current = $settings[$key] ?? null;
            if ($current === null && $ids === SalesTargetRepository::usersWithRoles($roles)) {
                continue; // still the defaults
            }
            $new = implode(',', $ids);
            if ($current !== null && $current === $new) {
                continue;
            }
            SalesTargetRepository::saveSetting($key, $new, $userId);
            AuditLogRepository::log($userId, 'SALES_TARGET_SETTINGS_CHANGED', 'sales_target_settings', null, $key, $current ?? 'defaults', $new === '' ? 'nobody (the MD)' : $new, $reason);
            $changes[] = $label;
        }
        return $changes;
    }

    /**
     * The daily alerts (check_alerts, after sync()), each sent once:
     *  - behind: the period ≥ 50 % gone, company target ON, (containers shipped in the period +
     *    confirmed awaiting shipment) ÷ containers needed below alert_pct → once per period;
     *  - review: a person with a target whose own target was last reviewed more than review_months
     *    ago (never reviewed: counted from joined_on, or due) → once per person per month;
     *  - no_owner: a standing credit row with nobody credited → once per order.
     * In-app notice and e-mail to the recipients of Admin › E-mail settings section E.
     *
     * @return int notices created
     */
    public static function runAlerts(string $today): int
    {
        $settings = self::settings();
        $credits = SalesTargetRepository::credits();
        $period = self::period((string) $settings['period'], $today);
        $created = 0;

        // behind
        if (self::elapsedPct($period, $today) >= 50) {
            $company = self::companyTarget($period, $today, $credits);
            if ($company['on'] && $company['containers_needed'] !== null && $company['containers_needed'] > 0) {
                $pct = 100 * ($company['shipped'] + $company['awaiting']) / $company['containers_needed'];
                if ($pct < (int) $settings['alert_pct'] && SalesTargetRepository::once('sales_target_behind', $period['key'], $period['type'], $today)) {
                    $msg = sprintf('Sales target %s: %d of %d containers needed are shipped or confirmed (%d%%, alert below %d%%) with %d%% of the period gone — see Sales targets.',
                        $period['label'], $company['shipped'] + $company['awaiting'], $company['containers_needed'], (int) floor($pct), (int) $settings['alert_pct'], self::elapsedPct($period, $today));
                    $ids = self::alertRecipients('behind', $settings);
                    $created += count(NotificationRepository::notifyRouted($ids, $ids, 'sales_target_behind', null, $msg));
                }
            }
        }

        // review
        $reviewMonths = (int) $settings['review_months'];
        $limit = (new \DateTimeImmutable($today))->modify("-{$reviewMonths} months")->format('Y-m-d');
        $people = array_unique(array_merge(SalesTargetRepository::salesMembers(), array_keys(SalesTargetRepository::targetsByScope('user'))));
        $users = SalesTargetRepository::usersByIds($people);
        $reviewIds = null;
        foreach ($users as $uid => $u) {
            if ((int) $u['is_active'] !== 1) {
                continue;
            }
            $t = self::personalTarget($uid, $period, $settings, $u);
            if ($t['containers'] <= 0) {
                continue;
            }
            $since = $t['reviewed_at'] !== null ? substr((string) $t['reviewed_at'], 0, 10) : ($u['joined_on'] ?? null);
            if ($since !== null && $since > $limit) {
                continue;
            }
            if (!SalesTargetRepository::once('sales_target_review', (string) $uid, substr($today, 0, 7), $today)) {
                continue;
            }
            $reviewIds ??= self::alertRecipients('review', $settings);
            $msg = sprintf("Sales target: %s's target was last reviewed %s — review it on Sales targets (every %d months).",
                (string) $u['name'], $t['reviewed_at'] !== null ? \App\Helpers\Dates::short((string) $t['reviewed_at']) : 'never', $reviewMonths);
            $created += count(NotificationRepository::notifyRouted($reviewIds, $reviewIds, 'sales_target_review', null, $msg));
        }

        // no_owner
        $standing = array_filter($credits, static fn (array $c): bool => empty($c['reversed_on']));
        $owners = SalesTargetRepository::creditUsers(array_keys($standing));
        $orders = null;
        $noOwnerIds = null;
        foreach ($standing as $oid => $c) {
            if (($owners[$oid] ?? null) !== null) {
                continue;
            }
            if (!SalesTargetRepository::once('sales_target_no_owner', (string) $oid, 'once', $today)) {
                continue;
            }
            $orders ??= SalesTargetRepository::ordersByIds(array_keys($standing));
            $noOwnerIds ??= self::alertRecipients('no_owner', $settings);
            $msg = sprintf('Sales target: order %s is confirmed (%s) but has no salesperson — choose its Sales & Export owner, or move its credit on Sales targets.',
                (string) ($orders[$oid]['order_reference'] ?? ('#' . $oid)), \App\Helpers\Dates::short((string) $c['confirmed_on']));
            $created += count(NotificationRepository::notifyRouted($noOwnerIds, $noOwnerIds, 'sales_target_no_owner', (int) $oid, $msg));
        }
        return $created;
    }
}
