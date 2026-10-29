<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Helpers\FinancialYear;

/**
 * CA / Accounting module (Phase 4) — expenses imported one-way from Zoho
 * Books. Zoho Books stays the one place expenses are entered: the import
 * inserts them and mirrors later changes and deletions made there
 * (updateFromZoho(), delete()). The only local writes are setTds() and the
 * order link, annotations that never push back to Zoho.
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

    /** @return array<int, array<string,mixed>> newest first, with the linked order's reference joined in for display */
    public static function all(): array
    {
        $stmt = Database::connection()->query(
            'SELECT ce.*, o.order_reference
             FROM ca_expenses ce
             LEFT JOIN orders o ON o.id = ce.order_id
             ORDER BY ce.expense_date DESC, ce.id DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Expenses linked to one specific order — shown on that order's own
     * detail page (e.g. ECGC insurance or a third-party inspection fee
     * paid for this shipment) alongside any linked export benefits.
     *
     * @return array<int, array<string,mixed>> newest first
     */
    public static function forOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM ca_expenses WHERE order_id = :order_id ORDER BY expense_date DESC, id DESC'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Point 2 follow-up — Zoho Books has no concept of this app's order
     * IDs, so an imported expense can only ever be linked to the order it
     * was actually incurred for as a manual, local-only step (never
     * pushed back to Zoho). Passing null unlinks it, to correct a
     * mis-link.
     */
    public static function linkToOrder(int $id, ?int $orderId): void
    {
        Database::connection()->prepare('UPDATE ca_expenses SET order_id = :order_id WHERE id = :id')
            ->execute(['order_id' => $orderId, 'id' => $id]);
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

    /**
     * TDS Payable Summary (closes a Reports/CA gap) — the per-expense TDS
     * annotation set via setTds() was never rolled up anywhere. Grouped by
     * calendar month AND quarter (Q1 Apr-Jun ... Q4 Jan-Mar) since TDS
     * returns (Form 26Q) are filed quarterly in India, while month is
     * still useful for a CA reconciling against the books month by month.
     *
     * @return array<int, array<string,mixed>> one row per month with at
     *   least one TDS-applicable expense, newest first
     */
    public static function tdsSummary(): array
    {
        $stmt = Database::connection()->query(
            "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month,
                    COUNT(*) AS expense_count,
                    COALESCE(SUM(amount), 0) AS total_expense_amount,
                    COALESCE(SUM(tds_amount), 0) AS total_tds_amount
             FROM ca_expenses
             WHERE is_tds_applicable = 1
             GROUP BY month
             ORDER BY month DESC"
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $monthNum = (int) substr($r['month'], 5, 2);
            $yearNum = (int) substr($r['month'], 0, 4);
            // Indian FY quarters: Q1 Apr-Jun, Q2 Jul-Sep, Q3 Oct-Dec, Q4 Jan-Mar.
            if ($monthNum >= 4 && $monthNum <= 6) {
                $r['quarter'] = "Q1 FY{$yearNum}-" . substr((string) ($yearNum + 1), 2, 2);
            } elseif ($monthNum >= 7 && $monthNum <= 9) {
                $r['quarter'] = "Q2 FY{$yearNum}-" . substr((string) ($yearNum + 1), 2, 2);
            } elseif ($monthNum >= 10 && $monthNum <= 12) {
                $r['quarter'] = "Q3 FY{$yearNum}-" . substr((string) ($yearNum + 1), 2, 2);
            } else {
                $r['quarter'] = "Q4 FY" . ($yearNum - 1) . '-' . substr((string) $yearNum, 2, 2);
            }
            $r['expense_count'] = (int) $r['expense_count'];
            $r['total_expense_amount'] = round((float) $r['total_expense_amount'], 2);
            $r['total_tds_amount'] = round((float) $r['total_tds_amount'], 2);
        }
        unset($r);
        return $rows;
    }

    /**
     * Owner decision 2026-10-29 (Books is the source of truth for what the
     * CA enters there): every expense imported so far, keyed by its Zoho id,
     * so the import can mirror Books' changes and deletions.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function allByZohoId(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT * FROM ca_expenses')->fetchAll() as $row) {
            $out[(string) $row['zoho_expense_id']] = $row;
        }
        return $out;
    }

    /** Books changed it: the imported copy follows (the order link and the TDS note stay). */
    public static function updateFromZoho(int $id, string $category, ?string $description, ?string $vendorName, float $amount, string $currencyCode, string $expenseDate): void
    {
        Database::connection()->prepare(
            'UPDATE ca_expenses SET category = :category, description = :description, vendor_name = :vendor_name, amount = :amount, currency_code = :currency_code, expense_date = :expense_date WHERE id = :id'
        )->execute(['category' => $category, 'description' => $description, 'vendor_name' => $vendorName, 'amount' => $amount, 'currency_code' => $currencyCode, 'expense_date' => $expenseDate, 'id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM ca_expenses WHERE id = :id')->execute(['id' => $id]);
    }

    /** Whether a bank statement line is matched to this expense (it then is not removed automatically). */
    public static function isBankMatched(int $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM ca_bank_statement_lines WHERE matched_expense_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Owner decision 2026-10-29 (order-wise import): the order whose buyer
     * enquiry reference or order reference appears, as a whole word, in a
     * Books expense's Reference # — or null when none or more than one does.
     */
    public static function orderIdForReference(?string $reference): ?int
    {
        $tokens = array_values(array_unique(array_filter(array_map(static fn (string $t): string => strtolower(trim($t)), preg_split('/[\s,;]+/', (string) $reference) ?: []))));
        if ($tokens === []) {
            return null;
        }
        $marks = implode(',', array_fill(0, count($tokens), '?'));
        $stmt = Database::connection()->prepare("SELECT id FROM orders WHERE LOWER(buyer_inquiry_ref) IN ({$marks}) OR LOWER(order_reference) IN ({$marks})");
        $stmt->execute([...$tokens, ...$tokens]);
        $ids = array_values(array_unique(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));
        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Owner decision 2026-10-29: the expenses booked in Zoho Books and linked
     * to this order, each marked whether it counts as this order's cost. It
     * does not when it has the same amount and date as a cost already
     * entered in the app for this order (an order expense, an order cost or
     * a supplier / logistics tax invoice) — a possible duplicate for the CA
     * to check — nor when it is not in rupees. The counted ones are part of
     * the order's cost, profit, cash position and CAFIN (C5) — CAFIN change
     * confirmed twice by the owner, 2026-10-29.
     *
     * @return list<array<string,mixed>> each with 'counted' (bool) and 'reason' (?string)
     */
    public static function forOrderCounted(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT amount_inr AS a, expense_date AS d FROM order_expenses WHERE order_id = :o1
             UNION ALL SELECT amount_inr, COALESCE(incurred_at, DATE(created_at)) FROM order_cost_entries WHERE order_id = :o2
             UNION ALL SELECT total_inr, bill_date FROM order_vendor_bills WHERE order_id = :o3 AND doc_kind = 'invoice'"
        );
        $stmt->execute(['o1' => $orderId, 'o2' => $orderId, 'o3' => $orderId]);
        $app = [];
        foreach ($stmt->fetchAll() as $r) {
            $app[number_format((float) $r['a'], 2, '.', '') . '|' . $r['d']] = true;
        }
        $out = [];
        foreach (self::forOrder($orderId) as $e) {
            $reason = null;
            if (strtoupper((string) $e['currency_code']) !== 'INR') {
                $reason = 'not in rupees — not counted';
            } elseif (isset($app[number_format((float) $e['amount'], 2, '.', '') . '|' . $e['expense_date']])) {
                $reason = 'possible duplicate of a cost entered in the app — not counted';
            }
            $out[] = $e + ['counted' => $reason === null, 'reason' => $reason];
        }
        return $out;
    }
}
