<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * CA / Accounting module (Phase 5) — bank statement lines imported from a
 * CSV export, matched one at a time to either a revenue settlement leg or
 * a ca_expenses row. See schema.sql's comment on ca_bank_statement_lines
 * for why a line can hold at most one kind of match.
 */
final class CaBankStatementRepository
{
    /**
     * Inserts one parsed line, skipping it silently if its hash already
     * exists (a duplicate from a repeat/overlapping upload) — this is the
     * one place de-duplication is enforced, via the line_hash UNIQUE key.
     *
     * @return bool true if inserted, false if it was a duplicate
     */
    public static function insertLine(
        string $lineHash,
        string $transactionDate,
        ?string $description,
        ?string $reference,
        ?float $credit,
        ?float $debit,
        int $importedBy
    ): bool {
        try {
            Database::connection()->prepare(
                'INSERT INTO ca_bank_statement_lines
                    (line_hash, transaction_date, description, reference, credit_amount, debit_amount, imported_by)
                 VALUES
                    (:line_hash, :transaction_date, :description, :reference, :credit, :debit, :imported_by)'
            )->execute([
                'line_hash' => $lineHash,
                'transaction_date' => $transactionDate,
                'description' => $description,
                'reference' => $reference,
                'credit' => $credit,
                'debit' => $debit,
                'imported_by' => $importedBy,
            ]);
            return true;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return false; // duplicate line_hash — already imported
            }
            throw $e;
        }
    }

    /**
     * @return array<int, array<string,mixed>> newest first, with matched
     *         order/expense info joined in for display. Also carries
     *         matched_leg_cleared_at / matched_expense_date (whichever
     *         applies, the other is always null) so the view can tell
     *         whether an existing match falls in a locked financial year
     *         (Phase 6) without a second query per row.
     */
    public static function all(): array
    {
        return Database::connection()->query(
            "SELECT bsl.*, o.buyer_inquiry_ref AS matched_order_ref, ce.category AS matched_expense_category, ce.vendor_name AS matched_expense_vendor,
                CASE bsl.matched_leg
                    WHEN 'advance' THEN ops.advance_cleared_at
                    WHEN 'balance' THEN ops.balance_cleared_at
                    WHEN 'freight' THEN ops.freight_cleared_at
                END AS matched_leg_cleared_at,
                ce.expense_date AS matched_expense_date
             FROM ca_bank_statement_lines bsl
             LEFT JOIN orders o ON o.id = bsl.matched_order_id
             LEFT JOIN order_payment_status ops ON ops.order_id = bsl.matched_order_id
             LEFT JOIN ca_expenses ce ON ce.id = bsl.matched_expense_id
             ORDER BY bsl.transaction_date DESC, bsl.id DESC"
        )->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM ca_bank_statement_lines WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, string> 'orderId:leg' keys currently matched to a bank line */
    public static function matchedRevenueKeys(): array
    {
        $rows = Database::connection()->query(
            'SELECT matched_order_id, matched_leg FROM ca_bank_statement_lines WHERE matched_order_id IS NOT NULL'
        )->fetchAll();
        return array_map(static fn(array $r): string => $r['matched_order_id'] . ':' . $r['matched_leg'], $rows);
    }

    /** @return array<int, int> ca_expenses ids currently matched to a bank line */
    public static function matchedExpenseIds(): array
    {
        $stmt = Database::connection()->query(
            'SELECT matched_expense_id FROM ca_bank_statement_lines WHERE matched_expense_id IS NOT NULL'
        );
        return array_map('intval', array_column($stmt->fetchAll(), 'matched_expense_id'));
    }

    public static function matchToRevenue(int $lineId, int $orderId, string $leg, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE ca_bank_statement_lines
             SET matched_order_id = :order_id, matched_leg = :leg, matched_expense_id = NULL, matched_by = :user_id, matched_at = NOW()
             WHERE id = :id'
        )->execute(['order_id' => $orderId, 'leg' => $leg, 'user_id' => $userId, 'id' => $lineId]);
    }

    public static function matchToExpense(int $lineId, int $expenseId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE ca_bank_statement_lines
             SET matched_expense_id = :expense_id, matched_order_id = NULL, matched_leg = NULL, matched_by = :user_id, matched_at = NOW()
             WHERE id = :id'
        )->execute(['expense_id' => $expenseId, 'user_id' => $userId, 'id' => $lineId]);
    }

    public static function unmatch(int $lineId): void
    {
        Database::connection()->prepare(
            'UPDATE ca_bank_statement_lines
             SET matched_order_id = NULL, matched_leg = NULL, matched_expense_id = NULL, matched_by = NULL, matched_at = NULL
             WHERE id = :id'
        )->execute(['id' => $lineId]);
    }

    /** @return array{totalCredit:float, totalDebit:float, matchedCredit:float, matchedDebit:float, lineCount:int} all-time totals across every imported line */
    public static function totals(): array
    {
        $row = Database::connection()->query(
            "SELECT
                COALESCE(SUM(credit_amount), 0) AS total_credit,
                COALESCE(SUM(debit_amount), 0) AS total_debit,
                COALESCE(SUM(CASE WHEN matched_order_id IS NOT NULL THEN credit_amount ELSE 0 END), 0) AS matched_credit,
                COALESCE(SUM(CASE WHEN matched_expense_id IS NOT NULL THEN debit_amount ELSE 0 END), 0) AS matched_debit,
                COUNT(*) AS line_count
             FROM ca_bank_statement_lines"
        )->fetch();

        return [
            'totalCredit' => (float) $row['total_credit'],
            'totalDebit' => (float) $row['total_debit'],
            'matchedCredit' => (float) $row['matched_credit'],
            'matchedDebit' => (float) $row['matched_debit'],
            'lineCount' => (int) $row['line_count'],
        ];
    }
}
