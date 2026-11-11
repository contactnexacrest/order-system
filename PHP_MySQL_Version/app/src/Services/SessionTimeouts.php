<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Helpers\Csrf;
use App\Repositories\CompanySettingsRepository;

/**
 * Owner decision D-283 (11 Nov 2026, point 21): the idle timeouts set in
 * Admin › Settings › Security really apply.
 *
 *  - Separate idle timeouts: staff 4 h, buyers 2 h, associates 2 h
 *    (session_timeout_staff_minutes / _client_ / _associate_).
 *  - Staff log in again at least every 12 h (session_absolute_staff_hours),
 *    however active they are.
 *  - PHP itself no longer throws the session away early: its garbage
 *    collection lifetime (session.gc_maxlifetime, often 24 minutes on a host)
 *    and the session cookie are set to the longest idle timeout, and session
 *    files live in the app's own storage folder, where no system clean-up job
 *    with a shorter lifetime removes them.
 *  - Every page of a signed-in area carries a <meta name="session-idle">; app.js
 *    shows a warning 2 minutes before the end with "Stay logged in", which posts
 *    to the area's keep-alive address.
 */
final class SessionTimeouts
{
    public const AREAS = ['staff', 'client', 'associate'];
    public const KEYS = ['staff' => 'session_timeout_staff_minutes', 'client' => 'session_timeout_client_minutes', 'associate' => 'session_timeout_associate_minutes'];
    public const DEFAULTS = ['staff' => 240, 'client' => 120, 'associate' => 120];
    public const ABSOLUTE_KEY = 'session_absolute_staff_hours';
    public const ABSOLUTE_DEFAULT = 12;
    public const WARN_SECONDS = 120;
    public const KEEPALIVE = ['staff' => '/keep-alive', 'client' => '/client/keep-alive', 'associate' => '/associate/keep-alive'];
    public const LOGIN = ['staff' => '/login', 'client' => '/client/login', 'associate' => '/associate/login'];
    /** Session key: when the staff member signed in (for the 12-hour limit). */
    public const STAFF_LOGIN_AT_KEY = '_staff_login_at_ts';

    /** @var array{area:string, idle:int, absolute_left:?int}|null the signed-in area of this page (set by the auth middleware) */
    public static ?array $current = null;

    /** Idle minutes for an area, from Admin (5 to 1440). */
    public static function idleMinutes(string $area): int
    {
        $raw = null;
        try {
            $raw = CompanySettingsRepository::get(self::KEYS[$area] ?? self::KEYS['staff']);
        } catch (\Throwable $e) {
            // No database yet (early bootstrap): the default.
        }
        $m = $raw === null || trim((string) $raw) === '' ? self::DEFAULTS[$area] ?? 240 : (int) $raw;
        return max(5, min(1440, $m));
    }

    /** Staff absolute limit in hours, from Admin (1 to 24). */
    public static function absoluteHours(): int
    {
        $raw = null;
        try {
            $raw = CompanySettingsRepository::get(self::ABSOLUTE_KEY);
        } catch (\Throwable $e) {
        }
        $h = $raw === null || trim((string) $raw) === '' ? self::ABSOLUTE_DEFAULT : (int) $raw;
        return max(1, min(24, $h));
    }

    /** The longest idle timeout of the three areas, in seconds — the session's own life on the server and in the cookie. */
    public static function maxIdleSeconds(): int
    {
        $max = 0;
        foreach (self::AREAS as $a) {
            $max = max($max, self::idleMinutes($a) * 60);
        }
        return $max;
    }

    /** Whether the idle limit of an area has passed since $lastActivity (a Unix time, null = no activity yet). */
    public static function idleExpired(string $area, ?int $lastActivity, ?int $now = null): bool
    {
        $now ??= time();
        return $lastActivity !== null && ($now - $lastActivity) > self::idleMinutes($area) * 60;
    }

    /** Whether the staff 12-hour limit has passed since $loginAt. */
    public static function absoluteExpired(?int $loginAt, ?int $now = null): bool
    {
        $now ??= time();
        return $loginAt !== null && ($now - $loginAt) > self::absoluteHours() * 3600;
    }

    /**
     * Called from bootstrap.php before session_start(): PHP's own session
     * life follows the Admin setting instead of the host's default.
     */
    public static function configurePhpSession(): int
    {
        $lifetime = self::maxIdleSeconds();
        ini_set('session.gc_maxlifetime', (string) ($lifetime + 300));
        $dir = rtrim(Env::storageBasePath(), '/') . '/sessions';
        if ($dir !== '/sessions' && (is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            session_save_path($dir);
        }
        return $lifetime;
    }

    /** Re-sends the session cookie with a fresh expiry on every request (rolling), so an active person is never cut off by the cookie. */
    public static function refreshCookie(int $lifetime, bool $secure): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        setcookie(session_name(), (string) session_id(), [
            'expires' => time() + $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** "4 hours", "90 minutes", "1 hour" — for the signed-out message. */
    public static function describeMinutes(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            $h = intdiv($minutes, 60);
            return $h . ' hour' . ($h === 1 ? '' : 's');
        }
        return $minutes . ' minutes';
    }

    /** The auth middleware marks the page's area (for the warning popup). */
    public static function markPage(string $area, ?int $absoluteLeft = null): void
    {
        self::$current = ['area' => $area, 'idle' => self::idleMinutes($area) * 60, 'absolute_left' => $absoluteLeft];
    }

    /** Seconds left before the staff 12-hour limit (null when there is no login time). */
    public static function staffAbsoluteLeft(?int $loginAt, ?int $now = null): ?int
    {
        if ($loginAt === null) {
            return null;
        }
        return max(0, $loginAt + self::absoluteHours() * 3600 - ($now ?? time()));
    }

    /** The <meta> tag the layouts print; empty when the page is not inside a signed-in area. */
    public static function metaTag(): string
    {
        if (self::$current === null) {
            return '';
        }
        $c = self::$current;
        $attr = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        return '<meta name="session-idle" content="' . (int) $c['idle'] . '"'
            . ' data-warn="' . self::WARN_SECONDS . '"'
            . ($c['absolute_left'] !== null ? ' data-absolute-left="' . (int) $c['absolute_left'] . '"' : '')
            . ' data-keepalive="' . $attr(self::KEEPALIVE[$c['area']]) . '"'
            . ' data-login="' . $attr(self::LOGIN[$c['area']]) . '"'
            . ' data-csrf="' . $attr(Csrf::token()) . '">';
    }
}
