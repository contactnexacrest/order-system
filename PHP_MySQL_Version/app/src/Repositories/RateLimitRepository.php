<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * QA-5 INT-05: backs App\Helpers\RateLimiter. See rate_limit_hits' own
 * comment in docs/schema.sql — one row per attempt, counted over a trailing
 * window rather than a running counter, so there's nothing to reset.
 */
final class RateLimitRepository
{
    public static function recordHit(string $bucketKey, string $ip): void
    {
        Database::connection()->prepare(
            'INSERT INTO rate_limit_hits (bucket_key, ip_address) VALUES (:bucket, :ip)'
        )->execute(['bucket' => $bucketKey, 'ip' => $ip]);
    }

    public static function recentHitCount(string $bucketKey, string $ip, int $withinMinutes): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) AS c FROM rate_limit_hits
             WHERE bucket_key = :bucket AND ip_address = :ip
               AND created_at >= (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->bindValue(':bucket', $bucketKey);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':minutes', $withinMinutes, \PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetch()['c'];
    }
}
