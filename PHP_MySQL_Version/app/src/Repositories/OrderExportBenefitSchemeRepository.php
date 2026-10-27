<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Per-order eligibility decision against the export_benefit_scheme
 * dropdown options (RODTEP, Duty Drawback, RoSCTL, Other) —
 * docs/schema.sql's order_export_benefit_schemes table, added
 * 2026-10-24d. Separate from CaExportBenefitRepository/
 * ca_export_benefits, which records a real claim (amount/date, written
 * only once staff actually submit one) — this table instead always
 * carries an explicit 'pending'/'eligible'/'not_eligible' row per order
 * per scheme, written at order creation (initializeForOrder(), called
 * from OrderRepository::insertRow()), so "not eligible" is never just
 * the absence of a claim row — the exact same "never a missing row
 * standing in for not applicable" pattern as
 * OrderComplianceTaskRepository.
 */
final class OrderExportBenefitSchemeRepository
{
    public const STATUSES = [
        'pending'      => 'Pending',
        'eligible'     => 'Eligible',
        'not_eligible' => 'Not Eligible',
    ];

    /** Writes a real 'pending' row for every active scheme option — called once, at order creation. */
    public static function initializeForOrder(int $orderId): void
    {
        $pdo = Database::connection();
        $schemeNames = $pdo->query(
            "SELECT option_value FROM dropdown_options WHERE list_key = 'export_benefit_scheme' AND is_active = 1"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare(
            'INSERT INTO order_export_benefit_schemes (order_id, scheme_name, status)
             VALUES (:order_id, :scheme_name, \'pending\')
             ON DUPLICATE KEY UPDATE status = status'
        );
        foreach ($schemeNames as $schemeName) {
            $stmt->execute(['order_id' => $orderId, 'scheme_name' => $schemeName]);
        }
    }

    /** @return array<int, array<string,mixed>> one row per active scheme option, LEFT JOINed against this order's own status */
    public static function forOrder(int $orderId): array
    {
        $sql = "SELECT
                    d.option_value AS scheme_name,
                    COALESCE(oebs.status, 'pending') AS status,
                    oebs.reason,
                    oebs.resolved_at,
                    oebs.claim_skipped_at,
                    oebs.claim_skip_reason,
                    u.name AS resolved_by_name
                FROM dropdown_options d
                LEFT JOIN order_export_benefit_schemes oebs
                    ON oebs.scheme_name = d.option_value AND oebs.order_id = :order_id
                LEFT JOIN users u ON u.id = oebs.resolved_by
                WHERE d.list_key = 'export_benefit_scheme' AND d.is_active = 1
                ORDER BY d.sort_order";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll();
    }

    public static function setStatus(int $orderId, string $schemeName, string $status, ?string $reason, int $userId): void
    {
        $sql = 'INSERT INTO order_export_benefit_schemes (order_id, scheme_name, status, reason, resolved_at, resolved_by)
                VALUES (:order_id, :scheme_name, :status, :reason, NOW(), :resolved_by)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    reason = VALUES(reason),
                    resolved_at = VALUES(resolved_at),
                    resolved_by = VALUES(resolved_by)';
        Database::connection()->prepare($sql)->execute([
            'order_id'    => $orderId,
            'scheme_name' => $schemeName,
            'status'      => $status,
            'reason'      => $reason,
            'resolved_by' => $userId,
        ]);
    }

    /**
     * Owner decision (2026-10-27): at Step 13g an eligible scheme the company
     * will not claim (or whose money will never come) is skipped with a
     * reason; null $reason undoes the skip.
     */
    public static function setClaimSkipped(int $orderId, string $schemeName, ?string $reason, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE order_export_benefit_schemes
                SET claim_skipped_at = :at, claim_skipped_by = :by, claim_skip_reason = :reason
              WHERE order_id = :order_id AND scheme_name = :scheme_name'
        )->execute([
            'at' => $reason === null ? null : date('Y-m-d H:i:s'),
            'by' => $reason === null ? null : $userId,
            'reason' => $reason,
            'order_id' => $orderId,
            'scheme_name' => $schemeName,
        ]);
    }

    /** @return array{total:int, resolved:int, pending:int} resolved = eligible + not_eligible */
    public static function summaryForOrder(int $orderId): array
    {
        $rows = self::forOrder($orderId);
        $total = count($rows);
        $resolved = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'eligible' || $r['status'] === 'not_eligible') {
                $resolved++;
            }
        }
        return ['total' => $total, 'resolved' => $resolved, 'pending' => $total - $resolved];
    }
}
