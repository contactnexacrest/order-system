<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Helpers\FinancialYear;

/**
 * CA / Accounting module (Phase 8) — government export benefit/incentive
 * claims (RODTEP and any other scheme in dropdown_options('export_benefit_scheme')).
 * Unlike CaExpenseRepository, this data is entered locally (there is no
 * Zoho Books import for it — see schema.sql's comment on ca_export_benefits).
 */
final class CaExportBenefitRepository
{
    public static function record(
        ?int $orderId,
        string $schemeName,
        ?string $referenceNumber,
        float $claimedAmount,
        string $claimedAt,
        string $currencyCode,
        ?string $notes,
        int $recordedBy
    ): int {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO ca_export_benefits
                (order_id, scheme_name, reference_number, claimed_amount, claimed_at, currency_code, notes, recorded_by)
             VALUES
                (:order_id, :scheme_name, :reference_number, :claimed_amount, :claimed_at, :currency_code, :notes, :recorded_by)'
        )->execute([
            'order_id' => $orderId,
            'scheme_name' => $schemeName,
            'reference_number' => $referenceNumber,
            'claimed_amount' => $claimedAmount,
            'claimed_at' => $claimedAt,
            'currency_code' => $currencyCode,
            'notes' => $notes,
            'recorded_by' => $recordedBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ceb.*, o.order_reference
             FROM ca_export_benefits ceb
             LEFT JOIN orders o ON o.id = ceb.order_id
             WHERE ceb.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> newest first, with the order reference joined in for display */
    public static function all(): array
    {
        $stmt = Database::connection()->query(
            'SELECT ceb.*, o.order_reference
             FROM ca_export_benefits ceb
             LEFT JOIN orders o ON o.id = ceb.order_id
             ORDER BY ceb.claimed_at DESC, ceb.id DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Benefits linked to one specific order — shown on that order's own
     * detail page (e.g. a RODTEP claim earned by this shipment) alongside
     * any linked expenses.
     *
     * @return array<int, array<string,mixed>> newest first
     */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ca_export_benefits WHERE order_id = :order_id ORDER BY claimed_at DESC, id DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function markReceived(int $id, float $receivedAmount, string $receivedAt): void
    {
        Database::connection()->prepare(
            'UPDATE ca_export_benefits SET received_amount = :amount, received_at = :received_at WHERE id = :id'
        )->execute(['amount' => $receivedAmount, 'received_at' => $receivedAt, 'id' => $id]);
    }

    public static function totalClaimed(): float
    {
        return (float) Database::connection()->query('SELECT COALESCE(SUM(claimed_amount), 0) FROM ca_export_benefits')->fetchColumn();
    }

    public static function totalReceived(): float
    {
        return (float) Database::connection()->query('SELECT COALESCE(SUM(received_amount), 0) FROM ca_export_benefits')->fetchColumn();
    }

    /** FY labels with at least one claim — for the FY Lock page's picker, same pattern as CaExpenseRepository. */
    public static function availableFinancialYears(): array
    {
        $dates = Database::connection()->query('SELECT claimed_at FROM ca_export_benefits')->fetchAll(\PDO::FETCH_COLUMN);
        return FinancialYear::labelsPresentIn($dates);
    }
}
