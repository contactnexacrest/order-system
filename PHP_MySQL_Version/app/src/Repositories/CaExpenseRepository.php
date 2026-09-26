<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Helpers\FinancialYear;

/**
 * CA / Accounting module (Phase 4) — expenses imported one-way from Zoho
 * Books. No create/update path for the expense data itself (Zoho Books
 * stays the one place expenses are entered) — the only write this
 * repository exposes besides the import insert is setTds(), a local-only
 * annotation that never pushes back to Zoho.
 */
final class CaExpenseRepository
{
    public static function existsByZohoId(string $zohoExpenseId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM ca_expenses WHERE zoho_expense_id = :id');
        $stmt->execute(['id' => $zohoExpenseId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function insert(
        string $zohoExpenseId,
        string $category,
        ?string $description,
        ?string $vendorName,
        float $amount,
        string $currencyCode,
        string $expenseDate
    ): int {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO ca_expenses (zoho_expense_id, category, description, vendor_name, amount, currency_code, expense_date)
             VALUES (:zoho_expense_id, :category, :description, :vendor_name, :amount, :currency_code, :expense_date)'
        )->execute([
            'zoho_expense_id' => $zohoExpenseId,
            'category' => $category,
            'description' => $description,
            'vendor_name' => $vendorName,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'expense_date' => $expenseDate,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> newest first */
    public static function all(): array
    {
        $stmt = Database::connection()->query('SELECT * FROM ca_expenses ORDER BY expense_date DESC, id DESC');
        return $stmt->fetchAll();
    }

    /** All-time total of every imported expense — used by the reconciliation summary. */
    public static function totalAll(): float
    {
        return (float) Database::connection()->query('SELECT COALESCE(SUM(amount), 0) FROM ca_expenses')->fetchColumn();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ca_expenses WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** FY labels with at least one imported expense — for the FY Lock page's picker (Phase 6). */
    public static function availableFinancialYears(): array
    {
        $dates = Database::connection()->query('SELECT expense_date FROM ca_expenses')->fetchAll(\PDO::FETCH_COLUMN);
        return FinancialYear::labelsPresentIn($dates);
    }

    /** Local-only TDS annotation — never written back to Zoho Books. */
    public static function setTds(int $id, bool $isTdsApplicable, ?float $tdsAmount, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE ca_expenses
             SET is_tds_applicable = :is_tds, tds_amount = :tds_amount, tds_set_by = :user_id, tds_set_at = NOW()
             WHERE id = :id'
        )->execute([
            'is_tds' => $isTdsApplicable ? 1 : 0,
            'tds_amount' => $tdsAmount,
            'user_id' => $userId,
            'id' => $id,
        ]);
    }
}
