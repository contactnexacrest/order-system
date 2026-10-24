<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section BR — Letter of Credit + bank loan interest
 * tracking for an order (export process steps 5-1a/5-2a/5-3a). At most
 * one row per order, created lazily the first time the client's LC
 * details are saved — most orders never opt for LC at all.
 */
final class OrderLcDetailRepository
{
    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_lc_details WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{lc_number:?string, lc_file_id:?int, bank_loan_amount:?float,
     *              bank_loan_interest_rate:?float, bank_loan_interest_paid:?float,
     *              interest_cost_entry_id:?int, recorded_by:?int} $fields
     */
    public static function upsert(int $orderId, array $fields): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_lc_details
                (order_id, lc_number, lc_file_id, bank_loan_amount, bank_loan_interest_rate,
                 bank_loan_interest_paid, interest_cost_entry_id, recorded_by)
             VALUES
                (:order_id, :lc_number, :lc_file_id, :bank_loan_amount, :bank_loan_interest_rate,
                 :bank_loan_interest_paid, :interest_cost_entry_id, :recorded_by)
             ON DUPLICATE KEY UPDATE
                lc_number = VALUES(lc_number),
                lc_file_id = VALUES(lc_file_id),
                bank_loan_amount = VALUES(bank_loan_amount),
                bank_loan_interest_rate = VALUES(bank_loan_interest_rate),
                bank_loan_interest_paid = VALUES(bank_loan_interest_paid),
                interest_cost_entry_id = VALUES(interest_cost_entry_id),
                recorded_by = VALUES(recorded_by)'
        )->execute([
            'order_id' => $orderId,
            'lc_number' => $fields['lc_number'],
            'lc_file_id' => $fields['lc_file_id'],
            'bank_loan_amount' => $fields['bank_loan_amount'],
            'bank_loan_interest_rate' => $fields['bank_loan_interest_rate'],
            'bank_loan_interest_paid' => $fields['bank_loan_interest_paid'],
            'interest_cost_entry_id' => $fields['interest_cost_entry_id'],
            'recorded_by' => $fields['recorded_by'],
        ]);
    }

    /**
     * Step 5 LC payment leg (order.payment_method = 'LC'): records the
     * LC that replaces the cash advance entirely — the issuing bank, LC
     * number, LC amount, and charges billed to the client. Separate from
     * upsert() above (which records the supplementary bank-loan-interest
     * paperwork that can apply to ANY order regardless of payment
     * method) — this never touches the bank_loan_ or lc_confirmed_
     * columns, so recording/re-recording LC details never silently
     * resets an already-confirmed LC back to unconfirmed.
     */
    public static function recordLegDetails(int $orderId, ?string $lcNumber, ?string $lcBankName, ?float $lcAmount, ?float $lcChargesAmount, ?int $lcFileId, int $recordedBy): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_lc_details
                (order_id, lc_number, lc_bank_name, lc_amount, lc_charges_amount, lc_file_id, recorded_by)
             VALUES
                (:order_id, :lc_number, :lc_bank_name, :lc_amount, :lc_charges_amount, :lc_file_id, :recorded_by)
             ON DUPLICATE KEY UPDATE
                lc_number = VALUES(lc_number),
                lc_bank_name = VALUES(lc_bank_name),
                lc_amount = VALUES(lc_amount),
                lc_charges_amount = VALUES(lc_charges_amount),
                lc_file_id = COALESCE(VALUES(lc_file_id), lc_file_id),
                recorded_by = VALUES(recorded_by)'
        )->execute([
            'order_id' => $orderId,
            'lc_number' => $lcNumber,
            'lc_bank_name' => $lcBankName,
            'lc_amount' => $lcAmount,
            'lc_charges_amount' => $lcChargesAmount,
            'lc_file_id' => $lcFileId,
            'recorded_by' => $recordedBy,
        ]);
    }

    /** Step 5e (LC leg): this leg's equivalent of OrderPaymentStatusRepository::markAdvanceCleared(). */
    public static function confirmUsable(int $orderId, int $confirmedBy): void
    {
        Database::connection()->prepare(
            'UPDATE order_lc_details SET lc_confirmed_at = NOW(), lc_confirmed_by = :confirmed_by WHERE order_id = :order_id'
        )->execute(['confirmed_by' => $confirmedBy, 'order_id' => $orderId]);
    }
}
