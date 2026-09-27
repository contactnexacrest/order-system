<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\AuthService;
use App\Services\ClientPortalService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 CP-13: staff (_auth_user_id) and client-portal (_client_portal_client_id)
 * logins are two independent, coexisting namespaces in the same session —
 * a staff member testing the client portal in another tab of the same
 * browser, for instance. AuthService::logout() used to do `$_SESSION = [];`,
 * wiping BOTH namespaces just from logging out of one. ClientPortalService's
 * own logout() was already scoped correctly (unset its own key only); this
 * pins that it stays that way and that the staff side now matches it.
 */
final class DualSessionIsolationTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // logout() calls session_regenerate_id(), which PHP warns about
        // without an actual active session — CLI PHPUnit never starts one
        // on its own (same setup as SessionExpiryGuardTest).
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    public function testStaffLogoutDoesNotTouchAnActiveClientPortalSession(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_client_portal_client_id'] = $clientId;

        AuthService::logout();

        self::assertNull(AuthService::currentUserId(), 'the staff session must actually end');
        self::assertSame($clientId, ClientPortalService::currentClientId(), 'an unrelated client-portal session in the same browser must survive');
    }

    public function testClientPortalLogoutDoesNotTouchAnActiveStaffSession(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $_SESSION['_auth_user_id'] = $userId;
        $_SESSION['_client_portal_client_id'] = $clientId;

        ClientPortalService::logout();

        self::assertNull(ClientPortalService::currentClientId(), 'the client-portal session must actually end');
        self::assertSame($userId, AuthService::currentUserId(), 'an unrelated staff session in the same browser must survive');
    }
}
