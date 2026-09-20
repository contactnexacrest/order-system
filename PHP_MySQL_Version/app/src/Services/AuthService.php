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

    public static function attemptLogin(string $email, string $password): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user = UserRepository::findByEmail($email);

        if (!$user) {
            LoginAttemptRepository::record(null, $email, $ip, false);
            return ['status' => 'invalid_credentials'];
        }

        if (!$user['is_active']) {
            LoginAttemptRepository::record((int) $user['id'], $email, $ip, false);
            return ['status' => 'account_disabled'];
        }

        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            LoginAttemptRepository::record((int) $user['id'], $email, $ip, false);
            return ['status' => 'locked_out', 'locked_until' => $user['locked_until']];
        }

        if (!password_verify($password, $user['password_hash'])) {
            UserRepository::incrementFailedLogins((int) $user['id']);
            LoginAttemptRepository::record((int) $user['id'], $email, $ip, false);

            $maxAttempts = (int) (CompanySettingsRepository::get('failed_login_lockout_count') ?? '5');
            $lockoutMinutes = (int) (CompanySettingsRepository::get('lockout_duration_minutes') ?? '15');
            $recentFailures = LoginAttemptRepository::recentFailedCount((int) $user['id'], $lockoutMinutes);

            if ($recentFailures >= $maxAttempts) {
                $until = date('Y-m-d H:i:s', time() + ($lockoutMinutes * 60));
                UserRepository::lockUntil((int) $user['id'], $until);
                AuditLogRepository::log((int) $user['id'], 'ACCOUNT_LOCKED', 'users', (int) $user['id'], null, null, null, "{$recentFailures} failed attempts within {$lockoutMinutes} minutes");
                return ['status' => 'locked_out', 'locked_until' => $until];
            }

            return ['status' => 'invalid_credentials'];
        }

        // Password correct.
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
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function changePassword(int $userId, string $newPassword): void
    {
        UserRepository::updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT), false);
        AuditLogRepository::log($userId, 'PASSWORD_CHANGED', 'users', $userId);
    }
}
