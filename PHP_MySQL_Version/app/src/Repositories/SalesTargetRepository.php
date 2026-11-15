<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-378 (15 Nov 2026): sales targets — the SQL of the feature.
 *
 * Reads order data only; writes only its own tables (sales_target_settings,
 * sales_target_company, sales_targets, sales_target_credits) plus
 * orders.sales_credit_user_id (the MD / ED moving an order's target credit).
 * Every query is MySQL 5.7 safe: no CTE, no window function, no GROUP BY
 * that ONLY_FULL_GROUP_BY would refuse. The rules (periods, confirmation,
 * corrections, build-up, alerts) live in SalesTargetService.
 *
 * Node twin: NODE_MySQL_Version/app/src/repositories/salesTargetRepository.js
 */
final class SalesTargetRepository
{
    // ---------------------------------------------------------------- settings

    /** @return array<string, ?string> setting_key => value (rows that exist) */
    public static function settings(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT setting_key, setting_value FROM sales_target_settings')->fetchAll() as $r) {
            $out[(string) $r['setting_key']] = $r['setting_value'] !== null ? (string) $r['setting_value'] : null;
        }
        return $out;
    }

    public static function saveSetting(string $key, ?string $value, int $userId): void
    {
        Database::connection()->prepare(
            'INSERT INTO sales_target_settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        )->execute(['k' => $key, 'v' => $value, 'u' => $userId]);
    }

    // ---------------------------------------------------------------- company target

    /** The company target row that applies on $date (greatest effective_from on or before it), or null. */
    public static function companyRowFor(string $date): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM sales_target_company WHERE effective_from <= :d ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['d' => $date]);
        return $stmt->fetch() ?: null;
    }

    /** The latest company target row entered (for the settings form), or null. */
    public static function latestCompanyRow(): ?array
    {
        return Database::connection()->query('SELECT * FROM sales_target_company ORDER BY effective_from DESC, id DESC LIMIT 1')->fetch() ?: null;
    }

    public static function insertCompanyRow(string $effectiveFrom, ?float $costs, ?float $profit, string $reason, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO sales_target_company (effective_from, costs_monthly, profit_monthly, reason, created_by) VALUES (:f, :c, :p, :r, :u)'
        )->execute(['f' => $effectiveFrom, 'c' => $costs, 'p' => $profit, 'r' => $reason, 'u' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    // ---------------------------------------------------------------- targets (designation / user / associate)

    public static function target(string $scope, int $refId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM sales_targets WHERE scope = :s AND ref_id = :r');
        $stmt->execute(['s' => $scope, 'r' => $refId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> ref_id => row, for one scope */
    public static function targetsByScope(string $scope): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM sales_targets WHERE scope = :s');
        $stmt->execute(['s' => $scope]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['ref_id']] = $r;
        }
        return $out;
    }

    /**
     * Inserts or updates the row of (scope, ref_id) with the given columns.
     *
     * @param array<string, mixed> $fields only containers, new_buyers, quotations, multiple, buildup_enabled, reason, reviewed_at
     */
    public static function saveTarget(string $scope, int $refId, array $fields, int $userId): void
    {
        $allowed = ['containers', 'new_buyers', 'quotations', 'multiple', 'buildup_enabled', 'reason', 'reviewed_at'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        $cols = array_keys($fields);
        $params = ['s' => $scope, 'r' => $refId, 'u' => $userId];
        foreach ($fields as $c => $v) {
            $params['f_' . $c] = $v;
        }
        $insertCols = implode(', ', array_merge(['scope', 'ref_id', 'updated_by'], $cols));
        $insertVals = implode(', ', array_merge([':s', ':r', ':u'], array_map(static fn (string $c): string => ':f_' . $c, $cols)));
        $updates = implode(', ', array_merge(['updated_by = VALUES(updated_by)'], array_map(static fn (string $c): string => "{$c} = VALUES({$c})", $cols)));
        Database::connection()->prepare(
            "INSERT INTO sales_targets ({$insertCols}) VALUES ({$insertVals}) ON DUPLICATE KEY UPDATE {$updates}"
        )->execute($params);
    }

    // ---------------------------------------------------------------- people

    /**
     * The person's default active department membership designation (id and title), as
     * IncentiveService::designationEligibility() picks it, or null.
     *
     * @return array{id:int, title:string}|null
     */
    public static function designationFor(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.id, d.title FROM user_department_memberships m
             JOIN department_designations d ON d.id = m.designation_id
             WHERE m.user_id = :u AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
             ORDER BY m.is_default DESC, m.id LIMIT 1'
        );
        $stmt->execute(['u' => $userId]);
        $r = $stmt->fetch();
        return $r ? ['id' => (int) $r['id'], 'title' => (string) $r['title']] : null;
    }

    /** @return list<array<string,mixed>> active designations with their department, Sales & Export first */
    public static function designations(): array
    {
        return Database::connection()->query(
            "SELECT dd.id, dd.title, d.name AS department_name, d.code AS department_code
               FROM department_designations dd JOIN departments d ON d.id = dd.department_id
              WHERE dd.is_active = 1 AND d.is_active = 1
              ORDER BY (d.code = 'SALES') DESC, d.sort_order, dd.sort_order, dd.title"
        )->fetchAll();
    }

    /** @return list<array{id:int, name:string}> active staff, by name */
    public static function activeStaff(): array
    {
        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            Database::connection()->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name, id')->fetchAll()
        );
    }

    /** @return list<int> active current members of the Sales & Export department */
    public static function salesMembers(): array
    {
        return array_map('intval', Database::connection()->query(
            "SELECT DISTINCT m.user_id FROM user_department_memberships m
               JOIN department_designations dd ON dd.id = m.designation_id
               JOIN departments d ON d.id = dd.department_id
               JOIN users u ON u.id = m.user_id
              WHERE d.code = 'SALES' AND u.is_active = 1
                AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())"
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> active users holding one of these roles, as their own role or through a current membership */
    public static function usersWithRoles(array $roleNames): array
    {
        if ($roleNames === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($roleNames), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.name IN ({$in})
             UNION
             SELECT m.user_id FROM user_department_memberships m JOIN department_designations dd ON dd.id = m.designation_id
               JOIN roles r ON r.id = dd.role_id JOIN users u ON u.id = m.user_id
              WHERE u.is_active = 1 AND r.name IN ({$in}) AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())"
        );
        $stmt->execute(array_merge(array_values($roleNames), array_values($roleNames)));
        $ids = array_values(array_unique(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));
        sort($ids);
        return $ids;
    }

    /** @return array<int, array<string,mixed>> id => users row (id, name, is_active, joined_on) */
    public static function usersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare("SELECT id, name, is_active, joined_on FROM users WHERE id IN ({$in})");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    // ---------------------------------------------------------------- order facts read by sync()

    /**
     * Every real order (no test or sample data) with the facts sync() needs: the advance receipts,
     * the LC receipt, the buyer PO and PI approval, the lost / cancelled date.
     *
     * @return list<array<string,mixed>>
     */
    public static function orderFacts(): array
    {
        return Database::connection()->query(
            "SELECT o.id, o.client_id, o.status, o.advance_pct, o.buyer_po_received_on,
                    DATE(o.frozen_at) AS frozen_on, DATE(o.lost_at) AS lost_on,
                    (SELECT MIN(r.received_on) FROM order_payment_receipts r WHERE r.order_id = o.id AND r.leg = 'advance') AS advance_receipt_on,
                    DATE(ps.advance_remittance_received_at) AS advance_status_on,
                    lc.lc_received_at AS lc_received_on,
                    (SELECT DATE(MAX(c.decided_at)) FROM order_cancellations c WHERE c.order_id = o.id AND c.status = 'approved') AS cancelled_on
               FROM orders o
               LEFT JOIN order_payment_status ps ON ps.order_id = o.id
               LEFT JOIN order_lc_details lc ON lc.order_id = o.id
              WHERE COALESCE(o.is_test_data, 0) = 0 AND COALESCE(o.is_sample_data, 0) = 0
              ORDER BY o.id"
        )->fetchAll();
    }

    /** The latest Commercial Invoice that is not a draft or superseded, or null. */
    public static function latestIssuedCi(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT d.* FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
              WHERE d.order_id = :o AND dt.code = 'CI' AND d.status NOT IN ('draft', 'superseded')
              ORDER BY d.revision_number DESC, d.id DESC LIMIT 1"
        );
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function shippingContainerCount(int $orderId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT container_count FROM order_shipping WHERE order_id = :o');
        $stmt->execute(['o' => $orderId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    // ---------------------------------------------------------------- credits

    /** @return array<int, array<string,mixed>> order_id => credit row */
    public static function credits(): array
    {
        $out = [];
        foreach (Database::connection()->query('SELECT * FROM sales_target_credits ORDER BY confirmed_on, order_id')->fetchAll() as $r) {
            $out[(int) $r['order_id']] = $r;
        }
        return $out;
    }

    public static function insertCredit(array $row): void
    {
        Database::connection()->prepare(
            'INSERT INTO sales_target_credits (order_id, client_id, confirmed_on, how, containers_confirmed, shipped_on, containers_shipped, reversed_on, reversed_why)
             VALUES (:order_id, :client_id, :confirmed_on, :how, :containers_confirmed, :shipped_on, :containers_shipped, :reversed_on, :reversed_why)'
        )->execute([
            'order_id' => $row['order_id'], 'client_id' => $row['client_id'], 'confirmed_on' => $row['confirmed_on'],
            'how' => $row['how'], 'containers_confirmed' => $row['containers_confirmed'],
            'shipped_on' => $row['shipped_on'] ?? null, 'containers_shipped' => $row['containers_shipped'] ?? null,
            'reversed_on' => $row['reversed_on'] ?? null, 'reversed_why' => $row['reversed_why'] ?? null,
        ]);
    }

    public static function markShipped(int $orderId, string $shippedOn, int $containers): void
    {
        Database::connection()->prepare(
            'UPDATE sales_target_credits SET shipped_on = :d, containers_shipped = :n WHERE order_id = :o AND shipped_on IS NULL'
        )->execute(['d' => $shippedOn, 'n' => $containers, 'o' => $orderId]);
    }

    public static function markReversed(int $orderId, string $reversedOn, string $why): void
    {
        Database::connection()->prepare(
            'UPDATE sales_target_credits SET reversed_on = :d, reversed_why = :w WHERE order_id = :o AND reversed_on IS NULL'
        )->execute(['d' => $reversedOn, 'w' => $why, 'o' => $orderId]);
    }

    /** New buyer = the first credit row (by confirmed_on, then order id) of each client; every other row is not. */
    public static function refreshNewBuyers(): void
    {
        $pdo = Database::connection();
        $first = [];
        foreach ($pdo->query('SELECT order_id, client_id FROM sales_target_credits ORDER BY confirmed_on, order_id')->fetchAll() as $r) {
            $first[(int) $r['client_id']] ??= (int) $r['order_id'];
        }
        $pdo->exec('UPDATE sales_target_credits SET is_new_buyer = 0 WHERE is_new_buyer = 1');
        $ids = array_values($first);
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $pdo->prepare("UPDATE sales_target_credits SET is_new_buyer = 1 WHERE order_id IN ({$in})")->execute($chunk);
        }
    }

    /**
     * Who gets the target credit of each order: orders.sales_credit_user_id when the MD / ED moved it,
     * else the order's Sales & Export department owner (the same owner
     * DepartmentTaskRepository::ownersForOrder() shows, read for many orders at once), else null.
     *
     * @param list<int> $orderIds
     * @return array<int, ?int> order id => user id or null (Unassigned)
     */
    public static function creditUsers(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        $out = [];
        foreach (array_chunk($orderIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connection()->prepare(
                "SELECT o.id, o.sales_credit_user_id,
                        (SELECT odo.user_id FROM order_department_owners odo JOIN departments d ON d.id = odo.department_id
                          WHERE odo.order_id = o.id AND d.code = 'SALES' AND d.is_active = 1 ORDER BY odo.user_id LIMIT 1) AS sales_owner_id
                   FROM orders o WHERE o.id IN ({$in})"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $r) {
                $uid = $r['sales_credit_user_id'] !== null ? (int) $r['sales_credit_user_id'] : ($r['sales_owner_id'] !== null ? (int) $r['sales_owner_id'] : null);
                $out[(int) $r['id']] = $uid;
            }
        }
        return $out;
    }

    public static function setCreditUser(int $orderId, ?int $userId): void
    {
        Database::connection()->prepare('UPDATE orders SET sales_credit_user_id = :u WHERE id = :o')->execute(['u' => $userId, 'o' => $orderId]);
    }

    /** @return array<int, array{id:int, order_reference:string, client_id:int, company_legal_name:?string, currency_code:?string, status:string}> */
    public static function ordersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connection()->prepare(
                "SELECT id, order_reference, client_id, company_legal_name, currency_code, status FROM orders WHERE id IN ({$in})"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $r) {
                $out[(int) $r['id']] = $r;
            }
        }
        return $out;
    }

    /** @return array<int, float> order id => the order's goods FOB value (order currency) */
    public static function fobValues(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connection()->prepare(
                "SELECT order_id, SUM(fob_value) AS fob FROM order_products WHERE is_active = 1 AND order_id IN ({$in}) GROUP BY order_id"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $r) {
                $out[(int) $r['order_id']] = (float) $r['fob'];
            }
        }
        return $out;
    }

    /**
     * Pipeline candidates: active real orders with the PI approved (frozen_at) or a Quotation e-mailed,
     * and no credit row yet (not confirmed).
     *
     * @return list<array<string,mixed>>
     */
    public static function pipelineOrders(): array
    {
        return Database::connection()->query(
            "SELECT o.id, o.order_reference, o.client_id, o.company_legal_name, o.currency_code
               FROM orders o
              WHERE o.status = 'active' AND COALESCE(o.is_test_data, 0) = 0 AND COALESCE(o.is_sample_data, 0) = 0
                AND NOT EXISTS (SELECT 1 FROM sales_target_credits c WHERE c.order_id = o.id)
                AND (o.frozen_at IS NOT NULL OR EXISTS (
                      SELECT 1 FROM email_log el JOIN documents d ON d.id = el.document_id JOIN document_types dt ON dt.id = d.document_type_id
                       WHERE d.order_id = o.id AND dt.code = 'QT' AND el.status = 'sent'))
              ORDER BY o.id"
        )->fetchAll();
    }

    /**
     * Quotations e-mailed in [from, to], per order (distinct QT documents), for the given orders.
     *
     * @return array<int, int> order id => quotations sent in the range
     */
    public static function quotationsSent(string $from, string $to): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT d.order_id, COUNT(DISTINCT el.document_id) AS n
               FROM email_log el JOIN documents d ON d.id = el.document_id JOIN document_types dt ON dt.id = d.document_type_id
               JOIN orders o ON o.id = d.order_id
              WHERE dt.code = 'QT' AND el.status = 'sent' AND DATE(el.sent_at) >= :f AND DATE(el.sent_at) <= :t
                AND COALESCE(o.is_test_data, 0) = 0 AND COALESCE(o.is_sample_data, 0) = 0
              GROUP BY d.order_id"
        );
        $stmt->execute(['f' => $from, 't' => $to]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['order_id']] = (int) $r['n'];
        }
        return $out;
    }

    // ---------------------------------------------------------------- alerts

    /** compliance_alerts_sent dedupe, as ComplianceTrackingService::once(): true the first time only. */
    public static function once(string $kind, string $ref, string $threshold, string $today): bool
    {
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO compliance_alerts_sent (alert_kind, ref_key, threshold, sent_on) VALUES (:k, :r, :t, :d)');
        $stmt->execute(['k' => $kind, 'r' => $ref, 't' => $threshold, 'd' => $today]);
        return $stmt->rowCount() > 0;
    }
}
