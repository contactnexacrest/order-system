<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Helpers\FinancialYear;

/**
 * CA / Accounting module (Phase 6) — year-end financial year lock. Once a
 * CA closes a financial year, every CA-module write path that would touch
 * that year's data (INR actuals, FIRC/eBRC references, the assumed
 * exchange rate, expense TDS annotations, bank-statement matching) is
 * blocked, so a closed year's numbers can never be quietly changed after
 * the fact.
 *
 * A lock is a row with unlocked_at IS NULL. Unlocking never deletes the
 * row — it stamps unlocked_at/unlocked_by/unlock_reason — and re-locking
 * the same year inserts a fresh row, so the full lock/unlock history for
 * every year stays on record (the same append-only spirit as
 * zoho_sync_log, not a single mutable status flag).
 */
final class CaFyLockRepository
{
    /** @return array<int, string> every financial year currently locked, e.g. ['2025-26'] */
    public static function lockedYears(): array
    {
        $rows = Database::connection()->query(
            'SELECT DISTINCT financial_year FROM ca_fy_locks WHERE unlocked_at IS NULL'
        )->fetchAll();
        return array_map(static fn(array $r) => (string) $r['financial_year'], $rows);
    }

    public static function isLocked(string $financialYear): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM ca_fy_locks WHERE financial_year = :fy AND unlocked_at IS NULL LIMIT 1'
        );
        $stmt->execute(['fy' => $financialYear]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param string|null $date any date string (e.g. a leg's cleared_at or an
     *        expense's expense_date) — null (not yet cleared/dated) is never
     *        locked, since there's nothing to have closed yet.
     * @return string|null a ready-to-display message if the date's FY is
     *         locked, otherwise null.
     */
    public static function lockMessageForDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $fy = FinancialYear::label($date);
        if (!self::isLocked($fy)) {
            return null;
        }
        return "This falls in FY {$fy}, which is locked for CA data entry. A CA module admin must reopen it first (CA / Accounting \xe2\x86\x92 Financial Year Lock) if this is a genuine correction.";
    }

    /** @return array<int, array<string,mixed>> every lock/unlock event, newest first */
    public static function history(): array
    {
        return Database::connection()->query(
            'SELECT * FROM ca_fy_locks ORDER BY locked_at DESC, id DESC'
        )->fetchAll();
    }

    public static function lock(string $financialYear, int $lockedBy): void
    {
        Database::connection()->prepare(
            'INSERT INTO ca_fy_locks (financial_year, locked_by) VALUES (:fy, :locked_by)'
        )->execute(['fy' => $financialYear, 'locked_by' => $lockedBy]);
    }

    public static function unlock(string $financialYear, int $unlockedBy, ?string $reason): void
    {
        Database::connection()->prepare(
            'UPDATE ca_fy_locks
             SET unlocked_at = NOW(), unlocked_by = :unlocked_by, unlock_reason = :reason
             WHERE financial_year = :fy AND unlocked_at IS NULL'
        )->execute(['unlocked_by' => $unlockedBy, 'reason' => $reason, 'fy' => $financialYear]);
    }
}
