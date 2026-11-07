<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Repositories\CompanySettingsRepository;

/**
 * "Remember this browser for N days" for staff (owner decision D-265,
 * 7 Nov 2026). Admin › Settings › Security › login_remember_browser_days;
 * 0 (the default) means the e-mailed code is asked at every login.
 *
 * When on, a staff member who ticks the box on the code screen gets a cookie
 * "selector.validator.signature": HttpOnly, SameSite=Lax, Secure except in
 * local dev, signed with APP_ENCRYPTION_KEY. The server keeps the selector,
 * a SHA-256 of the validator, the user and their session_version
 * (login_remembered_browsers). A password change or reset bumps
 * session_version and deletes the rows, so every remembered browser is
 * forgotten. Buyers and associates never get this.
 */
final class RememberBrowserService
{
    public const COOKIE = 'nexacrest_trusted_browser';
    public const MAX_DAYS = 90;

    public static function days(): int
    {
        $d = (int) (CompanySettingsRepository::get('login_remember_browser_days') ?? '0');
        return max(0, min(self::MAX_DAYS, $d));
    }

    private static function sign(string $payload): string
    {
        $key = (string) (Env::get('APP_ENCRYPTION_KEY', '') ?? '');
        return hash_hmac('sha256', $payload, 'remember-browser|' . $key);
    }

    /** Remembers this browser for the user; returns the cookie value ('' when the setting is 0). */
    public static function remember(int $userId): string
    {
        $days = self::days();
        if ($days <= 0) {
            return '';
        }
        $user = \App\Repositories\UserRepository::findById($userId);
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = time() + $days * 86400;
        Database::connection()->prepare(
            'INSERT INTO login_remembered_browsers (user_id, selector, validator_hash, session_version, expires_at)
             VALUES (:u, :s, :h, :v, :e)'
        )->execute([
            'u' => $userId, 's' => $selector, 'h' => hash('sha256', $validator),
            'v' => (int) ($user['session_version'] ?? 0), 'e' => date('Y-m-d H:i:s', $expires),
        ]);
        $value = $selector . '.' . $validator . '.' . self::sign($selector . '.' . $validator);
        if (!headers_sent()) {
            setcookie(self::COOKIE, $value, [
                'expires' => $expires, 'path' => '/', 'secure' => !Env::isLocal(), 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $value;
        return $value;
    }

    /** True when this browser carries a valid, unexpired, still-current remembered sign-in for the user. */
    public static function isRemembered(array $user, ?string $cookie = null): bool
    {
        if (self::days() <= 0) {
            return false;
        }
        $cookie ??= (string) ($_COOKIE[self::COOKIE] ?? '');
        $parts = explode('.', $cookie);
        if (count($parts) !== 3 || !hash_equals(self::sign($parts[0] . '.' . $parts[1]), $parts[2])) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM login_remembered_browsers WHERE selector = :s LIMIT 1');
        $stmt->execute(['s' => $parts[0]]);
        $row = $stmt->fetch();
        if (!$row
            || (int) $row['user_id'] !== (int) $user['id']
            || strtotime((string) $row['expires_at']) < time()
            || (int) $row['session_version'] !== (int) ($user['session_version'] ?? 0)
            || !hash_equals((string) $row['validator_hash'], hash('sha256', $parts[1]))) {
            return false;
        }
        Database::connection()->prepare('UPDATE login_remembered_browsers SET last_used_at = NOW() WHERE id = :id')->execute(['id' => $row['id']]);
        return true;
    }

    /** Forgets every remembered browser of the user (password change or reset). */
    public static function forgetAll(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM login_remembered_browsers WHERE user_id = :u')->execute(['u' => $userId]);
    }
}
