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

    /**
     * Owner decision 2026-10-29: the money the bank actually paid under the
     * LC — date, amount in the order currency, rupees credited and bank
     * charges deducted. It goes to Zoho Books as a customer payment applied
     * to the CI (CaSyncService), so it can be corrected only until then.
     */
    public static function recordReceived(int $orderId, string $receivedAt, float $amount, float $inrCredited, float $bankChargesInr, int $userId, ?string $bankReference = null): void
    {
        Database::connection()->prepare(
            'UPDATE order_lc_details
                SET lc_received_at = :received_at, lc_received_amount = :amount, lc_inr_credited = :inr,
                    lc_bank_charges_inr = :charges, lc_received_by = :user_id, lc_bank_reference = :ref
              WHERE order_id = :order_id AND lc_zoho_synced_at IS NULL'
        )->execute(['received_at' => $receivedAt, 'amount' => round($amount, 2), 'inr' => round($inrCredited, 2), 'charges' => round($bankChargesInr, 2), 'user_id' => $userId,
            'ref' => $bankReference !== null && trim($bankReference) !== '' ? trim($bankReference) : null, 'order_id' => $orderId]);
    }

    /**
     * Owner decision 2026-10-29: every LC payment received, for the Zoho
     * sync — not yet in Books, or in Books but not yet applied to the CI.
     * Same production / test rule as the other payments.
     *
     * @return list<array<string,mixed>>
     */
    public static function receiptsForZohoSync(): array
    {
        $rows = Database::connection()->query(
            'SELECT l.*, o.client_id, o.buyer_inquiry_ref, o.company_legal_name, o.currency_code,
                    (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
             FROM order_lc_details l
             JOIN orders o ON o.id = l.order_id
             JOIN clients c ON c.id = o.client_id
             WHERE l.lc_received_at IS NOT NULL AND (l.lc_zoho_synced_at IS NULL OR l.lc_zoho_applied_at IS NULL)
             ORDER BY l.lc_received_at, l.order_id'
        )->fetchAll();
        return array_values(array_filter($rows, static fn (array $r): bool => \App\Services\ZohoGuard::isProduction()
            ? (int) $r['is_test_or_sample'] !== 1
            : str_starts_with((string) $r['company_legal_name'], \App\Services\ZohoGuard::TEST_PREFIX)));
    }

    public static function setZohoSynced(int $orderId, string $paymentId, bool $applied): void
    {
        Database::connection()->prepare('UPDATE order_lc_details SET lc_zoho_payment_id = :id, lc_zoho_synced_at = NOW(), lc_zoho_applied_at = ' . ($applied ? 'NOW()' : 'NULL') . ' WHERE order_id = :order_id')
            ->execute(['id' => $paymentId, 'order_id' => $orderId]);
    }

    public static function setZohoApplied(int $orderId): void
    {
        Database::connection()->prepare('UPDATE order_lc_details SET lc_zoho_applied_at = NOW() WHERE order_id = :order_id')->execute(['order_id' => $orderId]);
    }

    /** The clean-up script removed this payment from Books: forget it here too. */
    public static function forgetZohoPayment(string $paymentId): void
    {
        Database::connection()->prepare('UPDATE order_lc_details SET lc_zoho_payment_id = NULL, lc_zoho_synced_at = NULL, lc_zoho_applied_at = NULL WHERE lc_zoho_payment_id = :id')->execute(['id' => $paymentId]);
    }

    /**
     * Owner decision 2026-10-29 (CAFIN change confirmed twice): every LC
     * payment received, for the INR Settlement Register.
     *
     * @return list<array<string,mixed>>
     */
    public static function receivedForRegister(): array
    {
        return Database::connection()->query(
            'SELECT l.*, o.client_id, o.buyer_inquiry_ref, o.company_legal_name, o.currency_code,
                    (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
             FROM order_lc_details l
             JOIN orders o ON o.id = l.order_id
             JOIN clients c ON c.id = o.client_id
             WHERE l.lc_received_at IS NOT NULL AND l.lc_received_amount > 0'
        )->fetchAll();
    }

    /** The LC payment's FIRC / eBRC realization proof (owner decision 2026-10-29). */
    public static function setFirc(int $orderId, string $reference, string $receivedAt): void
    {
        Database::connection()->prepare('UPDATE order_lc_details SET lc_firc_reference = :ref, lc_firc_received_at = :received_at WHERE order_id = :order_id')
            ->execute(['ref' => $reference, 'received_at' => $receivedAt, 'order_id' => $orderId]);
    }
}
