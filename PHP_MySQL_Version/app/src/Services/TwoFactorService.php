<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Repositories\EmailTemplateRepository;

/**
 * The 6-digit login code e-mailed at every sign-in (owner decision D-265,
 * 7 Nov 2026): every staff member, every buyer. Associates have their own
 * code (AssociatePortalService), unchanged.
 *
 * The code is short-lived and single-use, so it lives in the session,
 * scoped to the sign-in in progress — never in users.two_fa_secret.
 * Each portal has its own pending slot ('staff' / 'client'), so a staff
 * sign-in and a buyer sign-in in the same browser never mix.
 *
 * Rules (the same as the associate code): valid 10 minutes, 5 tries, a new
 * code at most once a minute. Always by e-mail (SMS stays off), sent as
 * security mail — it goes out even when Admin has switched business e-mail off.
 */
final class TwoFactorService
{
    public const CODE_TTL_SECONDS = 600; // 10 minutes
    public const MAX_VERIFY_ATTEMPTS = 5;
    public const RESEND_AFTER_SECONDS = 60;

    /** D-280: the buyer's own login-code wording (template client_login_code; this is its fallback). */
    public const CLIENT_CODE_BODY = "Hello,\n\nYour code to log in to your NexaCrest order portal is: {code}\n\nIt is valid for 10 minutes.\n\nIf you did not try to log in, you can ignore this e-mail — nobody can log in without this code. If it keeps happening, change your password with \"Forgot your password?\" on the login page.\n\nNexaCrest International Private Limited";

    private const SESSION_KEYS = ['staff' => '_2fa_pending', 'client' => '_client_2fa_pending'];

    private static function key(string $scope): string
    {
        return self::SESSION_KEYS[$scope] ?? self::SESSION_KEYS['staff'];
    }

    /** Sends a new code by e-mail. Returns the code only in local dev (APP_ENV=local), else ''. */
    public static function issueCodeFor(int $subjectId, string $method, string $destination, string $scope = 'staff'): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION[self::key($scope)] = [
            'user_id'     => $subjectId,
            'method'      => 'email',
            'destination' => $destination,
            'code_hash'   => password_hash($code, PASSWORD_DEFAULT),
            'issued_at'   => time(),
            'expires_at'  => time() + self::CODE_TTL_SECONDS,
            'attempts'    => 0,
        ];
        // 90-item backlog #37: admin-editable (Admin > Settings > Email Templates), with this
        // wording as the fallback, since a login code must never silently fail to go out.
        // D-280: each audience gets its own plain wording — a buyer has no "administrator".
        $rendered = $scope === 'client'
            ? EmailTemplateRepository::renderOrDefault(
                'client_login_code',
                ['{code}' => $code],
                'Your NexaCrest order portal login code',
                self::CLIENT_CODE_BODY
            )
            : EmailTemplateRepository::renderOrDefault(
                'two_factor_code',
                ['{code}' => $code],
                'Your NexaCrest login verification code',
                "Your verification code is: {code}\nThis code expires in 10 minutes. If you did not request this, contact your administrator."
            );
        EmailService::sendPlainText($destination, $rendered['subject'], $rendered['body'], true, 'login_code', $scope === 'client' ? 'buyer' : 'staff');

        // Local dev only: the code is shown on screen so the flow can be tried without a mail server.
        return Env::isLocal() ? $code : '';
    }

    public static function pendingUserId(string $scope = 'staff'): ?int
    {
        $p = $_SESSION[self::key($scope)] ?? null;
        return $p ? (int) $p['user_id'] : null;
    }

    /**
     * A new code for the sign-in in progress, at most once a minute.
     * @return array{status:string, wait?:int, dev_code?:string} status 'sent' | 'too_soon' | 'none'
     */
    public static function resend(string $scope = 'staff'): array
    {
        $p = $_SESSION[self::key($scope)] ?? null;
        if (!$p) {
            return ['status' => 'none'];
        }
        $wait = (int) $p['issued_at'] + self::RESEND_AFTER_SECONDS - time();
        if ($wait > 0) {
            return ['status' => 'too_soon', 'wait' => $wait];
        }
        $dev = self::issueCodeFor((int) $p['user_id'], 'email', (string) $p['destination'], $scope);
        return ['status' => 'sent', 'dev_code' => $dev];
    }

    /** Counts a try against the pending sign-in; false (and the sign-in ends) once 5 tries are used or it expired. */
    private static function useTry(string $scope): bool
    {
        $key = self::key($scope);
        $p = $_SESSION[$key] ?? null;
        if (!$p) {
            return false;
        }
        if ($p['attempts'] >= self::MAX_VERIFY_ATTEMPTS || time() > $p['expires_at']) {
            unset($_SESSION[$key]);
            return false;
        }
        $_SESSION[$key]['attempts']++;
        return true;
    }

    public static function verify(string $submittedCode, string $scope = 'staff'): bool
    {
        if (!self::useTry($scope)) {
            return false;
        }
        $key = self::key($scope);
        if (password_verify(trim($submittedCode), (string) $_SESSION[$key]['code_hash'])) {
            unset($_SESSION[$key]);
            return true;
        }
        if ($_SESSION[$key]['attempts'] >= self::MAX_VERIFY_ATTEMPTS) {
            unset($_SESSION[$key]);
        }
        return false;
    }

    /** A backup-code try counts against the same 5 tries; the caller checks the backup code itself. */
    public static function countBackupTry(string $scope = 'staff'): bool
    {
        return self::useTry($scope);
    }

    public static function clear(string $scope = 'staff'): void
    {
        unset($_SESSION[self::key($scope)]);
    }
}
