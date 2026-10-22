<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-03: with "lock after N", the account used to lock
 * on the (N+1)th failed attempt, not the Nth. attemptLogin() called
 * LoginAttemptRepository::recentFailedCount() to decide whether to lock
 * BEFORE calling LoginAttemptRepository::record() for the attempt that was
 * just made — so the count it read never included the very failure that
 * triggered the check. On the Nth failure the count was only N-1 (N-1 >= N
 * is false), and the account only actually locked on the (N+1)th attempt,
 * once that count finally reached N. Fixed by recording the attempt first.
 */
final class AccountLockoutThresholdTest extends DbTestCase
{
    private const KNOWN_PASSWORD = 'CorrectHorseBatteryStaple42!';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        // Deterministic threshold, independent of whatever seed.sql ships.
        CompanySettingsRepository::set('failed_login_lockout_count', '3');
        CompanySettingsRepository::set('lockout_duration_minutes', '15');
    }

    public function testAccountLocksOnTheConfiguredNthFailureNotTheNPlusOneth(): void
    {
        $userId = $this->createTestUserWithKnownPassword('Export Executive');
        $email = $this->emailFor($userId);

        // Failures 1 and 2 (below the threshold of 3): must NOT lock yet.
        AuthService::attemptLogin($email, 'wrong-password');
        self::assertNull(UserRepository::findById($userId)['locked_until'], 'must not lock before the configured threshold');

        AuthService::attemptLogin($email, 'wrong-password');
        self::assertNull(UserRepository::findById($userId)['locked_until'], 'must not lock before the configured threshold');

        // Failure 3 — exactly the configured threshold — must lock NOW,
        // not on a 4th attempt.
        AuthService::attemptLogin($email, 'wrong-password');
        self::assertNotNull(UserRepository::findById($userId)['locked_until'], 'must lock on the Nth failure, not the (N+1)th');

        // And the correct password is now refused too, proving the lock is
        // real, not just a DB column with no effect.
        $result = AuthService::attemptLogin($email, self::KNOWN_PASSWORD);
        self::assertSame('locked_out', $result['status']);
    }

    private function createTestUserWithKnownPassword(string $roleName): int
    {
        $userId = $this->createTestUser($roleName);
        UserRepository::updatePassword($userId, password_hash(self::KNOWN_PASSWORD, PASSWORD_DEFAULT), false);
        return $userId;
    }

    private function emailFor(int $userId): string
    {
        return (string) UserRepository::findById($userId)['email'];
    }
}
