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

    // ----------------------------------------------------------------
    // CA / Accounting module (Phase 1) — INR actual settlement amounts.
    // Gated on inr_actual_edit/inr_actual_delete in OrderController, not
    // tied to the Mark Cleared actions above (see schema.sql comment on
    // order_payment_status for why they're deliberately separate actions).
    // ----------------------------------------------------------------

    public static function setAdvanceInrActual(int $orderId, float $amount, int $recordedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET advance_inr_actual = :amount, advance_inr_actual_recorded_at = NOW(), advance_inr_actual_recorded_by = :recorded_by
             WHERE order_id = :order_id'
        )->execute(['amount' => $amount, 'recorded_by' => $recordedBy, 'order_id' => $orderId]);
    }

    public static function clearAdvanceInrActual(int $orderId): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET advance_inr_actual = NULL, advance_inr_actual_recorded_at = NULL, advance_inr_actual_recorded_by = NULL
             WHERE order_id = :order_id'
        )->execute(['order_id' => $orderId]);
    }

    public static function setBalanceInrActual(int $orderId, float $amount, int $recordedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET balance_inr_actual = :amount, balance_inr_actual_recorded_at = NOW(), balance_inr_actual_recorded_by = :recorded_by
             WHERE order_id = :order_id'
        )->execute(['amount' => $amount, 'recorded_by' => $recordedBy, 'order_id' => $orderId]);
    }

    public static function clearBalanceInrActual(int $orderId): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET balance_inr_actual = NULL, balance_inr_actual_recorded_at = NULL, balance_inr_actual_recorded_by = NULL
             WHERE order_id = :order_id'
        )->execute(['order_id' => $orderId]);
    }

    public static function setFreightInrActual(int $orderId, float $amount, int $recordedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET freight_inr_actual = :amount, freight_inr_actual_recorded_at = NOW(), freight_inr_actual_recorded_by = :recorded_by
             WHERE order_id = :order_id'
        )->execute(['amount' => $amount, 'recorded_by' => $recordedBy, 'order_id' => $orderId]);
    }

    public static function clearFreightInrActual(int $orderId): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET freight_inr_actual = NULL, freight_inr_actual_recorded_at = NULL, freight_inr_actual_recorded_by = NULL
             WHERE order_id = :order_id'
        )->execute(['order_id' => $orderId]);
    }

    // ----------------------------------------------------------------
    // CA / Accounting module (Phase 2) — assumed exchange rate (for the
    // forex gain/loss shown in the register) and per-leg FIRC/eBRC
    // references. Same inr_actual_edit gating as Phase 1's INR-actual
    // fields — this is the same CA financial-data set, not a new
    // permission tier.
    // ----------------------------------------------------------------

    public static function setAssumedExchangeRate(int $orderId, float $rate, int $setBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status
             SET assumed_exchange_rate = :rate, assumed_exchange_rate_set_at = NOW(), assumed_exchange_rate_set_by = :set_by
             WHERE order_id = :order_id'
        )->execute(['rate' => $rate, 'set_by' => $setBy, 'order_id' => $orderId]);
    }

    public static function setAdvanceFirc(int $orderId, string $reference, string $receivedAt): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status SET advance_firc_reference = :ref, advance_firc_received_at = :received_at WHERE order_id = :order_id'
        )->execute(['ref' => $reference, 'received_at' => $receivedAt, 'order_id' => $orderId]);
    }

    public static function setBalanceFirc(int $orderId, string $reference, string $receivedAt): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status SET balance_firc_reference = :ref, balance_firc_received_at = :received_at WHERE order_id = :order_id'
        )->execute(['ref' => $reference, 'received_at' => $receivedAt, 'order_id' => $orderId]);
    }

    public static function setFreightFirc(int $orderId, string $reference, string $receivedAt): void
    {
        Database::connection()->prepare(
            'UPDATE order_payment_status SET freight_firc_reference = :ref, freight_firc_received_at = :received_at WHERE order_id = :order_id'
        )->execute(['ref' => $reference, 'received_at' => $receivedAt, 'order_id' => $orderId]);
    }

    /**
     * Every order with at least one cleared settlement leg, for the CA
     * module's settlement register (CaRepository). Joined here rather than
     * in CaRepository since this is still just order_payment_status data —
     * CaRepository flattens the three legs into rows.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function clearedSettlements(): array
    {
        return Database::connection()->query(
            "SELECT ops.*, o.buyer_inquiry_ref, o.client_id, c.company_legal_name, cur.code AS currency_code
             FROM order_payment_status ops
             JOIN orders o ON o.id = ops.order_id
             JOIN clients c ON c.id = o.client_id
             JOIN currencies cur ON cur.id = o.currency_id
             WHERE ops.advance_cleared_at IS NOT NULL
                OR ops.balance_cleared_at IS NOT NULL
                OR ops.freight_cleared_at IS NOT NULL
             ORDER BY o.id DESC"
        )->fetchAll();
    }
}
