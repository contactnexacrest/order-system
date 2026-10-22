<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\DeadlockRetry;
use PHPUnit\Framework\TestCase;

/**
 * External QA report L-13 (CONC-03): concurrent order creation deadlocked
 * under real load (1-2 of 8 parallel creates got an uncaught SQLSTATE
 * 40001 / driver error 1213, surfacing as HTTP 500). DeadlockRetry is the
 * fix — see its own docblock for why retrying the whole attempt, not a
 * mock PDO connection (too heavy for a pure unit test), drives these.
 */
final class DeadlockRetryTest extends TestCase
{
    private function deadlockException(): \PDOException
    {
        $e = new \PDOException("SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction");
        $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
        return $e;
    }

    public function testReturnsTheResultImmediatelyWhenNothingFails(): void
    {
        $result = DeadlockRetry::run(fn () => 'ok');

        self::assertSame('ok', $result);
    }

    public function testRetriesAfterADeadlockAndReturnsTheEventualResult(): void
    {
        $attempts = 0;
        $result = DeadlockRetry::run(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                throw $this->deadlockException();
            }
            return 'succeeded on attempt ' . $attempts;
        });

        self::assertSame('succeeded on attempt 2', $result);
        self::assertSame(2, $attempts);
    }

    public function testGivesUpAfterMaxAttemptsAndRethrowsTheDeadlock(): void
    {
        $attempts = 0;
        $this->expectException(\PDOException::class);

        try {
            DeadlockRetry::run(function () use (&$attempts) {
                $attempts++;
                throw $this->deadlockException();
            }, 3);
        } finally {
            self::assertSame(3, $attempts, 'must try exactly maxAttempts times, no more, no fewer');
        }
    }

    public function testNeverRetriesAnUnrelatedPdoException(): void
    {
        $attempts = 0;
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('a syntax error, not a deadlock');

        try {
            DeadlockRetry::run(function () use (&$attempts) {
                $attempts++;
                throw new \PDOException('a syntax error, not a deadlock');
            });
        } finally {
            self::assertSame(1, $attempts, 'a non-deadlock PDOException must fail fast, not burn retries');
        }
    }

    public function testNeverRetriesANonPdoException(): void
    {
        $attempts = 0;
        $this->expectException(\RuntimeException::class);

        try {
            DeadlockRetry::run(function () use (&$attempts) {
                $attempts++;
                throw new \RuntimeException('unrelated application error');
            });
        } finally {
            self::assertSame(1, $attempts);
        }
    }
}
