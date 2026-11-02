<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;

/**
 * Owner decision 2 Nov 2026 (D-239, "Zoho limits yes"): this app's share of
 * the Zoho API calls of the organisation, which every Zoho app and
 * integration of the company draws on.
 *
 * Every Zoho Books API call (reads and the OAuth token call included) goes
 * through ZohoBooksService::request(), which asks acquire() first:
 *  - the day's allowance (setting zoho_api_daily_limit, default 800; the day
 *    runs midnight to midnight India time) used up → ZohoBudgetExhausted,
 *    no call made;
 *  - this minute's allowance (zoho_api_minute_limit, default 30) used up →
 *    the scheduled (background) sync waits for the next minute; anything a
 *    person started (Sync now, Sync this order, Check against Zoho, a
 *    delete) waits at most 5 seconds, otherwise ZohoRateLimited with "Zoho
 *    speed limit for this app reached — try again in a minute."
 * Calls are counted in zoho_api_usage (one row per environment and minute /
 * day), with atomic upserts, so web requests, cron runs and both stacks
 * count together.
 */
final class ZohoApiBudget
{
    public const DEFAULT_DAILY = 800;
    public const DEFAULT_MINUTE = 30;
    public const SPEED_LIMIT_MESSAGE = 'Zoho speed limit for this app reached — try again in a minute.';
    public const RATE_LIMIT_MESSAGE = 'Stopped: Zoho rate limit — remaining records will be sent at the next sync.';

    private static bool $background = false;
    /** @var (callable():int)|null tests: the clock */
    private static $clock = null;
    /** @var (callable(int):void)|null tests: instead of sleeping */
    private static $sleeper = null;
    /** @var array{0:int,1:int}|null tests: [daily, minute] instead of the settings (null = the settings) */
    private static ?array $testLimits = null;

    /** Run $fn as the scheduled (background) sync: the per-minute limit waits instead of refusing. */
    public static function inBackground(callable $fn): mixed
    {
        $before = self::$background;
        self::$background = true;
        try {
            return $fn();
        } finally {
            self::$background = $before;
        }
    }

    /**
     * Tests only: a fake Zoho (ZohoBooksService::setTransport) is not limited
     * — older tests run whole syncs well past 30 calls a minute — unless a
     * test asks for the real limits with useSettingLimits().
     */
    public static function setTestLimits(?int $daily, ?int $minute): void
    {
        self::$testLimits = $daily === null || $minute === null ? null : [$daily, $minute];
    }

    /** Tests only: use the settings (zoho_api_daily_limit / zoho_api_minute_limit). */
    public static function useSettingLimits(): void
    {
        self::$testLimits = null;
    }

