<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Helpers\Flash;
use App\Repositories\ClientLoginRepository;
use App\Repositories\ClientRepository;
use App\Services\ClientPortalService;
use App\Tests\Support\DbTestCase;

/**
 * Order Detail Page Polish follow-up: for a non-technical client locked
 * out of the portal who can't follow their own "Forgot password" email
 * link, there was no path at all before this — not even via staff
 * impersonation, since the client's own /client/account "Change
 * Password" form requires their CURRENT password, which staff can never
 * know. ClientController::resetPortalPassword() mirrors
 * UserController::forceResetPassword() exactly, against client_logins
 * instead of users.
 */
final class ClientPasswordAdminResetTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // ClientPortalService::attemptLogin() (exercised by the
        // login-check test below) calls session_regenerate_id(), which
        // PHP warns about without an actual active session — same setup
        // as ClientImpersonationTest.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_POST = [];
        $_SESSION = [];
    }

    private function provisionLogin(int $clientId, string $passwordHash = 'irrelevant-starting-hash'): void
    {
        Database::connection()->prepare(
            'INSERT INTO client_logins (client_id, password_hash, force_password_change) VALUES (:cid, :hash, 0)'
        )->execute(['cid' => $clientId, 'hash' => $passwordHash]);
    }

    private function auditLogCount(string $actionType, int $entityId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action_type = :action AND entity_type = 'clients' AND entity_id = :entity_id"
        );
        $stmt->execute(['action' => $actionType, 'entity_id' => $entityId]);
        return (int) $stmt->fetchColumn();
    }

    public function testRefusesWhenNoPortalAccountExistsYet(): void
    {
        $clientId = $this->createTestClient(); // never provisioned a client_logins row
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['reason'] = 'Client called asking for a reset';

        ob_start();
        (new ClientController())->resetPortalPassword(['id' => $clientId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('no client-portal account yet', $flash[0]['message']);
        self::assertSame(0, $this->auditLogCount('CLIENT_PASSWORD_ADMIN_RESET', $clientId));
    }

    public function testRefusesWithoutAReason(): void
    {
        $clientId = $this->createTestClient();
        $this->provisionLogin($clientId);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['reason'] = 'short';

        ob_start();
        (new ClientController())->resetPortalPassword(['id' => $clientId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertSame(0, $this->auditLogCount('CLIENT_PASSWORD_ADMIN_RESET', $clientId));
    }

    public function testSucceedsAndTheNewTemporaryPasswordActuallyLogsIn(): void
    {
        $clientId = $this->createTestClient('reset-login-check-' . bin2hex(random_bytes(4)) . '@nexacrest.test');
        $this->provisionLogin($clientId);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['reason'] = 'Client called, forgot password, cannot access email';

        ob_start();
        (new ClientController())->resetPortalPassword(['id' => $clientId]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        self::assertMatchesRegularExpression('/temporary password: (\S+)/', $flash[0]['message']);
        preg_match('/temporary password: (\S+)/', $flash[0]['message'], $m);
        $tempPassword = $m[1];

        $login = ClientLoginRepository::findByClientId($clientId);
        self::assertTrue((bool) $login['force_password_change'], 'must force a real change on next login');
        self::assertSame(1, $this->auditLogCount('CLIENT_PASSWORD_ADMIN_RESET', $clientId));

        $client = ClientRepository::find($clientId);
        $result = ClientPortalService::attemptLogin((string) $client['email'], $tempPassword);
        self::assertSame('ok', $result['status'], 'the exact temporary password shown to staff must actually log the client in');
        self::assertTrue($result['force_password_change']);
    }

    // ---------------------------------------------------------------
    // View rendering — clients/show.php
    // ---------------------------------------------------------------

    public function testClientPageOffersTheResetButtonWhenAPortalAccountExists(): void
    {
        $clientId = $this->createTestClient();
        $this->provisionLogin($clientId);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        (new ClientController())->show(['id' => (string) $clientId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Reset client-portal password', $output);
        self::assertStringContainsString('action="/clients/' . $clientId . '/reset-password"', $output);
    }

    public function testClientPageExplainsThereIsNoPortalAccountYet(): void
    {
        $clientId = $this->createTestClient(); // no client_logins row
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        (new ClientController())->show(['id' => (string) $clientId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('action="/clients/' . $clientId . '/reset-password"', $output);
        self::assertStringContainsString('no portal account yet', $output);
    }
}
