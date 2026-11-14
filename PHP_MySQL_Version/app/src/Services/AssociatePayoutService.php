<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\Dates;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderRepository;

/**
 * Owner decision D-264 (7 Nov 2026): an associate's commission is fixed by itself
 * the moment the buyer has paid the order in full (the last buyer receipt cleared
 * with the rupees credited recorded) — in the order's currency and in rupees at the
 * rate of ALL the buyer's payments — and never changes afterwards. The payout is
 * created at once, the associate is told, and the order's profit uses the fixed
 * amount. A daily job holds it while a buyer claim is open, works it out again (at
 * the same fixed rate) after an agreed discount or settled claim, and carries an
 * abroad associate's small amounts to the next run. The team incentive keeps the
 * MD's lock (D-245). Paying it records what the payment really cost; the
 * difference is a company cost ("Associate payout costs"), never the order's.
 *
 * Node twin: src/services/associatePayoutService.js.
 */
final class AssociatePayoutService
{
    public const CLAIM_HOLD_REASON = 'A buyer claim on this order is open — the commission waits until it is resolved.';
    public const UNPAID_HOLD_REASON = 'The buyer did not pay this order in full — no commission is due on it.';

    /** Someone to record the fixed commission's cost line as: whoever cleared the last payment, else the first MD / admin. */
    private static function actingUserId(int $orderId, ?int $userId): int
    {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }
        $stmt = Database::connection()->prepare('SELECT COALESCE(balance_cleared_by, freight_cleared_by, advance_cleared_by) FROM order_payment_status WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        return (int) (Database::connection()->query('SELECT id FROM users ORDER BY is_super_admin DESC, id LIMIT 1')->fetchColumn() ?: 0);
    }

    /** Whether the associate is paid in rupees (based in India). */
    public static function isIndia(?array $associate): bool
    {
        return ($associate['based_in'] ?? 'india') === 'india';
    }

    /**
     * D-264 (B): the currency the associate is paid in — India always INR (whatever an old
     * record says); abroad their chosen trading currency, USD when none (INR is refused abroad).
     * The portal's "How it works" and the "How we set your commission" box both use it.
     */
    public static function payCurrency(?array $associate): string
    {
        if (self::isIndia($associate)) {
            return 'INR';
        }
        $cur = strtoupper(trim((string) ($associate['currency_code'] ?? '')));
        return $cur === '' || $cur === 'INR' ? 'USD' : $cur;
    }

    public static function money(float $v): string
    {
        return number_format($v, 2);
    }

    /** "₹83.45" — the fixed rate, to the paisa. */
    public static function rateText(float $rate): string
    {
        return '₹' . number_format($rate, 2);
    }

    /** India only, once fixed: "We will pay you ₹104,312.50 (at ₹83.45 per USD, set on 12 Nov 2026; this will not change)". */
    public static function fixedRupeeLine(float $inr, float $rate, string $currency, string $fixedOn): string
    {
        return 'We will pay you ₹' . self::money($inr) . ' (at ' . self::rateText($rate) . ' per ' . $currency . ', set on ' . Dates::short(substr($fixedOn, 0, 10)) . '; this will not change)';
    }

    /** @return array{title:string, text:string} the "How your commission is fixed" box, worded for India or abroad. */
    public static function howFixedBox(?array $associate): array
    {
        $title = 'How we set your commission — it does not change later';
        if (self::isIndia($associate)) {
            return ['title' => $title, 'text' => 'Your commission is a percentage of the value of the goods (FOB), in the order\'s currency. On the day the buyer finishes paying, we turn it into rupees at the rate the bank used for the buyer\'s payment. We pay you that rupee amount. If the exchange rate changes later, your amount stays the same. We pay all bank charges.'];
        }
        $cur = self::payCurrency($associate);
        $charges = AssociateCommission::settings()['abroad_charges'] === 'SHA'
            ? 'We pay our own bank\'s charges; your own bank may charge you when the money arrives.'
            : 'We pay our bank\'s charges and the charges of any bank in between.';
        return ['title' => $title, 'text' => 'Your commission is a percentage of the value of the goods (FOB). On the day the buyer finishes paying, we set the amount in ' . self::currencyName($cur) . '. We pay you exactly that amount in ' . self::currencyName($cur) . ' into your checked bank account. ' . $charges . ' If you need it in your local currency, your own bank changes it.'];
    }

