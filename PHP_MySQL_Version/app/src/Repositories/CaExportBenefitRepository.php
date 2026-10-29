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

    /** Owner request (2026-10-27): fix a wrongly typed claim — every field at once; a null received amount clears the receipt. */
    public static function correct(int $id, ?string $referenceNumber, float $claimedAmount, string $claimedAt, ?float $receivedAmount, ?string $receivedAt): void
    {
        Database::connection()->prepare(
            'UPDATE ca_export_benefits
                SET reference_number = :ref, claimed_amount = :claimed, claimed_at = :claimed_at,
                    received_amount = :received, received_at = :received_at
              WHERE id = :id'
        )->execute([
            'ref' => $referenceNumber, 'claimed' => $claimedAmount, 'claimed_at' => $claimedAt,
            'received' => $receivedAmount, 'received_at' => $receivedAmount === null ? null : $receivedAt, 'id' => $id,
        ]);
    }

    /** Owner request (2026-10-27): remove a claim recorded by mistake (the full row and the reason go to the audit log). */
    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM ca_export_benefits WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Owner decision 2026-10-29: export benefits go to Zoho Books as a
     * journal when received. Every received claim (dated on or after
     * $startDate when given) and every claim already in Books — so a
     * corrected or cleared receipt is updated or removed there too. Same
     * production / test rule as the rest of the sync: in production no
     * claim of a test or sample order; in test only claims of TEST- orders.
     *
     * @return list<array<string,mixed>>
     */
    public static function forZohoSync(?string $startDate = null): array
    {
        $rows = Database::connection()->query(
            'SELECT ceb.*, o.buyer_inquiry_ref, o.company_legal_name AS buyer_name,
                    COALESCE(o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1, 0) AS is_test_or_sample
             FROM ca_export_benefits ceb
             LEFT JOIN orders o ON o.id = ceb.order_id
             LEFT JOIN clients c ON c.id = o.client_id
             WHERE (ceb.received_amount IS NOT NULL AND ceb.received_amount > 0) OR ceb.zoho_journal_id IS NOT NULL
             ORDER BY ceb.received_at, ceb.id'
        )->fetchAll();
        return array_values(array_filter($rows, static fn (array $r): bool => ($r['zoho_journal_id'] !== null || $startDate === null || (string) $r['received_at'] >= $startDate)
            && (\App\Services\ZohoGuard::isProduction()
                ? (int) $r['is_test_or_sample'] !== 1
                : str_starts_with((string) ($r['buyer_name'] ?? ''), \App\Services\ZohoGuard::TEST_PREFIX))));
    }

    public static function setZohoJournal(int $id, ?string $journalId, ?string $hash): void
    {
        Database::connection()->prepare('UPDATE ca_export_benefits SET zoho_journal_id = :journal, zoho_synced_at = ' . ($journalId !== null ? 'NOW()' : 'NULL') . ', zoho_pushed_hash = :hash WHERE id = :id')
            ->execute(['journal' => $journalId, 'hash' => $hash, 'id' => $id]);
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
