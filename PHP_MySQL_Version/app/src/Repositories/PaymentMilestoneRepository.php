<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-215 (5a): an order's own payment schedule. The first
 * step is the advance and the last the balance (order_payment_status, as
 * always); the steps in between live here, each tracked as its own payment
 * — remittance with bank reference, cleared, pushed to Zoho Books. An order
 * without rows here works exactly as before. Port:
 * paymentMilestoneRepository.js.
 */
final class PaymentMilestoneRepository
{
    public const MAX_STEPS = 4;

    /** @return list<array<string,mixed>> the order's middle steps, in order */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_payment_milestones WHERE order_id = :o ORDER BY seq');
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_payment_milestones WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public static function totalPct(int $orderId): float
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(SUM(pct), 0) FROM order_payment_milestones WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        return (float) $stmt->fetchColumn();
    }

    /** D-215: the milestone money received so far (what the buyer no longer owes). */
    public static function receivedTotal(int $orderId): float
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(SUM(received_amount), 0) FROM order_payment_milestones WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** D-215: the milestones' planned amounts (each its share of the FOB value) — what the balance leg leaves out. */
    public static function plannedTotal(int $orderId, float $fobTotal): float
    {
        return round(array_sum(array_map(static fn (array $m): float => round($fobTotal * (float) $m['pct'] / 100, 2), self::forOrder($orderId))), 2);
    }

    /**
     * QA batch 4, MON-2 (owner double-confirmed 5 Nov 2026): the order's middle payment milestones in rupees,
     * for profit, CAFIN, the ledger and "fully paid". A milestone received with the INR credited on every
     * receipt is its INR actual (plus the bank charges deducted, which the buyer paid and which are counted
     * once as the order's Bank Charges cost); received with only a rate, received × rate (estimated); not
     * received yet, its planned share of the FOB value at $fallbackRate (estimated).
     *
     * @return array{inr: float, estimated: bool, rows: list<array<string,mixed>>}
     */
    public static function inInr(int $orderId, float $fobTotal, ?float $fallbackRate): array
    {
        $total = 0.0;
        $estimated = false;
        $rows = [];
        $receipts = Database::connection()->prepare("SELECT inr_credited FROM order_payment_receipts WHERE order_id = :o AND leg = 'milestone' AND milestone_id = :m");
        foreach (self::forOrder($orderId) as $m) {
            $planned = round($fobTotal * (float) $m['pct'] / 100, 2);
            $received = $m['received_amount'] !== null ? (float) $m['received_amount'] : null;
            $rate = $m['exchange_rate'] !== null ? (float) $m['exchange_rate'] : null;
            $credited = null;
            $charges = 0.0;
            if ($received !== null) {
                $receipts->execute(['o' => $orderId, 'm' => $m['id']]);
                $credited = \App\Services\RemittanceInr::legInrActual($receipts->fetchAll(\PDO::FETCH_ASSOC));
                $charges = \App\Services\RemittanceDifference::chargesInr($orderId, 'milestone', (int) $m['id']);
            }
            if ($credited !== null) {
                $inr = round($credited + $charges, 2);
                $basis = 'actual';
            } elseif ($received !== null && $rate !== null) {
                $inr = round($received * $rate, 2);
                $basis = 'rate';
            } elseif ($received === null && $fallbackRate !== null && $planned > 0) {
                $inr = round($planned * $fallbackRate, 2);
                $basis = 'planned';
            } else {
                $inr = 0.0;
                $basis = null;
            }
            if ($basis !== 'actual' && ($planned > 0 || $received !== null)) {
                $estimated = true;
            }
            $total += $inr;
            $rows[] = $m + ['planned_amount' => $planned, 'credited_inr' => $credited, 'charges_inr' => $charges, 'inr' => $inr, 'inr_basis' => $basis];
        }
        return ['inr' => round($total, 2), 'estimated' => $estimated, 'rows' => $rows];
    }

    /**
     * QA batch 4, ORD-09 (owner double-confirmed 5 Nov 2026): what the order's milestones take off the
     * balance — one formula for the buyer pages, the commercial invoice and the staff checks. A milestone
     * received (or settled short) counts its money received plus any shortfall written off (an agreed
     * discount); a shortfall carried to the balance stays in the balance. A milestone not received yet
     * counts its planned share for the staff side ($plannedForOpen) and nothing on the buyer side, where
     * it is still owed with the balance.
     */
    public static function balanceDeduction(int $orderId, float $fobTotal, bool $plannedForOpen): float
    {
        $writtenOff = Database::connection()->prepare("SELECT COALESCE(SUM(shortfall_amount), 0) FROM order_payment_shortfalls WHERE order_id = :o AND leg = 'milestone' AND milestone_id = :m AND treatment = 'written_off'");
        $total = 0.0;
        foreach (self::forOrder($orderId) as $m) {
            $writtenOff->execute(['o' => $orderId, 'm' => $m['id']]);
            $off = (float) $writtenOff->fetchColumn();
            if ($m['received_amount'] !== null || $off > 0) {
                $total += (float) ($m['received_amount'] ?? 0) + $off;
            } elseif ($plannedForOpen) {
                $total += round($fobTotal * (float) $m['pct'] / 100, 2);
            }
        }
        return round($total, 2);
    }

    public static function anyMoneyRecorded(int $orderId): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM order_payment_milestones WHERE order_id = :o AND received_amount IS NOT NULL');
        $stmt->execute(['o' => $orderId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<array<string,mixed>> steps not yet cleared */
    public static function notCleared(int $orderId): array
    {
        return array_values(array_filter(self::forOrder($orderId), static fn (array $m): bool => $m['cleared_on'] === null));
    }

    /**
     * Replaces the order's middle steps and sets its balance % to what is
     * left (advance + steps + balance = 100). Saved together or not at all.
     *
     * @param list<array{pct: float, due_text: string}> $steps
     */
    public static function replaceSchedule(int $orderId, array $steps, float $advancePct): void
    {
        Database::transaction(static function () use ($orderId, $steps, $advancePct): void {
            $pdo = Database::connection();
            // QA Round 7, R7-N11: two saves at once used to deadlock (DELETE + INSERT); one at a time per order.
            $pdo->prepare('SELECT id FROM orders WHERE id = :o FOR UPDATE')->execute(['o' => $orderId]);
            $pdo->prepare('DELETE FROM order_payment_milestones WHERE order_id = :o')->execute(['o' => $orderId]);
            $insert = $pdo->prepare('INSERT INTO order_payment_milestones (order_id, seq, pct, due_text) VALUES (:o, :seq, :pct, :due_text)');
            $total = 0.0;
            foreach (array_values($steps) as $i => $step) {
                $insert->execute(['o' => $orderId, 'seq' => $i + 2, 'pct' => round($step['pct'], 2), 'due_text' => $step['due_text']]);
                $total += round($step['pct'], 2);
            }
            $pdo->prepare('UPDATE orders SET balance_pct = :b WHERE id = :o')->execute(['b' => round(100 - $advancePct - $total, 2), 'o' => $orderId]);
        });
    }

    public static function recordReceived(int $id, float $amount, string $receivedOn, float $rate, string $bankReference, ?string $staffNote, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_milestones
                SET received_amount = :amount, received_on = :received_on, exchange_rate = :rate, bank_reference = :ref,
                    staff_note = :note, recorded_by = :user, recorded_at = NOW()
              WHERE id = :id AND received_amount IS NULL'
        )->execute(['amount' => round($amount, 2), 'received_on' => $receivedOn, 'rate' => round($rate, 4), 'ref' => trim($bankReference),
            'note' => $staffNote !== null && trim($staffNote) !== '' ? trim($staffNote) : null, 'user' => $userId, 'id' => $id]);
    }

    public static function markCleared(int $id, string $clearedOn, int $userId): void
    {
        Database::connection()->prepare('UPDATE order_payment_milestones SET cleared_on = :d, cleared_by = :u WHERE id = :id AND cleared_on IS NULL')
            ->execute(['d' => $clearedOn, 'u' => $userId, 'id' => $id]);
    }

    public static function setZohoReference(int $id, string $reference): void
    {
        Database::connection()->prepare('UPDATE order_payment_milestones SET zoho_reference = :r WHERE id = :id')->execute(['r' => $reference, 'id' => $id]);
    }

    public static function setZohoApplied(int $id): void
    {
        Database::connection()->prepare('UPDATE order_payment_milestones SET zoho_applied_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Same rule as the advance and balance (CaRepository::legsPendingZohoSync):
     * in production, test and sample orders never go to Zoho; in test, only
     * TEST- customers do.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function zohoAllowed(array $rows): array
    {
        return array_values(array_filter($rows, static fn (array $r): bool => \App\Services\ZohoGuard::isProduction()
            ? (int) $r['is_test_or_sample'] !== 1
            : str_starts_with((string) $r['company_legal_name'], \App\Services\ZohoGuard::TEST_PREFIX)));
    }

    /** @return list<array<string,mixed>> cleared steps not yet in Zoho Books, with the order and buyer */
    public static function pendingZohoSync(): array
    {
        return self::zohoAllowed(Database::connection()->query(
            'SELECT m.*, o.buyer_inquiry_ref, o.currency_code, o.client_id, o.company_legal_name, (o.is_test_data = 1 OR o.is_sample_data = 1) AS is_test_or_sample
               FROM order_payment_milestones m
               JOIN orders o ON o.id = m.order_id
              WHERE m.cleared_on IS NOT NULL AND m.zoho_reference IS NULL
              ORDER BY m.cleared_on, m.id'
        )->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> steps in Zoho Books but not yet applied to the Commercial Invoice */
    public static function heldInZoho(): array
    {
        return self::zohoAllowed(Database::connection()->query(
            'SELECT m.*, o.buyer_inquiry_ref, o.currency_code, o.client_id, o.company_legal_name, (o.is_test_data = 1 OR o.is_sample_data = 1) AS is_test_or_sample
               FROM order_payment_milestones m
               JOIN orders o ON o.id = m.order_id
              WHERE m.zoho_reference IS NOT NULL AND m.zoho_applied_at IS NULL
              ORDER BY m.id'
        )->fetchAll(\PDO::FETCH_ASSOC));
    }
}
