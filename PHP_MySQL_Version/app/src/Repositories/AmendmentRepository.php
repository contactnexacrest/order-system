<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Spec Section 8 — "PAYMENT TERMS AMENDMENT SYSTEM" (SC/AMD). Original
 * payment terms are frozen into original_terms_snapshot (JSON) at request
 * time — never re-read live afterwards, so a later change to the order
 * can't retroactively rewrite what this amendment says it changed *from*.
 */
final class AmendmentRepository
{
    public static function create(
        string $amendmentReference,
        int $orderId,
        string $reason,
        string $requestedBy,
        array $originalTermsSnapshot,
        ?float $amendedAdvancePct,
        ?float $amendedAdvanceAmount,
        ?string $amendedBalanceTerms,
        ?string $amendedBalanceTriggerOption,
        ?int $amendedBalanceDays,
        ?float $amendedBalanceAmount,
        ?string $effectiveFrom
    ): int {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO amendments
                (amendment_reference, order_id, reason, requested_by, original_terms_snapshot,
                 amended_advance_pct, amended_advance_amount, amended_balance_terms,
                 amended_balance_trigger_option, amended_balance_days, amended_balance_amount,
                 effective_from, status)
             VALUES
                (:ref, :order_id, :reason, :requested_by, :snapshot,
                 :amended_advance_pct, :amended_advance_amount, :amended_balance_terms,
                 :amended_balance_trigger_option, :amended_balance_days, :amended_balance_amount,
                 :effective_from, \'pending\')'
        );
        $stmt->execute([
            'ref'                    => $amendmentReference,
            'order_id'               => $orderId,
            'reason'                 => $reason,
            'requested_by'           => $requestedBy,
            'snapshot'               => json_encode($originalTermsSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'amended_advance_pct'    => $amendedAdvancePct,
            'amended_advance_amount' => $amendedAdvanceAmount,
            'amended_balance_terms'  => $amendedBalanceTerms,
            'amended_balance_trigger_option' => $amendedBalanceTriggerOption,
            'amended_balance_days'   => $amendedBalanceDays,
            'amended_balance_amount' => $amendedBalanceAmount,
            'effective_from'         => $effectiveFrom,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT a.*, o.order_reference, o.buyer_inquiry_ref
             FROM amendments a JOIN orders o ON o.id = a.order_id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row && $row['original_terms_snapshot']) {
            $row['original_terms_snapshot'] = json_decode((string) $row['original_terms_snapshot'], true);
        }
        return $row ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM amendments WHERE order_id = :order_id ORDER BY created_at DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function approveByMd(int $id, int $mdUserId): void
    {
        Database::connection()->prepare(
            "UPDATE amendments SET status = 'md_approved', md_approved_by = :md, md_approved_at = NOW() WHERE id = :id"
        )->execute(['md' => $mdUserId, 'id' => $id]);
    }

    public static function reject(int $id): void
    {
        Database::connection()->prepare(
            "UPDATE amendments SET status = 'rejected' WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public static function attachDocument(int $id, int $documentId): void
    {
        Database::connection()->prepare(
            'UPDATE amendments SET document_id = :document_id WHERE id = :id'
        )->execute(['document_id' => $documentId, 'id' => $id]);
    }

    public static function activate(int $id, int $signedCopyFileId): void
    {
        Database::connection()->prepare(
            "UPDATE amendments SET status = 'active', signed_copy_file_id = :file_id WHERE id = :id"
        )->execute(['file_id' => $signedCopyFileId, 'id' => $id]);
    }
}
