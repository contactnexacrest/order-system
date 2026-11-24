<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\PricingRepository;

/**
 * Owner decision D-410 (24 Nov 2026, confirm 1 + confirm 2): every order of an associate's client earns.
 * Node twin: src/services/associateRules.js (same rules, same wording).
 *
 *  - Every order of the client earns while the agreement is active (the old "client's first 10 days only" rule
 *    is gone for orders whose Proforma Invoice is issued from the cut-over date on — owner choice 2A).
 *  - An order earns only when its value of the goods (FOB) is at least the minimum (setting, ₹5,00,000);
 *    the client's first order always earns, whatever its size.
 *  - Ladder steps: an order of at least the minimum counts as one step once its advance has cleared (LC orders:
 *    once the LC is confirmed; no advance and no LC: once the buyer has paid in full). Orders of the same client
 *    within N days (setting, default 15) of the first order of a group are one step. Cancelled or lost orders
 *    never count. The history is worked out again every time, so it follows the rules from the start (2A).
 *  - The client's price includes a % chosen by the MD (setting: starting rate / a fixed % (default 5%) /
 *    ladder rate; per-associate override). The associate's lowest price uses min(their rate, that %); any
 *    commission above that % is paid from NexaCrest's margin.
 */
final class AssociateRules
{
    /**
     * Owner decision D-411 (24 Nov 2026): who may change the associate commission rules — commission levels and the
     * ladder, what the client's price includes, an associate's own rule, Associates › Settings, payment advice
     * corrections, a client's associate and the portal access levels. The permission "Manage associate rules": the MD
     * and the ED to start; Admin › Roles can give it to any role or person. (Until D-411: the MD only.)
     */
    public const MANAGE_PERMISSION = 'manage_associate_rules';

    /** The wording shown where the rules are read only. */
    public const WHO_CHANGES = 'Only the MD, the ED and people with "Manage associate rules" can change this.';

    public static function canManage(?array $user): bool
    {
        if ($user === null || empty($user['id'])) {
            return false;
        }
        return PermissionService::can((int) $user['id'], isset($user['role_id']) && $user['role_id'] !== null ? (int) $user['role_id'] : null, self::MANAGE_PERMISSION);
    }

    public const PRICE_RULES = ['starting' => 'Starting rate', 'fixed' => 'A fixed %', 'ladder' => 'Ladder rate'];
    public const PRICE_RULE_TEXT = [
        'starting' => "The client's price always includes the first ladder step. The price never rises. When the associate is above it, we pay the rest from our margin.",
        'fixed' => "The client's price always includes this %. The price never rises. Below it, the associate can lower the price to win the deal (or we keep the difference); above it, we pay the rest from our margin.",
        'ladder' => "The client's price includes the associate's full current %. Nothing comes from our margin, but the client's price goes up each time the associate moves up the ladder.",
    ];
    public const GROUP_DAYS_MIN = 7;
    public const GROUP_DAYS_MAX = 45;

    /** @var array<int, list<array<string,mixed>>> history per associate, for one request */
    private static array $cache = [];

    public static function reset(): void
    {
        self::$cache = [];
    }

    /** Same client within this many days of the first order of a group = one ladder step (default 15, 7–45). */
    public static function groupDays(): int
    {
        $v = (int) (IncentiveRepository::settings()['associate_group_days'] ?? 15);
        return max(self::GROUP_DAYS_MIN, min(self::GROUP_DAYS_MAX, $v > 0 ? $v : 15));
    }

    /** The minimum value of the goods (FOB, ₹) an order needs to earn commission and to count on the ladder. */
    public static function minOrderInr(): float
    {
        return (float) (IncentiveRepository::settings()['associate_counting_threshold_inr'] ?? 500000);
    }

