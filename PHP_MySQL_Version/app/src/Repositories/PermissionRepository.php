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
}
