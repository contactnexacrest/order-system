<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Repositories\AssociateRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\LoginAttemptRepository;

/**
 * Owner decision 1 Nov 2026 (AS-1, AS-8): the associate portal's own login —
 * separate session key, separate account (the associate record), never a
 * staff login. Password, then a 6-digit code e-mailed every time. Staff may
 * "view as" an associate, read-only.
 */
final class AssociatePortalService
{
    /**
     * QA Round 8 (Q-R8-2, AS-3): a suspended associate may log in and see
     * their clients, orders and commission, but cannot invite clients or
     * change their code, profile, bank details or documents. Messages to
     * NexaCrest and commission invoices stay open, so they can still raise
     * a question and be paid for work already done.
     */
    public const SUSPENDED_BANNER = 'Your account is suspended — you can see your clients, orders and commission, but cannot invite clients or change your details.';
    public const SUSPENDED_REFUSED = 'Your account is suspended — you cannot invite clients or change your details. Please contact your NexaCrest relationship manager.';

    public static function isSuspended(?array $a): bool
    {
        return $a !== null && ($a['status'] ?? '') === 'suspended';
    }

    private const SESSION_ID = '_associate_portal_id';
    private const SESSION_PENDING = '_associate_portal_pending_id';
    private const SESSION_VIEW_AS = '_associate_portal_view_as_staff_id';
    /** QA ACC-10: fingerprint of the password this session logged in with (a change or reset ends other sessions). */
    private const SESSION_PASSWORD_FP = '_associate_portal_password_fp';

    /** Whether this session's password is still the associate's current one (a session from before the check is accepted and pinned). */
    public static function sessionPasswordStillCurrent(?string $currentHash): bool
    {
        $fp = hash('sha256', (string) $currentHash);
        if (!isset($_SESSION[self::SESSION_PASSWORD_FP])) {
            $_SESSION[self::SESSION_PASSWORD_FP] = $fp;
            return true;
        }
        return hash_equals((string) $_SESSION[self::SESSION_PASSWORD_FP], $fp);
    }
    private const DUMMY_HASH = '$2y$12$XkZEWJ9OTUrQ7pC.e9pnpO1LA.XHdoTUKJahoxDBHKjd8rQTbId22';

