<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderRepository;
use App\Services\OrderEditGuard;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog Phase 6 Item 4 — is_locked (an order closed via Stage 9,
 * or marked lost) previously had teeth for document generation
 * (DocumentGenerationService::generate()) but NOT for the order's own
 * core details or product lines: OrderEditGuard::allow() only ever asked
 * "has Stage 4 passed," never "is this order locked." Anyone holding just
 * edit_order_post_confirmation could silently edit a closed order. This
 * adds a second, stricter check in front of the existing stage-4 one:
 * once is_locked, editing needs edit_locked_data specifically — the same
 * permission already required to force a document through on a locked
 * order — plus a mandatory reason, logged as ORDER_EDITED_WHILE_LOCKED.
 */
final class OrderLockedEditGuardTest extends DbTestCase
{
    private function lockOrder(int $orderId): void
    {
        OrderRepository::markComplete($orderId);
        self::assertSame(1, (int) OrderRepository::find($orderId)['is_locked']);
    }

    public function testAllowRefusesALockedOrderEditWithoutEditLockedDataPermission(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $roleId = (int) Database::connection()->query("SELECT id FROM roles WHERE name = 'Export Executive'")->fetchColumn();
        self::assertFalse(OrderEditGuard::allow(9, true, $userId, $roleId, 'A genuine reason, long enough.', 'orders', 1, 'order_details'));
    }

    public function testAllowRefusesALockedOrderEditWithTooShortAReason(): void
    {
        $userId = $this->createTestUser('Admin');
        $roleId = (int) Database::connection()->query("SELECT id FROM roles WHERE name = 'Admin'")->fetchColumn();
        self::assertFalse(OrderEditGuard::allow(9, true, $userId, $roleId, 'short', 'orders', 1, 'order_details'));
    }

    public function testAllowPermitsALockedOrderEditWithPermissionAndAReason(): void
    {
        $userId = $this->createTestUser('Admin');
        $roleId = (int) Database::connection()->query("SELECT id FROM roles WHERE name = 'Admin'")->fetchColumn();
        self::assertTrue(OrderEditGuard::allow(9, true, $userId, $roleId, 'Correcting a genuine mistake found after closure.', 'orders', 1, 'order_details'));
    }

    public function testAllowIgnoresStageEntirelyWhenNotLocked(): void
    {
        // stage 9 but not locked — the existing post-confirmation (stage >= 4) path still governs, unaffected.
        $userId = $this->createTestUser('Export Executive');
        $roleId = (int) Database::connection()->query("SELECT id FROM roles WHERE name = 'Export Executive'")->fetchColumn();
        self::assertFalse(OrderEditGuard::allow(9, false, $userId, $roleId, 'A genuine reason, long enough.', 'orders', 1, 'order_details'));
    }

    public function testUpdateDetailsRefusesOnALockedOrderWithoutEditLockedData(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->lockOrder($orderId);
        $original = OrderRepository::find($orderId);
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'incoterm_id' => $original['incoterm_id'] + 0, // keep same incoterm, change currency below
            'currency_id' => $this->otherCurrencyId((int) $original['currency_id']),
            'reason' => 'Trying to edit a closed order.',
        ];

        ob_start();
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame((int) $original['currency_id'], (int) OrderRepository::find($orderId)['currency_id'], 'a locked order must not be editable without edit_locked_data');
    }

    public function testUpdateDetailsSucceedsOnALockedOrderWithEditLockedDataAndAReason(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->lockOrder($orderId);
        $original = OrderRepository::find($orderId);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $newCurrencyId = $this->otherCurrencyId((int) $original['currency_id']);
        $_POST = [
            'incoterm_id' => $original['incoterm_id'],
            'currency_id' => $newCurrencyId,
            'reason' => 'Correcting a genuine currency mistake found after closure.',
        ];

        ob_start();
        (new OrderController())->updateDetails(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame($newCurrencyId, (int) OrderRepository::find($orderId)['currency_id']);

        $stmt = Database::connection()->prepare(
            "SELECT * FROM audit_log WHERE action_type = 'ORDER_EDITED_WHILE_LOCKED' AND entity_type = 'orders' AND entity_id = :id"
        );
        $stmt->execute(['id' => $orderId]);
        self::assertNotFalse($stmt->fetch());
    }

    public function testAddProductRefusesOnALockedOrderWithoutEditLockedData(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->lockOrder($orderId);
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['description' => 'Granite Slab', 'hs_code' => $this->firstActiveHsCode(), 'reason' => 'Trying to add a line to a closed order.'];
        $countBefore = (int) Database::connection()->query('SELECT COUNT(*) FROM order_products WHERE order_id = ' . $orderId)->fetchColumn();

        ob_start();
        (new OrderController())->addProduct(['id' => (string) $orderId]);
        ob_end_clean();

        $countAfter = (int) Database::connection()->query('SELECT COUNT(*) FROM order_products WHERE order_id = ' . $orderId)->fetchColumn();
        self::assertSame($countBefore, $countAfter);
    }

    private function otherCurrencyId(int $excludeId): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM currencies WHERE id != :id LIMIT 1');
        $stmt->execute(['id' => $excludeId]);
        return (int) $stmt->fetchColumn();
    }

    private function firstActiveHsCode(): string
    {
        return (string) Database::connection()->query('SELECT code FROM hs_codes WHERE is_active = 1 LIMIT 1')->fetchColumn();
    }
}
