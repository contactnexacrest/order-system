<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Handles the short-lived one-time code for email/SMS 2FA. Deliberately
 * NOT stored in the users.two_fa_secret column — that column holds each
 * user's persistent 2FA configuration, whereas a login OTP is single-use
 * and expires in minutes, so it lives in the session instead, scoped to
 * the in-progress login attempt only.
 */
final class TwoFactorService
{
    private const SESSION_KEY = '_2fa_pending';
    private const CODE_TTL_SECONDS = 300; // 5 minutes
    private const MAX_VERIFY_ATTEMPTS = 5;

    public static function issueCodeFor(int $userId, string $method, string $destination): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $_SESSION[self::SESSION_KEY] = [
            'user_id'     => $userId,
            'method'      => $method,
            'code_hash'   => password_hash($code, PASSWORD_DEFAULT),
            'expires_at'  => time() + self::CODE_TTL_SECONDS,
            'attempts'    => 0,
        ];

        $subject = 'Your NexaCrest login verification code';
        $body    = "Your verification code is: {$code}\nThis code expires in 5 minutes. If you did not request this, contact your administrator.";

        if ($method === 'sms' && SmsService::isAvailable()) {
            SmsService::send($destination, "NexaCrest login code: {$code} (expires in 5 min)");
        } else {
            EmailService::sendPlainText($destination, $subject, $body);
        }

        // In local dev only, surface the code so the flow can be tested without
        // a real SMTP/SMS provider wired up. Never happens outside APP_ENV=local.
        if (Env::isLocal()) {
            return $code;
        }
        return '';
    }

    public static function pendingUserId(): ?int
    {
        return $_SESSION[self::SESSION_KEY]['user_id'] ?? null;
    }

    public static function verify(string $submittedCode): bool
    {
        $pending = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$pending) {
            return false;
        }

        if ($pending['attempts'] >= self::MAX_VERIFY_ATTEMPTS) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }

        if (time() > $pending['expires_at']) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }

        $_SESSION[self::SESSION_KEY]['attempts']++;

        if (password_verify($submittedCode, $pending['code_hash'])) {
            unset($_SESSION[self::SESSION_KEY]);
            return true;
        }

        return false;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
