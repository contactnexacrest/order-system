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
            if ($user) {
                $mustChange = (int) $user['force_password_change'] === 1;

                // password_expiry_days (company_settings, category
                // 'security') — a stored setting the spec flagged as "not
                // yet enforced." password_changed_at is only NULL for an
                // account that has never completed its first (forced)
                // password change, which the force_password_change branch
                // above already gates — so a NULL here means "not yet
                // applicable," not "never expires."
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
            }

            return true;
        };
    }
}
