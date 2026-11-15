<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\AuditLogRepository;
use App\Services\AuthService;
use App\Services\ReportAccess;

/**
 * Owner decision D-379: the route gate of every report in ReportAccess::REPORTS. It runs after
 * PermissionCheck (the base permission stays on the route as the ceiling) and refuses — the
 * same 403 page as PermissionCheck, for the page and its CSV alike — when the signed-in person's
 * effective level for the report is below $min (default: the level that opens its page).
 *
 * Node twin: NODE_MySQL_Version/app/src/middleware/reportAccessCheck.js
 */
final class ReportAccessCheck
{
    public static function requires(string $reportKey, ?string $min = null): callable
    {
        return static function (array $params) use ($reportKey, $min): bool {
            $user = AuthService::currentUser();
            if (!$user) {
                header('Location: /login');
                return false;
            }
            if (ReportAccess::can($reportKey, $min ?? ReportAccess::pageMin($reportKey))) {
                return true;
            }
            AuditLogRepository::log((int) $user['id'], 'PERMISSION_DENIED', 'permissions', null, 'report:' . $reportKey);
            http_response_code(403);
            echo '<h1>403 — Not permitted</h1><p>' . htmlspecialchars(ReportAccess::REFUSED) . '</p><p><a href="/">Back to dashboard</a></p>';
            return false;
        };
    }

    /** /reports itself: at least one report page opens for the person. */
    public static function anyReport(): callable
    {
        return static function (array $params): bool {
            $user = AuthService::currentUser();
            if (!$user) {
                header('Location: /login');
                return false;
            }
            if (ReportAccess::anyOpen()) {
                return true;
            }
            AuditLogRepository::log((int) $user['id'], 'PERMISSION_DENIED', 'permissions', null, 'report:any');
            http_response_code(403);
            echo '<h1>403 — Not permitted</h1><p>' . htmlspecialchars(ReportAccess::REFUSED) . '</p><p><a href="/">Back to dashboard</a></p>';
            return false;
        };
    }
}
