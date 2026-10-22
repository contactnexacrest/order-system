<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Middleware\SessionAuth;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-26: "Security: changing your own password does
 * not end your other logged-in sessions (a stolen session stays
 * valid)." users.session_version is now bumped on every password
 * change (self-service, forgot-password, or admin force-reset) and
 * snapshotted into the session at login time; SessionAuth::required()
 * compares the two on every request, so a session that logged in
 * BEFORE the change — the attacker's stolen one, in the real scenario
 * this defends against — is refused on its very next request, the
 * same immediate-effect guarantee AUTH-10 already gives for
 * deactivation.
 */
final class SessionVersionInvalidationTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    public function testAnotherSessionIsLoggedOutAfterThisUsersPasswordChanges(): void
    {
        $userId = $this->createTestUser('Export Executive');

        // Simulates two separate open sessions for the same user — e.g.
        // a legitimate session and a stolen one — each snapshotting
        // session_version at its own login time.
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_session_version'] = AuthService::sessionVersion() ?? 0;
        $otherSessionVersion = $_SESSION['_session_version'];

        $middleware = SessionAuth::required();
        self::assertTrue($middleware([]), 'a freshly logged-in session must pass through normally');

        // The SAME user changes their password from a different
        // session/device — AuthService::changePassword() bumps
        // session_version in the DB but only refreshes THIS session's
        // own copy when currentUserId() matches, which it won't here
        // since this simulates a call from elsewhere.
        $_SESSION['_auth_user_id'] = null; // simulate "elsewhere": not this session
        AuthService::changePassword($userId, 'NewPassword123!Zz');

        // Restore this (the OTHER, still-open) session's old state.
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_session_version'] = $otherSessionVersion;

        self::assertFalse($middleware([]), 'a session that logged in before the password change must be refused on its next request');
        self::assertNull(AuthService::currentUserId(), 'the stale session itself must be torn down, not just the request refused');
    }

    public function testTheSessionThatMadeTheChangeItselfKeepsWorking(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_session_version'] = AuthService::sessionVersion() ?? 0;

        $middleware = SessionAuth::required();
        self::assertTrue($middleware([]));

        AuthService::changePassword($userId, 'NewPassword123!Zz');

        self::assertTrue($middleware([]), 'the session that made the change must keep working, not log itself out');
    }

    public function testAdminForceResetAlsoInvalidatesTheUsersExistingSession(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_session_version'] = AuthService::sessionVersion() ?? 0;

        $middleware = SessionAuth::required();
        self::assertTrue($middleware([]));

        UserRepository::adminForceResetPassword($userId, password_hash('TempPass123!Zz', PASSWORD_DEFAULT));

        self::assertFalse($middleware([]), 'an admin-forced password reset must invalidate the existing session too — this is often used BECAUSE a session is suspected compromised');
    }
}
