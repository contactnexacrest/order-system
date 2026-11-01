<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PermissionRepository;

/**
 * Owner decision 1 Nov 2026 (A-8 / QA R7-N13): nobody hands out a right they
 * do not hold themselves, and nobody changes their own rights — not through
 * a role's permissions, a personal exception, their own role, or a
 * department membership. A Super Admin holds every permission; the "own"
 * rules still let a Super Admin act on their own account, so the founder
 * accounts can never lock themselves out.
 */
final class GrantGuard
{
    /** The permission keys a role carries. @return list<string> */
    public static function roleKeys(?int $roleId): array
    {
        if ($roleId === null) {
            return [];
        }
        $byId = array_column(PermissionRepository::all(), 'permission_key', 'id');
        return array_values(array_intersect_key($byId, array_flip(PermissionRepository::enabledForRole($roleId))));
    }

    /** The first of these permissions the signed-in person does not hold, or null. */
    public static function firstNotHeld(array $user, array $permissionKeys): ?string
    {
        foreach ($permissionKeys as $key) {
            if (!PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, (string) $key)) {
                return (string) $key;
            }
        }
        return null;
    }

    public static function isSuperAdmin(array $user): bool
    {
        return SuperAdminService::isEffective((int) $user['id']);
    }

    /** The roles the person works under: their own role and the role of the department they are acting as. @return list<int> */
    public static function ownRoleIds(array $user): array
    {
        $ids = [];
        if ($user['role_id'] !== null) {
            $ids[] = (int) $user['role_id'];
        }
        $acting = ActiveDepartmentService::effectiveRoleId((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null);
        if ($acting !== null) {
            $ids[] = $acting;
        }
        foreach (\App\Repositories\DepartmentRepository::activeRoleIdsForUser((int) $user['id']) as $roleId) {
            $ids[] = (int) $roleId;
        }
        return array_values(array_unique($ids));
    }

    public static function notHeldMessage(string $key): string
    {
        $p = PermissionRepository::findByKey($key);
        $name = $p !== null ? (string) $p['name'] : $key;
        return "You cannot give the \"{$name}\" permission: you do not hold it yourself. Ask someone who holds it.";
    }
}
