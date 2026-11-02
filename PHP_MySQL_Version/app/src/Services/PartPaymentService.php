<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentMilestoneRepository;

/**
 * Gap list G-5 (owner, 1 Nov 2026): part payments and short payments from
 * the buyer. Every remittance of the advance, the freight Debit Note, the
 * balance and each payment milestone is its own receipt. The leg's existing
 * columns keep the total received, the latest date, the amount-weighted
 * rate and the receipts' bank references — so documents, CAFIN, Zoho and
 * reports read what they always read. A leg cannot be cleared while money
 * is still owed, unless a manager with "Approve short payment" gives a
 * written reason; the shortfall is then recorded.
 */
final class PartPaymentService
{
    public const LEGS = ['advance', 'freight', 'balance'];
    public const TOLERANCE = 0.01;

    public static function label(string $leg, ?array $milestone = null): string
    {
        return match ($leg) {
            'advance' => 'Advance',
            'freight' => 'Freight',
            'balance' => 'Balance',
            default => 'Milestone ' . (int) ($milestone['seq'] ?? 0),
        };
    }

    /** @return list<array<string,mixed>> oldest first, with who recorded it */
    public static function receipts(int $orderId, string $leg, ?int $milestoneId = null): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.name AS recorded_by_name FROM order_payment_receipts r LEFT JOIN users u ON u.id = r.recorded_by
              WHERE r.order_id = :o AND r.leg = :l AND r.milestone_id <=> :m ORDER BY r.received_on, r.id'
        );
        $stmt->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId]);
        return $stmt->fetchAll();
    }

    public static function receivedTotal(int $orderId, string $leg, ?int $milestoneId = null): float
    {
        // Owner 2 Nov 2026 ("18 yes"): bank charges the banks deducted count as paid by the buyer.
        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(amount + COALESCE(bank_charges, 0)), 0) AS total FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id <=> :m');
        $stmt->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId]);
        $row = $stmt->fetch();
        if ((int) $row['n'] === 0) {
            // A remittance written to the leg itself (sample data, before receipts were kept): it counts as the one receipt.
            $legacy = self::legacyReceipt($orderId, $leg, $milestoneId);
            return $legacy !== null ? round((float) $legacy['amount'], 2) : 0.0;
        }
        return round((float) $row['total'], 2);
    }

    /** @return array<string,mixed>|null the remittance held on the leg's own columns when it has no receipt rows */
    private static function legacyReceipt(int $orderId, string $leg, ?int $milestoneId): ?array
    {
        if ($leg === 'milestone') {
            $m = $milestoneId !== null ? PaymentMilestoneRepository::find($milestoneId) : null;
            return $m !== null && $m['received_amount'] !== null && $m['received_on'] !== null
                ? ['amount' => $m['received_amount'], 'received_on' => $m['received_on'], 'exchange_rate' => $m['exchange_rate'], 'bank_reference' => $m['bank_reference']] : null;
        }
        $ops = OrderPaymentStatusRepository::find($orderId);
        if ($ops === null || $ops[$leg . '_amount'] === null || $ops[$leg . '_remittance_received_at'] === null) {
            return null;
        }
        return ['amount' => $ops[$leg . '_amount'], 'received_on' => substr((string) $ops[$leg . '_remittance_received_at'], 0, 10), 'exchange_rate' => $ops[$leg . '_exchange_rate'], 'bank_reference' => $ops[$leg . '_bank_reference']];
    }

    /**
     * What the buyer owes on the leg: the advance due by the terms, the Debit
     * Note amount, the balance (order value less the advance actually received
     * and the milestones planned) or the milestone's share. Null when not known yet.
     */
    public static function expected(int $orderId, string $leg, ?array $milestone = null): ?float
    {
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            return null;
        }
        $fob = OrderProductRepository::totalFobValue($orderId);
        return match ($leg) {
            'advance' => round($fob * (float) ($order['advance_pct'] ?? 0) / 100, 2),
            'freight' => PaymentActionService::amountDue($order, 'freight'),
            'balance' => OrderPaymentStatusRepository::balanceDue($fob, (float) ($order['advance_pct'] ?? 0), OrderPaymentStatusRepository::find($orderId), PaymentMilestoneRepository::plannedTotal($orderId, $fob)),
            default => $milestone !== null ? round($fob * (float) $milestone['pct'] / 100, 2) : null,
        };
    }

    /** @return array{expected: ?float, received: float, owed: ?float, receipts: list<array<string,mixed>>, shortfall: ?array<string,mixed>} */
    public static function summary(int $orderId, string $leg, ?array $milestone = null): array
    {
        $milestoneId = $milestone !== null ? (int) $milestone['id'] : null;
        $expected = self::expected($orderId, $leg, $milestone);
        $received = self::receivedTotal($orderId, $leg, $milestoneId);
        $stmt = Database::connection()->prepare('SELECT s.*, u.name AS approved_by_name FROM order_payment_shortfalls s LEFT JOIN users u ON u.id = s.approved_by WHERE s.order_id = :o AND s.leg = :l AND s.milestone_id <=> :m ORDER BY s.id DESC LIMIT 1');
        $stmt->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId]);
        return [
            'expected' => $expected,
            'received' => $received,
            'owed' => $expected !== null ? round($expected - $received, 2) : null,
            'receipts' => self::receipts($orderId, $leg, $milestoneId) ?: array_values(array_filter([self::legacyReceipt($orderId, $leg, $milestoneId)])),
            'shortfall' => $stmt->fetch() ?: null,
        ];
    }

    /** A second click of the same form: this exact receipt is already there. Locks the leg's row; call inside the transaction. */
    public static function receiptExists(int $orderId, string $leg, ?int $milestoneId, float $amount, string $receivedOn, float $rate): bool
    {
        $pdo = Database::connection();
        $pdo->prepare('SELECT order_id FROM order_payment_status WHERE order_id = :o FOR UPDATE')->execute(['o' => $orderId]);
        $stmt = $pdo->prepare(
            'SELECT 1 FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id <=> :m
                AND ABS(amount - :a) < 0.005 AND received_on = :d AND ABS(COALESCE(exchange_rate, 0) - :r) < 0.00005 LIMIT 1'
        );
        $stmt->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId, 'a' => round($amount, 2), 'd' => substr($receivedOn, 0, 10), 'r' => round($rate, 4)]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Adds a receipt to the advance, freight or balance, and puts the leg's
     * totals back on order_payment_status (amount, latest date, weighted rate,
     * bank references). Call inside the transaction that checked receiptExists().
     */
    public static function addLegReceipt(int $orderId, string $leg, float $amount, string $receivedOn, float $rate, string $bankReference, int $userId, ?string $differenceReason = null, float $bankCharges = 0.0): int
    {
        if (!in_array($leg, self::LEGS, true)) {
            throw new \InvalidArgumentException("Unknown payment leg {$leg}");
        }
        $pdo = Database::connection();
        if (self::receipts($orderId, $leg) === [] && ($legacy = self::legacyReceipt($orderId, $leg, null)) !== null) {
            // The remittance already on the leg becomes its first receipt, so the new one adds to it.
            $pdo->prepare('INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, staff_note) VALUES (:o, :l, :a, :d, :r, :b, :n)')
                ->execute(['o' => $orderId, 'l' => $leg, 'a' => $legacy['amount'], 'd' => $legacy['received_on'], 'r' => $legacy['exchange_rate'], 'b' => $legacy['bank_reference'], 'n' => 'Recorded before part payments were kept separately']);
        }
        $pdo->prepare('INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, bank_reference, difference_reason, bank_charges, bank_charges_inr, recorded_by) VALUES (:o, :l, :a, :d, :r, :b, :dr, :bc, :bci, :u)')
            ->execute(['o' => $orderId, 'l' => $leg, 'a' => round($amount, 2), 'd' => substr($receivedOn, 0, 10), 'r' => round($rate, 4), 'b' => trim($bankReference),
                'dr' => $differenceReason, 'bc' => $bankCharges > 0 ? round($bankCharges, 2) : null, 'bci' => $bankCharges > 0 ? round($bankCharges * $rate, 2) : null, 'u' => $userId]);
        $receiptId = (int) $pdo->lastInsertId();
        $receipts = self::receipts($orderId, $leg);
        // The leg holds what the buyer paid: received plus any bank charges the banks deducted (owner, 2 Nov 2026).
        $total = round(array_sum(array_map(static fn (array $r): float => (float) $r['amount'] + (float) ($r['bank_charges'] ?? 0), $receipts)), 2);
        $latest = max(array_map(static fn (array $r): string => (string) $r['received_on'], $receipts));
        $weighted = 0.0;
        foreach ($receipts as $r) {
            $weighted += ((float) $r['amount'] + (float) ($r['bank_charges'] ?? 0)) * (float) ($r['exchange_rate'] ?? $rate);
        }
        $avgRate = $total > 0 ? round($weighted / $total, 4) : round($rate, 4);
        $method = 'record' . ucfirst($leg) . 'Received';
        OrderPaymentStatusRepository::$method($orderId, $total, $latest);
        OrderPaymentStatusRepository::setLegBankReference($orderId, $leg, self::joinReferences($receipts));
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, $leg, $avgRate, $userId);
        return $receiptId;
    }

    /** The receipts' bank references, each once, joined ", " (cut to the column's 100 characters). */
    public static function joinReferences(array $receipts): string
    {
        $refs = [];
        foreach ($receipts as $r) {
            $ref = trim((string) ($r['bank_reference'] ?? ''));
            if ($ref !== '' && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }
        $joined = implode(', ', $refs);
        return mb_strlen($joined) > 100 ? mb_substr($joined, 0, 99) . '…' : $joined;
    }

    /** Adds a milestone receipt to the history (the milestone row itself holds its one remittance). */
    public static function addMilestoneReceipt(array $milestone, float $amount, string $receivedOn, float $rate, string $bankReference, ?string $note, int $userId, ?string $differenceReason = null, float $bankCharges = 0.0): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO order_payment_receipts (order_id, leg, milestone_id, amount, received_on, exchange_rate, bank_reference, staff_note, difference_reason, bank_charges, bank_charges_inr, recorded_by) VALUES (:o, \'milestone\', :m, :a, :d, :r, :b, :n, :dr, :bc, :bci, :u)')
            ->execute(['o' => $milestone['order_id'], 'm' => $milestone['id'], 'a' => round($amount, 2), 'd' => substr($receivedOn, 0, 10), 'r' => round($rate, 4), 'b' => trim($bankReference),
                'n' => $note !== null && trim($note) !== '' ? trim($note) : null, 'dr' => $differenceReason,
                'bc' => $bankCharges > 0 ? round($bankCharges, 2) : null, 'bci' => $bankCharges > 0 ? round($bankCharges * $rate, 2) : null, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function money(string $currency, float $amount): string
    {
        return trim($currency . ' ' . number_format($amount, 2));
    }

    /**
     * Before a leg is marked cleared. Returns ['error' => message] to refuse,
     * or ['short' => array|null] — the shortfall to record once cleared.
     *
     * @return array{error?: string, short?: ?array<string,mixed>}
     */
    public static function clearCheck(int $orderId, string $leg, ?array $milestone, array $user, string $reason): array
    {
        $s = self::summary($orderId, $leg, $milestone);
        if ($s['owed'] === null || $s['owed'] <= self::TOLERANCE) {
            return ['short' => null];
        }
        $order = OrderRepository::find($orderId) ?? [];
        $currency = (string) ($order['currency_code'] ?? '');
        $label = self::label($leg, $milestone);
        $canApprove = PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, 'approve_short_payment');
        if (!$canApprove) {
            return ['error' => "{$label} received so far: " . self::money($currency, $s['received']) . ' of ' . self::money($currency, (float) $s['expected']) . ' due — ' . self::money($currency, $s['owed'])
                . ' is still owed. Record the rest when it arrives, or ask a manager with "Approve short payment" to clear it with a written reason.'];
        }
        // QA Round 8, R8-N09: a manager who recorded this payment's receipts clears it short only if their role is ticked.
        if (self::recordedBy($orderId, $leg, $milestone !== null ? (int) $milestone['id'] : null, (int) $user['id'])
            && !MakerCheckerGuard::selfApprovalAllowed('short_payment', (int) $user['id'])) {
            return ['error' => 'You recorded this payment\'s receipts, so a different manager with "Approve short payment" must clear it short.'];
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return ['error' => self::money($currency, $s['owed']) . ' of the ' . strtolower($label) . ' is still owed. To clear it short, give the reason (10 to 500 characters) — it is recorded and shown on the order.'];
        }
        return ['short' => ['expected' => (float) $s['expected'], 'received' => $s['received'], 'owed' => $s['owed'], 'reason' => $reason, 'currency' => $currency, 'label' => $label]];
    }

    /** Records the approved shortfall after the leg is cleared; returns the sentence for the flash message. */
    public static function recordShortfall(int $orderId, string $leg, ?array $milestone, array $short, array $user): string
    {
        $treatment = $leg === 'advance' ? 'carried_to_balance' : 'written_off';
        Database::connection()->prepare(
            'INSERT INTO order_payment_shortfalls (order_id, leg, milestone_id, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by)
             VALUES (:o, :l, :m, :e, :r, :s, :t, :reason, :u)'
        )->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestone['id'] ?? null, 'e' => $short['expected'], 'r' => $short['received'], 's' => $short['owed'], 't' => $treatment, 'reason' => $short['reason'], 'u' => $user['id']]);
        AuditLogRepository::log((int) $user['id'], 'PAYMENT_SHORTFALL_APPROVED', 'orders', $orderId, $leg . ($milestone !== null ? ':' . $milestone['seq'] : ''), (string) $short['expected'], (string) $short['received'], $short['reason']);
        if (self::recordedBy($orderId, $leg, $milestone !== null ? (int) $milestone['id'] : null, (int) $user['id'])) {
            AuditLogRepository::log((int) $user['id'], 'SHORT_PAYMENT_SELF_APPROVED', 'orders', $orderId, $leg . ($milestone !== null ? ':' . $milestone['seq'] : ''), null, null, 'Cleared short a payment whose receipts they recorded themselves (Admin → Self-approval rules).');
        }
        return 'Short by ' . self::money($short['currency'], $short['owed']) . ': approved by ' . $user['name'] . ' — '
            . ($treatment === 'carried_to_balance' ? 'carried into the balance.' : 'written off.');
    }

    /** Whether this person recorded any receipt of the leg (QA Round 8, R8-N09). */
    public static function recordedBy(int $orderId, string $leg, ?int $milestoneId, int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM order_payment_receipts WHERE order_id = :o AND leg = :l AND milestone_id <=> :m AND recorded_by = :u LIMIT 1');
        $stmt->execute(['o' => $orderId, 'l' => $leg, 'm' => $milestoneId, 'u' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /** The leg is cleared: no receipt may be added. */
    public static function clearedMessage(string $leg, ?array $milestone = null): string
    {
        return 'The ' . strtolower(self::label($leg, $milestone)) . ' is already cleared — no more receipts can be added to it.';
    }
}
