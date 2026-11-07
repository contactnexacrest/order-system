<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;

/**
 * Team incentive program and associate commission.
 *
 * Owner decision D-245 P4 (4 Nov 2026):
 * - The team incentive is a % of the order's profit after the associate's
 *   commission (never below 0) or a fixed amount: the global rule (Pricing
 *   settings), a product's own rule, or the MD's amount for this one order,
 *   with a reason. With the program off there is no global incentive, but the
 *   MD may still set one by hand on one order.
 * - The roles are an editable list with usual shares adding up to 100%; on
 *   an order the MD ticks who gets it (unticked shares spread proportionally
 *   over the ticked roles) or sets the shares; equal split inside a role.
 * - Nothing locks by itself: an order is "ready to lock" once the buyer has
 *   fully paid and no claim is open; the MD locks it (one or all ready) and
 *   may unlock it with a reason until anything is paid. Every change is kept
 *   in the order's incentive history.
 * - Associate commission (D-245 P3, AssociateCommission) is deducted first
 *   and locks together with the order.
 * - Staff payouts go into the payout run after the lock date (the same run
 *   settings as associate commission); associate commission keeps its due
 *   date and the run after it.
 * - The price check's "incentive cut" on a line the MD approved below the
 *   floor is shown only as an explanation: the incentive is a % of the
 *   order's actual profit, which that lower price already reduced, so it is
 *   never subtracted a second time.
 */
final class IncentiveService
{
    /** @return array{enabled:bool,reminder_days:int} */
    public static function settings(): array
    {
        $raw = IncentiveRepository::settings();
        return [
            'enabled' => ($raw['program_enabled'] ?? '0') === '1',
            'reminder_days' => max(0, min(28, (int) ($raw['lock_reminder_days'] ?? 3))),
        ];
    }

