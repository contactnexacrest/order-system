<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\SuperAdminService;

final class PermissionRepository
{
    /**
     * Effective permission keys for a user: role's enabled permissions,
     * with per-user overrides in user_permissions taking final precedence
     * (is_enabled = 1 force-enables even if the role doesn't have it;
     * is_enabled = 0 force-disables even if the role does).
     *
     * @return array<string, bool>  permission_key => effective boolean
     */
    public static function effectivePermissions(int $userId, ?int $roleId): array
    {
        $pdo = Database::connection();
        $effective = [];

        if ($roleId !== null) {
            $stmt = $pdo->prepare(
                'SELECT p.permission_key, rp.is_enabled
                 FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = :role_id'
            );
            $stmt->execute(['role_id' => $roleId]);
            foreach ($stmt->fetchAll() as $row) {
                $effective[$row['permission_key']] = (bool) $row['is_enabled'];
            }
        }

        $stmt = $pdo->prepare(
            'SELECT p.permission_key, up.is_enabled
             FROM user_permissions up
             JOIN permissions p ON p.id = up.permission_id
             WHERE up.user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $effective[$row['permission_key']] = (bool) $row['is_enabled'];
        }

        return $effective;
    }

    /**
     * Active users who effectively hold a given permission — role grant,
     * minus any per-user force-disable, plus a per-user grant override,
     * and always including an effective Super Admin regardless of role.
     * Used to route approval/escalation notifications (e.g. "notify
     * whoever can approve this") by the actual permission the action
     * requires, not by a role's display name — a role can be renamed via
     * /admin/roles, so a hardcoded name like 'Admin' or 'Managing
     * Director' would silently stop matching after a rename. Not
     * indexed/optimized — called for occasional notification fan-out (an
     * amendment request, a daily alert sweep), never a request hot path.
     *
     * @return array<int, int> user ids
     */
    public static function usersWithPermission(string $permissionKey): array
    {
        $activeUsers = Database::connection()
            ->query('SELECT id, role_id FROM users WHERE is_active = 1')
            ->fetchAll();
        $matched = [];
        foreach ($activeUsers as $u) {
            $userId = (int) $u['id'];
            if (SuperAdminService::isEffective($userId)) {
                $matched[] = $userId;
                continue;
            }
            $roleId = $u['role_id'] !== null ? (int) $u['role_id'] : null;
            $effective = self::effectivePermissions($userId, $roleId);
            if (!empty($effective[$permissionKey])) {
                $matched[] = $userId;
            }
        }
        return $matched;
    }

    /** @return array<int, array<string,mixed>> every permission, for pickers and the role matrix */
    public static function all(): array
    {
        return Database::connection()
            ->query('SELECT * FROM permissions ORDER BY category, name')
            ->fetchAll();
    }

    /**
     * Read-only role -> permission matrix, for admin visibility. Building
     * this from role_permissions rather than exposing a generic editor —
     * see PermissionAdminController's docblock for why bulk role-matrix
     * editing is deliberately out of scope here.
     *
     * @return array<string, array<string, bool>> role name => (permission_key => enabled)
     */
    public static function roleMatrix(): array
    {
        $pdo = Database::connection();
        $roles = $pdo->query('SELECT id, name FROM roles ORDER BY id')->fetchAll();
        $matrix = [];
        foreach ($roles as $role) {
            $stmt = $pdo->prepare(
                'SELECT p.permission_key, rp.is_enabled
                 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = :role_id'
            );
            $stmt->execute(['role_id' => $role['id']]);
            $perms = [];
            foreach ($stmt->fetchAll() as $row) {
                $perms[$row['permission_key']] = (bool) $row['is_enabled'];
            }
            $matrix[$role['name']] = $perms;
        }
        return $matrix;
    }

