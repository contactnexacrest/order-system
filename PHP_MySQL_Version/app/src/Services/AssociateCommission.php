<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Repositories\ClientRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PricingRepository;

/**
 * Owner decision D-245 (4 Nov 2026), phase P3: an associate's commission.
 *
 * - Basis: the final invoiced FOB of the goods, line by line (goods lines only —
 *   no freight, insurance, services or charges). A line the MD approved below
 *   the floor earns nothing and does not count toward the ladder.
 * - Rate: the commission level fixed on the order when its Proforma Invoice was
 *   issued (fixed level, or the ladder step for the associate's counting orders).
 * - Converted to rupees at the rate the buyer's money actually realised (INR
 *   credited ÷ foreign amount, D-244); rupees the bank kept are added back, so
 *   bank charges never lower it.
 * - Only an agreed discount or a settled claim lowers it (an approved shortfall
 *   marked so); an unpaid shortfall means the order was not paid in full and
 *   earns nothing (no pro rata).
 * - Due N days after full payment, held while a buyer claim opened on or before
 *   the due date is open, paid in the payout run (monthly day N or weekly).
 * - GST on top for a GST-registered associate once their tax invoice is on file.
 *
 * Node twin: src/services/associateCommission.js.
 */
final class AssociateCommission
{
    public const SETTLEMENT_KINDS = [
        'agreed_discount' => 'Agreed discount — lowers the associate commission basis',
        'claim_settled' => 'Settled buyer claim — lowers the associate commission basis',
        'unpaid' => 'Not paid by the buyer — no associate commission on this order',
    ];

    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** @return array{due_days:int,claim_hold:bool,run:string,day:int,threshold_inr:float,gst_pct:float,registration_months:int} */
    public static function settings(): array
    {
        $s = IncentiveRepository::settings();
        $run = ($s['associate_payout_run'] ?? 'monthly') === 'weekly' ? 'weekly' : 'monthly';
        $day = (int) ($s['associate_payout_day'] ?? 5);
        return [
            'due_days' => max(0, (int) ($s['associate_commission_due_days'] ?? 30)),
            'claim_hold' => ($s['associate_claim_hold'] ?? '1') === '1',
            'run' => $run,
            'day' => $run === 'weekly' ? max(1, min(7, $day > 0 ? $day : 5)) : max(1, min(28, $day > 0 ? $day : 5)),
            'threshold_inr' => (float) ($s['associate_counting_threshold_inr'] ?? 500000),
            'gst_pct' => (float) ($s['associate_gst_pct'] ?? 18),
            'zoho_gst_tax_id' => trim((string) ($s['zoho_gst_commission_tax_id'] ?? '')),
            'registration_months' => AssociateService::protectionMonths(),
        ];
    }

    public static function dueOn(string $fullyPaidOn, ?array $settings = null): string
    {
        $settings ??= self::settings();
        return date('Y-m-d', (int) strtotime(substr($fullyPaidOn, 0, 10) . ' +' . $settings['due_days'] . ' days'));
    }

    /** The first payout run on or after the due date. */
    public static function payoutRunOn(string $dueOn, ?array $settings = null): string
    {
        $settings ??= self::settings();
        $due = (int) strtotime($dueOn);
        if ($settings['run'] === 'weekly') {
            $w = (int) date('N', $due);
            return date('Y-m-d', (int) strtotime($dueOn . ' +' . (($settings['day'] - $w + 7) % 7) . ' days'));
        }
        $candidate = date('Y-m-', $due) . sprintf('%02d', $settings['day']);
        if ($candidate < $dueOn) {
            $candidate = date('Y-m-', (int) strtotime(date('Y-m-01', $due) . ' +1 month')) . sprintf('%02d', $settings['day']);
        }
        return $candidate;
    }

    public static function runLabel(?array $settings = null): string
    {
        $settings ??= self::settings();
        return $settings['run'] === 'weekly' ? 'weekly, every ' . self::WEEKDAYS[$settings['day']] : 'monthly, on day ' . $settings['day'];
    }

    /** A buyer complaint or claim opened on or before the due date and not resolved. */
    public static function claimOpen(int $orderId, string $dueOn): bool
    {
        $stmt = Database::connection()->prepare("SELECT 1 FROM order_complaints WHERE order_id = :o AND status <> 'resolved' AND DATE(created_at) <= :d LIMIT 1");
        $stmt->execute(['o' => $orderId, 'd' => $dueOn]);
        return $stmt->fetchColumn() !== false;
    }

