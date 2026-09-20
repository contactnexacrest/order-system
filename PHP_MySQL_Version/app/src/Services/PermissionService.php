<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PermissionRepository;

final class PermissionService
{
    /** @var array<string,bool>|null cached for the current request */
    private static ?array $cache = null;
    private static ?int $cachedForUserId = null;

    public static function can(int $userId, ?int $roleId, string $permissionKey): bool
    {
        if (self::$cache === null || self::$cachedForUserId !== $userId) {
            self::$cache = PermissionRepository::effectivePermissions($userId, $roleId);
            self::$cachedForUserId = $userId;
        }
        return self::$cache[$permissionKey] ?? false;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
        self::$cachedForUserId = null;
    }
}
