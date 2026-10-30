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
 * Team incentive program and associate commission (owner decision
 * 2026-10-30, mockups approved).
 *
 * - An order's figures are fixed ("locked") when the buyer has fully paid:
 *   every buyer payment it has (advance or LC, balance, freight) cleared.
 * - Associate commission: the client's associate, on the client's first
 *   orders (Day 1 = the client's first PO received, plus 9 days: the
 *   agreement's 10 days), at the ladder rate for the associate's Nth client, on the
 *   product (FOB) value. It is deducted from the profit first.
 * - Team reward: the amount set on the order (above 0), else the reward
 *   table for the profit left after the associate, if it reaches the
 *   minimum and the program is on. Plus the new client bonus on a client's
 *   first order. Split by role, equally between the people in a role.
 * - Both become the order's cost (a commission cost entry) when locked,
 *   and are paid in the incentive month: cut-off day C means the 21st of
 *   last month to the 20th of this month for C = 20.
 */
final class IncentiveService
{
    /** @return array{enabled:bool,start_date:string,min_profit:float,table:list<array{from:float,reward:float}>,bonus:float,split:array<string,float>,cutoff:string} */
    public static function settings(): array
    {
        $raw = IncentiveRepository::settings();
        $table = [];
        foreach ((array) json_decode($raw['reward_table'] ?? '[]', true) as $row) {
            if (is_array($row) && (float) ($row['from'] ?? 0) > 0 && (float) ($row['reward'] ?? 0) > 0) {
                $table[] = ['from' => (float) $row['from'], 'reward' => (float) $row['reward']];
            }
        }
        usort($table, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);
        $split = [];
        $savedSplit = (array) json_decode($raw['role_split'] ?? '{}', true);
        foreach (array_keys(IncentiveRepository::ROLES) as $role) {
            $split[$role] = (float) ($savedSplit[$role] ?? 0);
        }
        $cutoff = (string) ($raw['cutoff_day'] ?? '20');
        return [
            'enabled' => ($raw['program_enabled'] ?? '0') === '1',
            'start_date' => (string) ($raw['program_start_date'] ?? ''),
            'min_profit' => (float) ($raw['min_profit_inr'] ?? 0),
            'table' => $table,
            'bonus' => (float) ($raw['new_client_bonus_inr'] ?? 0),
            'split' => $split,
            'cutoff' => $cutoff === 'last' ? 'last' : (string) max(1, min(28, (int) $cutoff)),
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

    /** The incentive month (YYYY-MM) a full payment on $date belongs to. */
    public static function incentiveMonth(string $date, string $cutoff): string
    {
        $ts = strtotime($date);
        if ($cutoff === 'last' || (int) date('j', $ts) <= (int) $cutoff) {
            return date('Y-m', $ts);
        }
        return date('Y-m', strtotime(date('Y-m-01', $ts) . ' +1 month'));
    }

    /** "21 Oct to 20 Nov" for the incentive month. */
    public static function cycleLabel(string $month, string $cutoff): string
    {
        $first = strtotime($month . '-01');
        if ($cutoff === 'last') {
            return date('j M', $first) . ' to ' . date('j M', strtotime(date('Y-m-t', $first)));
        }
        $start = strtotime(date('Y-m-', strtotime('-1 month', $first)) . sprintf('%02d', (int) $cutoff + 1));
        return date('j M', $start) . ' to ' . date('j M', strtotime($month . '-' . sprintf('%02d', (int) $cutoff)));
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

    /** Rupees per unit of the order currency actually received for the products (advance or LC, and balance). */
    private static function productRate(array $order): float
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
            $inr += (float) $lc['lc_inr_credited'];
            $foreign += (float) $lc['lc_received_amount'];
        }
        if ($foreign > 0) {
            return $inr / $foreign;
        }
        return (float) ($payment['balance_exchange_rate'] ?? $payment['advance_exchange_rate'] ?? 0);
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
            foreach (array_keys(IncentiveRepository::ROLES) as $role) {
                $holders = array_keys(array_filter($rolesByUser, static fn (array $roles): bool => in_array($role, $roles, true)));
                $onOrder = array_values(array_intersect($holders, $assigned));
                $chosen[$role] = $onOrder !== [] ? $onOrder : $holders;
            }
        }
        $out = [];
        foreach (array_keys(IncentiveRepository::ROLES) as $role) {
            $out[$role] = [];
            foreach ($chosen[$role] ?? [] as $userId) {
                if (isset($names[$userId])) {
                    $out[$role][] = ['id' => $userId, 'name' => $names[$userId]];
                }
            }
        }
        return $out;
    }

    /**
     * The order's incentive as it stands (or would stand, if the buyer had
     * fully paid on $paidOn).
     */
    public static function calculate(int $orderId, ?string $paidOn = null): array
    {
        $order = OrderRepository::find($orderId);
        $settings = self::settings();
        $row = IncentiveRepository::forOrder($orderId) ?? ['reward_override_inr' => 0, 'is_excluded' => 0, 'associate_mode' => 'auto', 'locked_at' => null];
        $profit = OrderProfitabilityService::computeForOrder($orderId);
        $already = array_sum(array_map(static fn (array $c): float => (float) $c['amount_inr'], IncentiveRepository::costEntries($orderId)));
        $profitBefore = round((float) $profit['profit_inr'] + $already, 2);

        $associate = null;
        $client = \App\Repositories\ClientRepository::find((int) $order['client_id']);
        if ($client !== null && !empty($client['associate_id']) && $row['associate_mode'] !== 'no'
            && ($row['associate_mode'] === 'yes' || self::withinFirstTenDays($order))) {
            $a = AssociateRepository::find((int) $client['associate_id']);
            if ($a !== null) {
                $number = !empty($row['associate_order_number']) ? (int) $row['associate_order_number'] : AssociateRepository::orderNumberForClient((int) $a['id'], (int) $order['client_id']);
                $rate = AssociateRepository::rateFor($a, $number);
                $fc = round(OrderProductRepository::totalFobValue($orderId) * $rate / 100, 2);
                $associate = [
                    'id' => (int) $a['id'], 'name' => (string) $a['name'], 'order_number' => $number, 'rate' => $rate,
                    'amount_fc' => $fc, 'currency' => (string) ($order['currency_code'] ?? 'INR'),
                    'amount_inr' => round($fc * self::productRate($order), 2),
                ];
            }
        }
        $basis = round($profitBefore - ($associate['amount_inr'] ?? 0.0), 2);

        $source = 'table';
        $reward = 0.0;
        if ((int) $row['is_excluded'] === 1) {
            $source = 'excluded';
        } elseif ((float) $row['reward_override_inr'] > 0) {
            $source = 'override';
            $reward = (float) $row['reward_override_inr'];
        } elseif (!$settings['enabled']) {
            $source = 'off';
        } elseif ($paidOn !== null && $settings['start_date'] !== '' && $paidOn < $settings['start_date']) {
            $source = 'before_start';
        } elseif ($basis < $settings['min_profit']) {
            $source = 'below_min';
        } else {
            foreach ($settings['table'] as $t) {
                if ($basis >= $t['from']) {
                    $reward = $t['reward'];
                }
            }
            if ($reward <= 0) {
                $source = 'below_min';
            }
        }
        $bonus = ($reward > 0 && $settings['bonus'] > 0 && self::isClientsFirstOrder($order)) ? $settings['bonus'] : 0.0;

        $people = self::peopleFor($orderId);
        $roles = [];
        $bonusRoles = array_filter(['sales', 'marketing'], static fn (string $r): bool => $people[$r] !== [] && $settings['split'][$r] > 0);
        $bonusWeight = array_sum(array_map(static fn (string $r): float => $settings['split'][$r], $bonusRoles));
        $allocated = 0.0;
        $unallocated = 0.0;
        foreach (IncentiveRepository::ROLES as $role => $label) {
            $amount = round($reward * $settings['split'][$role] / 100, 2);
            if (in_array($role, $bonusRoles, true) && $bonusWeight > 0) {
                $amount = round($amount + $bonus * $settings['split'][$role] / $bonusWeight, 2);
            }
            $each = $people[$role] !== [] ? round($amount / count($people[$role]), 2) : 0.0;
            if ($people[$role] === []) {
                $unallocated += $amount;
            } else {
                $allocated += $each * count($people[$role]);
            }
            $roles[$role] = ['label' => $label, 'pct' => $settings['split'][$role], 'amount' => $amount, 'each' => $each, 'people' => $people[$role]];
        }
        if ($bonus > 0 && $bonusRoles === []) {
            $unallocated += $bonus;
        }

        return [
            'profit_before' => $profitBefore,
            'associate' => $associate,
            'basis' => $basis,
            'source' => $source,
            'reward' => $reward,
            'new_client_bonus' => $bonus,
            'team_total' => round($allocated, 2),
            'unallocated' => round($unallocated, 2),
            'roles' => $roles,
            'pct_of_profit' => $basis > 0 ? round(($allocated) / $basis * 100, 1) : null,
            'min_profit' => $settings['min_profit'],
        ];
    }

    /** Fixes the incentive of every order whose buyer has fully paid since the last look. */
    public static function lockDue(int $userId): int
    {
        $locked = 0;
        foreach (IncentiveRepository::unlockedOrderIdsWithPayments() as $orderId) {
            $paidOn = self::fullyPaidOn($orderId);
            if ($paidOn !== null) {
                self::lock($orderId, $paidOn, $userId);
                $locked++;
            }
        }
        return $locked;
    }

    /** Fixes this one order's incentive if its buyer has fully paid and it is not fixed yet. */
    public static function lockIfDue(int $orderId, int $userId): void
    {
        $row = IncentiveRepository::forOrder($orderId);
        if ($row !== null && !empty($row['locked_at'])) {
            return;
        }
        $order = OrderRepository::find($orderId);
        if ($order === null || (int) $order['is_test_data'] === 1 || (int) $order['is_sample_data'] === 1 || in_array($order['status'], ['cancelled', 'lost'], true)) {
            return;
        }
        $paidOn = self::fullyPaidOn($orderId);
        if ($paidOn !== null) {
            self::lock($orderId, $paidOn, $userId);
        }
    }

    public static function lock(int $orderId, string $paidOn, int $userId): void
    {
        $calc = self::calculate($orderId, $paidOn);
        $settings = self::settings();
        $month = self::incentiveMonth($paidOn, $settings['cutoff']);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $teamEntryId = null;
            if ($calc['team_total'] > 0) {
                foreach ($calc['roles'] as $role => $r) {
                    foreach ($r['people'] as $p) {
                        if ($r['each'] > 0) {
                            IncentiveRepository::addPayout(['order_id' => $orderId, 'payee_kind' => 'staff', 'user_id' => $p['id'], 'role' => $role, 'amount_inr' => $r['each'], 'incentive_month' => $month]);
                        }
                    }
                }
                $teamEntryId = IncentiveRepository::addCostEntry($orderId, 'team', 'Team incentive — ' . self::monthLabel($month) . ' incentive', $calc['team_total'], $paidOn, $userId);
            }
            $associateEntryId = null;
            $a = $calc['associate'];
            if ($a !== null && $a['amount_fc'] > 0) {
                IncentiveRepository::addPayout(['order_id' => $orderId, 'payee_kind' => 'associate', 'associate_id' => $a['id'], 'role' => 'associate',
                    'amount_inr' => $a['amount_inr'], 'amount_fc' => $a['amount_fc'], 'currency_code' => $a['currency'], 'incentive_month' => $month]);
                $associateEntryId = IncentiveRepository::addCostEntry($orderId, 'associate',
                    'Associate commission — ' . $a['name'] . ' (' . number_format($a['rate'], 2) . '%, client no. ' . $a['order_number'] . ')', $a['amount_inr'], $paidOn, $userId);
            }
            IncentiveRepository::saveOrder($orderId, [
                'locked_at' => date('Y-m-d H:i:s'),
                'fully_paid_on' => $paidOn,
                'incentive_month' => $month,
                'profit_basis_inr' => $calc['basis'],
                'team_total_inr' => $calc['team_total'],
                'new_client_bonus_inr' => $calc['new_client_bonus'],
                'associate_id' => $a['id'] ?? null,
                'associate_order_number' => $a['order_number'] ?? null,
                'associate_rate_pct' => $a['rate'] ?? null,
                'associate_amount_fc' => $a['amount_fc'] ?? null,
                'associate_currency' => $a['currency'] ?? null,
                'associate_amount_inr' => $a['amount_inr'] ?? null,
                'team_cost_entry_id' => $teamEntryId,
                'associate_cost_entry_id' => $associateEntryId,
            ], $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Undoes a lock so the incentive can be worked out again; refused once anything is paid. Returns an error or null. */
    public static function unlock(int $orderId, int $userId): ?string
    {
        foreach (IncentiveRepository::payoutsForOrder($orderId) as $p) {
            if ($p['status'] === 'paid') {
                return 'Part of this order\'s incentive is already paid, so its figures can no longer be worked out again.';
            }
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        IncentiveRepository::deleteOrderPayouts($orderId);
        $pdo->prepare('DELETE FROM order_cost_entries WHERE order_id = :id AND incentive_kind IS NOT NULL')->execute(['id' => $orderId]);
        IncentiveRepository::saveOrder($orderId, [
            'locked_at' => null, 'fully_paid_on' => null, 'incentive_month' => null, 'profit_basis_inr' => null, 'team_total_inr' => null,
            'new_client_bonus_inr' => null, 'associate_id' => null, 'associate_order_number' => null, 'associate_rate_pct' => null,
            'associate_amount_fc' => null, 'associate_currency' => null, 'associate_amount_inr' => null,
            'team_cost_entry_id' => null, 'associate_cost_entry_id' => null,
        ], $userId);
        $pdo->commit();
        return null;
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
    public static function markPaid(int $payoutId, string $paidOn, float $paidInr, ?float $tdsInr, int $userId): bool
    {
        $payout = IncentiveRepository::findPayout($payoutId);
        if ($payout === null || $payout['status'] !== 'due') {
            return false;
        }
        if (!IncentiveRepository::markPayoutPaid($payoutId, $paidOn, $paidInr, $tdsInr, $userId)) {
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
            IncentiveRepository::settleCostEntry((int) $entry['id'], round(array_sum(array_map(static fn (array $p): float => (float) $p['paid_inr'], $rows)), 2),
                max(array_map(static fn (array $p): string => (string) $p['paid_on'], $rows)));
        }
        return true;
    }
}
