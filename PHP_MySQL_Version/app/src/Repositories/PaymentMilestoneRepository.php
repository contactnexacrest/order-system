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