    /** Whether a payout may be paid on $on: due date reached and no buyer claim holding it. Returns the reason it may not, or null. */
    public static function payBlockReason(array $payout, string $on): ?string
    {
        if (($payout['payee_kind'] ?? '') !== 'associate' || empty($payout['due_on']) || (float) $payout['amount_inr'] < 0) {
            return null;
        }
        if ($on < (string) $payout['due_on']) {
            return 'This commission is due on ' . \App\Helpers\Dates::short((string) $payout['due_on']) . ' (' . self::settings()['due_days'] . ' days after the buyer paid in full) — it cannot be paid before that.';
        }
        if (self::settings()['claim_hold'] && self::claimOpen((int) $payout['order_id'], (string) $payout['due_on'])) {
            return 'A buyer claim on this order is open — the commission waits until it is resolved.';
        }
        // Owner decision D-252 (A3): no commission is paid until the associate's KYC is complete.
        $a = !empty($payout['associate_id']) ? AssociateRepository::find((int) $payout['associate_id']) : null;
        if ($a !== null && ($missing = AssociateKyc::missing($a)) !== []) {
            return "The associate's KYC is not complete — still to be accepted: " . implode(', ', $missing) . '.';
        }
        return null;
    }

    /** The associate who earns on this order (client's associate, mode not "no", first-order window or forced "yes"), or null. */
    public static function earningAssociate(array $order, ?array $incentiveRow = null): ?array
    {
        $client = !empty($order['client_id']) ? ClientRepository::find((int) $order['client_id']) : null;
        if ($client === null || empty($client['associate_id'])) {
            return null;
        }
        $mode = (string) (($incentiveRow ?? (isset($order['id']) ? IncentiveRepository::forOrder((int) $order['id']) : null))['associate_mode'] ?? 'auto');
        if ($mode === 'no' || ($mode !== 'yes' && isset($order['id']) && !IncentiveService::withinFirstTenDays($order))) {
            return null;
        }
        $a = AssociateRepository::find((int) $client['associate_id']);
        if ($a === null || (int) ($a['no_commission'] ?? 0) === 1) {
            return null;
        }
        return $a;
    }