    private static function currencyName(string $cur): string
    {
        return ['USD' => 'US dollars', 'EUR' => 'euros', 'GBP' => 'pounds sterling', 'INR' => 'rupees'][$cur] ?? $cur;
    }

    /** The message the associate gets when the commission is fixed (portal notice and e-mail, mockup 7). */
    public static function fixedMessage(array $associate, array $order, array $a, string $dueOn, string $runOn): string
    {
        $cur = (string) $a['currency'];
        $level = AssociateCommission::rateLabel($a['level'] ?? null);
        $client = (string) ($order['company_legal_name'] ?? 'Your client');
        $head = 'Your client ' . $client . ' has paid order ' . $order['order_reference'] . ' in full. ';
        $when = 'It is due on ' . Dates::short($dueOn) . '. We will pay it on ' . Dates::short($runOn) . ' into your checked bank account';
        if (self::isIndia($associate)) {
            return $head . 'Your commission is ₹' . self::money((float) $a['amount_inr']) . ' (' . $cur . ' ' . self::money((float) $a['amount_fc'])
                . ': ' . $level . ' of the invoice value of the goods, at ' . self::rateText((float) $a['rate_inr']) . ' per ' . $cur . ', the rate the bank used for the buyer\'s payment). '
                . 'This amount will not change, even if the exchange rate changes. ' . $when . ', after deducting tax (TDS) as the law requires. '
                . 'Please upload your invoice for this commission under "My commission". We pay all bank charges.';
        }
        $charges = AssociateCommission::settings()['abroad_charges'] === 'SHA'
            ? 'We pay our own bank\'s charges; your own bank may charge you when the money arrives.'
            : 'We pay our bank\'s charges and the charges of any bank in between.';
        return $head . 'Your commission is ' . $cur . ' ' . self::money((float) $a['amount_fc']) . ' (' . $level . ' of the invoice value of the goods). '
            . 'This amount will not change. ' . $when . ', in ' . self::currencyName($cur) . '. '
            . 'Please upload your invoice for this commission under "My commission". ' . $charges;
    }

