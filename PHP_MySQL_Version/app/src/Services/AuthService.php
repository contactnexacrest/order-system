<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;

/**
 * Login lockout thresholds are DB-driven (company_settings, category
 * 'security') rather than hardcoded, per the same "everything from DB"
 * rule as the rest of the schema — an MD who wants a stricter or looser
 * policy changes a settings row, not code.
 */
final class AuthService
{
    private const SESSION_USER_ID = '_auth_user_id';

    // AUTH-04/AUTH-05: a fixed dummy hash so password_verify() always runs
    // real bcrypt work, even for an email that doesn't exist — otherwise
    // the timing difference alone could tell an attacker an account exists.
    private const DUMMY_PASSWORD_HASH = '$2y$12$XkZEWJ9OTUrQ7pC.e9pnpO1LA.XHdoTUKJahoxDBHKjd8rQTbId22';

    public static function attemptLogin(string $email, string $password): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user = UserRepository::findByEmail($email);

        // AUTH-04/AUTH-05: the password is checked BEFORE anything about
        // account state (exists / disabled / locked) is revealed — an
        // attacker submitting any password for a guessed email must see the
        // exact same 'invalid_credentials' response whether that account
        // doesn't exist, is perfectly normal, is disabled, or is currently
        // locked out. Only once the real password is confirmed correct does
        // the legitimate account holder get told why they still can't log in.
        $passwordCorrect = password_verify($password, $user['password_hash'] ?? self::DUMMY_PASSWORD_HASH) && $user !== null;

        if (!$passwordCorrect) {
            if ($user) {
                UserRepository::incrementFailedLogins((int) $user['id']);

                $maxAttempts = (int) (CompanySettingsRepository::get('failed_login_lockout_count') ?? '5');
                $lockoutMinutes = (int) (CompanySettingsRepository::get('lockout_duration_minutes') ?? '15');
                $recentFailures = LoginAttemptRepository::recentFailedCount((int) $user['id'], $lockoutMinutes);

                if ($recentFailures >= $maxAttempts) {
                    $until = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
                    UserRepository::lockUntil((int) $user['id'], $until);
                    AuditLogRepository::log((int) $user['id'], 'ACCOUNT_LOCKED', 'users', (int) $user['id'], null, null, null, "{$recentFailures} failed attempts within {$lockoutMinutes} minutes");
                }
            }
            LoginAttemptRepository::record($user['id'] ?? null, $email, $ip, false);
            return ['status' => 'invalid_credentials'];
        }

        // Password confirmed correct — safe to reveal real account state now.
        if (!$user['is_active']) {
            LoginAttemptRepository::record((int) $user['id'], $email, $ip, false);
            return ['status' => 'account_disabled'];
        }

        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            LoginAttemptRepository::record((int) $user['id'], $email, $ip, false);
            return ['status' => 'locked_out', 'locked_until' => $user['locked_until']];
        }

        // Password correct, active, not locked.
        UserRepository::resetFailedLogins((int) $user['id']);
        LoginAttemptRepository::record((int) $user['id'], $email, $ip, true);

        if ($user['two_fa_enabled']) {
            $method = $user['two_fa_method'] ?? 'email';
            $destination = $method === 'sms' ? ($user['phone'] ?? '') : $user['email'];

            if ($method === 'sms' && !SmsService::isAvailable()) {
                // Gateway not configured after all — fall back to email rather
                // than lock the user out of their own account.
                $method = 'email';
                $destination = $user['email'];
            }

            $devCode = TwoFactorService::issueCodeFor((int) $user['id'], $method, $destination);
            return ['status' => 'requires_2fa', 'method' => $method, 'dev_code' => $devCode];
        }

        self::establishSession((int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'LOGIN_SUCCESS', 'users', (int) $user['id']);
        return ['status' => 'ok', 'force_password_change' => (bool) $user['force_password_change']];
    }

    public static function completeTwoFactor(string $submittedCode): array
    {
        $userId = TwoFactorService::pendingUserId();
        if (!$userId) {
            return ['status' => 'no_pending_2fa'];
        }

        if (!TwoFactorService::verify($submittedCode)) {
            AuditLogRepository::log($userId, 'LOGIN_2FA_FAILED', 'users', $userId);
            return ['status' => 'invalid_code'];
        }

        $user = UserRepository::findById($userId);
        self::establishSession($userId);
        AuditLogRepository::log($userId, 'LOGIN_SUCCESS_2FA', 'users', $userId);
        return ['status' => 'ok', 'force_password_change' => (bool) ($user['force_password_change'] ?? false)];
    }

    private static function establishSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_ID] = $userId;
        UserRepository::updateLastLogin($userId);
    }

    public static function currentUserId(): ?int
    {
        return $_SESSION[self::SESSION_USER_ID] ?? null;
    }

    public static function currentUser(): ?array
    {
        $id = self::currentUserId();
        return $id ? UserRepository::findById($id) : null;
    }

    public static function logout(): void
    {
        $userId = self::currentUserId();
        if ($userId) {
            AuditLogRepository::log($userId, 'LOGOUT', 'users', $userId);
        }
        // QA-5 CP-13: this used to be `$_SESSION = [];` — wiping the ENTIRE
        // session, not just the staff login. A staff member with the client
        // portal open in the same browser (or vice versa — see
        // ClientPortalService::logout(), already scoped correctly) got
        // silently logged out of that other, unrelated surface too, just by
        // logging out of this one. Scoped to only this service's own key,
        // matching ClientPortalService::logout()'s existing pattern.
        unset($_SESSION[self::SESSION_USER_ID]);
        session_regenerate_id(true);
    }

    public static function changePassword(int $userId, string $newPassword): void
    {
        UserRepository::updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT), false);
        AuditLogRepository::log($userId, 'PASSWORD_CHANGED', 'users', $userId);
    }
}
