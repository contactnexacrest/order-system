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
    private const SESSION_VERSION_KEY = '_session_version';

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
            // External QA report L-03: this failed attempt must be recorded
            // BEFORE recentFailedCount() runs, or the count it reads never
            // includes the very attempt that triggered it — with
            // "lock after 5", the 5th failure would see only 4 prior rows
            // (4 >= 5 is false) and the account would only actually lock on
            // the 6th.
            LoginAttemptRepository::record($user['id'] ?? null, $email, $ip, false);
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

        // Owner decision D-265 (7 Nov 2026): every staff member, every role, enters a
        // 6-digit code e-mailed at each login. It cannot be switched off per person;
        // only a browser the person chose to remember (Admin setting, default off) skips it.
        if (RememberBrowserService::isRemembered($user)) {
            self::establishSession((int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'LOGIN_SUCCESS_REMEMBERED_BROWSER', 'users', (int) $user['id']);
            return ['status' => 'ok', 'force_password_change' => (bool) $user['force_password_change']];
        }
        $devCode = TwoFactorService::issueCodeFor((int) $user['id'], 'email', (string) $user['email']);
        return ['status' => 'requires_2fa', 'method' => 'email', 'dev_code' => $devCode];
    }

    public static function completeTwoFactor(string $submittedCode, bool $rememberBrowser = false): array
    {
        $userId = TwoFactorService::pendingUserId();
        if (!$userId) {
            return ['status' => 'no_pending_2fa'];
        }

        if (!TwoFactorService::verify($submittedCode)) {
            AuditLogRepository::log($userId, 'LOGIN_2FA_FAILED', 'users', $userId);
            return ['status' => 'invalid_code'];
        }

        return self::finishCodeLogin($userId, 'LOGIN_SUCCESS_2FA', $rememberBrowser);
    }

    /** D-265: an emergency backup code instead of the e-mailed one (Super Admin / company-settings holders only). */
    public static function completeWithBackupCode(string $backupCode): array
    {
        $userId = TwoFactorService::pendingUserId();
        if (!$userId) {
            return ['status' => 'no_pending_2fa'];
        }
        if (!BackupCodeService::eligible($userId)) {
            return ['status' => 'not_allowed'];
        }
        if (!TwoFactorService::countBackupTry() || !BackupCodeService::consume($userId, $backupCode)) {
            AuditLogRepository::log($userId, 'LOGIN_BACKUP_CODE_FAILED', 'users', $userId);
            return ['status' => 'invalid_code'];
        }
        TwoFactorService::clear();
        BackupCodeService::recordUse($userId);
        return self::finishCodeLogin($userId, 'LOGIN_SUCCESS_BACKUP_CODE', false);
    }

    private static function finishCodeLogin(int $userId, string $auditAction, bool $rememberBrowser): array
    {
        $user = UserRepository::findById($userId);
        self::establishSession($userId);
        AuditLogRepository::log($userId, $auditAction, 'users', $userId);
        if ($rememberBrowser && RememberBrowserService::days() > 0) {
            RememberBrowserService::remember($userId);
            AuditLogRepository::log($userId, 'LOGIN_BROWSER_REMEMBERED', 'users', $userId, null, null, null, 'For ' . RememberBrowserService::days() . ' day(s)');
        }
        return ['status' => 'ok', 'force_password_change' => (bool) ($user['force_password_change'] ?? false)];
    }

    private static function establishSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_ID] = $userId;
        // D-283: the idle clock and the 12-hour limit start now (an old
        // activity time left from an earlier, timed-out login must not count).
        $_SESSION['_last_activity_ts'] = time();
        $_SESSION[SessionTimeouts::STAFF_LOGIN_AT_KEY] = time();
        // External QA report L-26: snapshot the row's current
        // session_version at login time — SessionAuth::required()
        // compares this against the live DB value on every request.
        $user = UserRepository::findById($userId);
        $_SESSION[self::SESSION_VERSION_KEY] = $user ? (int) $user['session_version'] : 0;
        UserRepository::updateLastLogin($userId);
    }

    /** @see SessionAuth::required() — the value this session logged in with. */
    public static function sessionVersion(): ?int
    {
        return $_SESSION[self::SESSION_VERSION_KEY] ?? null;
    }

    /** Called after a successful in-session password change, so THIS session (unlike every other one) keeps working. */
    public static function refreshSessionVersion(int $userId): void
    {
        $user = UserRepository::findById($userId);
        $_SESSION[self::SESSION_VERSION_KEY] = $user ? (int) $user['session_version'] : 0;
    }

    public static function currentUserId(): ?int
    {
        return $_SESSION[self::SESSION_USER_ID] ?? null;
    }

    public static function currentUser(): ?array
    {
        $id = self::currentUserId();
        $user = $id ? UserRepository::findById($id) : null;
        if ($user !== null) {
            // Owner decision D-214: role_id is the role of the department this
            // session works as (own_role_id keeps the person's own role).
            $user['own_role_id'] = $user['role_id'];
            $user['role_id'] = ActiveDepartmentService::effectiveRoleId((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null);
        }
        return $user;
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
        // HNT-L10: a "view as associate" this staff member had open ends with their sign-out
        // (Node's logout regenerates the session keeping only the buyer portal, so it ends there too).
        if (AssociatePortalService::viewAsStaffId() !== null) {
            AssociatePortalService::endViewAs();
        }
        ActiveDepartmentService::forgetOnLogout();
        session_regenerate_id(true);
    }

    public static function changePassword(int $userId, string $newPassword): void
    {
        UserRepository::updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT), false);
        AuditLogRepository::log($userId, 'PASSWORD_CHANGED', 'users', $userId);
        // External QA report L-26: updatePassword() just bumped
        // session_version — without this, the session that JUST made
        // this very change would itself be logged out on its next
        // request, which only makes sense for every OTHER session.
        if (self::currentUserId() === $userId) {
            self::refreshSessionVersion($userId);
        }
    }
}