    /** Tests only. */
    public static function setClock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    /** Tests only. */
    public static function setSleeper(?callable $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    public static function environment(): string
    {
        return ZohoGuard::environment();
    }

    public static function dailyLimit(): int
    {
        if (self::$testLimits !== null) {
            return self::$testLimits[0];
        }
        $v = (int) (CompanySettingsRepository::get('zoho_api_daily_limit') ?? self::DEFAULT_DAILY);
        return $v > 0 ? $v : self::DEFAULT_DAILY;
    }

    public static function minuteLimit(): int
    {
        if (self::$testLimits !== null) {
            return self::$testLimits[1];
        }
        $v = (int) (CompanySettingsRepository::get('zoho_api_minute_limit') ?? self::DEFAULT_MINUTE);
        return $v > 0 ? $v : self::DEFAULT_MINUTE;
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    private static function ist(int $ts): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Kolkata'));
    }

    public static function dayKey(?int $ts = null): string
    {
        return self::ist($ts ?? self::now())->format('Y-m-d');
    }

    public static function minuteKey(?int $ts = null): string
    {
        return self::ist($ts ?? self::now())->format('Y-m-d H:i');
    }

    private static function count(string $type, string $bucket): int
    {
        $stmt = Database::connection()->prepare('SELECT calls FROM zoho_api_usage WHERE environment = :e AND bucket_type = :t AND bucket = :b');
        $stmt->execute(['e' => self::environment(), 't' => $type, 'b' => $bucket]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** Atomic +1 (or -1); returns the new count. */
    private static function bump(string $type, string $bucket, int $by = 1): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO zoho_api_usage (environment, bucket_type, bucket, calls) VALUES (:e, :t, :b, :n)
                       ON DUPLICATE KEY UPDATE calls = GREATEST(0, calls + :n2)')
            ->execute(['e' => self::environment(), 't' => $type, 'b' => $bucket, 'n' => max(0, $by), 'n2' => $by]);
        return self::count($type, $bucket);
    }

    public static function exhaustedMessage(): string
    {
        return 'Paused: daily Zoho allowance for this app (' . self::dailyLimit() . ' calls) used — resumes tomorrow.';
    }

    public static function exhaustedToday(): bool
    {
        return self::count('day', self::dayKey()) >= self::dailyLimit();
    }

    /** Before every Zoho call: reserve one call of today's and this minute's allowance, or stop. */
    public static function acquire(): void
    {
        if (self::count('day', self::dayKey()) >= self::dailyLimit()) {
            throw new ZohoBudgetExhausted(self::exhaustedMessage());
        }
        $limit = self::minuteLimit();
        $waited = 0;
        while (true) {
            $minute = self::minuteKey();
            if (self::bump('minute', $minute) <= $limit) {
                break;
            }
            self::bump('minute', $minute, -1);
            $wait = 60 - (int) self::ist(self::now())->format('s') + 1;
            if (!self::$background && $waited + $wait > 5) {
                throw new ZohoRateLimited(self::SPEED_LIMIT_MESSAGE);
            }
            $waited += $wait;
            self::$sleeper !== null ? (self::$sleeper)($wait) : sleep($wait);
        }
        if (self::bump('day', self::dayKey()) === 1) {
            // A new day: drop the minute rows of earlier days (the day rows stay for the history).
            Database::connection()->prepare("DELETE FROM zoho_api_usage WHERE bucket_type = 'minute' AND bucket < :d")
                ->execute(['d' => self::dayKey()]);
        }
    }

    /**
     * After a response: Zoho's "too many requests" (HTTP 429, Books codes
     * 44/45, the OAuth "too many requests continuously") stops the run.
     */
    public static function assertNotRateLimited(int $status, string $body): void
    {
        $decoded = json_decode($body, true);
        $code = is_array($decoded) && isset($decoded['code']) ? (int) $decoded['code'] : null;
        $text = is_array($decoded) ? (string) (($decoded['message'] ?? '') . ' ' . ($decoded['error_description'] ?? '') . ' ' . ($decoded['error'] ?? '')) : $body;
        if ($status === 429 || in_array($code, [44, 45], true)
            || preg_match('/too many requests|maximum number of api|api (call|request)s? limit/i', $text) === 1) {
            throw new ZohoRateLimited(self::RATE_LIMIT_MESSAGE);
        }
    }

    /** @return array{environment:string, today:int, minute:int, daily_limit:int, minute_limit:int, exhausted:bool, history:list<array{day:string, calls:int}>} */
    public static function usage(): array
    {
        $stmt = Database::connection()->prepare("SELECT bucket, calls FROM zoho_api_usage WHERE environment = :e AND bucket_type = 'day' AND bucket >= :since ORDER BY bucket DESC");
        $stmt->execute(['e' => self::environment(), 'since' => self::dayKey(self::now() - 6 * 86400)]);
        $history = array_map(static fn (array $r): array => ['day' => (string) $r['bucket'], 'calls' => (int) $r['calls']], $stmt->fetchAll());
        $today = self::count('day', self::dayKey());
        return [
            'environment' => self::environment(),
            'today' => $today,
            'minute' => self::count('minute', self::minuteKey()),
            'daily_limit' => self::dailyLimit(),
            'minute_limit' => self::minuteLimit(),
            'exhausted' => $today >= self::dailyLimit(),
            'history' => $history,
        ];
    }
}
