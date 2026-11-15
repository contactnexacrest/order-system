<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decision D-379: the tables behind App\Services\ReportAccess — role_report_access (a role's
 * level per report; no row = off) and user_report_extras (a person's higher level for one report,
 * with the reason) — plus the department and order lookups the report scope needs.
 * MySQL 5.7-safe: no window functions, no CTEs; integer id lists are inlined after intval().
 *
 * Node twin: NODE_MySQL_Version/app/src/repositories/reportAccessRepository.js
 */
final class ReportAccessRepository
{
    /** @return array<string,string> report key => level stored for the role */
    public static function levelsForRole(int $roleId): array
    {
        $stmt = Database::connection()->prepare('SELECT report_key, level FROM role_report_access WHERE role_id = :r');
        $stmt->execute(['r' => $roleId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['report_key']] = (string) $row['level'];
        }
        return $out;
    }

    public static function setLevel(int $roleId, string $key, string $level, int $byUserId): void
    {
        Database::connection()->prepare(
            'INSERT INTO role_report_access (role_id, report_key, level, updated_by) VALUES (:r, :k, :l, :u)
             ON DUPLICATE KEY UPDATE level = VALUES(level), updated_by = VALUES(updated_by)'
        )->execute(['r' => $roleId, 'k' => $key, 'l' => $level, 'u' => $byUserId]);
    }

    /** @return array<string, array<string,mixed>> report key => row with the name of who set it */
    public static function extrasForUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT x.report_key, x.level, x.reason, x.created_by, x.created_at, u.name AS created_by_name
               FROM user_report_extras x LEFT JOIN users u ON u.id = x.created_by
              WHERE x.user_id = :u ORDER BY x.report_key'
        );
        $stmt->execute(['u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['report_key']] = $row;
        }
        return $out;
    }

    public static function saveExtra(int $userId, string $key, string $level, string $reason, int $byUserId): void
    {
        Database::connection()->prepare(
            'INSERT INTO user_report_extras (user_id, report_key, level, reason, created_by) VALUES (:u, :k, :l, :r, :b)
             ON DUPLICATE KEY UPDATE level = VALUES(level), reason = VALUES(reason), created_by = VALUES(created_by), created_at = CURRENT_TIMESTAMP'
        )->execute(['u' => $userId, 'k' => $key, 'l' => $level, 'r' => $reason, 'b' => $byUserId]);
    }

    public static function deleteExtra(int $userId, string $key): void
    {
        Database::connection()->prepare('DELETE FROM user_report_extras WHERE user_id = :u AND report_key = :k')
            ->execute(['u' => $userId, 'k' => $key]);
    }

    /** @return list<string> the permission keys a role grants (role_permissions only — for the Reports tab and its preview) */
    public static function rolePermissionKeys(int $roleId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :r AND rp.is_enabled = 1'
        );
        $stmt->execute(['r' => $roleId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** The membership rule of DepartmentRepository::activeMembershipsForUser(): started, not ended, department and designation active. */
    private const CURRENT_MEMBERSHIP = 'm.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE()) AND dd.is_active = 1 AND d.is_active = 1';

    /** @return list<int> the departments a person currently belongs to */
    public static function currentDepartmentIds(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT dd.department_id
               FROM user_department_memberships m
               JOIN department_designations dd ON dd.id = m.designation_id
               JOIN departments d ON d.id = dd.department_id
              WHERE m.user_id = :u AND ' . self::CURRENT_MEMBERSHIP
        );
        $stmt->execute(['u' => $userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $departmentIds
     * @return list<int> every current member of these departments
     */
    public static function currentMemberIds(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $departmentIds));
        return array_map('intval', Database::connection()->query(
            "SELECT DISTINCT m.user_id
               FROM user_department_memberships m
               JOIN department_designations dd ON dd.id = m.designation_id
               JOIN departments d ON d.id = dd.department_id
              WHERE dd.department_id IN ({$in}) AND " . self::CURRENT_MEMBERSHIP
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $departmentIds
     * @return list<string>
     */
    public static function departmentCodes(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $departmentIds));
        return array_map('strval', Database::connection()->query("SELECT code FROM departments WHERE id IN ({$in}) ORDER BY sort_order")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $orderIds
     * @param array<string,int> $params
     * @return list<int> those ids that meet the scope condition $scopeSql on `orders o`
     */
    public static function orderIdsWhere(array $orderIds, string $scopeSql, array $params): array
    {
        $in = implode(',', array_map('intval', $orderIds));
        $stmt = Database::connection()->prepare("SELECT o.id FROM orders o WHERE o.id IN ({$in}){$scopeSql}");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,int> $params
     * @return list<int> the clients of the orders meeting $scopeSql on `orders o`
     */
    public static function clientIdsWhere(string $scopeSql, array $params): array
    {
        $stmt = Database::connection()->prepare("SELECT DISTINCT o.client_id FROM orders o WHERE o.client_id IS NOT NULL{$scopeSql}");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
