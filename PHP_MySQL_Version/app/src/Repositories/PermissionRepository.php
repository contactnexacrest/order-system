<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

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
}