    /** The associate's earlier counting orders (fixed and counted when their order was locked). */
    public static function countingOrders(int $associateId, ?int $excludeOrderId = null): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM order_incentives oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.associate_id = :a AND oi.associate_counts = 1 AND oi.locked_at IS NOT NULL AND oi.order_id <> :x
                AND o.status NOT IN ('cancelled','lost')"
        );
        $stmt->execute(['a' => $associateId, 'x' => $excludeOrderId ?? 0]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The associate's level: their fixed level, or the ladder step. For the ladder,
     * an order expected to count (or not yet known: $orderInRangeInr null) takes the
     * step for its place (counting orders + 1); a smaller order earns the current
     * step (that of the last counting order, at least the first step) and does not count.
     *
     * @return array{0: ?array, 1: ?int} [level, counting orders so far (ladder only)]
     */
    public static function levelForAssociate(array $associate, ?int $excludeOrderId, ?float $orderInRangeInr = null): array
    {
        if (($associate['commission_mode'] ?? 'ladder') === 'fixed') {
            return [!empty($associate['commission_level_id']) ? PricingRepository::findLevel((int) $associate['commission_level_id']) : null, null];
        }
        $count = self::countingOrders((int) $associate['id'], $excludeOrderId);
        $position = ($orderInRangeInr === null || $orderInRangeInr >= self::settings()['threshold_inr']) ? $count + 1 : max($count, 1);
        foreach (PricingRepository::ladder() as $step) {
            if ($position >= (int) $step['from_order'] && ($step['to_order'] === null || $position <= (int) $step['to_order'])) {
                return [PricingRepository::findLevel((int) $step['level_id']), $count];
            }
        }
        return [null, $count];
    }

    public static function levelLabel(?array $level): string
    {
        if ($level === null) {
            return 'no level';
        }
        $v = rtrim(rtrim(number_format((float) $level['value'], 2, '.', ''), '0'), '.');
        return $level['name'] . ' — ' . (($level['kind'] ?? 'pct') === 'pct' ? $v . '%' : 'USD ' . number_format((float) $level['value'], 2) . ' per unit');
    }

    /**
     * The level fixed on the order at PI, or null when not fixed yet.
     *
     * Fresh-eyes review M2 (5 Nov 2026): given an associate, the fixed level
     * applies only when it was fixed for that associate (orders.associate_id).
     * After the MD moves the client to another associate, the new associate
     * earns at their own live level — the same rule the order-line price
     * check (OrderLinePricing::levelForAssociate) already uses.
     */
    public static function fixedLevel(array $order, ?int $associateId = null): ?array
    {
        if (empty($order['associate_level_fixed_at']) || $order['associate_level_kind'] === null) {
            return null;
        }
        if ($associateId !== null && (int) ($order['associate_id'] ?? 0) !== $associateId) {
            return null;
        }
        return ['id' => $order['associate_level_id'] !== null ? (int) $order['associate_level_id'] : null, 'name' => (string) $order['associate_level_name'],
            'kind' => (string) $order['associate_level_kind'], 'value' => (float) $order['associate_level_value'], 'fixed' => true];
    }

    /** Called when the PI is issued: fixes the associate's commission level on the order (once). */
    public static function fixLevelAtPi(int $orderId): void
    {
        $order = OrderRepository::find($orderId);
        if ($order === null || !empty($order['associate_level_fixed_at'])) {
            return;
        }
        $a = self::earningAssociate($order);
        if ($a === null) {
            return;
        }
        [$level] = self::levelForAssociate($a, $orderId, self::estimateInr($order, self::inRangeValue($orderId)));
        if ($level === null) {
            return;
        }
        Database::connection()->prepare(
            'UPDATE orders SET associate_id = :a, associate_level_id = :l, associate_level_name = :n, associate_level_kind = :k, associate_level_value = :v,
                    associate_level_fixed_at = NOW(), associate_rate_pct = :r, associate_rate_source = :s WHERE id = :id AND associate_level_fixed_at IS NULL'
        )->execute(['a' => $a['id'], 'l' => $level['id'], 'n' => $level['name'], 'k' => $level['kind'], 'v' => $level['value'],
            'r' => $level['kind'] === 'pct' ? $level['value'] : null, 's' => ($a['commission_mode'] ?? 'ladder') === 'fixed' ? 'fixed' : 'ladder', 'id' => $orderId]);
        if ((int) $order['is_test_data'] !== 1 && (int) $order['is_sample_data'] !== 1) {
            AssociateService::notify($a, 'order_created', "The Proforma Invoice for order {$order['order_reference']} was issued. Commission level fixed on it: " . self::levelLabel($level) . '.',
                (int) $order['client_id'], $orderId);
        }
    }

    /**
     * The order's in-range goods value in rupees as estimated at PI, for the ladder's counting threshold.
     * Fresh-eyes review H1: the pricing exchange rate is a USD rate, so it converts a USD order only;
     * another currency uses the rate expected on its receipts (unknown → null, treated as an order that counts).
     */
    public static function estimateInr(array $order, float $inRange): ?float
    {
        $currency = strtoupper((string) ($order['currency_code'] ?? ''));
        if ($currency === 'INR') {
            return $inRange;
        }
        if ($currency === 'USD') {
            $fx = (float) (PricingRepository::settings()['pricing_fx_rate'] ?? 0);
            return $fx > 0 ? $inRange * $fx : null;
        }
        $orderRate = IncentiveService::productRate($order);
        return $orderRate > 0 ? $inRange * $orderRate : null;
    }

    /** Whether the MD approved this line below the floor (no commission, not in range). */
    public static function belowFloorApproved(array $line): bool
    {
        return (int) ($line['price_approval_commission_zero'] ?? 0) === 1 && !empty($line['price_approved_by']);
    }

    /** @return list<array<string,mixed>> the active goods lines */
    public static function goodsLines(int $orderId): array
    {
        return array_values(array_filter(OrderProductRepository::forOrder($orderId), static fn (array $l): bool => ($l['line_type'] ?? 'goods') === 'goods'));
    }

    /** FOB of the goods lines not approved below the floor, order currency. */
    public static function inRangeValue(int $orderId): float
    {
        $sum = 0.0;
        foreach (self::goodsLines($orderId) as $l) {
            if (!self::belowFloorApproved($l)) {
                $sum += (float) $l['fob_value'];
            }
        }
        return round($sum, 2);
    }

    /**
     * Approved shortfalls on the goods payments: the agreed reductions (agreed
     * discount, settled claim) and whether any amount was left unpaid (an unpaid
     * shortfall, or one recorded before P3 without saying what it is).
     *
     * @return array{reduction: float, unpaid: bool}
     */
    public static function settlements(int $orderId): array
    {
        $stmt = Database::connection()->prepare("SELECT leg, shortfall_amount, settlement_kind FROM order_payment_shortfalls WHERE order_id = :o AND treatment = 'written_off'");
        $stmt->execute(['o' => $orderId]);
        $reduction = 0.0;
        $unpaid = false;
        foreach ($stmt->fetchAll() as $r) {
            $kind = (string) ($r['settlement_kind'] ?? '');
            if (in_array($kind, ['agreed_discount', 'claim_settled'], true)) {
                if ($r['leg'] !== 'freight') {
                    $reduction += (float) $r['shortfall_amount'];
                }
            } else {
                $unpaid = true;
            }
        }
        return ['reduction' => round($reduction, 2), 'unpaid' => $unpaid];
    }

    /**
     * Rupees per unit of the order currency the buyer's goods payments realised:
     * INR credited (plus rupees the bank kept as charges) ÷ foreign amount, over
     * the advance, middle milestones, balance and LC. Estimated (at the recorded
     * rate) while some receipt has no INR credited yet.
     *
     * @return array{rate: float, estimated: bool}
     */
    public static function realisedRate(array $order): array
    {
        if (strtoupper((string) ($order['currency_code'] ?? 'INR')) === 'INR') {
            return ['rate' => 1.0, 'estimated' => false];
        }
        $stmt = Database::connection()->prepare("SELECT amount, exchange_rate, inr_credited, rate_gap_choice, rate_gap_inr FROM order_payment_receipts WHERE order_id = :o AND leg IN ('advance','balance','milestone')");
        $stmt->execute(['o' => $order['id']]);
        $inr = 0.0;
        $foreign = 0.0;
        $estimated = false;
        foreach ($stmt->fetchAll() as $r) {
            $amount = (float) $r['amount'];
            if ($r['inr_credited'] !== null) {
                $inr += (float) $r['inr_credited'];
                if ($r['rate_gap_choice'] === 'bank_charges' && (float) $r['rate_gap_inr'] > 0) {
                    $inr += (float) $r['rate_gap_inr'];
                }
            } else {
                $inr += $amount * (float) $r['exchange_rate'];
                $estimated = true;
            }
            $foreign += $amount;
        }
        if (($order['payment_method'] ?? '') === 'LC') {
            $lc = OrderLcDetailRepository::find((int) $order['id']);
            if ($lc !== null && (float) ($lc['lc_received_amount'] ?? 0) > 0) {
                $inr += (float) $lc['lc_inr_credited'];
                $foreign += (float) $lc['lc_received_amount'];
            }
        }
        if ($foreign > 0) {
            return ['rate' => $inr / $foreign, 'estimated' => $estimated];
        }
        // Nothing received yet: the rate expected on the receipts, else the Admin pricing exchange rate (estimated either way).
        $rate = IncentiveService::productRate($order);
        if ($rate <= 0) {
            $rate = (float) (PricingRepository::settings()['pricing_fx_rate'] ?? 0);
        }
        return ['rate' => $rate, 'estimated' => true];
    }

    /**
     * The order's associate commission as it stands, or null when no associate earns on it.
     *
     * @return ?array<string,mixed>
     */
    public static function forOrder(int $orderId, ?array $order = null, ?array $incentiveRow = null): ?array
    {
        $order ??= OrderRepository::find($orderId);
        if ($order === null) {
            return null;
        }
        $a = self::earningAssociate($order, $incentiveRow);
        if ($a === null) {
            return null;
        }
        $set = self::settings();
        $level = self::fixedLevel($order, (int) $a['id']);
        $fixed = $level !== null;
        if ($level === null) {
            [$level] = self::levelForAssociate($a, $orderId);
        }
        $currency = (string) ($order['currency_code'] ?? 'INR');
        $realised = self::realisedRate($order);
        // Fresh-eyes review H1: a "USD x per unit" level is in US dollars whatever the order
        // currency. On a non-USD order it is converted at the USD rate (usdRate) to rupees,
        // and from there to the order currency for the figures shown in it.
        $usdLevel = $level !== null && ($level['kind'] ?? 'pct') !== 'pct';
        $convertUsd = $usdLevel && strtoupper($currency) !== 'USD';
        $usdRate = $convertUsd ? self::usdRate($order) : null;
        $usdToFc = $convertUsd ? ($realised['rate'] > 0 ? $usdRate / $realised['rate'] : 0.0) : 1.0;
        $lines = [];
        $inRange = 0.0;
        $linesCommission = 0.0;
        $total = 0.0;
        foreach (self::goodsLines($orderId) as $l) {
            $value = (float) $l['fob_value'];
            $total += $value;
            $zero = self::belowFloorApproved($l);
            $commission = 0.0;
            if (!$zero && $level !== null) {
                $commission = $level['kind'] === 'pct' ? $value * (float) $level['value'] / 100 : (float) $l['quantity'] * (float) $level['value'] * $usdToFc;
                $inRange += $value;
            } elseif (!$zero) {
                $inRange += $value;
            }
            $linesCommission += $commission;
            $lines[] = ['line_no' => (int) $l['line_no'], 'description' => (string) $l['description'], 'fob' => round($value, 2), 'in_range' => !$zero, 'commission' => round($commission, 2)];
        }
        $settle = self::settlements($orderId);
        $basis = max(0.0, $inRange - $settle['reduction']);
        $commissionFc = $inRange > 0 ? round($linesCommission * $basis / $inRange, 2) : 0.0;
        $commissionInr = $convertUsd
            ? ($inRange > 0 && $usdToFc > 0 ? round($linesCommission / $usdToFc * $basis / $inRange * $usdRate, 2) : 0.0)
            : round($commissionFc * $realised['rate'], 2);
        $fullyPaidOn = IncentiveService::fullyPaidOn($orderId);
        $state = $settle['unpaid'] ? 'unpaid' : ($fullyPaidOn !== null ? 'paid' : 'awaiting');
        $dueOn = $fullyPaidOn !== null ? self::dueOn($fullyPaidOn, $set) : null;
        $basisInr = round($basis * $realised['rate'], 2);
        $counts = $state === 'paid' && $basisInr >= $set['threshold_inr'];
        return [
            'id' => (int) $a['id'], 'name' => (string) $a['name'], 'status' => (string) $a['status'], 'gstin' => (string) ($a['gstin'] ?? ''),
            'mode' => (string) ($a['commission_mode'] ?? 'ladder'),
            'level' => $level, 'level_label' => self::levelLabel($level), 'level_fixed' => $fixed,
            'rate' => $level !== null && $level['kind'] === 'pct' ? (float) $level['value'] : null,
            'currency' => $currency, 'lines' => $lines,
            'goods_fc' => round($total, 2), 'in_range_fc' => round($inRange, 2), 'reduction_fc' => $settle['reduction'], 'basis_fc' => round($basis, 2),
            'rate_inr' => $realised['rate'], 'rate_estimated' => $realised['estimated'], 'basis_inr' => $basisInr,
            'amount_fc' => $state === 'unpaid' ? 0.0 : $commissionFc,
            'amount_inr' => $state === 'unpaid' ? 0.0 : $commissionInr,
            'usd_rate' => $usdRate,
            'state' => $state, 'fully_paid_on' => $fullyPaidOn,
            'due_on' => $dueOn, 'payout_run_on' => $dueOn !== null ? self::payoutRunOn($dueOn, $set) : null,
            'claim_hold' => $dueOn !== null && $set['claim_hold'] && self::claimOpen($orderId, $dueOn),
            'counts' => $counts, 'threshold_inr' => $set['threshold_inr'],
            'counting_number' => $counts ? self::countingOrders((int) $a['id'], $orderId) + 1 : null,
            'gst_pct' => $set['gst_pct'],
        ];
    }

    /**
     * Rupees per US dollar for a fixed USD amount (fresh-eyes review H1): on a USD order
     * the rate its receipts realised (estimated until received); on any other order the
     * Admin pricing exchange rate, which is the company's USD rate.
     */
    public static function usdRate(array $order): float
    {
        if (strtoupper((string) ($order['currency_code'] ?? '')) === 'USD') {
            return self::realisedRate($order)['rate'];
        }
        return (float) (PricingRepository::settings()['pricing_fx_rate'] ?? 0);
    }

    /**
     * The rupees of GST on top of a payout: only for a GST-registered associate whose tax invoice for it is on file.
     * Fresh-eyes review L2: once paid, on the gross actually billed (paid + TDS) — the bill line Zoho taxes — so the
     * GST paid equals the GST on the Zoho bill and the payment never exceeds the bill.
     */
    public static function gstFor(array $payout): float
    {
        if (($payout['payee_kind'] ?? '') !== 'associate' || (float) $payout['amount_inr'] <= 0) {
            return 0.0;
        }
        $a = AssociateRepository::find((int) $payout['associate_id']);
        if ($a === null || trim((string) ($a['gstin'] ?? '')) === '' || \App\Repositories\AssociateDocumentRepository::invoiceForPayout((int) $payout['id']) === null) {
            return 0.0;
        }
        $base = isset($payout['paid_inr']) && $payout['paid_inr'] !== null
            ? (float) $payout['paid_inr'] + (float) ($payout['tds_inr'] ?? 0)
            : (float) $payout['amount_inr'];
        return round($base * self::settings()['gst_pct'] / 100, 2);
    }

    /**
     * Owner rule D-212: what a commission payout is — for which order and
     * associate, what it settles, and the bank reference once paid.
     */
    public static function payoutDescription(array $payout, ?array $order = null, ?array $associate = null): string
    {
        $order ??= OrderRepository::find((int) $payout['order_id']);
        $associate ??= AssociateRepository::find((int) $payout['associate_id']);
        $s = 'Associate commission to ' . ($associate['name'] ?? 'associate') . ' on order ' . ($order['order_reference'] ?? $payout['order_id'])
            . ' (payout no. ' . $payout['id'] . ', settles their commission invoice for this order)'
            . ': ₹' . number_format((float) $payout['amount_inr'], 2);
        if ((float) ($payout['gst_inr'] ?? 0) > 0) {
            $s .= ' + GST ₹' . number_format((float) $payout['gst_inr'], 2);
        }
        if ((float) ($payout['tds_inr'] ?? 0) > 0) {
            $s .= ', TDS ₹' . number_format((float) $payout['tds_inr'], 2);
        }
        if (!empty($payout['paid_reference'])) {
            $s .= ' · bank ref ' . $payout['paid_reference'];
        }
        return $s;
    }

    /**
     * Daily: a buyer registration whose period has ended with no order from the
     * buyer lapses — the client is unlinked and the associate told.
     */
    public static function lapseRegistrations(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $stmt = Database::connection()->prepare(
            "SELECT c.id, c.company_legal_name, c.associate_id, c.associate_locked_until FROM clients c
              WHERE c.associate_id IS NOT NULL AND c.associate_locked_until IS NOT NULL AND c.associate_locked_until < :t
                AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.client_id = c.id AND o.status NOT IN ('cancelled','lost'))"
        );
        $stmt->execute(['t' => $today]);
        $n = 0;
        foreach ($stmt->fetchAll() as $c) {
            $upd = Database::connection()->prepare('UPDATE clients SET associate_id = NULL, associate_registered_on = NULL, associate_locked_until = NULL, associate_source = NULL, associate_invitation_id = NULL WHERE id = :id AND associate_id = :a');
            $upd->execute(['id' => $c['id'], 'a' => $c['associate_id']]);
            if ($upd->rowCount() === 0) {
                continue;
            }
            \App\Repositories\AuditLogRepository::log(null, 'ASSOCIATE_REGISTRATION_LAPSED', 'clients', (int) $c['id'], 'associate_id', (string) $c['associate_id'], null,
                'Registration ended ' . $c['associate_locked_until'] . ' with no order from the buyer.');
            $a = AssociateRepository::find((int) $c['associate_id']);
            if ($a !== null) {
                AssociateService::notify($a, 'registration_lapsed', "Your registration of {$c['company_legal_name']} ended on " . \App\Helpers\Dates::short((string) $c['associate_locked_until'])
                    . ' with no order from the buyer, so it has lapsed and the buyer is no longer linked to you.', (int) $c['id'], null);
            }
            $n++;
        }
        return $n;
    }
}
