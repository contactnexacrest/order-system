<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section AD — a client's own "I've paid" self-report.
 * Purely informational: nothing here ever touches order_payment_status or
 * a stage gate. Staff still record the actual advance/balance/freight
 * receipt the normal way (OrderController::recordAdvancePayment() etc)
 * once they've checked the real bank statement.
 */
final class ClientPaymentReportRepository
{
    public static function create(
        int $orderId,
        string $paymentType,
        string $transactionRef,
        ?string $payerBankDetails,
        ?float $amount,
        ?string $paymentDate,
        ?int $screenshotFileId
    ): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO client_payment_reports
                (order_id, payment_type, transaction_ref, payer_bank_details, amount, payment_date, screenshot_file_id)
             VALUES
                (:order_id, :payment_type, :transaction_ref, :payer_bank_details, :amount, :payment_date, :screenshot_file_id)'
        );
        $stmt->execute([
            'order_id'           => $orderId,
            'payment_type'       => $paymentType,
            'transaction_ref'    => $transactionRef,
            'payer_bank_details' => $payerBankDetails,
            'amount'             => $amount,
            'payment_date'       => $paymentDate,
            'screenshot_file_id' => $screenshotFileId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> newest first, for both the client's own view and the staff order page */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.*, u.name AS reviewed_by_name, fs.mime_type AS screenshot_mime_type, fs.original_filename AS screenshot_filename
             FROM client_payment_reports r
             LEFT JOIN users u ON u.id = r.reviewed_by
             LEFT JOIN file_store fs ON fs.id = r.screenshot_file_id
             WHERE r.order_id = :order_id
             ORDER BY r.reported_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM client_payment_reports WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function markReviewed(int $id, int $reviewedBy): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE client_payment_reports SET status = 'reviewed', reviewed_by = :reviewed_by, reviewed_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'reviewed_by' => $reviewedBy]);
    }
}
