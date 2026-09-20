<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderPaymentStatusRepository
{
    public static function initializeForOrder(int $orderId): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_payment_status (order_id) VALUES (:order_id)'
        )->execute(['order_id' => $orderId]);
    }

    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_payment_status WHERE order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function recordAdvanceReceived(int $orderId, float $amount, string $receivedAt): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET advance_amount = :amount, advance_remittance_received_at = :received_at
             WHERE order_id = :order_id'
        )->execute(['amount' => $amount, 'received_at' => $receivedAt, 'order_id' => $orderId]);
    }

    public static function markAdvanceCleared(int $orderId, string $clearedAt, int $clearedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET advance_cleared_at = :cleared_at, advance_cleared_by = :cleared_by
             WHERE order_id = :order_id'
        )->execute(['cleared_at' => $clearedAt, 'cleared_by' => $clearedBy, 'order_id' => $orderId]);
    }

    public static function setBalanceAmount(int $orderId, float $balanceAmount, ?string $balanceDueDate): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status SET balance_amount = :amount, balance_due_date = :due_date WHERE order_id = :order_id'
        )->execute(['amount' => $balanceAmount, 'due_date' => $balanceDueDate, 'order_id' => $orderId]);
    }

    /** CFR/CIF only — Stage 6 (Freight Debit Note). Skipped entirely for FOB orders. */
    public static function recordFreightReceived(int $orderId, float $amount, string $receivedAt): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET freight_amount = :amount, freight_remittance_received_at = :received_at
             WHERE order_id = :order_id'
        )->execute(['amount' => $amount, 'received_at' => $receivedAt, 'order_id' => $orderId]);
    }

    /** Stage 6->7 gate: freight & insurance payment cleared in NexaCrest's bank account. */
    public static function markFreightCleared(int $orderId, string $clearedAt, int $clearedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET freight_cleared_at = :cleared_at, freight_cleared_by = :cleared_by
             WHERE order_id = :order_id'
        )->execute(['cleared_at' => $clearedAt, 'cleared_by' => $clearedBy, 'order_id' => $orderId]);
    }

    public static function recordBalanceReceived(int $orderId, float $amount, string $receivedAt): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET balance_amount = :amount, balance_remittance_received_at = :received_at
             WHERE order_id = :order_id'
        )->execute(['amount' => $amount, 'received_at' => $receivedAt, 'order_id' => $orderId]);
    }

    /** Stage 8->9 gate: 60% balance payment cleared — the trigger for COO prep / document despatch / closure. */
    public static function markBalanceCleared(int $orderId, string $clearedAt, int $clearedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET balance_cleared_at = :cleared_at, balance_cleared_by = :cleared_by
             WHERE order_id = :order_id'
        )->execute(['cleared_at' => $clearedAt, 'cleared_by' => $clearedBy, 'order_id' => $orderId]);
    }
}
