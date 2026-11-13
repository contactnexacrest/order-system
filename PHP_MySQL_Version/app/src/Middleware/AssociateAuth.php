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

    /** D-261 (mockup 5): the friendly lock page for a feature the associate's level does not show. */
    public static function notAvailable(array $associate): void
    {
        http_response_code(403);
        \App\Helpers\View::render('associate_portal/not_available', ['me' => $associate, 'unread' => 0], 'layout/associate');
    }

    public static function required(): callable
    {
        return function (array $params): bool {
            $a = AssociatePortalService::current();
            if ($a === null) {
                header('Location: /associate/login');
                return false;
            }
            // D-283: the associates' own idle timeout (Admin › Settings › Security, default 2 hours).
            $last = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;
            if (\App\Services\SessionTimeouts::idleExpired('associate', $last !== null ? (int) $last : null)) {
                AssociatePortalService::logout();
                unset($_SESSION[self::LAST_ACTIVITY_KEY]);
                \App\Helpers\Flash::set('info', 'You were signed out after ' . \App\Services\SessionTimeouts::describeMinutes(\App\Services\SessionTimeouts::idleMinutes('associate')) . ' without activity. Please log in again.');
                header('Location: /associate/login');
                return false;
            }
            $_SESSION[self::LAST_ACTIVITY_KEY] = time();
            \App\Services\SessionTimeouts::markPage('associate');
            $viewAs = AssociatePortalService::viewAsStaffId() !== null;
            // HNT-L10 (QA 13 Nov 2026): staff "view as associate" lasts only while that same staff
            // member is still signed in — after their logout or session end, nobody else using the
            // browser sees the portal.
            if ($viewAs && (int) (\App\Services\AuthService::currentUserId() ?? 0) !== (int) AssociatePortalService::viewAsStaffId()) {
                AssociatePortalService::endViewAs();
                header('Location: /associate/login');
                return false;
            }
            // QA ACC-10: a password changed or reset since this session logged in ends it.
            if (!$viewAs && ((int) $a['portal_enabled'] !== 1 || in_array($a['status'], ['ended', 'inactive'], true) || !AssociatePortalService::sessionPasswordStillCurrent($a['password_hash'] ?? null))) {
                AssociatePortalService::logout();
                header('Location: /associate/login');
                return false;
            }
            if ($viewAs && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
                && rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') !== '/associate/keep-alive') {
                http_response_code(403);
                echo 'Read-only: you are viewing this portal as staff.';
                return false;
            }
            // D-261: a feature this associate's access level does not show is refused here, in one
            // place for every portal page (the menu hides it too). Staff "view as" sees the same.
            $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
            if (!\App\Services\AssociateAccess::allowsPath($a, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $_POST)) {
                self::notAvailable($a);
                return false;
            }
            return true;
        };
    }
}
