<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Repositories\RateLimitRepository;

/**
 * QA-5 INT-05: the public quotation-intake form (spec Section 16's actual
 * entry point into the system — no auth, no CAPTCHA, no per-order token
 * like PI-intake has) accepted unlimited submissions from a single IP; the
 * QA report's own reproduction sent 30 requests in a burst and all 30 were
 * accepted into the staff review queue. A scripted flood — from a bored
 * outsider or a client/ex-employee wanting to bury the intake queue in
 * noise — costs the attacker nothing and drowns real prospects in the same
 * queue. This is a simple IP+bucket sliding-window limiter backed by
 * rate_limit_hits (see its own schema comment); every call records a hit
 * before counting, so a request that's itself rejected still counts toward
 * the window instead of giving a flood a free retry.
 */
final class RateLimiter
{
    /** @return bool true if this request should be BLOCKED (limit already exceeded) */
    public static function tooManyRequests(string $bucketKey, string $ip, int $maxRequests, int $withinMinutes): bool
    {
        RateLimitRepository::recordHit($bucketKey, $ip);
        return RateLimitRepository::recentHitCount($bucketKey, $ip, $withinMinutes) > $maxRequests;
    }
}
