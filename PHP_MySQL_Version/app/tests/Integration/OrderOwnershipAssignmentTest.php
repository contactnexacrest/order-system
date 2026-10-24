<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Middleware\PermissionCheck;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Services\OrderAssignmentService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Order ownership / assignment visibility (see
 * docs/migrations/2026-10-24b-order-owner-assignment.sql). A single
 * staff "owner" per order (orders.assigned_to), auto-set to the
 * creator, visible only to that owner plus the oversight tier (Admin,
 * Managing Director, Executive Director, Accounts Executive, Viewer /
 * Auditor — view_all_orders), reassignable only by Admin/MD/ED
 * (assign_order_owner). Deliberately visibility-only: never consulted
 * by the stage-gate/permission system, which this suite's own
 * orthogonality test exists specifically to prove.
 */
final class OrderOwnershipAssignmentTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    private function render(int $orderId, int $userId): string
    {
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        http_response_code(200);
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    // --- 1. Auto-assignment at creation ---------------------------------

    public function testNewOrderIsAutoAssignedToItsCreator(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $clientId = $this->createTestClient();

        $pdo = Database::connection();
        $incotermId = (int) $pdo->query("SELECT id FROM incoterms WHERE code = 'FOB'")->fetchColumn();
        $currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        $presetId = (int) $pdo->query("SELECT id FROM payment_presets WHERE preset_name = 'Standard — New Buyer'")->fetchColumn();

        $_POST = [
            'client_id'           => (string) $clientId,
            'incoterm_id'         => (string) $incotermId,
            'currency_id'         => (string) $currencyId,
            'payment_preset_id'   => (string) $presetId,
            'product_description' => ['Ownership auto-assignment test slab'],
            'product_hs_code'     => ['680293'],
            'product_quantity'    => ['10'],
            'product_unit_price'  => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        $orderId = (int) $pdo->query('SELECT MAX(id) FROM orders')->fetchColumn();
        $order = OrderRepository::find($orderId);

        self::assertSame($userId, (int) $order['assigned_to']);
        self::assertSame($userId, (int) $order['created_by']);
    }

    // --- 2. Scoped visibility (Export/Logistics Executive) --------------

    public function testScopedRoleSeesOnlyItsOwnAssignedOrdersInIndexAndKanban(): void
    {
        $exportUserId = $this->createTestUser('Export Executive');
        $otherUserId = $this->createTestUser('Export Executive');

        // createTestOrder() hardcodes sequence_no = 1, so two orders for
        // the SAME client in one test would collide on uq_client_sequence
        // — a separate client per order sidesteps that unrelated helper
        // limitation.
        $mineId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $exportUserId);
        $theirsId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $otherUserId);

        $seesAll = PermissionService::can($exportUserId, $this->roleIdFor($exportUserId), 'view_all_orders');
        self::assertFalse($seesAll, 'Export Executive must not hold view_all_orders');

        $list = OrderRepository::allVisibleTo($exportUserId, $seesAll);
        $ids = array_map(static fn(array $o): int => (int) $o['id'], $list);

        self::assertContains($mineId, $ids);
        self::assertNotContains($theirsId, $ids);
    }

    public function testScopedRoleKanbanAlsoOnlyShowsItsOwnAssignedOrders(): void
    {
        $logisticsUserId = $this->createTestUser('Logistics Executive');
        $otherUserId = $this->createTestUser('Logistics Executive');

        $mineId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $logisticsUserId);
        $theirsId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $otherUserId);

        $seesAll = PermissionService::can($logisticsUserId, $this->roleIdFor($logisticsUserId), 'view_all_orders');
        $list = OrderRepository::allVisibleTo($logisticsUserId, $seesAll);
        $ids = array_map(static fn(array $o): int => (int) $o['id'], $list);

        self::assertContains($mineId, $ids);
        self::assertNotContains($theirsId, $ids);
    }

    // --- 3. Oversight visibility -----------------------------------------

    public function testOversightRolesSeeEveryOrderRegardlessOfAssignment(): void
    {
        $clientId = $this->createTestClient();
        $ownerUserId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $ownerUserId);

        foreach (['Admin', 'Managing Director', 'Executive Director', 'Accounts Executive', 'Viewer / Auditor'] as $roleName) {
            $viewerId = $this->createTestUser($roleName);
            $seesAll = PermissionService::can($viewerId, $this->roleIdFor($viewerId), 'view_all_orders');
            self::assertTrue($seesAll, "{$roleName} must hold view_all_orders");

            $list = OrderRepository::allVisibleTo($viewerId, $seesAll);
            $ids = array_map(static fn(array $o): int => (int) $o['id'], $list);
            self::assertContains($orderId, $ids, "{$roleName} must see an order assigned to someone else");
        }
    }

    // --- 4. Direct access (/orders/{id}) ----------------------------------

    public function testDirectAccessIs403ForAScopedNonOwnerAnd200ForTheOwnerAndOversightRoles(): void
    {
        $clientId = $this->createTestClient();
        $ownerUserId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $ownerUserId);

        $strangerId = $this->createTestUser('Logistics Executive');
        $this->render($orderId, $strangerId);
        self::assertSame(403, http_response_code(), 'a scoped non-owner must be refused direct access');

        $this->render($orderId, $ownerUserId);
        self::assertSame(200, http_response_code(), 'the assigned owner must reach their own order');

        $adminId = $this->createTestUser('Admin');
        $this->render($orderId, $adminId);
        self::assertSame(200, http_response_code(), 'an oversight role must reach any order');
    }

    // --- 5. Assignment permission -----------------------------------------

    private function callRequires(string $key): bool
    {
        $middleware = PermissionCheck::requires($key);
        ob_start();
        $result = $middleware([]);
        ob_end_clean();
        return $result;
    }

    public function testOnlyAdminMdEdCanPassTheAssignOrderOwnerGate(): void
    {
        foreach (['Admin', 'Managing Director', 'Executive Director'] as $roleName) {
            $_SESSION['_auth_user_id'] = $this->createTestUser($roleName);
            self::assertTrue($this->callRequires('assign_order_owner'), "{$roleName} must pass assign_order_owner");
        }

        foreach (['Export Executive', 'Accounts Executive', 'Logistics Executive', 'Viewer / Auditor', 'CA / Chartered Accountant'] as $roleName) {
            $_SESSION['_auth_user_id'] = $this->createTestUser($roleName);
            self::assertFalse($this->callRequires('assign_order_owner'), "{$roleName} must NOT pass assign_order_owner");
        }
    }

    public function testTheCurrentNonPrivilegedOwnerCannotPassTheAssignOrderOwnerGateEither(): void
    {
        // Being the order's own assignee grants no special reassignment
        // right of its own — only the assign_order_owner permission does.
        $ownerUserId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $ownerUserId;
        self::assertFalse($this->callRequires('assign_order_owner'));
    }

    // --- 6. Write + notification -------------------------------------------

    public function testReassignmentUpdatesTheOwnerAndCreatesExactlyOneNotification(): void
    {
        $clientId = $this->createTestClient();
        $originalOwnerId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $originalOwnerId);

        $newOwnerId = $this->createTestUser('Logistics Executive');
        $adminId = $this->createTestUser('Admin');

        $before = count(NotificationRepository::forUser($newOwnerId));
        OrderAssignmentService::reassign($orderId, $newOwnerId, $adminId);
        $after = NotificationRepository::forUser($newOwnerId);

        $order = OrderRepository::find($orderId);
        self::assertSame($newOwnerId, (int) $order['assigned_to']);
        self::assertCount($before + 1, $after);
        self::assertSame('order_assigned', $after[0]['type']);
    }

    public function testReassigningToTheSameOwnerIsANoOpAndSendsNoNotification(): void
    {
        $clientId = $this->createTestClient();
        $ownerUserId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $ownerUserId);
        $adminId = $this->createTestUser('Admin');

        $before = count(NotificationRepository::forUser($ownerUserId));
        OrderAssignmentService::reassign($orderId, $ownerUserId, $adminId);
        $after = count(NotificationRepository::forUser($ownerUserId));

        self::assertSame($before, $after);
    }

    // --- 7. Orthogonality: assignment never touches the permission system --

    public function testAccountsExecutiveNotAssignedCanStillReachTheOrderAndHoldsPaymentPermissions(): void
    {
        $clientId = $this->createTestClient();
        $exportOwnerId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $exportOwnerId);

        $accountsUserId = $this->createTestUser('Accounts Executive');

        // Visibility: Accounts Executive is not the assigned owner, but
        // view_all_orders still lets them open it directly.
        $this->render($orderId, $accountsUserId);
        self::assertSame(200, http_response_code());

        // Action permissions are completely unaffected by who owns the
        // order — this is the whole point of keeping assignment
        // visibility-only.
        $roleId = $this->roleIdFor($accountsUserId);
        self::assertTrue(PermissionService::can($accountsUserId, $roleId, 'manage_payments'));
        self::assertTrue(PermissionService::can($accountsUserId, $roleId, 'clear_payments'));
    }

    public function testExportExecutiveWithoutPaymentPermissionStaysBlockedRegardlessOfOwningTheOrder(): void
    {
        // The flip side: OWNING an order (being its assigned_to) grants no
        // action permission either — Export Executive can record a
        // payment (manage_payments) but never clear one (clear_payments),
        // exactly as before this feature, whether or not they're the
        // order's assigned owner.
        $clientId = $this->createTestClient();
        $exportUserId = $this->createTestUser('Export Executive');
        $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $exportUserId);

        $roleId = $this->roleIdFor($exportUserId);
        self::assertTrue(PermissionService::can($exportUserId, $roleId, 'manage_payments'));
        self::assertFalse(PermissionService::can($exportUserId, $roleId, 'clear_payments'));
    }

    private function roleIdFor(int $userId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT role_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $roleId = $stmt->fetchColumn();
        return $roleId !== null ? (int) $roleId : null;
    }
}
