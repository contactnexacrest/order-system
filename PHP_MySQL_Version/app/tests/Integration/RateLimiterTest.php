<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Helpers\RateLimiter;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 INT-05: pins RateLimiter's sliding-window contract directly against
 * the real rate_limit_hits table. A unique bucket key per test avoids any
 * collision with concurrent tests or leftover rows from a previous run.
 */
final class RateLimiterTest extends DbTestCase
{
    public function testAllowsUpToTheLimitThenBlocks(): void
    {
        $bucket = 'phpunit-' . bin2hex(random_bytes(8));
        $ip = '10.0.0.1';

        for ($i = 1; $i <= 5; $i++) {
            self::assertFalse(
                RateLimiter::tooManyRequests($bucket, $ip, 5, 15),
                "request {$i} of 5 must be allowed"
            );
        }

        self::assertTrue(RateLimiter::tooManyRequests($bucket, $ip, 5, 15), 'the 6th request must be blocked');
        self::assertTrue(RateLimiter::tooManyRequests($bucket, $ip, 5, 15), 'requests beyond the limit stay blocked');
    }

    public function testLimitsAreIsolatedPerIp(): void
    {
        $bucket = 'phpunit-' . bin2hex(random_bytes(8));

        for ($i = 1; $i <= 5; $i++) {
            RateLimiter::tooManyRequests($bucket, '10.0.0.2', 5, 15);
        }
        self::assertTrue(RateLimiter::tooManyRequests($bucket, '10.0.0.2', 5, 15), '10.0.0.2 must now be blocked');
        self::assertFalse(
            RateLimiter::tooManyRequests($bucket, '10.0.0.3', 5, 15),
            'a different IP in the same bucket must not be affected'
        );
    }

    public function testLimitsAreIsolatedPerBucket(): void
    {
        $ip = '10.0.0.4';
        $bucketA = 'phpunit-a-' . bin2hex(random_bytes(8));
        $bucketB = 'phpunit-b-' . bin2hex(random_bytes(8));

        for ($i = 1; $i <= 5; $i++) {
            RateLimiter::tooManyRequests($bucketA, $ip, 5, 15);
        }
        self::assertTrue(RateLimiter::tooManyRequests($bucketA, $ip, 5, 15), 'bucketA must now be blocked');
        self::assertFalse(
            RateLimiter::tooManyRequests($bucketB, $ip, 5, 15),
            'the same IP in a different bucket must not be affected'
        );
    }
}
