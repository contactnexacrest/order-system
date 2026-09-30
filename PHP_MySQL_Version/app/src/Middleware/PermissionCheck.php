<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\AuditLogRepository;
use App\Services\AuthService;
use App\Services\PermissionService;

final class PermissionCheck
{
    public static function requires(string $permissionKey): callable
    {
        return function (array $params) use ($permissionKey): bool {
            $user = AuthService::currentUser();
            if (!$user) {
                header('Location: /login');
                return false;
            }

            if (!PermissionService::can((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, $permissionKey)) {
                AuditLogRepository::log((int) $user['id'], 'PERMISSION_DENIED', 'permissions', null, $permissionKey);
                http_response_code(403);
                echo '<h1>403 — Not permitted</h1><p>You do not have the "' . htmlspecialchars($permissionKey) . '" permission.</p><p><a href="/">Back to dashboard</a></p>';
                return false;
            }

            return true;
        };
    }

    /**
     * Passes if the user holds ANY of the given permission keys — e.g. a
     * dispute page must be reachable by someone who can only reply
     * (respond_to_disputes) as well as someone who manages disputes
     * broadly (manage_disputes).
     *
     * @param array<int, string> $permissionKeys
     */
    public static function requiresAny(array $permissionKeys): callable
    {
        return function (array $params) use ($permissionKeys): bool {
            $user = AuthService::currentUser();
            if (!$user) {
                header('Location: /login');
                return false;
            }

            $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
            foreach ($permissionKeys as $key) {
                if (PermissionService::can((int) $user['id'], $roleId, $key)) {
                    return true;
                }
            }

            AuditLogRepository::log((int) $user['id'], 'PERMISSION_DENIED', 'permissions', null, implode('|', $permissionKeys));
            http_response_code(403);
            echo '<h1>403 — Not permitted</h1><p>You do not have any of the required permissions.</p><p><a href="/">Back to dashboard</a></p>';
            return false;
        };
    }
}
