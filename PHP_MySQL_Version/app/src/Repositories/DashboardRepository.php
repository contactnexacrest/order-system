<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Spec Section 16 — "DASHBOARD: Active orders by stage, awaiting
 * review/approval, overdue payments, LUT/RCMC expiry warnings, recent
 * activity, search by client/ref number." One repository, one query per
 * widget — kept separate from OrderRepository/AuditLogRepository etc.
 * since these are dashboard-shaped aggregate reads, not entity CRUD.
 */
final class DashboardRepository
{
    /** @return array<int, array<string,mixed>> stage_name, stage_slug, order_count — active orders only */
    public static function activeOrdersByStage(): array
    {
        $stmt = Database::connection()->query(
            "SELECT sm.stage_name, sm.stage_slug, sm.sequence, COUNT(o.id) AS order_count
             FROM stages_master sm
             LEFT JOIN orders o ON o.current_stage_id = sm.id AND o.status = 'active'
             GROUP BY sm.id, sm.stage_name, sm.stage_slug, sm.sequence
             ORDER BY sm.sequence"
        );
        return $stmt->fetchAll();
    }

    public static function activeOrderCount(): int
    {
        $stmt = Database::connection()->query("SELECT COUNT(*) AS c FROM orders WHERE status = 'active'");
        return (int) $stmt->fetch()['c'];
    }

    /**
     * "Awaiting review/approval" — three separate queues the spec covers
     * under Sections 9/10/8 (document review, email-send approval,
     * amendment MD-approval) rolled into one dashboard count each, since
     * they're different workflows with different approvers, not one list.
     */
    public static function pendingReviewCount(?int $reviewerId = null): int
    {
        $sql = "SELECT COUNT(*) AS c FROM document_reviews WHERE status = 'pending'";
        $params = [];
        if ($reviewerId !== null) {
            $sql .= ' AND reviewer_id = :reviewer_id';
            $params['reviewer_id'] = $reviewerId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetch()['c'];
    }

    public static function pendingEmailApprovalCount(): int
    {
        $stmt = Database::connection()->query("SELECT COUNT(*) AS c FROM email_log WHERE status = 'pending_approval'");
        return (int) $stmt->fetch()['c'];
    }

    public static function pendingAmendmentApprovalCount(): int
    {
        $stmt = Database::connection()->query("SELECT COUNT(*) AS c FROM amendments WHERE status = 'pending'");
        return (int) $stmt->fetch()['c'];
    }

    /**
     * Overdue payments — balance (per order_payment_status.balance_due_date,
     * the actual computed due date the app already tracks) and freight
     * (same "FDN issued, still not cleared, past the configured overdue
     * window" definition check_alerts.php's cron already uses — duplicated
     * here as a read-only dashboard view of the same condition, not a
     * second source of truth for what "overdue" means).
     *
     * @return array<int, array<string,mixed>>
     */
    public static function overdueBalancePayments(): array
    {
        $stmt = Database::connection()->query(
            "SELECT o.id AS order_id, o.order_reference, c.company_legal_name,
                    ops.balance_due_date, ops.balance_amount
             FROM order_payment_status ops
             JOIN orders o ON o.id = ops.order_id
             JOIN clients c ON c.id = o.client_id
             WHERE ops.balance_due_date IS NOT NULL
               AND ops.balance_cleared_at IS NULL
               AND ops.balance_due_date < CURDATE()
               AND o.status = 'active'
             ORDER BY ops.balance_due_date ASC"
        );
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function overdueFreightPayments(): array
    {
        $overdueDays = (int) (CompanySettingsRepository::get('fdn_overdue_days_c') ?? '7');
        $stmt = Database::connection()->prepare(
            "SELECT o.id AS order_id, o.order_reference, c.company_legal_name, d.generated_at AS fdn_generated_at
             FROM order_freight of_
             JOIN documents d ON d.id = of_.fdn_document_id
             JOIN orders o ON o.id = of_.order_id
             JOIN clients c ON c.id = o.client_id
             LEFT JOIN order_payment_status ps ON ps.order_id = o.id
             WHERE of_.fdn_document_id IS NOT NULL
               AND ps.freight_cleared_at IS NULL
               AND DATE(d.generated_at) <= DATE_SUB(CURDATE(), INTERVAL :days DAY)
               AND o.status = 'active'
             ORDER BY d.generated_at ASC"
        );
        $stmt->execute(['days' => $overdueDays]);
        return $stmt->fetchAll();
    }

    /**
     * LUT/RCMC expiry warnings — mirrors check_alerts.php's own threshold
     * logic (company_settings) so the dashboard shows the same "how many
     * days left" the cron alerts on, without waiting for the next cron
     * tick to see it.
     *
     * @return array{lut: array<string,mixed>|null, rcmc: array<string,mixed>|null}
     */
    public static function complianceExpiryWarnings(): array
    {
        $result = ['lut' => null, 'rcmc' => null];
        $today = new \DateTimeImmutable('today');

        $lutExpiry = CompanySettingsRepository::get('lut_expiry_date');
        if ($lutExpiry) {
            $daysLeft = $today->diff(new \DateTimeImmutable($lutExpiry))->days * (new \DateTimeImmutable($lutExpiry) >= $today ? 1 : -1);
            $alertDays = (int) (CompanySettingsRepository::get('lut_alert_days_x') ?? '30');
            if ($daysLeft <= $alertDays) {
                $result['lut'] = ['expiry_date' => $lutExpiry, 'days_left' => $daysLeft];
            }
        }

        $rcmcExpiry = CompanySettingsRepository::get('rcmc_valid_until');
        if ($rcmcExpiry) {
            $daysLeft = $today->diff(new \DateTimeImmutable($rcmcExpiry))->days * (new \DateTimeImmutable($rcmcExpiry) >= $today ? 1 : -1);
            $alertDays = (int) (CompanySettingsRepository::get('rcmc_alert_days_a') ?? '60');
            if ($daysLeft <= $alertDays) {
                $result['rcmc'] = ['expiry_date' => $rcmcExpiry, 'days_left' => $daysLeft];
            }
        }

        return $result;
    }

    /**
     * Search by client/ref number — spec's own phrase from Section 16.
     * Matches client company name, client unique number, or order
     * reference, case-insensitively, partial match.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function search(string $term): array
    {
        $like = '%' . $term . '%';
        $stmt = Database::connection()->prepare(
            "SELECT o.id AS order_id, o.order_reference, o.status, c.company_legal_name, c.client_unique_number,
                    sm.stage_name AS current_stage_name
             FROM orders o
             JOIN clients c ON c.id = o.client_id
             LEFT JOIN stages_master sm ON sm.id = o.current_stage_id
             WHERE o.order_reference LIKE :term1
                OR c.client_unique_number LIKE :term2
                OR c.company_legal_name LIKE :term3
             ORDER BY o.created_at DESC
             LIMIT 50"
        );
        $stmt->execute(['term1' => $like, 'term2' => $like, 'term3' => $like]);
        return $stmt->fetchAll();
    }
}
