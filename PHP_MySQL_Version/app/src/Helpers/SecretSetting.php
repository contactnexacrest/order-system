<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Owner decision 1 Nov 2026 (C-14, O-14): the Zoho client secrets and
 * refresh tokens are never shown on Company Settings, nor written to the
 * audit log. The page shows only the last 4 characters; typing a new value
 * replaces it, an empty field keeps the stored one.
 */
final class SecretSetting
{
    public const KEYS = ['zoho_client_secret', 'zoho_refresh_token', 'zoho_books_client_secret', 'zoho_books_refresh_token'];

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** "not set" or "•••• stored, ends in 1a2b". */
    public static function hint(?string $value): string
    {
        $value = (string) $value;
        return $value === '' ? 'not set' : '•••• stored, ends in ' . mb_substr($value, -4);
    }

    /** What the audit log records instead of the secret itself. */
    public static function forAudit(?string $value): ?string
    {
        return $value === null || $value === '' ? $value : '(secret, ends in ' . mb_substr($value, -4) . ')';
    }
}