    /** Called after a buyer payment is cleared or its rupees recorded: fixes the commission if the order is now fully paid. Never throws. */
    public static function afterPaymentChange(int $orderId, ?int $userId = null): void
    {
        try {
            self::autoFix($orderId, $userId);
        } catch (\Throwable $e) {
            error_log('[D-264] associate commission not fixed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * Fixes the associate's commission when the buyer has paid in full and the rupees of every
     * receipt are recorded. Returns true when it fixed it now; false when there is nothing to fix
     * (no associate, not fully paid, rupees not recorded yet, nothing earned, already fixed or locked).
     */
    public static function autoFix(int $orderId, ?int $userId = null): bool
    {
        $order = OrderRepository::find($orderId);
        if ($order === null || in_array($order['status'] ?? '', ['cancelled', 'lost'], true)) {
            return false;
        }
        $row = IncentiveRepository::forOrder($orderId);
        if ($row !== null && (!empty($row['associate_fixed_at']) || !empty($row['locked_at']))) {
            return false;
        }
        $paidOn = IncentiveService::fullyPaidOn($orderId);
        if ($paidOn === null) {
            return false;
        }
        $a = AssociateCommission::forOrder($orderId, $order, $row ?? ['associate_mode' => 'auto']);
        if ($a === null || $a['state'] !== 'paid' || (float) $a['amount_fc'] <= 0 || !empty($a['rate_estimated'])) {
            return false;
        }
        $uid = self::actingUserId($orderId, $userId);
        $today = date('Y-m-d');
        $set = AssociateCommission::settings();
        $dueOn = AssociateCommission::dueOn($paidOn, $set);
        $runOn = AssociateCommission::payoutRunOn($dueOn, $set);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT IGNORE INTO order_incentives (order_id, updated_by) VALUES (:id, :u)')->execute(['id' => $orderId, 'u' => $uid]);
            // The order's row is claimed first, so two requests at the same moment fix it once (as the D-245 lock does).
            $claim = $pdo->prepare('SELECT associate_fixed_at, locked_at FROM order_incentives WHERE order_id = :id FOR UPDATE');
            $claim->execute(['id' => $orderId]);
            $c = $claim->fetch() ?: [];
            $existing = $pdo->prepare("SELECT COUNT(*) FROM incentive_payouts WHERE order_id = :id AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL");
            $existing->execute(['id' => $orderId]);
            if (!empty($c['associate_fixed_at']) || !empty($c['locked_at']) || (int) $existing->fetchColumn() > 0) {
                $pdo->rollBack();
                return false;
            }
            $rate = round((float) $a['rate_inr'], 6);
            $payoutId = IncentiveRepository::addPayout(['order_id' => $orderId, 'payee_kind' => 'associate', 'associate_id' => $a['id'], 'role' => 'associate',
                'amount_inr' => $a['amount_inr'], 'amount_fc' => $a['amount_fc'], 'currency_code' => $a['currency'], 'incentive_month' => substr($runOn, 0, 7),
                'payout_run_on' => $runOn, 'locked_on' => $today]);
            $pdo->prepare('UPDATE incentive_payouts SET due_on = :d, fixed_rate = :r, fixed_on = :f WHERE id = :id')->execute(['d' => $dueOn, 'r' => $rate, 'f' => $today, 'id' => $payoutId]);
            if ($a['status'] === 'suspended') {
                IncentiveRepository::holdPayout($payoutId, 'The associate is suspended.');
            }
            $entryId = IncentiveRepository::addCostEntry($orderId, 'associate', self::costDescription($a, $today), (float) $a['amount_inr'], $paidOn, $uid);
            IncentiveRepository::saveOrder($orderId, [
                'fully_paid_on' => $paidOn,
                'associate_id' => $a['id'],
                'associate_order_number' => $a['counts'] ? $a['counting_number'] : null,
                'associate_rate_pct' => $a['rate'],
                'associate_amount_fc' => $a['amount_fc'],
                'associate_currency' => $a['currency'],
                'associate_amount_inr' => $a['amount_inr'],
                'associate_basis_fc' => $a['basis_fc'],
                'associate_basis_inr' => $a['basis_inr'],
                'associate_counts' => $a['counts'] ? 1 : 0,
                'associate_due_on' => $dueOn,
                'associate_cost_entry_id' => $entryId,
                'associate_fixed_at' => date('Y-m-d H:i:s'),
                'associate_fixed_rate' => $rate,
            ], $uid);
            IncentiveRepository::addHistory($orderId, 'associate_fixed', null, 'Associate commission fixed: ' . $a['currency'] . ' ' . self::money((float) $a['amount_fc'])
                . ' = ₹' . self::money((float) $a['amount_inr']) . ' at ' . self::rateText($rate) . ' per ' . $a['currency'] . '; due ' . Dates::short($dueOn) . ', payout run ' . Dates::short($runOn),
                'Fixed automatically when the buyer paid in full (D-264).', $uid);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $associate = AssociateRepository::find((int) $a['id']);
        if ($associate !== null) {
            AssociateService::notify($associate, $a['status'] === 'suspended' ? 'payout_held' : 'commission_earned',
                self::fixedMessage($associate, $order, $a, $dueOn, $runOn) . ($a['status'] === 'suspended' ? ' It is on hold while your account is suspended.' : ''),
                (int) $order['client_id'], $orderId);
        }
        return true;
    }

    /** Owner rule D-212: the commission cost line says what it is, and the rate it was fixed at. */
    public static function costDescription(array $a, string $fixedOn): string
    {
        return IncentiveService::associateCostDescription($a) . '; fixed on ' . Dates::short($fixedOn) . ' at ' . self::rateText((float) $a['rate_inr']) . ' per ' . $a['currency'];
    }

    /** Daily: fix what was missed, hold / release for buyer claims, work out again after a settlement, carry small abroad amounts. */
    public static function daily(?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $out = ['fixed' => 0, 'held' => 0, 'released' => 0, 'recomputed' => 0, 'carried' => 0];
        foreach (self::candidateOrderIds() as $orderId) {
            try {
                if (self::autoFix((int) $orderId)) {
                    $out['fixed']++;
                }
            } catch (\Throwable $e) {
                error_log('[D-264] daily fix failed for order ' . $orderId . ': ' . $e->getMessage());
            }
        }
        $set = AssociateCommission::settings();
        $rows = Database::connection()->query(
            "SELECT p.* FROM incentive_payouts p WHERE p.payee_kind = 'associate' AND p.status IN ('due','held') AND p.fixed_rate IS NOT NULL AND p.clawback_of_payout_id IS NULL ORDER BY p.id"
        )->fetchAll();
        foreach ($rows as $p) {
            if (self::recompute($p)) {
                $out['recomputed']++;
                $p = IncentiveRepository::findPayout((int) $p['id']) ?? $p;
            }
            $claim = $set['claim_hold'] && !empty($p['due_on']) && AssociateCommission::claimOpen((int) $p['order_id'], (string) $p['due_on']);
            $associate = AssociateRepository::find((int) $p['associate_id']);
            $order = OrderRepository::find((int) $p['order_id']);
            if ($p['status'] === 'due' && $claim && (float) $p['amount_inr'] > 0) {
                if (IncentiveRepository::holdPayout((int) $p['id'], self::CLAIM_HOLD_REASON)) {
                    $out['held']++;
                    if ($associate !== null && $order !== null) {
                        AssociateService::notify($associate, 'payout_held', 'Your commission on order ' . $order['order_reference'] . ' is on hold. The buyer has an open claim (complaint) about this order. We will pay your commission once it is settled.', (int) $order['client_id'], (int) $order['id']);
                    }
                }
            } elseif ($p['status'] === 'held' && (string) $p['hold_reason'] === self::CLAIM_HOLD_REASON && !$claim) {
                if (IncentiveRepository::releasePayout((int) $p['id'])) {
                    $out['released']++;
                    if ($associate !== null && $order !== null) {
                        AssociateService::notify($associate, 'commission_earned', 'The buyer\'s claim on order ' . $order['order_reference'] . ' is settled. Your commission is no longer on hold. We will pay it on ' . Dates::short((string) $p['payout_run_on']) . '.', (int) $order['client_id'], (int) $order['id']);
                    }
                }
            }
        }
        $out['carried'] = self::carrySmallAbroad($today);
        return $out;
    }

    /** @return list<int> orders of an associate's client, not cancelled or lost, whose commission is neither fixed nor locked */
    public static function candidateOrderIds(): array
    {
        return array_map('intval', Database::connection()->query(
            "SELECT o.id FROM orders o JOIN clients c ON c.id = o.client_id
               LEFT JOIN order_incentives oi ON oi.order_id = o.id
              WHERE c.associate_id IS NOT NULL AND oi.locked_at IS NULL AND oi.associate_fixed_at IS NULL
                AND o.status NOT IN ('cancelled','lost') AND o.is_sample_data = 0 ORDER BY o.id"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * After an agreed discount or a settled claim lowers the goods value, the commission is worked out
     * again at the SAME fixed rate (never a new rate). An MD correction is kept as it is.
     */
    public static function recompute(array $p): bool
    {
        $row = IncentiveRepository::forOrder((int) $p['order_id']);
        if ($row === null || empty($row['associate_fixed_at']) || $row['associate_override_fc'] !== null || $p['status'] === 'paid') {
            return false;
        }
        $order = OrderRepository::find((int) $p['order_id']);
        $a = $order !== null ? AssociateCommission::forOrder((int) $p['order_id'], $order, $row) : null;
        if ($a === null || (int) $a['id'] !== (int) $p['associate_id']) {
            return false;
        }
        $fc = round((float) $a['amount_fc'], 2);
        $inr = round((float) $a['amount_inr'], 2);
        if (abs($fc - (float) $p['amount_fc']) < 0.005 && abs($inr - (float) $p['amount_inr']) < 0.005) {
            return false;
        }
        self::applyAmount((int) $p['order_id'], $p, $fc, $inr, [
            'associate_basis_fc' => $a['basis_fc'], 'associate_basis_inr' => round((float) $a['basis_fc'] * (float) $row['associate_fixed_rate'], 2),
        ]);
        $uid = self::actingUserId((int) $p['order_id'], null);
        IncentiveRepository::addHistory((int) $p['order_id'], 'associate_recomputed', $p['currency_code'] . ' ' . self::money((float) $p['amount_fc']) . ' = ₹' . self::money((float) $p['amount_inr']),
            $p['currency_code'] . ' ' . self::money($fc) . ' = ₹' . self::money($inr) . ' at the same fixed rate ' . self::rateText((float) $row['associate_fixed_rate']),
            $a['state'] === 'unpaid' ? 'The buyer did not pay in full.' : 'Agreed discount / settled buyer claim.', $uid);
        if ($a['state'] === 'unpaid' && $p['status'] === 'due') {
            IncentiveRepository::holdPayout((int) $p['id'], self::UNPAID_HOLD_REASON);
        }
        $associate = AssociateRepository::find((int) $p['associate_id']);
        if ($associate !== null && $order !== null) {
            AssociateService::notify($associate, 'commission_earned', self::recomputedMessage($associate, $order, (string) $p['currency_code'], $fc, $inr, (float) $row['associate_fixed_rate'], $a['state'] === 'unpaid'), (int) $order['client_id'], (int) $order['id']);
        }
        return true;
    }

    public static function recomputedMessage(array $associate, array $order, string $cur, float $fc, float $inr, float $rate, bool $unpaid): string
    {
        if ($unpaid) {
            return 'Order ' . $order['order_reference'] . ': the buyer did not pay the order in full, so no commission is due on it.';
        }
        return 'Order ' . $order['order_reference'] . ': after an agreed discount or a settled buyer claim, your commission is now ' . $cur . ' ' . self::money($fc)
            . (self::isIndia($associate) ? ' = ₹' . self::money($inr) . ', at the same fixed rate of ' . self::rateText($rate) . ' per ' . $cur : '') . '.';
    }

    /** Writes a new commission amount on the unpaid payout, the order's fixed figures and its cost line. */
    private static function applyAmount(int $orderId, array $p, float $fc, float $inr, array $extra = []): void
    {
        $pdo = Database::connection();
        $pdo->prepare("UPDATE incentive_payouts SET amount_fc = :fc, amount_inr = :inr WHERE id = :id AND status <> 'paid'")->execute(['fc' => $fc, 'inr' => $inr, 'id' => $p['id']]);
        $row = IncentiveRepository::forOrder($orderId);
        $uid = self::actingUserId($orderId, null);
        IncentiveRepository::saveOrder($orderId, ['associate_amount_fc' => $fc, 'associate_amount_inr' => $inr] + $extra, $uid);
        if (!empty($row['associate_cost_entry_id'])) {
            $pdo->prepare('UPDATE order_cost_entries SET amount_inr = :a WHERE id = :id')->execute(['a' => $inr, 'id' => $row['associate_cost_entry_id']]);
        }
    }

    /** The MD corrects a fixed commission before it is paid, with a reason (audited; the associate is told). Returns an error or null. */
    public static function changeCommission(int $orderId, string $amountFc, string $reason, array $user): ?string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return 'Say why the commission is changed (10 to 500 characters).';
        }
        if (!is_numeric($amountFc) || (float) $amountFc < 0 || (float) $amountFc > 100000000) {
            return 'Enter the new commission in the order currency (0 or more).';
        }
        $row = IncentiveRepository::forOrder($orderId);
        if ($row === null || empty($row['associate_fixed_at'])) {
            return 'This order\'s associate commission is not fixed yet — it is fixed when the buyer has paid in full.';
        }
        $p = null;
        foreach (IncentiveRepository::payoutsForOrder($orderId) as $x) {
            if ($x['payee_kind'] === 'associate' && $x['clawback_of_payout_id'] === null) {
                $p = $x;
                break;
            }
        }
        if ($p === null || $p['status'] === 'paid') {
            return 'This commission is already paid, so it can no longer be changed.';
        }
        $fc = round((float) $amountFc, 2);
        $inr = round($fc * (float) $row['associate_fixed_rate'], 2);
        $before = $p['currency_code'] . ' ' . self::money((float) $p['amount_fc']) . ' = ₹' . self::money((float) $p['amount_inr']);
        $after = $p['currency_code'] . ' ' . self::money($fc) . ' = ₹' . self::money($inr);
        self::applyAmount($orderId, $p, $fc, $inr, ['associate_override_fc' => $fc, 'associate_override_reason' => $reason]);
        IncentiveRepository::addHistory($orderId, 'associate_changed', $before, $after . ' at the same fixed rate ' . self::rateText((float) $row['associate_fixed_rate']), $reason, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ASSOCIATE_COMMISSION_CHANGED', 'incentive_payouts', (int) $p['id'], 'amount_fc', number_format((float) $p['amount_fc'], 2, '.', ''), number_format($fc, 2, '.', ''), $reason);
        $associate = AssociateRepository::find((int) $p['associate_id']);
        $order = OrderRepository::find($orderId);
        if ($associate !== null && $order !== null) {
            AssociateService::notify($associate, 'commission_earned', 'NexaCrest has corrected your commission on order ' . $order['order_reference'] . ' to ' . $p['currency_code'] . ' ' . self::money($fc)
                . (self::isIndia($associate) ? ' = ₹' . self::money($inr) . ' (at the same fixed rate of ' . self::rateText((float) $row['associate_fixed_rate']) . ' per ' . $p['currency_code'] . ')' : '')
                . '. Reason: ' . $reason, (int) $order['client_id'], $orderId);
        }
        return null;
    }

    /** Rupees per US dollar to compare an abroad amount with the USD minimum. */
    private static function usdEquivalent(array $p): float
    {
        if (strtoupper((string) $p['currency_code']) === 'USD') {
            return (float) $p['amount_fc'];
        }
        $usd = FxRateService::rate();
        return $usd > 0 ? (float) $p['amount_inr'] / $usd : (float) $p['amount_fc'];
    }

    /**
     * On or after a payout run: an associate abroad whose amount due in that run is below the minimum
     * (default USD 100) is paid in the next run instead, so one transfer's charges are not spent on a
     * small amount. Returns the payouts carried.
     */
    public static function carrySmallAbroad(?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $min = AssociateCommission::settings()['abroad_min_usd'];
        if ($min <= 0) {
            return 0;
        }
        $stmt = Database::connection()->prepare(
            "SELECT p.* FROM incentive_payouts p JOIN associates a ON a.id = p.associate_id
              WHERE p.payee_kind = 'associate' AND p.status = 'due' AND a.based_in = 'abroad' AND p.amount_inr > 0 AND p.payout_run_on IS NOT NULL AND p.payout_run_on <= :t
              ORDER BY p.associate_id, p.id"
        );
        $stmt->execute(['t' => $today]);
        $byAssociate = [];
        foreach ($stmt->fetchAll() as $p) {
            $byAssociate[(int) $p['associate_id']][] = $p;
        }
        $carried = 0;
        $next = AssociateCommission::payoutRunOn(date('Y-m-d', (int) strtotime($today . ' +1 day')));
        foreach ($byAssociate as $rows) {
            $total = array_sum(array_map(static fn (array $p): float => self::usdEquivalent($p), $rows));
            if ($total + 0.005 >= $min) {
                continue;
            }
            foreach ($rows as $p) {
                Database::connection()->prepare('UPDATE incentive_payouts SET payout_run_on = :r, incentive_month = :m, note = :n WHERE id = :id AND status = \'due\'')
                    ->execute(['r' => $next, 'm' => substr($next, 0, 7), 'n' => self::carriedNote($min), 'id' => $p['id']]);
                $carried++;
            }
        }
        return $carried;
    }

    public static function carriedNote(float $min): string
    {
        return 'Carried to the next payout run: the amount due is below the USD ' . number_format($min, 0) . ' minimum for a transfer abroad.';
    }

    /**
     * What paying an associate really cost against the fixed rupees: abroad, the rupees the bank
     * debited (+ TDS kept) + our and the intermediary bank's charges − the fixed commission;
     * in India, the bank charges. Booked as "Associate payout costs" for the month — never on the order.
     */
    public static function payoutDifference(array $payout, ?float $bankDebitedInr, ?float $bankChargesInr, bool $india): float
    {
        $charges = (float) ($bankChargesInr ?? 0);
        if ($india || $bankDebitedInr === null) {
            return round($charges, 2);
        }
        return round($bankDebitedInr + (float) ($payout['tds_inr'] ?? 0) + $charges - (float) $payout['amount_inr'], 2);
    }

    public static function recordPaymentCosts(int $payoutId, ?float $bankDebitedInr, ?float $bankChargesInr): void
    {
        $p = IncentiveRepository::findPayout($payoutId);
        if ($p === null || $p['payee_kind'] !== 'associate') {
            return;
        }
        $india = self::isIndia(AssociateRepository::find((int) $p['associate_id']));
        $diff = self::payoutDifference($p, $india ? null : $bankDebitedInr, $bankChargesInr, $india);
        Database::connection()->prepare('UPDATE incentive_payouts SET bank_debited_inr = :d, bank_charges_inr = :c, payout_difference_inr = :x WHERE id = :id')
            ->execute(['d' => $india ? null : $bankDebitedInr, 'c' => $bankChargesInr, 'x' => $diff, 'id' => $payoutId]);
    }

    /**
     * Owner rule D-212: the payout-cost line, e.g. "Associate commission payout — NC-EXP-0142 — Demo Associate —
     * USD 1,250.00 sent, SWIFT ref FT2612050001; exchange difference ₹812.50 and transfer charges ₹1,850.00".
     */
    public static function payoutCostDescription(array $p, ?array $order = null, ?array $associate = null, ?string $orderLabel = null): string
    {
        $order ??= OrderRepository::find((int) $p['order_id']);
        $associate ??= AssociateRepository::find((int) $p['associate_id']);
        $s = 'Associate commission payout — ' . ($orderLabel ?? ($order['order_reference'] ?? $p['order_id'])) . ' — ' . ($associate['name'] ?? 'associate') . ' — ';
        $charges = (float) ($p['bank_charges_inr'] ?? 0);
        if (self::isIndia($associate)) {
            return $s . '₹' . self::money((float) $p['paid_inr']) . ' paid, UTR ' . ($p['paid_reference'] ?? '—') . '; transfer charges ₹' . self::money($charges);
        }
        $exchange = round((float) ($p['payout_difference_inr'] ?? 0) - $charges, 2);
        return $s . $p['currency_code'] . ' ' . self::money((float) $p['amount_fc']) . ' sent, SWIFT ref ' . ($p['paid_reference'] ?? '—')
            . '; exchange difference ₹' . self::money($exchange) . ' and transfer charges ₹' . self::money($charges);
    }

    /** @return list<array<string,mixed>> paid associate payouts of a payout month with a payout cost, each with its description */
    public static function payoutCostsForMonth(string $month): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT p.* FROM incentive_payouts p WHERE p.payee_kind = 'associate' AND p.status = 'paid' AND p.payout_difference_inr IS NOT NULL AND p.incentive_month = :m ORDER BY p.id"
        );
        $stmt->execute(['m' => $month]);
        $out = [];
        foreach ($stmt->fetchAll() as $p) {
            $p['description'] = self::payoutCostDescription($p);
            $out[] = $p;
        }
        return $out;
    }
}