    /** A one-time token (set password) or 6-digit code (login), stored hashed. Returns the raw value. */
    public static function issueToken(int $associateId, string $purpose, int $minutes): string
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE associate_tokens SET used_at = NOW() WHERE associate_id = :a AND purpose = :p AND used_at IS NULL')->execute(['a' => $associateId, 'p' => $purpose]);
        $raw = $purpose === 'login_code' ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO associate_tokens (associate_id, purpose, token_hash, expires_at) VALUES (:a, :p, :h, DATE_ADD(NOW(), INTERVAL :m MINUTE))')
            ->execute(['a' => $associateId, 'p' => $purpose, 'h' => hash('sha256', $raw), 'm' => $minutes]);
        return $raw;
    }

    /** The associate a valid set-password token belongs to (not used, not expired). */
    public static function associateForSetPassword(string $raw): ?array
    {
        $stmt = Database::connection()->prepare("SELECT associate_id FROM associate_tokens WHERE purpose = 'set_password' AND token_hash = :h AND used_at IS NULL AND expires_at > NOW()");
        $stmt->execute(['h' => hash('sha256', $raw)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : AssociateRepository::find((int) $id);
    }

    public static function setPassword(string $raw, string $password): bool
    {
        $a = self::associateForSetPassword($raw);
        if ($a === null) {
            return false;
        }
        Database::connection()->prepare("UPDATE associate_tokens SET used_at = NOW() WHERE purpose = 'set_password' AND token_hash = :h")->execute(['h' => hash('sha256', $raw)]);
        AssociateRepository::setFields((int) $a['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'force_password_change' => 0, 'failed_login_count' => 0, 'locked_until' => null]);
        AuditLogRepository::log(null, 'ASSOCIATE_PASSWORD_SET', 'associates', (int) $a['id']);
        return true;
    }

    /**
     * Step 1: e-mail and password. On success a login code is e-mailed and
     * the session waits for it. Returns 'code_sent', 'invalid', 'locked' or 'disabled'.
     * @return array{status:string, code?:string}
     */
    public static function attemptLogin(string $email, string $password): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $a = $email !== '' ? AssociateRepository::findByEmail($email) : null;
        // Locked: refused before the password is looked at, so a locked account never tells a right password from a wrong one.
        if ($a !== null && !empty($a['locked_until']) && strtotime((string) $a['locked_until']) > time()) {
            LoginAttemptRepository::record(null, $email, $ip, false);
            return ['status' => 'locked'];
        }
        $ok = password_verify($password, (string) ($a['password_hash'] ?? self::DUMMY_HASH)) && $a !== null && !empty($a['password_hash']);
        if (!$ok) {
            if ($a !== null) {
                $fails = (int) $a['failed_login_count'] + 1;
                AssociateRepository::setFields((int) $a['id'], ['failed_login_count' => $fails] + ($fails >= 5 ? ['locked_until' => date('Y-m-d H:i:s', time() + 15 * 60)] : []));
            }
            LoginAttemptRepository::record(null, $email, $ip, false);
            return ['status' => 'invalid'];
        }
        if ((int) $a['portal_enabled'] !== 1 || in_array($a['status'], ['ended', 'inactive'], true)) {
            return ['status' => 'disabled'];
        }
        $code = self::issueToken((int) $a['id'], 'login_code', 10);
        EmailService::sendPlainText((string) $a['email'], 'Your NexaCrest login code', "Your login code is {$code}. It is valid for 10 minutes.\n\nIf you did not try to log in, change your password.");
        self::regenerate();
        unset($_SESSION[self::SESSION_ID], $_SESSION[self::SESSION_VIEW_AS]);
        $_SESSION[self::SESSION_PENDING] = (int) $a['id'];
        return ['status' => 'code_sent', 'code' => $code];
    }

    /** Step 2: the e-mailed code. */
    public static function verifyCode(string $code): bool
    {
        $id = $_SESSION[self::SESSION_PENDING] ?? null;
        if ($id === null) {
            return false;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id, token_hash, attempts FROM associate_tokens WHERE associate_id = :a AND purpose = 'login_code' AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute(['a' => $id]);
        $t = $stmt->fetch();
        if (!$t || (int) $t['attempts'] >= 5) {
            return false;
        }
        if (!hash_equals((string) $t['token_hash'], hash('sha256', trim($code)))) {
            $pdo->prepare('UPDATE associate_tokens SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $t['id']]);
            return false;
        }
        $pdo->prepare('UPDATE associate_tokens SET used_at = NOW() WHERE id = :id')->execute(['id' => $t['id']]);
        self::regenerate();
        unset($_SESSION[self::SESSION_PENDING]);
        $_SESSION[self::SESSION_ID] = (int) $id;
        $_SESSION[self::SESSION_PASSWORD_FP] = hash('sha256', (string) (AssociateRepository::find((int) $id)['password_hash'] ?? ''));
        AssociateRepository::setFields((int) $id, ['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => date('Y-m-d H:i:s')]);
        AuditLogRepository::log(null, 'ASSOCIATE_LOGIN', 'associates', (int) $id);
        return true;
    }

    public static function pendingId(): ?int
    {
        return isset($_SESSION[self::SESSION_PENDING]) ? (int) $_SESSION[self::SESSION_PENDING] : null;
    }

    public static function currentId(): ?int
    {
        return isset($_SESSION[self::SESSION_ID]) ? (int) $_SESSION[self::SESSION_ID] : null;
    }

    public static function current(): ?array
    {
        $id = self::currentId();
        return $id === null ? null : AssociateRepository::find($id);
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_ID], $_SESSION[self::SESSION_PENDING], $_SESSION[self::SESSION_VIEW_AS], $_SESSION[self::SESSION_PASSWORD_FP]);
        self::regenerate();
    }

    /** Staff "view as associate" — read-only, audit-logged (part 6.2). */
    public static function startViewAs(int $staffId, int $associateId): void
    {
        self::regenerate();
        $_SESSION[self::SESSION_ID] = $associateId;
        $_SESSION[self::SESSION_VIEW_AS] = $staffId;
        AuditLogRepository::log($staffId, 'ASSOCIATE_VIEW_AS_STARTED', 'associates', $associateId);
    }

    public static function viewAsStaffId(): ?int
    {
        return isset($_SESSION[self::SESSION_VIEW_AS]) ? (int) $_SESSION[self::SESSION_VIEW_AS] : null;
    }

    public static function endViewAs(): ?int
    {
        $staff = self::viewAsStaffId();
        $id = self::currentId();
        if ($staff !== null && $id !== null) {
            AuditLogRepository::log($staff, 'ASSOCIATE_VIEW_AS_ENDED', 'associates', $id);
        }
        unset($_SESSION[self::SESSION_ID], $_SESSION[self::SESSION_VIEW_AS]);
        return $id;
    }

    private static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    public static function showCodeOnScreen(): bool
    {
        return Env::isLocal();
    }
}