    /** The day the D-410 rules started (migration 2026-11-24a); null = the rules apply to every order. */
    public static function cutover(): ?string
    {
        $v = trim((string) (IncentiveRepository::settings()['associate_rules_from'] ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    /** Whether the D-410 rules decide this order: its PI is not yet issued, or was issued on or after the cut-over date (2A). */
    public static function newRules(array $order): bool
    {
        $from = self::cutover();
        if ($from === null) {
            return true;
        }
        $fixedAt = (string) ($order['associate_level_fixed_at'] ?? '');
        return $fixedAt === '' || substr($fixedAt, 0, 10) >= $from;
    }

    /** The step moment: advance cleared; LC orders: LC confirmed; no advance and no LC: paid in full. */
    public static function stepTriggered(array $order): bool
    {
        $id = (int) $order['id'];
        if (($order['payment_method'] ?? '') === 'LC') {
            $lc = OrderLcDetailRepository::find($id);
            return $lc !== null && !empty($lc['lc_confirmed_at']);
        }
        if ((float) ($order['advance_pct'] ?? 0) > 0) {
            $ps = OrderPaymentStatusRepository::find($id);
            return $ps !== null && !empty($ps['advance_cleared_at']);
        }
        return IncentiveService::fullyPaidOn($id) !== null;
    }

    /** The order's value of the goods in rupees for the minimum (in range, estimated until the buyer's payments are in). */
    public static function orderValueInr(array $order): ?float
    {
        return AssociateCommission::estimateInr($order, AssociateCommission::inRangeValue((int) $order['id']));
    }

    private static function days(string $from, string $to): int
    {
        return (int) floor((strtotime(substr($to, 0, 10)) - strtotime(substr($from, 0, 10))) / 86400);
    }

    /**
     * The associate's orders in date order with their ladder status:
     * counted (step n) · same_group (of order x) · below_min · not_yet (step moment not reached).
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $associateId): array
    {
        if (isset(self::$cache[$associateId])) {
            return self::$cache[$associateId];
        }
        $stmt = Database::connection()->prepare(
            "SELECT o.* FROM orders o JOIN clients c ON c.id = o.client_id LEFT JOIN order_incentives oi ON oi.order_id = o.id
              WHERE (o.associate_id = :a OR (o.associate_id IS NULL AND c.associate_id = :a2))
                AND o.status NOT IN ('cancelled','lost') AND (oi.associate_mode IS NULL OR oi.associate_mode <> 'no')
              ORDER BY COALESCE(o.buyer_po_received_on, DATE(o.created_at)), o.id"
        );
        $stmt->execute(['a' => $associateId, 'a2' => $associateId]);
        $days = self::groupDays();
        $min = self::minOrderInr();
        $start = [];
        $step = 0;
        $out = [];
        foreach ($stmt->fetchAll() as $o) {
            $po = IncentiveService::poDate($o);
            $row = ['order_id' => (int) $o['id'], 'client_id' => (int) $o['client_id'], 'po_date' => $po, 'reference' => (string) $o['order_reference'],
                'status' => 'not_yet', 'step' => null, 'group_order_id' => null, 'group_reference' => null];
            if (self::stepTriggered($o)) {
                $value = self::orderValueInr($o);
                $client = (int) $o['client_id'];
                if (isset($start[$client]) && self::days($start[$client]['po'], $po) < $days) {
                    $row['status'] = 'same_group';
                    $row['step'] = $start[$client]['step'];
                    $row['group_order_id'] = $start[$client]['order_id'];
                    $row['group_reference'] = $start[$client]['reference'];
                } elseif ($value === null || $value < $min) {
                    $row['status'] = 'below_min';
                } else {
                    $step++;
                    $start[$client] = ['po' => $po, 'step' => $step, 'order_id' => (int) $o['id'], 'reference' => (string) $o['order_reference']];
                    $row['status'] = 'counted';
                    $row['step'] = $step;
                }
            }
            $out[] = $row;
        }
        return self::$cache[$associateId] = $out;
    }

    /** Ladder steps counted so far, leaving one order out (the order being priced). */
    public static function stepsCounted(int $associateId, ?int $excludeOrderId = null): int
    {
        $n = 0;
        foreach (self::history($associateId) as $r) {
            if ($r['status'] === 'counted' && $r['order_id'] !== (int) $excludeOrderId) {
                $n++;
            }
        }
        return $n;
    }

    /** The ladder row of one order, or null. */
    public static function stepFor(int $associateId, int $orderId): ?array
    {
        foreach (self::history($associateId) as $r) {
            if ($r['order_id'] === $orderId) {
                return $r;
            }
        }
        return null;
    }

    /** A counted group of the same client whose window covers this order (it then shares that group's step and level). */
    public static function groupCovering(int $associateId, array $order): ?array
    {
        $po = IncentiveService::poDate($order);
        $days = self::groupDays();
        foreach (self::history($associateId) as $r) {
            if ($r['status'] === 'counted' && $r['order_id'] !== (int) $order['id'] && $r['client_id'] === (int) $order['client_id']
                && $r['po_date'] <= $po && self::days($r['po_date'], $po) < $days) {
                return $r;
            }
        }
        return null;
    }

    /** Whether this order earns: at least the minimum, or the client's first order. */
    public static function earnsOnValue(array $order, ?float $valueInr): bool
    {
        if (IncentiveService::isClientsFirstOrder($order)) {
            return true;
        }
        return $valueInr !== null && $valueInr >= self::minOrderInr();
    }

    /** One-line ladder status for staff screens (the "Ladder step" column). */
    public static function stepText(?array $row): string
    {
        if ($row === null) {
            return '—';
        }
        return match ($row['status']) {
            'counted' => 'Step ' . $row['step'],
            'same_group' => 'Same step as ' . ($row['group_reference'] ?? ('#' . $row['group_order_id'])) . ' (same client within ' . self::groupDays() . ' days)',
            'below_min' => 'Does not count — below ₹' . \App\Helpers\MoneyFormat::indian(self::minOrderInr(), 0),
            default => 'Counts when the advance has cleared',
        };
    }

    // ------------------------------------------------------------------ price rule

    /** The company setting: ['rule' => starting|fixed|ladder, 'pct' => float|null]. */
    public static function companyPriceRule(): array
    {
        $s = PricingRepository::settings();
        $rule = (string) ($s['associate_price_rule'] ?? 'fixed');
        $rule = isset(self::PRICE_RULES[$rule]) ? $rule : 'fixed';
        return ['rule' => $rule, 'pct' => $rule === 'fixed' ? (float) ($s['associate_price_fixed_pct'] ?? 5) : null];
    }

    /** The rule that applies to one associate: their own (D-410 override) or the company's; 'own' says which. */
    public static function priceRuleFor(array $associate): array
    {
        $own = (string) ($associate['price_rule_override'] ?? '');
        if (isset(self::PRICE_RULES[$own])) {
            return ['rule' => $own, 'pct' => $own === 'fixed' ? (float) ($associate['price_rule_fixed_pct'] ?? 0) : null, 'own' => true];
        }
        return self::companyPriceRule() + ['own' => false];
    }

    /** The first step's % of the associate's ladder (their fixed level when they have a fixed %). */
    public static function startingPct(array $associate): ?float
    {
        if (($associate['commission_mode'] ?? 'ladder') === 'fixed') {
            $l = !empty($associate['commission_level_id']) ? PricingRepository::findLevel((int) $associate['commission_level_id']) : null;
            return $l !== null && ($l['kind'] ?? 'pct') === 'pct' ? (float) $l['value'] : null;
        }
        $steps = AssociateCommission::ladderFor($associate);
        return $steps !== [] && ($steps[0]['kind'] ?? 'pct') === 'pct' ? (float) $steps[0]['value'] : null;
    }

    /** The % included in the client's price for this associate at this level (null = a USD-per-unit level: priced as today). */
    public static function includedPct(array $associate, ?array $level): ?float
    {
        if ($level === null || ($level['kind'] ?? 'pct') !== 'pct') {
            return null;
        }
        $r = self::priceRuleFor($associate);
        return match ($r['rule']) {
            'starting' => self::startingPct($associate) ?? (float) $level['value'],
            'ladder' => (float) $level['value'],
            default => (float) $r['pct'],
        };
    }

    /** The % the company setting puts in the client's price for an associate at this level (no per-associate override) — product page, levels page. */
    public static function companyIncludedPct(?array $level): ?float
    {
        if ($level === null || ($level['kind'] ?? 'pct') !== 'pct') {
            return null;
        }
        $r = self::companyPriceRule();
        if ($r['rule'] === 'starting') {
            $steps = PricingRepository::ladder();
            return $steps !== [] && ($steps[0]['kind'] ?? 'pct') === 'pct' ? (float) $steps[0]['value'] : (float) $level['value'];
        }
        return $r['rule'] === 'ladder' ? (float) $level['value'] : (float) $r['pct'];
    }

    /** An associate level's prices under the company setting, with the part paid from the margin (product page, levels page). */
    public static function levelPrices(array $raw, array $level): array
    {
        $inc = self::companyIncludedPct($level);
        if ($inc === null) {
            return PricingEngine::associate($raw, (string) $level['kind'], (float) $level['value']) + ['included_pct' => null, 'margin_pct' => 0.0];
        }
        return PricingEngine::associateIncluded($raw, (float) $level['value'], $inc) + ['included_pct' => $inc, 'margin_pct' => self::marginPct((float) $level['value'], $inc)];
    }

    /** The part of the commission paid from NexaCrest's margin, in % of the FOB (0 when the price covers it). */
    public static function marginPct(?float $ratePct, ?float $includedPct): float
    {
        if ($ratePct === null || $includedPct === null) {
            return 0.0;
        }
        return max(0.0, round($ratePct - $includedPct, 4));
    }

    /** "Right now" line for the associate page: what the price includes, the rate, and what comes from the margin. */
    public static function rightNow(array $associate, ?array $level): string
    {
        $inc = self::includedPct($associate, $level);
        if ($inc === null) {
            return 'Right now: this associate has a fixed USD amount per unit — the client price includes it, as before.';
        }
        $rate = (float) $level['value'];
        $m = self::marginPct($rate, $inc);
        $fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . '%';
        return 'Right now for this associate: client price includes ' . $fmt($inc) . ' · associate is at ' . $fmt($rate) . ' · '
            . ($m > 0 ? $fmt($m) . ' comes from our margin.' : ($inc > $rate ? 'the associate has ' . $fmt($inc - $rate) . ' room to negotiate.' : 'nothing comes from our margin.'));
    }

    /**
     * Paid from our margin (₹) on this associate's orders with the % fixed at their PI, in the financial year of $today.
     * @return array{inr: float, orders: int}
     */
    public static function marginSoFar(int $associateId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $y = (int) substr($today, 0, 4);
        $fyStart = ((int) substr($today, 5, 2) >= 4 ? $y : $y - 1) . '-04-01';
        $stmt = Database::connection()->prepare(
            "SELECT id, associate_rate_pct, associate_included_pct FROM orders
              WHERE associate_id = :a AND associate_level_fixed_at >= :f AND associate_included_pct IS NOT NULL AND associate_rate_pct IS NOT NULL
                AND status NOT IN ('cancelled','lost')"
        );
        $stmt->execute(['a' => $associateId, 'f' => $fyStart]);
        // Owner 24 Nov 2026 (recommendation 5): only earned commission (the buyer paid in full) counts; orders whose
        // buyer is still paying are shown apart as "expected", since they may never be paid.
        $inr = 0.0;
        $n = 0;
        $openInr = 0.0;
        $openN = 0;
        foreach ($stmt->fetchAll() as $o) {
            $m = self::marginPct((float) $o['associate_rate_pct'], (float) $o['associate_included_pct']);
            if ($m <= 0) {
                continue;
            }
            $c = AssociateCommission::forOrder((int) $o['id']);
            if ($c === null || (float) ($c['rate'] ?? 0) <= 0 || ($c['state'] ?? '') === 'unpaid') {
                continue;
            }
            $part = round((float) $c['amount_inr'] * $m / (float) $c['rate'], 2);
            if (($c['state'] ?? '') === 'paid') {
                $inr += $part;
                $n++;
            } else {
                $openInr += $part;
                $openN++;
            }
        }
        return ['inr' => round($inr, 2), 'orders' => $n, 'open_inr' => round($openInr, 2), 'open_orders' => $openN];
    }

    /**
     * "What each choice would cost us": over the last 12 months of associate orders with a level fixed at their PI,
     * the commission above the % each choice puts in the price (₹, at the order's own commission rupees).
     * @return array{starting: float, fixed: float, ladder: float, fixed_pct: float, orders: int}
     */
    public static function whatIf(float $fixedPct, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $from = date('Y-m-d', strtotime($today . ' -12 months'));
        $stmt = Database::connection()->prepare(
            "SELECT o.id, o.associate_id, o.associate_rate_pct FROM orders o
              WHERE o.associate_id IS NOT NULL AND o.associate_level_fixed_at >= :f AND o.associate_rate_pct IS NOT NULL
                AND o.status NOT IN ('cancelled','lost') AND o.is_test_data = 0 AND o.is_sample_data = 0"
        );
        $stmt->execute(['f' => $from]);
        $out = ['starting' => 0.0, 'fixed' => 0.0, 'ladder' => 0.0, 'fixed_pct' => $fixedPct, 'orders' => 0];
        foreach ($stmt->fetchAll() as $o) {
            $c = AssociateCommission::forOrder((int) $o['id']);
            $rate = (float) $o['associate_rate_pct'];
            if ($c === null || $rate <= 0) {
                continue;
            }
            $a = \App\Repositories\AssociateRepository::find((int) $o['associate_id']);
            $start = $a !== null ? (self::startingPct($a) ?? $rate) : $rate;
            $per = (float) $c['amount_inr'] / $rate;
            $out['starting'] += $per * max(0.0, $rate - $start);
            $out['fixed'] += $per * max(0.0, $rate - $fixedPct);
            $out['orders']++;
        }
        foreach (['starting', 'fixed', 'ladder'] as $k) {
            $out[$k] = round($out[$k], 2);
        }
        return $out;
    }

    /** How many associates follow their own price choice instead of the company setting. */
    public static function ownRuleCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM associates WHERE price_rule_override IS NOT NULL AND price_rule_override <> ''")->fetchColumn();
    }
}
