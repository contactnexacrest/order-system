<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Middleware\PermissionCheck;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Repositories\OrderRepository;
use App\Services\OrderAssignmentService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Order ownership / assignment visibility, multi-assignee model (see
 * docs/migrations/2026-10-24c-order-multi-assignee.sql). Supersedes the
 * single-owner `orders.assigned_to` design with `order_assignees`: an
 * order can have 0, 1, or more assignees at once — e.g. an Export
 * Executive on office work and a Logistics Executive on CHA/factory work,
 * concurrently, on the SAME order — because a single owner column can
 * never represent that. Still visibility-only (which staff see an order
 * at all), never consulted by the stage-gate/action-permission system,
 * which this suite's own orthogonality test exists specifically to prove.
 */
final class OrderMultiAssigneeTest extends DbTestCase
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

    public function testNewOrderIsAutoAssignedToItsCreatorAsSoleAssignee(): void
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
            'product_description' => ['Multi-assignee auto-assignment test slab'],
            'product_hs_code'     => ['680293'],
            'product_quantity'    => ['10'],
            'product_unit_price'  => ['5.00'],
        ];

        ob_start();
        (new OrderController())->store([]);
        ob_end_clean();

        $orderId = (int) $pdo->query('SELECT MAX(id) FROM orders')->fetchColumn();
        $assignees = OrderAssigneeRepository::forOrder($orderId);

        self::assertCount(1, $assignees);
        self::assertSame($userId, (int) $assignees[0]['user_id']);
    }

    // --- 2. Multi-assignee visibility: the actual scenario this model fixes ---

    public function testAnOrderAssignedToBothAnExportAndLogisticsExecutiveIsVisibleToBoth(): void
    {
        $clientId = $this->createTestClient();
        $exportUserId = $this->createTestUser('Export Executive');
        $logisticsUserId = $this->createTestUser('Logistics Executive');
        $strangerUserId = $this->createTestUser('Export Executive');

        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, [$exportUserId, $logisticsUserId]);

        $seesAllExport = PermissionService::can($exportUserId, $this->roleIdFor($exportUserId), 'view_all_orders');
        $exportList = OrderRepository::allVisibleTo($exportUserId, $seesAllExport);
        self::assertContains($orderId, array_map(static fn(array $o): int => (int) $o['id'], $exportList), 'the Export Executive assignee must see the order');

        $seesAllLogistics = PermissionService::can($logisticsUserId, $this->roleIdFor($logisticsUserId), 'view_all_orders');
        $logisticsList = OrderRepository::allVisibleTo($logisticsUserId, $seesAllLogistics);
        self::assertContains($orderId, array_map(static fn(array $o): int => (int) $o['id'], $logisticsList), 'the Logistics Executive assignee must see the order');

        $seesAllStranger = PermissionService::can($strangerUserId, $this->roleIdFor($strangerUserId), 'view_all_orders');
        $strangerList = OrderRepository::allVisibleTo($strangerUserId, $seesAllStranger);
        self::assertNotContains($orderId, array_map(static fn(array $o): int => (int) $o['id'], $strangerList), 'a third scoped user who is neither assignee must not see the order');
    }

    // --- 3. Scoped visibility still excludes non-assignees -----------------

    public function testScopedRoleSeesOnlyOrdersItIsAssignedToInIndexAndKanban(): void
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

    // --- 4. Oversight visibility -----------------------------------------

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

    // --- 5. Direct access (/orders/{id}) ----------------------------------

    public function testDirectAccessIs403ForANonAssigneeAnd200ForEveryAssigneeAndOversightRole(): void
    {
        $clientId = $this->createTestClient();
        $exportUserId = $this->createTestUser('Export Executive');
        $logisticsUserId = $this->createTestUser('Logistics Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, [$exportUserId, $logisticsUserId]);

        $strangerId = $this->createTestUser('Export Executive');
        $this->render($orderId, $strangerId);
        self::assertSame(403, http_response_code(), 'a scoped non-assignee must be refused direct access');

        $this->render($orderId, $exportUserId);
        self::assertSame(200, http_response_code(), 'the first assignee must reach the order');

        $this->render($orderId, $logisticsUserId);
        self::assertSame(200, http_response_code(), 'the second concurrent assignee must also reach the order');

        $adminId = $this->createTestUser('Admin');
        $this->render($orderId, $adminId);
        self::assertSame(200, http_response_code(), 'an oversight role must reach any order');
    }

    // --- 6. Assignment permission -----------------------------------------

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

    public function testAnExistingAssigneeThemselvesCannotPassTheAssignOrderOwnerGateEither(): void
    {
        // Being one of the order's own assignees grants no special
        // add/remove right of its own — only the assign_order_owner
        // permission does.
        $assigneeUserId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $assigneeUserId;
        self::assertFalse($this->callRequires('assign_order_owner'));
    }

    // --- 7. Add is idempotent -----------------------------------------------

    public function testAddingAnAlreadyAssignedUserIsANoOpWithNoDuplicateRowOrNotification(): void
    {
        $clientId = $this->createTestClient();
        $ownerUserId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $ownerUserId);
        $adminId = $this->createTestUser('Admin');

        $before = count(NotificationRepository::forUser($ownerUserId));
        OrderAssignmentService::addAssignee($orderId, $ownerUserId, $adminId);
        $after = count(NotificationRepository::forUser($ownerUserId));

        self::assertSame($before, $after, 're-adding an existing assignee must send no notification');
        self::assertCount(1, OrderAssigneeRepository::forOrder($orderId), 're-adding an existing assignee must not create a duplicate row');
    }

    // --- 8. Add/remove write + notification ---------------------------------

    public function testAddCreatesExactlyOneNotificationAndRemoveCreatesNone(): void
    {
        $clientId = $this->createTestClient();
        $originalAssigneeId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $originalAssigneeId);

        $newAssigneeId = $this->createTestUser('Logistics Executive');
        $adminId = $this->createTestUser('Admin');

        $before = count(NotificationRepository::forUser($newAssigneeId));
        OrderAssignmentService::addAssignee($orderId, $newAssigneeId, $adminId);
        $after = NotificationRepository::forUser($newAssigneeId);

        self::assertTrue(OrderAssigneeRepository::isAssignedTo($orderId, $newAssigneeId));
        self::assertCount($before + 1, $after);
        self::assertSame('order_assigned', $after[0]['type']);

        $removeBefore = count(NotificationRepository::forUser($originalAssigneeId));
        OrderAssignmentService::removeAssignee($orderId, $originalAssigneeId, $adminId);
        $removeAfter = count(NotificationRepository::forUser($originalAssigneeId));

        self::assertFalse(OrderAssigneeRepository::isAssignedTo($orderId, $originalAssigneeId), 'removal must drop visibility for the removed user');
        self::assertSame($removeBefore, $removeAfter, 'removal must send no notification');
    }

    // --- 9. Zero-assignee state ----------------------------------------------

    public function testRemovingEveryAssigneeLeavesTheOrderVisibleOnlyToOversightRoles(): void
    {
        $clientId = $this->createTestClient();
        $soleAssigneeId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $soleAssigneeId);
        $adminId = $this->createTestUser('Admin');

        OrderAssignmentService::removeAssignee($orderId, $soleAssigneeId, $adminId);
        self::assertCount(0, OrderAssigneeRepository::forOrder($orderId));

        $this->render($orderId, $soleAssigneeId);
        self::assertSame(403, http_response_code(), 'the removed sole assignee must now be blocked');

        $this->render($orderId, $adminId);
        self::assertSame(200, http_response_code(), 'an oversight role must still reach a zero-assignee order');
    }

    // --- 10. Orthogonality: assignment never touches the permission system --

    public function testAccountsExecutiveNotAssignedCanStillReachTheOrderAndHoldsPaymentPermissions(): void
    {
        $clientId = $this->createTestClient();
        $exportAssigneeId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $exportAssigneeId);

        $accountsUserId = $this->createTestUser('Accounts Executive');

        // Visibility: Accounts Executive is not an assignee, but
        // view_all_orders still lets them open it directly.
        $this->render($orderId, $accountsUserId);
        self::assertSame(200, http_response_code());

        // Action permissions are completely unaffected by who's assigned
        // to the order — this is the whole point of keeping assignment
        // visibility-only.
        $roleId = $this->roleIdFor($accountsUserId);
        self::assertTrue(PermissionService::can($accountsUserId, $roleId, 'manage_payments'));
        self::assertTrue(PermissionService::can($accountsUserId, $roleId, 'clear_payments'));
    }

    public function testExportExecutiveWithoutPaymentPermissionStaysBlockedRegardlessOfBeingAssigned(): void
    {
        // The flip side: BEING an assignee grants no action permission
        // either — Export Executive can record a payment
        // (manage_payments) but never clear one (clear_payments), exactly
        // as before this feature, whether or not they're assigned to the
        // order.
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
