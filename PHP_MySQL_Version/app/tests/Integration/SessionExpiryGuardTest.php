<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Middleware\ClientAuth;
use App\Middleware\SessionAuth;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\ClientPortalService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (AUTH-10 / CP-06 / CP-12 — external QA report cross-verification):
 * deactivating a staff user or client used to only ever be checked at
 * login time — an already-open session (staff or client portal) kept
 * working indefinitely afterward, and the client portal had no idle
 * timeout at all (staff already had one). A revenge-minded employee or
 * client, deactivated the moment their behaviour is noticed, must lose
 * access on their very next request, not whenever they happen to log out.
 */
final class SessionExpiryGuardTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Unlike most controller tests here, this class exercises real
        // logout() calls (session_regenerate_id()), which PHP warns about
        // without an actual active session — CLI PHPUnit never starts one
        // on its own.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    public function testADeactivatedStaffUserIsLoggedOutOnItsNextRequest(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;

        $middleware = SessionAuth::required();
        self::assertTrue($middleware([]), 'an active user must pass through normally');

        UserRepository::setActive($userId, false);

        self::assertFalse($middleware([]), 'a deactivated user must be refused on their very next request');
        self::assertNull(AuthService::currentUserId(), 'the session itself must be torn down, not just the request refused');
    }

    public function testAClientDeactivatedAtTheCompanyLevelIsLoggedOutOfThePortal(): void
    {
        $clientId = $this->createTestClient();
        $this->provisionClientLogin($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;

        $middleware = ClientAuth::required();
        self::assertTrue($middleware([]), 'an active client must pass through normally');

        ClientRepository::setActive($clientId, false);

        self::assertFalse($middleware([]), 'a company-deactivated client must be refused on their very next request');
        self::assertNull(ClientPortalService::currentClientId());
    }

    public function testAClientWithItsPortalLoginSpecificallyDisabledIsLoggedOut(): void
    {
        $clientId = $this->createTestClient();
        $this->provisionClientLogin($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;

        $middleware = ClientAuth::required();
        self::assertTrue($middleware([]));

        Database::connection()
            ->prepare('UPDATE client_logins SET is_active = 0 WHERE client_id = :cid')
            ->execute(['cid' => $clientId]);

        self::assertFalse($middleware([]), 'a client whose portal login alone was disabled must be refused too');
    }

    public function testTheClientPortalEnforcesTheSameIdleTimeoutAsStaff(): void
    {
        CompanySettingsRepository::set('session_timeout_minutes', '5');

        $clientId = $this->createTestClient();
        $this->provisionClientLogin($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_SESSION['_client_last_activity_ts'] = time() - (10 * 60); // 10 minutes idle

        $middleware = ClientAuth::required();
        self::assertFalse($middleware([]), 'an idle client-portal session must expire, matching the staff-side timeout');
        self::assertNull(ClientPortalService::currentClientId());
    }

    private function provisionClientLogin(int $clientId): void
    {
        Database::connection()
            ->prepare('INSERT INTO client_logins (client_id, password_hash) VALUES (:cid, :hash)')
            ->execute(['cid' => $clientId, 'hash' => password_hash('irrelevant', PASSWORD_DEFAULT)]);
    }
}
