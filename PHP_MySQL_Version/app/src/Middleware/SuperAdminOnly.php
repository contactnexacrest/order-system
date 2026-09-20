<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\AuditLogRepository;
use App\Services\AuthService;
use App\Services\SuperAdminService;

/**
 * Gates the Super Admin management screen itself (promoting/demoting the
 * permanent flag, granting/revoking delegations) — deliberately NOT a
 * `permissions` row like every other gate in the app, since Super Admin is
 * the tier above the permission system, not a bundle within it. Only an
 * already-effective Super Admin (permanent or delegated) may manage it.
 */
final class SuperAdminOnly
{
    public static function required(): callable
    {
        return function (array $params): bool {
            $user = AuthService::currentUser();
            if (!$user) {
                header('Location: /login');
                return false;
            }

            if (!SuperAdminService::isEffective((int) $user['id'])) {
                AuditLogRepository::log((int) $user['id'], 'PERMISSION_DENIED', 'super_admin', null, 'super_admin_only');
                http_response_code(403);
                echo '<h1>403 — Not permitted</h1><p>Only a Super Admin can access this page.</p><p><a href="/">Back to dashboard</a></p>';
                return false;
            }

            return true;
        };
    }
}