    /** QA Round 6, R6-N29: a real incentive month, YYYY-MM with MM 01-12. */
    public static function isMonth(string $month): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
    }

    /**
     * QA Round 6, R6-N10: the earliest date a payout of this incentive month
     * can have been paid — the 1st of the month before it (the cycle runs
     * from the cut-off day of the previous month).
     */
    public static function earliestPaidOn(string $month): string
    {
        return self::isMonth($month) ? date('Y-m-01', (int) strtotime($month . '-01 -1 month')) : '2000-01-01';
    }

    /**
     * D-245 P4: the payout month (YYYY-MM) of something locked on $date — the
     * month of the first payout run on or after it (Associates › Commission
     * settings: monthly on day N, or weekly).
     */
    public static function incentiveMonth(string $date, mixed $unused = null): string
    {
        return substr(AssociateCommission::payoutRunOn(substr($date, 0, 10)), 0, 7);
    }

    public static function monthLabel(string $month): string
    {
        return date('F Y', strtotime($month . '-01'));
    }

    /** The date the buyer's last payment cleared, once every buyer payment the order has is cleared; else null. */
    public static function fullyPaidOn(int $orderId): ?string
    {
        $order = OrderRepository::find($orderId);
        $payment = OrderPaymentStatusRepository::find($orderId);
        if ($order === null || $payment === null) {
            return null;
        }
        $dates = [];
        if (($order['payment_method'] ?? '') === 'LC') {
            $lc = OrderLcDetailRepository::find($orderId);
            if ($lc !== null && (float) ($lc['lc_amount'] ?? 0) > 0) {
                if (empty($lc['lc_received_at'])) {
                    return null;
                }
                $dates[] = (string) $lc['lc_received_at'];
            }
        }
        foreach (['advance', 'balance', 'freight'] as $leg) {
            if ((float) ($payment[$leg . '_amount'] ?? 0) <= 0) {
                continue;
            }
            if (empty($payment[$leg . '_cleared_at'])) {
                return null;
            }
            $dates[] = substr((string) $payment[$leg . '_cleared_at'], 0, 10);
        }
        // QA batch 4, MON-2: a middle payment milestone must be cleared too before the order is fully paid.
        foreach (\App\Repositories\PaymentMilestoneRepository::forOrder((int) $order['id']) as $m) {
            if ((float) $m['pct'] <= 0) {
                continue;
            }
            if (empty($m['cleared_on'])) {
                return null;
            }
            $dates[] = substr((string) $m['cleared_on'], 0, 10);
        }
        return $dates === [] ? null : max($dates);
    }

    /** Whether this is the client's first order (its first real, not cancelled or lost, order). */
    public static function isClientsFirstOrder(array $order): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT id FROM orders WHERE client_id = :c AND is_test_data = 0 AND is_sample_data = 0 AND status NOT IN ('cancelled','lost')
             ORDER BY created_at, id LIMIT 1"
        );
        $stmt->execute(['c' => $order['client_id']]);
        return (int) $stmt->fetchColumn() === (int) $order['id'];
    }

    /**
     * Whether the order falls in the client's first 10 days. Day 1 is the
     * day the client's first PO was received (agreement clause 4.1, Step
     * 4d); an order confirmed before that date was recorded counts from
     * its order date instead.
     */
    public static function withinFirstTenDays(array $order): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT MIN(COALESCE(buyer_po_received_on, DATE(created_at))) FROM orders
             WHERE client_id = :c AND is_test_data = 0 AND is_sample_data = 0 AND status NOT IN ('cancelled','lost')"
        );
        $stmt->execute(['c' => $order['client_id']]);
        $dayOne = (string) $stmt->fetchColumn();
        if ($dayOne === '') {
            return false;
        }
        $days = (int) floor((strtotime(self::poDate($order)) - strtotime(substr($dayOne, 0, 10))) / 86400);
        return $days <= 9;
    }

    /** The day the order's PO was received, else its order date. */
    public static function poDate(array $order): string
    {
        return substr((string) (($order['buyer_po_received_on'] ?? null) ?: $order['created_at']), 0, 10);
    }

    /** Rupees per unit of the order currency actually received for the products (advance or LC, and balance) — the fallback when no receipt is recorded. */
    public static function productRate(array $order): float
    {
        if (strtoupper((string) ($order['currency_code'] ?? 'INR')) === 'INR') {
            return 1.0;
        }
        $payment = OrderPaymentStatusRepository::find((int) $order['id']) ?? [];
        $inr = 0.0;
        $foreign = 0.0;
        foreach (['advance', 'balance'] as $leg) {
            if ((float) ($payment[$leg . '_amount'] ?? 0) > 0 && $payment[$leg . '_inr_actual'] !== null) {
                $inr += (float) $payment[$leg . '_inr_actual'];
                $foreign += (float) $payment[$leg . '_amount'];
            }
        }
        $lc = OrderLcDetailRepository::find((int) $order['id']);
        if (($order['payment_method'] ?? '') === 'LC' && $lc !== null && (float) ($lc['lc_received_amount'] ?? 0) > 0) {
            // QA batch 4, MON-6: plus the rupees the bank kept as LC charges, as for every other receipt.
            $inr += (float) $lc['lc_inr_credited'] + (float) ($lc['lc_bank_charges_inr'] ?? 0);
            $foreign += (float) $lc['lc_received_amount'];
        }
        if ($foreign > 0) {
            return $inr / $foreign;
        }
        return (float) ($payment['balance_exchange_rate'] ?? $payment['advance_exchange_rate'] ?? 0);
    }

    /**
     * D-259 (owner approval 6 Nov 2026): who shares in the team incentive. A person's own
     * switch (users.incentive_eligible) wins; empty follows their designation (the default
     * active membership's department designation); with no designation they are eligible.
     *
     * @return array<int, array{eligible:bool, source:string, designation:?string}>
     */
    public static function eligibility(): array
    {
        return \App\Helpers\BatchCache::memo('incentive-eligibility', static function (): array {
            $pdo = Database::connection();
            $designation = [];
            foreach ($pdo->query(
                'SELECT m.user_id, d.title, d.incentive_eligible FROM user_department_memberships m
                 JOIN department_designations d ON d.id = m.designation_id
                 WHERE m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
                 ORDER BY m.is_default DESC, m.id'
            )->fetchAll() as $r) {
                $designation[(int) $r['user_id']] ??= ['title' => (string) $r['title'], 'eligible' => (int) $r['incentive_eligible'] === 1];
            }
            $out = [];
            foreach ($pdo->query('SELECT id, incentive_eligible FROM users')->fetchAll() as $u) {
                $id = (int) $u['id'];
                $d = $designation[$id] ?? null;
                if ($u['incentive_eligible'] !== null) {
                    $out[$id] = ['eligible' => (int) $u['incentive_eligible'] === 1, 'source' => 'person', 'designation' => $d['title'] ?? null];
                } else {
                    $out[$id] = ['eligible' => $d['eligible'] ?? true, 'source' => $d !== null ? 'designation' : 'default', 'designation' => $d['title'] ?? null];
                }
            }
            return $out;
        });
    }

    /** D-259: only the Managing Director changes who is eligible for the team incentive. */
    public static function isMd(?array $user): bool
    {
        if ($user === null || empty($user['role_id'])) {
            return false;
        }
        return (\App\Repositories\RoleRepository::find((int) $user['role_id'])['name'] ?? '') === 'Managing Director';
    }

    /** D-259: the person's designation and whether it is eligible (null: no designation). @return array{title:string, eligible:bool}|null */
    public static function designationEligibility(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.title, d.incentive_eligible FROM user_department_memberships m
             JOIN department_designations d ON d.id = m.designation_id
             WHERE m.user_id = :u AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
             ORDER BY m.is_default DESC, m.id LIMIT 1'
        );
        $stmt->execute(['u' => $userId]);
        $r = $stmt->fetch();
        return $r ? ['title' => (string) $r['title'], 'eligible' => (int) $r['incentive_eligible'] === 1] : null;
    }

    /** D-259: active people whose own switch differs from their designation's. @return list<array{name:string, designation:?string, eligible:bool}> */
    public static function eligibilityOverrides(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT id, name, incentive_eligible FROM users WHERE is_active = 1 AND incentive_eligible IS NOT NULL ORDER BY name')->fetchAll() as $u) {
            $d = self::designationEligibility((int) $u['id']);
            $own = (int) $u['incentive_eligible'] === 1;
            if ($own !== ($d['eligible'] ?? true)) {
                $out[] = ['name' => (string) $u['name'], 'designation' => $d['title'] ?? null, 'eligible' => $own];
            }
        }
        return $out;
    }

    /** D-259: whether this person shares in the team incentive now (their own switch, else their designation). */
    public static function isEligible(int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT incentive_eligible FROM users WHERE id = :u');
        $stmt->execute(['u' => $userId]);
        $own = $stmt->fetchColumn();
        if ($own !== false && $own !== null) {
            return (int) $own === 1;
        }
        return self::designationEligibility($userId)['eligible'] ?? true;
    }

    /**
     * D-259: the MD sets the person's switch. Matching the designation stores "follow the
     * designation" (NULL), so a later change to the designation still applies to them.
     * Returns an error or null.
     */
    public static function setEligible(?array $actor, int $userId, bool $eligible): ?string
    {
        if (!self::isMd($actor)) {
            return 'Only the MD can change who is eligible for the team incentive.';
        }
        $before = self::isEligible($userId);
        $designation = self::designationEligibility($userId);
        $value = $eligible === ($designation['eligible'] ?? true) ? null : ($eligible ? 1 : 0);
        Database::connection()->prepare('UPDATE users SET incentive_eligible = :v WHERE id = :u')->execute(['v' => $value, 'u' => $userId]);
        if ($before !== $eligible) {
            \App\Repositories\AuditLogRepository::log((int) $actor['id'], 'USER_UPDATED', 'users', $userId, 'incentive_eligible', $before ? '1' : '0', $eligible ? '1' : '0');
        }
        return null;
    }

    /** D-259: the incentive month an unlocked order would be locked into today (the next staff payout run). */
    public static function estimateMonth(): string
    {
        return substr(AssociateCommission::payoutRunOn(date('Y-m-d')), 0, 7);
    }

    /**
     * The people on each role: those set on the order, else the order's
     * assignees with that incentive role, else everyone with that role.
     *
     * @return array<string, list<array{id:int,name:string}>>
     */
    public static function peopleFor(int $orderId): array
    {
        $names = [];
        foreach (UserRepository::listActive() as $u) {
            $names[(int) $u['id']] = (string) $u['name'];
        }
        $row = IncentiveRepository::forOrder($orderId);
        if ($row !== null && (int) $row['people_customised'] === 1) {
            $chosen = IncentiveRepository::orderPeople($orderId);
        } else {
            $rolesByUser = IncentiveRepository::userRoles();
            $assigned = array_map(static fn (array $a): int => (int) $a['user_id'], OrderAssigneeRepository::forOrder($orderId));
            $chosen = [];
            foreach (array_keys(IncentiveRepository::roles()) as $role) {
                $holders = array_keys(array_filter($rolesByUser, static fn (array $roles): bool => in_array($role, $roles, true)));
                $onOrder = array_values(array_intersect($holders, $assigned));
                $chosen[$role] = $onOrder !== [] ? $onOrder : $holders;
            }
        }
        $out = [];
        foreach (array_keys(IncentiveRepository::roles()) as $role) {
            $out[$role] = [];
            foreach ($chosen[$role] ?? [] as $userId) {
                if (isset($names[$userId])) {
                    $out[$role][] = ['id' => $userId, 'name' => $names[$userId]];
                }
            }
        }
        return $out;
    }

    /** The order's own amount: global / pct / fixed. @return array{mode:string,value:?float,reason:?string} */
    public static function orderRule(?array $row): array
    {
        $mode = (string) ($row['incentive_mode'] ?? 'global');
        return ['mode' => in_array($mode, ['pct', 'fixed'], true) ? $mode : 'global',
            'value' => isset($row['incentive_value']) && $row['incentive_value'] !== null ? (float) $row['incentive_value'] : null,
            'reason' => $row['incentive_reason'] ?? null];
    }

    /**
     * The global (or product) rule's amount for the order: % of the profit
     * basis, or a fixed USD amount per unit converted at the order's rate.
     * A line whose product has its own rule uses it, weighted by its share
     * of the goods FOB; other lines use the global rule.
     *
     * @return array{amount: float, text: string}
     */
    public static function globalAmount(int $orderId, array $order, float $basis): array
    {
        $settings = PricingService::settings();
        $lines = AssociateCommission::goodsLines($orderId);
        $total = 0.0;
        foreach ($lines as $l) {
            $total += (float) $l['fob_value'];
        }
        // Fresh-eyes review H1: a "USD x per unit" rule is converted at the USD rate, not the order currency's.
        $rate = AssociateCommission::usdRate($order);
        $describe = static fn (string $mode, float $value): string => $mode === 'usd' ? 'USD ' . number_format($value, 2) . ' per unit' : PricingService::pct($value) . ' of profit';
        if ($lines === [] || $total <= 0) {
            $amount = $settings['incentive_mode'] === 'usd' ? 0.0 : $basis * $settings['incentive_value'] / 100;
            return ['amount' => round($amount, 2), 'text' => $describe($settings['incentive_mode'], $settings['incentive_value'])];
        }
        $amount = 0.0;
        $own = false;
        foreach ($lines as $l) {
            $mode = $settings['incentive_mode'];
            $value = $settings['incentive_value'];
            if (!empty($l['catalog_product_id'])) {
                $product = \App\Repositories\ProductRepository::find((int) $l['catalog_product_id']);
                if ($product !== null && ($product['pricing_incentive_mode'] ?? null) !== null) {
                    $mode = (string) $product['pricing_incentive_mode'];
                    $value = (float) $product['pricing_incentive_value'];
                    $own = true;
                }
            }
            $amount += $mode === 'usd'
                ? $value * (float) ($l['quantity'] ?? 0) * $rate
                : $basis * $value / 100 * ((float) $l['fob_value'] / $total);
        }
        return ['amount' => round($amount, 2), 'text' => $describe($settings['incentive_mode'], $settings['incentive_value']) . ($own ? ' (some products have their own rule)' : '')];
    }

    /**
     * The order's incentive as it stands. Before the order is locked every
     * figure is an estimate; the locked figures are on order_incentives.
     */
    /** @param array<string,mixed>|null $baseProfit the order's computeForOrder($id, false) when the caller has it already (D-245 P5: not worked out twice) */
    public static function calculate(int $orderId, ?array $baseProfit = null): array
    {
        $order = OrderRepository::find($orderId);
        $row = IncentiveRepository::forOrder($orderId);
        $profit = $baseProfit ?? OrderProfitabilityService::computeForOrder($orderId, false);
        $already = array_sum(array_map(static fn (array $c): float => (float) $c['amount_inr'], IncentiveRepository::costEntries($orderId)));
        $profitBefore = round((float) $profit['profit_inr'] + $already, 2);
        $revenue = (float) ($profit['revenue_inr'] ?? 0);

        // Owner decision D-245 P3: the associate's commission comes off first.
        $associate = AssociateCommission::forOrder($orderId, $order, $row ?? ['associate_mode' => 'auto']);
        $basisRaw = round($profitBefore - ($associate['amount_inr'] ?? 0.0), 2);
        // P4: the profit used for the incentive never goes below 0.
        $basis = max(0.0, $basisRaw);
        $programOn = self::settings()['enabled'];
        $rule = self::orderRule($row);
        $global = self::globalAmount($orderId, $order, $basis);
        if ($rule['mode'] === 'fixed') {
            $amount = round((float) $rule['value'], 2);
            $source = 'order_fixed';
        } elseif ($rule['mode'] === 'pct') {
            $amount = round($basis * (float) $rule['value'] / 100, 2);
            $source = 'order_pct';
        } elseif (!$programOn) {
            $amount = 0.0;
            $source = 'off';
        } else {
            $amount = $global['amount'];
            $source = 'global';
        }

        $split = self::split($orderId, $amount);
        $minMargin = (float) PricingService::settings()['margin_pct'];
        $left = round($basis - $split['team_total'], 2);
        $marginAfter = $revenue > 0 ? round(($profitBefore - ($associate['amount_inr'] ?? 0.0) - $split['team_total']) / $revenue * 100, 2) : null;
        $cutUsd = 0.0;
        foreach (AssociateCommission::goodsLines($orderId) as $l) {
            if (!empty($l['price_approved_by']) && $l['price_approval_incentive_cut'] !== null) {
                $cutUsd += (float) $l['price_approval_incentive_cut'];
            }
        }

        return [
            'profit_before' => $profitBefore,
            'revenue' => $revenue,
            'associate' => $associate,
            'basis_raw' => $basisRaw,
            'basis' => $basis,
            'estimated' => self::fullyPaidOn($orderId) === null,
            'program_on' => $programOn,
            'rule' => $rule,
            'global_amount' => $global['amount'],
            'global_text' => $global['text'],
            'source' => $source,
            'amount' => $amount,
            'team_total' => $split['team_total'],
            'unallocated' => $split['unallocated'],
            'roles' => $split['roles'],
            'per_person' => $split['per_person'],
            'lines' => $split['lines'],
            'team_by_share' => $split['team_by_share'],
            'kept_total' => $split['kept_total'],
            'kept_not_eligible' => $split['kept_not_eligible'],
            'kept_capped' => $split['kept_capped'],
            'shares_customised' => $split['customised'],
            'left_for_company' => $left,
            'margin_after_pct' => $marginAfter,
            'min_margin_pct' => $minMargin,
            'margin_warning' => $marginAfter !== null && $split['team_total'] > 0 && $marginAfter < $minMargin,
            'over_profit' => $amount > $basis + 0.005,
            'incentive_cut_usd' => round($cutUsd, 2),
            'state' => self::state($orderId, $row),
        ];
    }

    /**
     * Who gets what: each active role with its tick, people and share on this
     * order. Unticked roles (or roles with nobody) give their share to the
     * ticked ones in proportion; custom shares (they add up to 100%) win.
     *
     * @return array{roles: array<string, array<string,mixed>>, team_total: float, unallocated: float, per_person: list<array{id:int,name:string,amount:float,roles:list<string>}>, customised: bool}
     */
    public static function split(int $orderId, float $amount): array
    {
        $row = IncentiveRepository::forOrder($orderId);
        $customised = (int) ($row['shares_customised'] ?? 0) === 1;
        $ticks = IncentiveRepository::orderRoles($orderId);
        $people = self::peopleFor($orderId);
        $all = IncentiveRepository::allRoles();
        $roles = [];
        foreach (IncentiveRepository::roles() as $key => $label) {
            $hasPeople = ($people[$key] ?? []) !== [];
            $gets = ($ticks[$key]['gets_it'] ?? true) && $hasPeople;
            $roles[$key] = ['key' => $key, 'label' => $label, 'usual_pct' => $all[$key]['share_pct'], 'ticked' => $ticks[$key]['gets_it'] ?? true,
                'gets_it' => $gets, 'has_people' => $hasPeople, 'people' => $people[$key] ?? [], 'custom_pct' => $ticks[$key]['share_pct'] ?? null,
                'pct' => 0.0, 'amount' => 0.0, 'each' => 0.0];
        }
        $weights = [];
        foreach ($roles as $key => $r) {
            if ($r['gets_it']) {
                $weights[$key] = $customised ? (float) ($r['custom_pct'] ?? 0) : $r['usual_pct'];
            }
        }
        $sum = array_sum($weights);
        $teamTotal = 0.0;
        $byShare = 0.0;
        $perPerson = [];
        $lines = [];
        if ($sum > 0 && $amount > 0) {
            $keys = array_keys($weights);
            $allocated = 0.0;
            foreach ($keys as $i => $key) {
                $pct = $weights[$key] / $sum * 100;
                // The last ticked role takes the rounding, so the shares add up to the amount exactly.
                $roleAmount = $i === count($keys) - 1 ? round($amount - $allocated, 2) : round($amount * $pct / 100, 2);
                $allocated += $roleAmount;
                $n = count($roles[$key]['people']);
                $each = round($roleAmount / $n, 2);
                $roles[$key]['pct'] = round($pct, 2);
                $roles[$key]['amount'] = $roleAmount;
                $roles[$key]['each'] = $each;
                foreach ($roles[$key]['people'] as $j => $p) {
                    // The last person in a role takes the rounding too.
                    $share = $j === $n - 1 ? round($roleAmount - $each * ($n - 1), 2) : $each;
                    // D-259: not eligible / above a cap → the company keeps it; nobody else's amount goes up.
                    $line = self::applyEligibilityAndCaps($orderId, $key, $roles[$key]['label'], $p, $share, $all[$key] ?? []);
                    $lines[] = $line;
                    $perPerson[$p['id']] ??= ['id' => $p['id'], 'name' => $p['name'], 'amount' => 0.0, 'roles' => []];
                    $perPerson[$p['id']]['amount'] = round($perPerson[$p['id']]['amount'] + $line['gets'], 2);
                    $perPerson[$p['id']]['roles'][] = $roles[$key]['label'];
                }
            }
            $byShare = round($allocated, 2);
            $teamTotal = round(array_sum(array_column($lines, 'gets')), 2);
        } elseif ($sum > 0) {
            foreach ($weights as $key => $w) {
                $roles[$key]['pct'] = round($w / $sum * 100, 2);
            }
        }
        $keptNotEligible = round(array_sum(array_map(static fn (array $l): float => $l['reason'] === 'not_eligible' ? $l['kept'] : 0.0, $lines)), 2);
        $keptCapped = round(array_sum(array_map(static fn (array $l): float => $l['reason'] !== null && $l['reason'] !== 'not_eligible' ? $l['kept'] : 0.0, $lines)), 2);
        return ['roles' => $roles, 'team_total' => $teamTotal, 'unallocated' => round(max(0.0, $amount - $byShare), 2),
            'per_person' => array_values($perPerson), 'customised' => $customised,
            'lines' => $lines, 'team_by_share' => $byShare, 'kept_total' => round($keptNotEligible + $keptCapped, 2),
            'kept_not_eligible' => $keptNotEligible, 'kept_capped' => $keptCapped];
    }

    /**
     * D-259: one person's line in one role — their share, what they get and what the company
     * keeps (not eligible, or above the role's per-order / monthly cap). The monthly cap counts
     * what the person already has locked in that role for the month the order would lock into.
     *
     * @param array{id:int,name:string} $person
     * @param array<string,mixed> $roleRow incentive_roles row (caps)
     * @return array{user_id:int, name:string, role:string, role_label:string, by_share:float, gets:float, kept:float, reason:?string, note:?string}
     */
    public static function applyEligibilityAndCaps(int $orderId, string $role, string $roleLabel, array $person, float $share, array $roleRow): array
    {
        $line = ['user_id' => (int) $person['id'], 'name' => (string) $person['name'], 'role' => $role, 'role_label' => $roleLabel,
            'by_share' => round($share, 2), 'gets' => round($share, 2), 'kept' => 0.0, 'reason' => null, 'note' => null];
        if ($share <= 0) {
            return $line;
        }
        $e = self::eligibility()[(int) $person['id']] ?? ['eligible' => true, 'source' => 'default', 'designation' => null];
        if (!$e['eligible']) {
            $line['gets'] = 0.0;
            $line['kept'] = $line['by_share'];
            $line['reason'] = 'not_eligible';
            $line['note'] = $e['source'] === 'person'
                ? 'not eligible (switched off for this person), share stays with company'
                : 'not eligible (' . ($e['designation'] ?? 'designation') . ' designation), share stays with company';
            return $line;
        }
        $capOrder = $roleRow['cap_per_order'] ?? null;
        if ($capOrder !== null && $line['gets'] > $capOrder) {
            $line['gets'] = round((float) $capOrder, 2);
            $line['reason'] = 'cap_order';
            $line['note'] = 'capped at ₹' . number_format((float) $capOrder, 0) . ' per order';
        }
        $capMonth = $roleRow['cap_per_month'] ?? null;
        if ($capMonth !== null) {
            $already = IncentiveRepository::lockedInMonth((int) $person['id'], $role, self::estimateMonth(), $orderId);
            $room = round(max(0.0, (float) $capMonth - $already), 2);
            if ($line['gets'] > $room) {
                $line['gets'] = $room;
                $line['reason'] = 'cap_month';
                $line['note'] = 'capped: ₹' . number_format($already, 0) . ' already this month, monthly cap ₹' . number_format((float) $capMonth, 0);
            }
        }
        $line['kept'] = round($line['by_share'] - $line['gets'], 2);
        return $line;
    }

    /** open | ready | locked | paid */
    public static function state(int $orderId, ?array $row = null): string
    {
        $row ??= IncentiveRepository::forOrder($orderId);
        if ($row !== null && !empty($row['locked_at'])) {
            foreach (IncentiveRepository::payoutsForOrder($orderId) as $p) {
                if ($p['status'] === 'paid') {
                    return 'paid';
                }
            }
            return 'locked';
        }
        return self::readyReason($orderId) === null ? 'ready' : 'open';
    }

    /** Why the order is not ready to lock yet, or null when it is (buyer fully paid, no claim open). */
    public static function readyReason(int $orderId): ?string
    {
        $order = OrderRepository::find($orderId);
        if ($order === null || in_array($order['status'], ['cancelled', 'lost'], true)) {
            return 'The order is cancelled or lost.';
        }
        $paidOn = self::fullyPaidOn($orderId);
        if ($paidOn === null) {
            return 'The buyer has not paid in full yet.';
        }
        if (AssociateCommission::claimOpen($orderId, date('Y-m-d'))) {
            return 'A buyer claim on this order is still open.';
        }
        return null;
    }

    /** @return list<int> order ids ready to lock (fully paid, no open claim, not locked) */
    public static function readyToLock(): array
    {
        $out = [];
        foreach (IncentiveRepository::unlockedOrderIdsWithPayments() as $orderId) {
            if (self::readyReason($orderId) === null) {
                $out[] = $orderId;
            }
        }
        return $out;
    }

    /** Locks the order if it is ready. Returns null on success, else why not. */
    public static function lockIfReady(int $orderId, int $userId, ?string $note = null): ?string
    {
        $row = IncentiveRepository::forOrder($orderId);
        if ($row !== null && !empty($row['locked_at'])) {
            return 'This order\'s incentive is already locked.';
        }
        if (($reason = self::readyReason($orderId)) !== null) {
            return $reason;
        }
        $calc = self::calculate($orderId);
        if ($calc['over_profit']) {
            return 'The team incentive (₹' . number_format($calc['amount'], 2) . ') is more than the order\'s profit (₹' . number_format($calc['basis'], 2) . ') — lower it before locking.';
        }
        if (!self::lock($orderId, (string) self::fullyPaidOn($orderId), $userId, $calc, $note)) {
            return 'This order\'s incentive is already locked.';
        }
        return null;
    }

    /** Locks every order that is ready. @return array{locked:int, skipped:list<string>} */
    public static function lockAllReady(int $userId): array
    {
        $locked = 0;
        $skipped = [];
        foreach (self::readyToLock() as $orderId) {
            $error = self::lockIfReady($orderId, $userId, 'Locked with "Lock all ready".');
            if ($error === null) {
                $locked++;
            } else {
                $o = OrderRepository::find($orderId);
                $skipped[] = (string) (($o['buyer_inquiry_ref'] ?? '') ?: ($o['order_reference'] ?? $orderId)) . ': ' . $error;
            }
        }
        return ['locked' => $locked, 'skipped' => $skipped];
    }

    /**
     * Locks the order's incentive: writes the payouts and cost entries. Returns false (and
     * writes nothing) when the order is already locked — fresh-eyes review M1: the order's
     * row is claimed with SELECT … FOR UPDATE inside the transaction, so two lock requests
     * at the same moment ("Lock all ready" in one tab, the order in another) write once.
     */
    public static function lock(int $orderId, string $paidOn, int $userId, ?array $calc = null, ?string $note = null): bool
    {
        $calc ??= self::calculate($orderId);
        $today = date('Y-m-d');
        // P4: staff are paid in the payout run after the lock date.
        $staffRun = AssociateCommission::payoutRunOn($today);
        $month = substr($staffRun, 0, 7);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT IGNORE INTO order_incentives (order_id, updated_by) VALUES (:id, :u)')->execute(['id' => $orderId, 'u' => $userId]);
            $claim = $pdo->prepare('SELECT locked_at, associate_fixed_at FROM order_incentives WHERE order_id = :id FOR UPDATE');
            $claim->execute(['id' => $orderId]);
            $claimRow = $claim->fetch() ?: [];
            // D-264: an associate commission fixed by itself at full payment stays as it is; the lock adds the team's part only.
            $assocFixed = !empty($claimRow['associate_fixed_at']);
            // The same guard on the payouts: an order never gets a second set (one per order, payee and role).
            $existing = $pdo->prepare("SELECT COUNT(*) FROM incentive_payouts WHERE order_id = :id AND clawback_of_payout_id IS NULL AND (payee_kind = 'staff' OR :af = 0)");
            $existing->execute(['id' => $orderId, 'af' => $assocFixed ? 1 : 0]);
            if (!empty($claimRow['locked_at']) || (int) $existing->fetchColumn() > 0) {
                $pdo->rollBack();
                return false;
            }
            $teamEntryId = null;
            // D-259: what each person gets after eligibility and caps; what the company keeps is recorded per line.
            foreach ($calc['lines'] ?? [] as $l) {
                if ($l['kept'] > 0) {
                    IncentiveRepository::addKept(['order_id' => $orderId, 'user_id' => $l['user_id'], 'role' => $l['role'], 'by_share_inr' => $l['by_share'],
                        'paid_inr' => $l['gets'], 'kept_inr' => $l['kept'], 'kept_reason' => $l['reason'], 'kept_note' => $l['note'],
                        'incentive_month' => $month, 'locked_on' => $today]);
                }
            }
            if ($calc['team_total'] > 0) {
                foreach ($calc['lines'] ?? [] as $l) {
                    if ($l['gets'] > 0) {
                        IncentiveRepository::addPayout(['order_id' => $orderId, 'payee_kind' => 'staff', 'user_id' => $l['user_id'], 'role' => $l['role'], 'amount_inr' => $l['gets'],
                            'incentive_month' => $month, 'payout_run_on' => $staffRun, 'locked_on' => $today]);
                    }
                }
                $order = OrderRepository::find($orderId);
                $teamEntryId = IncentiveRepository::addCostEntry($orderId, 'team', 'Team incentive for order ' . (($order['buyer_inquiry_ref'] ?? '') ?: ($order['order_reference'] ?? $orderId))
                    . ' — ' . self::ruleText($calc) . ', locked ' . \App\Helpers\Dates::short($today) . ', paid in the ' . \App\Helpers\Dates::short($staffRun) . ' payout run', $calc['team_total'], $paidOn, $userId);
            }
            $associateEntryId = null;
            $a = $assocFixed ? null : $calc['associate'];
            // Owner decision D-245 P3: due N days after full payment, paid in the payout run that follows.
            $commissionSet = AssociateCommission::settings();
            $dueOn = $a !== null ? AssociateCommission::dueOn($paidOn, $commissionSet) : null;
            $runOn = $dueOn !== null ? AssociateCommission::payoutRunOn($dueOn, $commissionSet) : null;
            $earned = $a !== null && $a['state'] !== 'unpaid' && $a['amount_fc'] > 0;
            if ($earned) {
                $payoutId = IncentiveRepository::addPayout(['order_id' => $orderId, 'payee_kind' => 'associate', 'associate_id' => $a['id'], 'role' => 'associate',
                    'amount_inr' => $a['amount_inr'], 'amount_fc' => $a['amount_fc'], 'currency_code' => $a['currency'], 'incentive_month' => substr((string) $runOn, 0, 7),
                    'payout_run_on' => $runOn, 'locked_on' => $today]);
                // D-264: the rate is fixed with it (only reached when the commission was not already fixed at full payment).
                Database::connection()->prepare('UPDATE incentive_payouts SET due_on = :d, fixed_rate = :r, fixed_on = :f WHERE id = :id')
                    ->execute(['d' => $dueOn, 'r' => round((float) $a['rate_inr'], 6), 'f' => $today, 'id' => $payoutId]);
                // AS-3: a suspended associate's commission is held until the MD releases it.
                if ($a['status'] === 'suspended') {
                    IncentiveRepository::holdPayout($payoutId, 'The associate is suspended.');
                }
                $associateEntryId = IncentiveRepository::addCostEntry($orderId, 'associate', self::associateCostDescription($a), $a['amount_inr'], $paidOn, $userId);
            }
            $teamFields = [
                'locked_at' => date('Y-m-d H:i:s'),
                'locked_by' => $userId,
                'unlock_reason' => null,
                'fully_paid_on' => $paidOn,
                'incentive_month' => $month,
                'profit_basis_inr' => $calc['basis'],
                'team_total_inr' => $calc['team_total'],
                'team_by_share_inr' => $calc['team_by_share'] ?? $calc['team_total'],
                'kept_not_eligible_inr' => $calc['kept_not_eligible'] ?? 0.0,
                'kept_capped_inr' => $calc['kept_capped'] ?? 0.0,
                'team_cost_entry_id' => $teamEntryId,
            ];
            IncentiveRepository::saveOrder($orderId, $assocFixed ? $teamFields : $teamFields + [
                'associate_id' => $a['id'] ?? null,
                'associate_order_number' => $a !== null && $a['counts'] ? $a['counting_number'] : null,
                'associate_rate_pct' => $a['rate'] ?? null,
                'associate_amount_fc' => $a !== null ? $a['amount_fc'] : null,
                'associate_currency' => $a['currency'] ?? null,
                'associate_amount_inr' => $a !== null ? $a['amount_inr'] : null,
                'associate_basis_fc' => $a['basis_fc'] ?? null,
                'associate_basis_inr' => $a['basis_inr'] ?? null,
                'associate_counts' => $a !== null ? ($a['counts'] ? 1 : 0) : null,
                'associate_due_on' => $earned ? $dueOn : null,
                'associate_cost_entry_id' => $associateEntryId,
                'associate_fixed_at' => $earned ? date('Y-m-d H:i:s') : null,
                'associate_fixed_rate' => $earned ? round((float) $a['rate_inr'], 6) : null,
            ], $userId);
            IncentiveRepository::addHistory($orderId, 'lock', null, 'Team ₹' . number_format($calc['team_total'], 2)
                . ($assocFixed ? '; associate already fixed at full payment' : ($a !== null ? '; associate ' . ($earned ? '₹' . number_format((float) $a['amount_inr'], 2) : 'none') : '')) . '; staff payout run ' . $staffRun, $note, $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($a !== null) {
            $associate = AssociateRepository::find((int) $a['id']);
            $order = OrderRepository::find($orderId);
            if ($associate !== null && $order !== null) {
                if (!$earned) {
                    AssociateService::notify($associate, 'commission_earned', "Order {$order['order_reference']} is closed for commission: "
                        . ($a['state'] === 'unpaid' ? 'the buyer did not pay the order in full, so no commission is due on it.' : 'no commission is due on it.'), (int) $order['client_id'], $orderId);
                } else {
                    AssociateService::notify($associate, $a['status'] === 'suspended' ? 'payout_held' : 'commission_earned',
                        "Your client paid order {$order['order_reference']} in full. Commission earned: {$a['currency']} " . number_format($a['amount_fc'], 2)
                        . ' (' . AssociateCommission::rateLabel($a['level'] ?? null) . ' on the invoiced FOB of the goods, ' . $a['currency'] . ' ' . number_format($a['basis_fc'], 2) . ').'
                        . ($a['status'] === 'suspended' ? ' It is on hold while your account is suspended.'
                            : ' It is due on ' . \App\Helpers\Dates::short((string) $dueOn) . ' and paid in the ' . \App\Helpers\Dates::short((string) $runOn) . ' payout run, against your invoice'
                              . (trim((string) ($associate['gstin'] ?? '')) !== '' ? ' (GST is added on top once your tax invoice is on file)' : '') . '.'),
                        (int) $order['client_id'], $orderId);
                }
            }
        }
        return true;
    }

    /** "15% of profit (global rule)", "fixed ₹1,00,000 set for this order" … */
    public static function ruleText(array $calc): string
    {
        return match ($calc['source']) {
            'order_fixed' => 'fixed ₹' . number_format($calc['amount'], 2) . ' set for this order',
            'order_pct' => PricingService::pct((float) $calc['rule']['value']) . ' of profit set for this order',
            'off' => 'program off — none',
            default => $calc['global_text'] . ' (global rule)',
        };
    }

    /** Owner rule D-212: the commission cost line says what it is, for whom, at which level and on what basis. */
    public static function associateCostDescription(array $a): string
    {
        return 'Associate commission — ' . $a['name'] . ' (' . $a['level_label'] . ') on the invoiced FOB of the goods, ' . $a['currency'] . ' ' . number_format((float) $a['basis_fc'], 2)
            . ((float) $a['reduction_fc'] > 0 ? ' (after agreed discount / settled claim of ' . $a['currency'] . ' ' . number_format((float) $a['reduction_fc'], 2) . ')' : '')
            . ((float) $a['in_range_fc'] < (float) $a['goods_fc'] ? '; lines the MD approved below the floor earn nothing' : '');
    }

    /** Unlocks the order so its incentive can change again; needs a reason; refused once anything is paid. Returns an error or null. */
    public static function unlock(int $orderId, int $userId, string $reason): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return 'Say why the incentive is unlocked (10 to 500 characters).';
        }
        $row = IncentiveRepository::forOrder($orderId);
        if ($row === null || empty($row['locked_at'])) {
            return 'This order\'s incentive is not locked.';
        }
        foreach (IncentiveRepository::payoutsForOrder($orderId) as $p) {
            if ($p['status'] === 'paid') {
                return 'Part of this order\'s incentive is already paid, so it can no longer be unlocked.';
            }
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        // Fresh-eyes review M1: checked again inside the transaction, with the rows locked, so a
        // payout marked paid at the same moment is never deleted (only unpaid ones are).
        $relock = $pdo->prepare('SELECT locked_at FROM order_incentives WHERE order_id = :id FOR UPDATE');
        $relock->execute(['id' => $orderId]);
        $paidNow = $pdo->prepare("SELECT COUNT(*) FROM incentive_payouts WHERE order_id = :id AND status = 'paid' FOR UPDATE");
        $paidNow->execute(['id' => $orderId]);
        if (empty($relock->fetchColumn())) {
            $pdo->rollBack();
            return 'This order\'s incentive is not locked.';
        }
        if ((int) $paidNow->fetchColumn() > 0) {
            $pdo->rollBack();
            return 'Part of this order\'s incentive is already paid, so it can no longer be unlocked.';
        }
        IncentiveRepository::deleteOrderKept($orderId);
        if (!empty($row['associate_fixed_at']) && empty($row['associate_cost_entry_id']) === false && self::associateFixedBeforeLock($orderId)) {
            // D-264: the associate's commission was fixed by itself at full payment — unlocking the team incentive leaves it as it is.
            $pdo->prepare("DELETE FROM incentive_payouts WHERE order_id = :id AND payee_kind = 'staff' AND status <> 'paid'")->execute(['id' => $orderId]);
            $pdo->prepare("DELETE FROM order_cost_entries WHERE order_id = :id AND incentive_kind = 'team'")->execute(['id' => $orderId]);
            IncentiveRepository::saveOrder($orderId, [
                'locked_at' => null, 'locked_by' => null, 'unlock_reason' => $reason, 'incentive_month' => null, 'profit_basis_inr' => null, 'team_total_inr' => null,
                'team_by_share_inr' => null, 'kept_not_eligible_inr' => null, 'kept_capped_inr' => null, 'team_cost_entry_id' => null,
            ], $userId);
            IncentiveRepository::addHistory($orderId, 'unlock', 'Locked ' . \App\Helpers\Dates::short(substr((string) $row['locked_at'], 0, 10)) . ', team ₹' . number_format((float) $row['team_total_inr'], 2), 'Open (the associate\'s fixed commission is kept)', $reason, $userId);
            $pdo->commit();
            return null;
        }
        IncentiveRepository::deleteOrderPayouts($orderId);
        $pdo->prepare('DELETE FROM order_cost_entries WHERE order_id = :id AND incentive_kind IS NOT NULL')->execute(['id' => $orderId]);
        IncentiveRepository::saveOrder($orderId, [
            'locked_at' => null, 'locked_by' => null, 'unlock_reason' => $reason, 'fully_paid_on' => null, 'incentive_month' => null, 'profit_basis_inr' => null, 'team_total_inr' => null,
            'team_by_share_inr' => null, 'kept_not_eligible_inr' => null, 'kept_capped_inr' => null,
            'associate_id' => null, 'associate_order_number' => null, 'associate_rate_pct' => null,
            'associate_amount_fc' => null, 'associate_currency' => null, 'associate_amount_inr' => null,
            'associate_basis_fc' => null, 'associate_basis_inr' => null, 'associate_counts' => null, 'associate_due_on' => null,
            'team_cost_entry_id' => null, 'associate_cost_entry_id' => null, 'associate_fixed_at' => null, 'associate_fixed_rate' => null,
            'associate_override_fc' => null, 'associate_override_reason' => null,
        ], $userId);
        IncentiveRepository::addHistory($orderId, 'unlock', 'Locked ' . \App\Helpers\Dates::short(substr((string) $row['locked_at'], 0, 10)) . ', team ₹' . number_format((float) $row['team_total_inr'], 2), 'Open', $reason, $userId);
        $pdo->commit();
        return null;
    }

    /** D-264: whether the associate's commission was fixed by itself at full payment (before the MD locked the team incentive). */
    private static function associateFixedBeforeLock(int $orderId): bool
    {
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM order_incentive_history WHERE order_id = :o AND action = 'associate_fixed'");
        $stmt->execute(['o' => $orderId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Daily: N days (setting) before the next payout run, remind the MD of
     * orders ready to lock but not locked. Returns the reminders sent.
     */
    public static function lockReminders(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $days = self::settings()['reminder_days'];
        $run = AssociateCommission::payoutRunOn($today);
        if ($days <= 0 || date('Y-m-d', (int) strtotime($run . ' -' . $days . ' days')) !== $today) {
            return 0;
        }
        $settings = IncentiveRepository::settings();
        if (($settings['lock_reminder_sent_for'] ?? '') === $run) {
            return 0;
        }
        $ready = self::readyToLock();
        if ($ready === []) {
            return 0;
        }
        $sent = 0;
        $users = Database::connection()->query(
            "SELECT DISTINCT u.id FROM users u JOIN role_permissions rp ON rp.role_id = u.role_id JOIN permissions p ON p.id = rp.permission_id
              WHERE p.permission_key = 'manage_pricing' AND u.is_active = 1"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($users as $uid) {
            \App\Repositories\NotificationRepository::createInAppOnly((int) $uid, 'incentive_lock_reminder', null,
                count($ready) . ' order(s) are ready to lock before the ' . \App\Helpers\Dates::short($run) . ' payout run (Team incentives › Ready to lock) — lock them so the team is paid in that run.');
            $sent++;
        }
        if ($users !== []) {
            IncentiveRepository::setSetting('lock_reminder_sent_for', $run, (int) $users[0]);
        }
        return $sent;
    }

    /**
     * Records a payout as paid. When every payout of its kind on the order
     * is paid, the order's cost entry takes the amounts actually paid and
     * goes to Zoho Books with the next sync.
     */
    /**
     * @return bool whether this call recorded the payment — false when the
     * payout was already paid, e.g. by a second click a moment earlier
     * (QA Round 6, R6-N17), so the caller logs it once.
     */
    public static function markPaid(int $payoutId, string $paidOn, float $paidInr, ?float $tdsInr, int $userId, ?string $paidVia = null, ?string $paidReference = null): bool
    {
        $payout = IncentiveRepository::findPayout($payoutId);
        if ($payout === null || $payout['status'] !== 'due') {
            return false;
        }
        if (!IncentiveRepository::markPayoutPaid($payoutId, $paidOn, $paidInr, $tdsInr, $userId, $paidVia, $paidReference)) {
            return false;
        }
        $kind = $payout['payee_kind'] === 'staff' ? 'team' : 'associate';
        $rows = array_filter(IncentiveRepository::payoutsForOrder((int) $payout['order_id']), static fn (array $p): bool => $p['payee_kind'] === $payout['payee_kind']);
        foreach ($rows as $p) {
            if ($p['status'] !== 'paid') {
                return true;
            }
        }
        $entry = IncentiveRepository::costEntries((int) $payout['order_id'])[$kind] ?? null;
        if ($entry !== null) {
            // Owner decision 2 Nov 2026 (D-235): an associate's cost is the gross commission — the TDS kept back is still part of it.
            IncentiveRepository::settleCostEntry((int) $entry['id'], round(array_sum(array_map(static fn (array $p): float => (float) $p['paid_inr'] + ($payout['payee_kind'] === 'associate' ? (float) ($p['tds_inr'] ?? 0) : 0.0), $rows)), 2),
                max(array_map(static fn (array $p): string => (string) $p['paid_on'], $rows)), self::paidNote($rows));
        }
        return true;
    }

    /**
     * Owner rule D-212: how the payouts of one order and kind were paid, for
     * its cost line — "with the salary (payroll), ref Nov-2026 payroll" —
     * each distinct way and reference once; null when none was recorded.
     *
     * @param array<int, array<string,mixed>> $rows
     */
    private static function paidNote(array $rows): ?string
    {
        $parts = [];
        foreach ($rows as $p) {
            if (empty($p['paid_via']) && empty($p['paid_reference'])) {
                continue;
            }
            $part = trim((IncentiveRepository::PAID_VIA[$p['paid_via'] ?? ''] ?? '') . (!empty($p['paid_reference']) ? ', ref ' . $p['paid_reference'] : ''), ' ,');
            $parts[$part] = true;
        }
        return $parts === [] ? null : implode('; ', array_keys($parts));
    }
}
