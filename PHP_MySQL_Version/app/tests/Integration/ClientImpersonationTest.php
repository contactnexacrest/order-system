<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Controllers\ClientPortalController;
use App\Helpers\Flash;
use App\Middleware\ClientAuth;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Services\ClientPortalService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section AV — staff "Log in as this client" impersonation.
 * Three independent gates must ALL hold before the feature does anything:
 * (1) the impersonate_client permission (route-level, not re-tested here —
 * PermissionCheck middleware is exercised elsewhere), (2) the global
 * client_impersonation_enabled company_setting, (3) the per-client
 * allow_staff_impersonation flag. ClientController::impersonate() re-checks
 * (2) and (3) itself regardless of the route gate, which is what these
 * tests exercise directly.
 */
final class ClientImpersonationTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // startImpersonation()/endImpersonation() call session_regenerate_id(), which PHP warns
        // about without an actual active session — CLI PHPUnit never starts one on its own (same
        // setup as DualSessionIsolationTest/SessionExpiryGuardTest).
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_POST = [];
        $_SESSION = [];
    }

    private function enableGlobally(): void
    {
        CompanySettingsRepository::set('client_impersonation_enabled', '1', null);
    }

    private function auditLogCount(string $actionType, int $entityId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action_type = :action AND entity_type = 'clients' AND entity_id = :entity_id"
        );
        $stmt->execute(['action' => $actionType, 'entity_id' => $entityId]);
        return (int) $stmt->fetchColumn();
    }

    // --- Gate 2: global switch ---

    public function testImpersonateRefusesWhenGlobalSwitchIsOff(): void
    {
        CompanySettingsRepository::set('client_impersonation_enabled', '0', null);
        $clientId = $this->createTestClient();
        ClientRepository::setAllowStaffImpersonation($clientId, true);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        (new ClientController())->impersonate(['id' => $clientId]);
        ob_end_clean();

        self::assertNull(ClientPortalService::currentClientId(), 'global switch off must refuse, even with the per-client flag on');
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    // --- Gate 3: per-client flag ---

    public function testImpersonateRefusesWhenClientFlagIsOff(): void
    {
        $this->enableGlobally();
        $clientId = $this->createTestClient(); // allow_staff_impersonation defaults to 0
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        (new ClientController())->impersonate(['id' => $clientId]);
        ob_end_clean();

        self::assertNull(ClientPortalService::currentClientId(), 'global switch on alone must not be enough — the per-client flag is also required');
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    public function testImpersonateRefusesForADeactivatedClient(): void
    {
        $this->enableGlobally();
        $clientId = $this->createTestClient();
        ClientRepository::setAllowStaffImpersonation($clientId, true);
        ClientRepository::setActive($clientId, false);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        (new ClientController())->impersonate(['id' => $clientId]);
        ob_end_clean();

        self::assertNull(ClientPortalService::currentClientId());
    }

    // --- All three gates satisfied ---

    public function testImpersonateSucceedsWhenAllThreeGatesHold(): void
    {
        $this->enableGlobally();
        $clientId = $this->createTestClient();
        ClientRepository::setAllowStaffImpersonation($clientId, true);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        (new ClientController())->impersonate(['id' => $clientId]);
        ob_end_clean();

        self::assertSame($clientId, ClientPortalService::currentClientId());
        self::assertTrue(ClientPortalService::isImpersonating());
        self::assertSame($staffId, ClientPortalService::impersonatedByStaffId());
        self::assertSame(1, $this->auditLogCount('CLIENT_IMPERSONATION_STARTED', $clientId));
    }

    // --- ClientAuth middleware: an impersonated session needs no client_logins row ---

    public function testClientAuthAllowsAnImpersonatedSessionWithNoLoginRowAtAll(): void
    {
        $this->enableGlobally();
        $clientId = $this->createTestClient(); // never provisioned a client_logins row — e.g. pre-Stage-3
        ClientRepository::setAllowStaffImpersonation($clientId, true);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        ob_start();
        (new ClientController())->impersonate(['id' => $clientId]);
        ob_end_clean();

        $allowed = (ClientAuth::required())(['id' => (string) $clientId]);

        self::assertTrue($allowed, 'an impersonated session must pass ClientAuth even though no client_logins row exists for this client');
    }

    public function testClientAuthEndsImpersonationWhenTheClientHasSinceBeenDeactivated(): void
    {
        $this->enableGlobally();
        $clientId = $this->createTestClient();
        ClientRepository::setAllowStaffImpersonation($clientId, true);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        ob_start();
        (new ClientController())->impersonate(['id' => $clientId]);
        ob_end_clean();
        ClientRepository::setActive($clientId, false);

        $allowed = (ClientAuth::required())(['id' => (string) $clientId]);

        self::assertFalse($allowed);
        self::assertNull(ClientPortalService::currentClientId(), 'impersonation must be torn down, not left dangling, once the client is deactivated mid-session');
    }

    // --- Ending impersonation ---

    public function testEndImpersonationClearsSessionAndReturnsTheClientId(): void
    {
        $this->enableGlobally();
        $clientId = $this->createTestClient();
        ClientRepository::setAllowStaffImpersonation($clientId, true);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        ob_start();
        (new ClientController())->impersonate(['id' => $clientId]);
        ob_end_clean();

        ob_start();
        (new ClientPortalController())->endImpersonation([]);
        ob_end_clean();

        self::assertNull(ClientPortalService::currentClientId());
        self::assertFalse(ClientPortalService::isImpersonating());
        self::assertSame(1, $this->auditLogCount('CLIENT_IMPERSONATION_ENDED', $clientId));
        // the staff member's own session (set above) must survive — this is "stop viewing as
        // the client", never a staff logout.
        self::assertSame($staffId, $_SESSION['_auth_user_id'] ?? null);
    }

    public function testEndImpersonationOnANonImpersonatedSessionIsANoOp(): void
    {
        $result = ClientPortalService::endImpersonation();

        self::assertNull($result);
    }

    // --- Per-client toggle ---

    public function testSetImpersonationAllowedTogglesTheFlagAndLogsIt(): void
    {
        $clientId = $this->createTestClient();
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['allow_staff_impersonation'] = '1';

        ob_start();
        (new ClientController())->setImpersonationAllowed(['id' => $clientId]);
        ob_end_clean();

        $client = ClientRepository::find($clientId);
        self::assertSame(1, (int) $client['allow_staff_impersonation']);
        self::assertSame(1, $this->auditLogCount('CLIENT_IMPERSONATION_ALLOWED_CHANGED', $clientId));
    }

    public function testSetImpersonationAllowedCanTurnItBackOff(): void
    {
        $clientId = $this->createTestClient();
        ClientRepository::setAllowStaffImpersonation($clientId, true);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST = []; // checkbox unchecked

        ob_start();
        (new ClientController())->setImpersonationAllowed(['id' => $clientId]);
        ob_end_clean();

        $client = ClientRepository::find($clientId);
        self::assertSame(0, (int) $client['allow_staff_impersonation']);
    }
}