    /**
     * Active grant-only overrides (is_enabled = 1) with names joined in,
     * for the per-user permission override screen. Force-disable rows
     * (is_enabled = 0) exist in the schema/effectivePermissions() logic but
     * are never created by that screen — see PermissionAdminController.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function activeGrantOverrides(): array
    {
        return Database::connection()->query(
            "SELECT up.id, up.user_id, u.name AS user_name, p.permission_key, p.name AS permission_name,
                    up.granted_by, g.name AS granted_by_name, up.granted_at, up.reason
             FROM user_permissions up
             JOIN users u ON u.id = up.user_id
             JOIN permissions p ON p.id = up.permission_id
             LEFT JOIN users g ON g.id = up.granted_by
             WHERE up.is_enabled = 1
             ORDER BY up.granted_at DESC"
        )->fetchAll();
    }

    public static function grantOverride(int $userId, int $permissionId, int $grantedBy, string $reason): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO user_permissions (user_id, permission_id, is_enabled, granted_by, reason)
             VALUES (:user_id, :permission_id, 1, :granted_by, :reason)
             ON DUPLICATE KEY UPDATE is_enabled = 1, granted_by = VALUES(granted_by), reason = VALUES(reason), granted_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['user_id' => $userId, 'permission_id' => $permissionId, 'granted_by' => $grantedBy, 'reason' => $reason]);
        return (int) $pdo->lastInsertId();
    }

    /** Only ever removes a grant-only override row (is_enabled = 1) — never touches a force-disable row. */
    public static function removeGrantOverride(int $id): void
    {
        Database::connection()
            ->prepare('DELETE FROM user_permissions WHERE id = :id AND is_enabled = 1')
            ->execute(['id' => $id]);
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM permissions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByKey(string $permissionKey): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM permissions WHERE permission_key = :permission_key');
        $stmt->execute(['permission_key' => $permissionKey]);
        return $stmt->fetch() ?: null;
    }

    /** Freshly created permissions are never system-protected — see schema.sql Section X. */
    public static function create(string $permissionKey, string $name, ?string $description, ?string $category): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO permissions (permission_key, name, description, category, is_system_permission)
             VALUES (:permission_key, :name, :description, :category, 0)'
        );
        $stmt->execute(['permission_key' => $permissionKey, 'name' => $name, 'description' => $description, 'category' => $category]);
        return (int) $pdo->lastInsertId();
    }

    /** permission_key is immutable once created — every PermissionCheck::requires() call in code is a string literal against it. */
    public static function update(int $id, string $name, ?string $description, ?string $category): void
    {
        Database::connection()->prepare(
            'UPDATE permissions SET name = :name, description = :description, category = :category WHERE id = :id'
        )->execute(['name' => $name, 'description' => $description, 'category' => $category, 'id' => $id]);
    }

    /** How many role or per-user grants currently reference this permission — deletion is blocked while this is nonzero. */
    public static function usageCount(int $permissionId): int
    {
        // Two distinct placeholders, not :id reused twice — PDO::ATTR_EMULATE_PREPARES
        // is off (native prepares), which doesn't allow binding one named
        // parameter to two positions in the same query.
        $stmt = Database::connection()->prepare(
            'SELECT
               (SELECT COUNT(*) FROM role_permissions WHERE permission_id = :id1) +
               (SELECT COUNT(*) FROM user_permissions WHERE permission_id = :id2) AS c'
        );
        $stmt->execute(['id1' => $permissionId, 'id2' => $permissionId]);
        return (int) $stmt->fetchColumn();
    }

    public static function deletePermission(int $id): void
    {
        Database::connection()->prepare('DELETE FROM permissions WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Replace a role's entire permission set in one go — the actual "edit
     * which permissions a role has" the read-only matrix used to explicitly
     * rule out. role_permissions is a pure junction table nothing else
     * references, so delete-then-reinsert for just this one role_id is safe
     * and doesn't touch any other role's rows.
     *
     * @param array<int, int> $enabledPermissionIds
     */
    public static function setForRole(int $roleId, array $enabledPermissionIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
        $stmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id, is_enabled) VALUES (:role_id, :permission_id, 1)');
        foreach ($enabledPermissionIds as $permissionId) {
            $stmt->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    /** The permission_ids currently enabled for one role — for pre-checking the edit-permissions form. @return array<int, int> */
    public static function enabledForRole(int $roleId): array
    {
        $stmt = Database::connection()->prepare('SELECT permission_id FROM role_permissions WHERE role_id = :role_id AND is_enabled = 1');
        $stmt->execute(['role_id' => $roleId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'permission_id'));
    }
}
