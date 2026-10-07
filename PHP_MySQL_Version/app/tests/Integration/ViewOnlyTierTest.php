<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientController;
use App\Controllers\OrderController;
use App\Middleware\PermissionCheck;
use App\Tests\Support\DbTestCase;

/**
 * Batch 3 #13a (part 2) — view_orders/view_clients: a read-only tier for
 * the Orders and Clients modules, alongside manage_orders (which already
 * implies full view+edit access). The seeded 'Viewer / Auditor' role now
 * carries both. Routes use PermissionCheck::requiresAny(['manage_orders',
 * 'view_orders'|'view_clients']) — never changed here — so this suite
 * covers: (1) requiresAny() itself passes a view-only user and blocks a
 * user with neither, and (2) the order/client show/index views, whose
 * mutating UI is gated on a computed $canManageOrders boolean, correctly
 * hide it for a view-only user and show it for a manage_orders user.
 */
final class ViewOnlyTierTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    private function callRequiresAny(array $keys): bool
    {
        $middleware = PermissionCheck::requiresAny($keys);
        ob_start();
        $result = $middleware([]);
        ob_end_clean();
        return $result;
    }

    public function testRequiresAnyPassesAViewOnlyUserForOrders(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $userId;

        self::assertTrue($this->callRequiresAny(['manage_orders', 'view_orders']));
    }

    public function testRequiresAnyPassesAViewOnlyUserForClients(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $userId;

        self::assertTrue($this->callRequiresAny(['manage_orders', 'view_clients']));
    }

    public function testRequiresAnyBlocksAUserWithNeitherPermission(): void
    {
        // 'CA / Chartered Accountant' holds ca_module_view/inr_actual_view only — neither manage_orders nor view_orders/view_clients.
        $userId = $this->createTestUser('CA / Chartered Accountant');
        $_SESSION['_auth_user_id'] = $userId;

        self::assertFalse($this->callRequiresAny(['manage_orders', 'view_orders']));
        self::assertFalse($this->callRequiresAny(['manage_orders', 'view_clients']));
    }

    public function testManageOrdersAloneAlsoPasses(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;

        self::assertTrue($this->callRequiresAny(['manage_orders', 'view_orders']));
    }

    public function testOrderShowPageHidesMutatingButtonsForAViewOnlyUser(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = ob_get_clean();

        self::assertStringNotContainsString('Edit Order Details', $html);
        self::assertStringNotContainsString('Add Product Line', $html, 'the editable product form must not render for a view-only user');
    }

    public function testOrderShowPageShowsMutatingButtonsForAManageOrdersUser(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = ob_get_clean();

        self::assertStringContainsString('Edit Order Details', $html);
        self::assertStringContainsString('Add Product Line', $html);
    }

    public function testClientShowPageHidesEditAndNewOrderButtonsForAViewOnlyUser(): void
    {
        $clientId = $this->createTestClient();
        $userId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new ClientController())->show(['id' => (string) $clientId]);
        $html = ob_get_clean();

        self::assertStringNotContainsString('>Edit<', $html);
        self::assertStringNotContainsString('New Order for this client', $html);
    }

    public function testClientShowPageShowsEditAndNewOrderButtonsForAManageOrdersUser(): void
    {
        $clientId = $this->createTestClient();
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new ClientController())->show(['id' => (string) $clientId]);
        $html = ob_get_clean();

        self::assertStringContainsString('>Edit<', $html);
        self::assertStringContainsString('New Order for this client', $html);
    }

    public function testClientIndexHidesNewClientAndEditLinksForAViewOnlyUser(): void
    {
        $this->createTestClient();
        $userId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new ClientController())->index([]);
        $html = ob_get_clean();

        self::assertStringNotContainsString('+ New Client', $html);
        self::assertStringNotContainsString('entity-card-action', $html, 'per-card Edit link must not render for a view-only user');
    }

    public function testClientIndexShowsNewClientAndEditLinksForAManageOrdersUser(): void
    {
        $this->createTestClient();
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new ClientController())->index([]);
        $html = ob_get_clean();

        self::assertStringContainsString('+ New Client', $html);
        self::assertStringContainsString('entity-card-action', $html);
    }

    public function testViewerAuditorRoleCarriesBothNewPermissionsOnTheirOwn(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $_SESSION['_auth_user_id'] = $userId;

        self::assertTrue($this->callRequiresAny(['view_orders']));
        self::assertTrue($this->callRequiresAny(['view_clients']));
    }
}
