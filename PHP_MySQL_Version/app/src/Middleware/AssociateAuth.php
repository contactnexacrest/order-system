<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\CompanySettingsRepository;
use App\Services\AssociatePortalService;

/**
 * Owner decision 1 Nov 2026 (AS-1): gates every /associate/* page. Separate
 * from staff (SessionAuth) and client (ClientAuth) logins — a staff or buyer
 * login never opens the associate portal. A staff "view as" session is
 * read-only: any form post is refused.
 */
final class AssociateAuth
{
    private const LAST_ACTIVITY_KEY = '_associate_last_activity_ts';

    public static function required(): callable
    {
        return function (array $params): bool {
            $a = AssociatePortalService::current();
            if ($a === null) {
                header('Location: /associate/login');
                return false;
            }
            $timeout = (int) (CompanySettingsRepository::get('session_timeout_minutes') ?? '30');
            $last = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;
            if ($last !== null && (time() - $last) > $timeout * 60) {
                AssociatePortalService::logout();
                header('Location: /associate/login');
                return false;
            }
            $_SESSION[self::LAST_ACTIVITY_KEY] = time();
            $viewAs = AssociatePortalService::viewAsStaffId() !== null;
            if (!$viewAs && ((int) $a['portal_enabled'] !== 1 || in_array($a['status'], ['ended', 'inactive'], true))) {
                AssociatePortalService::logout();
                header('Location: /associate/login');
                return false;
            }
            if ($viewAs && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                http_response_code(403);
                echo 'Read-only: you are viewing this portal as staff.';
                return false;
            }
            return true;
        };
    }
}
