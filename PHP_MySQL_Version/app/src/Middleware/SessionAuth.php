<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

final class SessionAuth
{
    private const LAST_ACTIVITY_KEY = '_last_activity_ts';

    /**
     * Returns a callable middleware. Redirects to /login if not
     * authenticated; enforces the DB-driven idle session timeout
     * (company_settings.session_timeout_minutes); also enforces the
     * forced-password-change gate so a user can't wander the app on a
     * temporary/reset password.
     */
    public static function required(): callable
    {
        return function (array $params): bool {
            $userId = AuthService::currentUserId();
            if (!$userId) {
                header('Location: /login');
                return false;
            }

            $timeoutMinutes = (int) (CompanySettingsRepository::get('session_timeout_minutes') ?? '30');
            $lastActivity = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;
            if ($lastActivity !== null && (time() - $lastActivity) > ($timeoutMinutes * 60)) {
                AuthService::logout();
                header('Location: /login');
                return false;
            }
            $_SESSION[self::LAST_ACTIVITY_KEY] = time();

            $user = AuthService::currentUser();

            // AUTH-10: a user deactivated mid-session (staff let go, a
            // wrongdoer suspended) must lose access on their very next
            // request, not just be blocked from a future login — is_active
            // is only ever checked at login time otherwise, so an already
            // logged-in session would keep working indefinitely.
            if (!$user || !$user['is_active']) {
                AuthService::logout();
                header('Location: /login');
                return false;
            }

            // External QA report L-26: a password change bumps
            // users.session_version — any OTHER session (a stolen one
            // included) still carries the OLD value it snapshotted at
            // its own login time, so it's logged out right here on its
            // very next request, the same immediate-effect guarantee
            // the is_active check above already gives for deactivation.
            if ((int) $user['session_version'] !== (int) (AuthService::sessionVersion() ?? 0)) {
                AuthService::logout();
                header('Location: /login');
                return false;
            }

            $mustChange = (int) $user['force_password_change'] === 1;

            // password_expiry_days (company_settings, category 'security')
            // — a stored setting the spec flagged as "not yet enforced."
            // password_changed_at is only NULL for an account that has
            // never completed its first (forced) password change, which the
            // force_password_change branch above already gates — so a NULL
            // here means "not yet applicable," not "never expires."
            if (!$mustChange && $user['password_changed_at'] !== null) {
                $expiryDays = (int) (CompanySettingsRepository::get('password_expiry_days') ?? '0');
                if ($expiryDays > 0) {
                    $ageDays = (time() - strtotime($user['password_changed_at'])) / 86400;
                    if ($ageDays > $expiryDays) {
                        UserRepository::flagPasswordExpired((int) $user['id']);
                        AuditLogRepository::log(
                            (int) $user['id'],
                            'PASSWORD_EXPIRED_FORCED_CHANGE',
                            'users',
                            (int) $user['id'],
                            null,
                            null,
                            null,
                            "Password last changed " . round($ageDays) . " days ago, exceeding the {$expiryDays}-day policy."
                        );
                        $mustChange = true;
                    }
                }
            }

            if ($mustChange) {
                $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
                if (rtrim($requestPath, '/') !== '/force-password-change') {
                    header('Location: /force-password-change');
                    return false;
                }
            }

            // Owner decision D-214: a person in several departments works as
            // one of them; until they choose, only the choice page (and logout) opens.
            if (!$mustChange && \App\Services\ActiveDepartmentService::needsChoice((int) $userId)) {
                $requestPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
                if (!in_array($requestPath, ['/choose-department', '/logout'], true)) {
                    header('Location: /choose-department');
                    return false;
                }
            }

            // QA Round 5, R5-24: the archived-order and assignment rules
            // apply to every page and action of an order, not just its
            // main page — checked once here for every staff route.
            $denied = \App\Services\OrderAccessService::denialReasonForPath(
                (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH),
                (int) $userId,
                $user['role_id'] !== null ? (int) $user['role_id'] : null
            );
            if ($denied !== null) {
                http_response_code(403);
                echo htmlspecialchars($denied, ENT_QUOTES, 'UTF-8');
                return false;
            }

            return true;
        };
    }
}
