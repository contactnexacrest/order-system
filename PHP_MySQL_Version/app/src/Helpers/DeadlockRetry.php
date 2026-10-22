<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * External QA report L-13 (CONC-03): concurrent order creation deadlocked
 * under real load — 1-2 of 8 parallel creates got an InnoDB "Deadlock
 * found when trying to get lock" (SQLSTATE 40001 / driver error 1213) and
 * surfaced as an uncaught HTTP 500. ReferenceNumberService's own
 * reference_sequences UPSERT is a single point-lock specifically to avoid
 * the deadlock-prone `SELECT ... FOR UPDATE` pattern it replaced (see its
 * docblock) — but OrderController::store()'s transaction also inserts the
 * order row, its stage/payment-status rows, and every product line before
 * committing, and under real concurrent load from several callers that
 * can still deadlock on lock acquisition order between those statements.
 * A deadlock is nobody's bug — MySQL's own documented way of breaking a
 * cycle is to abort one of the transactions and let the caller retry — so
 * this retries the whole attempt a few times with a small jittered
 * backoff before giving up, rather than only retrying the sequence
 * reservation alone (shortening the transaction to just that one step
 * would reopen the "half-created order" gap QA-5 ORD-05 fixed).
 */
final class DeadlockRetry
{
    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(callable $fn, int $maxAttempts = 3)
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $fn();
            } catch (\PDOException $e) {
                if (!self::isDeadlock($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep(random_int(20_000, 80_000) * $attempt);
            }
        }
    }

    private static function isDeadlock(\PDOException $e): bool
    {
        if ($e->getCode() === '40001') {
            return true;
        }
        $driverCode = $e->errorInfo[1] ?? null;
        return $driverCode === 1213;
    }
}
