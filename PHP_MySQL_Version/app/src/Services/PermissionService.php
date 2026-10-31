<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PermissionRepository;

final class PermissionService
{
    /** @var array<string,bool>|null cached for the current request */
    private static ?array $cache = null;
    private static ?string $cachedForKey = null;
    private static ?bool $superAdminCache = null;
    private static ?int $superAdminCachedForUserId = null;

    /**
     * A Super Admin (permanent flag or an active delegation — see
     * SuperAdminService) is unrestricted everywhere: every permission key
     * resolves true, with no exceptions and no per-key configuration
     * needed. Checked before the ordinary role/override lookup so a Super
     * Admin's access never depends on role_permissions/user_permissions
     * rows existing at all.
     */
    public static function can(int $userId, ?int $roleId, string $permissionKey): bool
    {
        // Owner decision D-214: the signed-in person's checks use the role of
        // the department they logged in as (or are previewing); a preview
        // shows exactly that designation, so no Super Admin bypass during it.
        $previewing = ActiveDepartmentService::isPreviewing($userId);
        $roleId = ActiveDepartmentService::effectiveRoleId($userId, $roleId);
        if (!$previewing) {
            if (self::$superAdminCache === null || self::$superAdminCachedForUserId !== $userId) {
                self::$superAdminCache = SuperAdminService::isEffective($userId);
                self::$superAdminCachedForUserId = $userId;
            }
            if (self::$superAdminCache) {
                return true;
            }
        }

        $key = $userId . ':' . ($roleId ?? '-') . ($previewing ? ':preview' : '');
        if (self::$cache === null || self::$cachedForKey !== $key) {
            self::$cache = PermissionRepository::effectivePermissions($userId, $roleId, $previewing);
            self::$cachedForKey = $key;
        }
        return self::$cache[$permissionKey] ?? false;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
        self::$cachedForKey = null;
        self::$superAdminCache = null;
        self::$superAdminCachedForUserId = null;
        OrderAccessService::resetCache();
    }
}
