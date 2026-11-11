<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\SessionTimeouts;

final class SessionAuth
{
    private const LAST_ACTIVITY_KEY = '_last_activity_ts';

    /**
     * Returns a callable middleware. Redirects to /login if not
     * authenticated; enforces the DB-driven idle session timeout
     * (D-283: session_timeout_staff_minutes and session_absolute_staff_hours); also enforces the
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

            // Owner decision D-283 (11 Nov 2026): the staff idle timeout
            // (Admin › Settings › Security, default 4 hours) and the 12-hour
            // limit since sign-in, however active.
            $lastActivity = $_SESSION[self::LAST_ACTIVITY_KEY] ?? null;
            if (SessionTimeouts::idleExpired('staff', $lastActivity !== null ? (int) $lastActivity : null)) {
                AuthService::logout();
                \App\Helpers\Flash::set('info', 'You were signed out after ' . SessionTimeouts::describeMinutes(SessionTimeouts::idleMinutes('staff')) . ' without activity. Please log in again.');
                header('Location: /login');
                return false;
            }
            $_SESSION[SessionTimeouts::STAFF_LOGIN_AT_KEY] ??= time(); // a session from before D-283 starts its 12 hours now
            $loginAt = (int) $_SESSION[SessionTimeouts::STAFF_LOGIN_AT_KEY];
            if (SessionTimeouts::absoluteExpired($loginAt)) {
                AuthService::logout();
                unset($_SESSION[SessionTimeouts::STAFF_LOGIN_AT_KEY]);
                \App\Helpers\Flash::set('info', 'For security, staff log in again at least every ' . SessionTimeouts::absoluteHours() . ' hours. Please log in again.');
                header('Location: /login');
                return false;
            }
            $_SESSION[self::LAST_ACTIVITY_KEY] = time();
            SessionTimeouts::markPage('staff', SessionTimeouts::staffAbsoluteLeft($loginAt));

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

            // QA ACC-2: "Preview as" / "view as" is for looking only. While a
            // preview runs nothing can be saved, approved or granted with the
            // previewed designation's rights — only "Exit preview" and log out post.
            if (\App\Services\ActiveDepartmentService::preview() !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $postPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
                if (!in_array($postPath, ['/preview/exit', '/logout', '/keep-alive'], true)) {
                    \App\Helpers\Flash::set('error', 'You are previewing the app: nothing can be saved during a preview. Use "Exit preview" at the top first.');
                    header('Location: ' . \App\Helpers\Router::safeRedirectBack());
                    return false;
                }
            }

            // QA ACC-12: staff placed in a division that is not active (planned, or
            // switched back) cannot create or change anything until it is activated.
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($user['division_id'] ?? null) !== null
                && !\App\Services\DivisionService::seesAll($user)) {
                $ownDivision = \App\Services\DivisionService::find((int) $user['division_id']);
                $postPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
                if ($ownDivision !== null && $ownDivision['status'] !== 'active' && !in_array($postPath, ['/logout', '/preview/exit', '/account/password', '/keep-alive'], true)) {
                    http_response_code(403);
                    echo 'Your division (' . htmlspecialchars((string) $ownDivision['name'], ENT_QUOTES, 'UTF-8') . ') is not active yet: nothing can be saved until the MD activates it in Admin › Divisions.';
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

            // D-257: a module switched off for the request's division(s) refuses its addresses.
            $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (\App\Services\DivisionService::disabledModuleForPath($requestPath) !== null) {
                http_response_code(403);
                echo 'This part of the system is not in use here.';
                return false;
            }
            // D-257: a client outside the request's division(s) is not available.
            if (preg_match('#^/clients/(\d+)(?:/|$)#', $requestPath, $clientMatch) && !\App\Services\DivisionService::allowsClient((int) $clientMatch[1])) {
                http_response_code(403);
                echo 'This client is not available to you.';
                return false;
            }
            // D-257: the report Division filter (company level, two or more active divisions).
            if (str_starts_with($requestPath, '/reports') && ($_GET['division'] ?? '') !== '' && \App\Services\DivisionService::showSwitcher()) {
                $reportDivision = \App\Services\DivisionService::find((int) $_GET['division']);
                if ($reportDivision !== null && $reportDivision['status'] === 'active' && \App\Services\DivisionService::allows((int) $reportDivision['id'])) {
                    \App\Services\DivisionService::enterOrderDivision((int) $reportDivision['id']);
                }
            }
            if (preg_match('#^/associates/(\d+)(?:/|$)#', $requestPath, $associateMatch)) {
                $associateDivision = \App\Config\Database::connection()->prepare('SELECT division_id FROM associates WHERE id = :id');
                $associateDivision->execute(['id' => (int) $associateMatch[1]]);
                $associateDivisionId = $associateDivision->fetchColumn();
                if ($associateDivisionId !== false && !\App\Services\DivisionService::allows((int) $associateDivisionId)) {
                    http_response_code(403);
                    echo 'This associate is not available to you.';
                    return false;
                }
            }
            // D-257: an order's pages work in the order's own division (its numbering and settings).
            $orderIdForPath = \App\Services\OrderAccessService::orderIdForPath($requestPath);
            if ($orderIdForPath !== null) {
                $orderForPath = \App\Repositories\OrderRepository::find($orderIdForPath);
                if ($orderForPath) {
                    \App\Services\DivisionService::enterOrderDivision((int) ($orderForPath['division_id'] ?? \App\Services\DivisionService::EXPORT_ID));
                    // QA ACC-11: a module switched off in the ORDER's division refuses that order's addresses
                    // (a company-level user on "All divisions" included).
                    if (\App\Services\DivisionService::disabledModuleForPath($requestPath) !== null) {
                        http_response_code(403);
                        echo 'This part of the system is not in use here.';
                        return false;
                    }
                }
            }

            return true;
        };
    }
}
