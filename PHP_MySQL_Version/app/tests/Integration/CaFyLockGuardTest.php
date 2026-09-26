<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\CaFyLockRepository;
use App\Services\CaFyLockGuard;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA-4 P0.3 (docs/QA/TEST_PLAN.md Section 6): every CA write path listed
 * under CA/Accounts must refuse once the relevant financial year is
 * locked, except through ca_fy_lock_override, which must itself be
 * audit-logged. CaFyLockGuard::allow() is the single choke point all 18
 * call sites across CaController/OrderController delegate to (see
 * CaFyLockGuard.php), so pinning it here covers every one of them at
 * once; the last two tests also verify one representative write path
 * (recordAdvanceInrActual) end to end, proving the guard is actually
 * wired into the real controller action, not just correct in isolation.
 */
final class CaFyLockGuardTest extends DbTestCase
{
    private const LOCKED_FY = '2020-21';
    private const LOCKED_DATE = '2020-06-15';
    private const OPEN_DATE = '2030-06-15';

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::resetCache();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        PermissionService::resetCache();
        parent::tearDown();
    }

    private function grantOverride(int $userId): void
    {
        $pdo = Database::connection();
        $permissionId = (int) $pdo->query("SELECT id FROM permissions WHERE permission_key = 'ca_fy_lock_override'")->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:user_id, :permission_id, 1)');
        $stmt->execute(['user_id' => $userId, 'permission_id' => $permissionId]);
    }

    public function testAllowsWhenDateIsNotInALockedYear(): void
    {
        CaFyLockRepository::lock(self::LOCKED_FY, 1);
        $userId = $this->createTestUser('Export Executive'); // no ca_fy_lock_override

        $result = CaFyLockGuard::allow(self::OPEN_DATE, $userId, null, 'order_payment_status', 1, 'advance_inr_actual');

        self::assertTrue($result);
    }

    public function testBlocksWhenDateIsInALockedYearAndUserHasNoOverride(): void
    {
        CaFyLockRepository::lock(self::LOCKED_FY, 1);
        $userId = $this->createTestUser('Export Executive'); // no ca_fy_lock_override

        $result = CaFyLockGuard::allow(self::LOCKED_DATE, $userId, null, 'order_payment_status', 1, 'advance_inr_actual');

        self::assertFalse($result);
        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString(self::LOCKED_FY, $flash[0]['message']);
    }

    public function testAllowsWithAuditLogAndWarningWhenUserHasOverride(): void
    {
        CaFyLockRepository::lock(self::LOCKED_FY, 1);
        $userId = $this->createTestUser('Export Executive');
        $this->grantOverride($userId);
        PermissionService::resetCache();

        $before = (int) Database::connection()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $result = CaFyLockGuard::allow(self::LOCKED_DATE, $userId, null, 'order_payment_status', 999, 'advance_inr_actual');

        self::assertTrue($result, 'a user holding ca_fy_lock_override must be allowed through');
        $flash = Flash::pull();
        self::assertSame('warning', $flash[0]['type']);

        $after = (int) Database::connection()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        self::assertSame($before + 1, $after, 'the override must be audit-logged, not silent');

        $entry = Database::connection()->query(
            "SELECT * FROM audit_log WHERE action_type = 'CA_FY_LOCK_OVERRIDDEN' ORDER BY id DESC LIMIT 1"
        )->fetch();
        self::assertNotFalse($entry);
        self::assertSame($userId, (int) $entry['user_id']);
        self::assertSame('order_payment_status', $entry['entity_type']);
        self::assertSame(999, (int) $entry['entity_id']);
    }

    public function testNullDateIsNeverBlocked(): void
    {
        CaFyLockRepository::lock(self::LOCKED_FY, 1);
        $userId = $this->createTestUser('Export Executive');

        self::assertTrue(CaFyLockGuard::allow(null, $userId, null, 'order_payment_status', 1, 'advance_inr_actual'));
    }

    /**
     * End-to-end proof that CaFyLockGuard is actually wired into a real
     * controller write path, not just correct as an isolated function:
     * a genuine attempt to record an advance INR actual against a leg
     * cleared inside a locked FY is refused, and nothing is written.
     */
    public function testRecordAdvanceInrActualRefusedEndToEndWhenLegClearedInLockedYear(): void
    {
        CaFyLockRepository::lock(self::LOCKED_FY, 1);
        $userId = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at) VALUES (:order_id, 500.00, :cleared_at)'
        )->execute(['order_id' => $orderId, 'cleared_at' => self::LOCKED_DATE]);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['advance_inr_actual' => '41000'];

        ob_start();
        (new OrderController())->recordAdvanceInrActual(['id' => $orderId]);
        ob_get_clean();

        $status = $pdo->prepare('SELECT advance_inr_actual FROM order_payment_status WHERE order_id = :id');
        $status->execute(['id' => $orderId]);
        self::assertNull($status->fetchColumn() ?: null, 'a locked-FY write must never actually land in the database');
    }

    public function testRecordAdvanceInrActualSucceedsEndToEndWhenLegClearedOutsideLockedYear(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at) VALUES (:order_id, 500.00, :cleared_at)'
        )->execute(['order_id' => $orderId, 'cleared_at' => self::OPEN_DATE]);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['advance_inr_actual' => '41000'];

        ob_start();
        (new OrderController())->recordAdvanceInrActual(['id' => $orderId]);
        ob_get_clean();

        $status = $pdo->prepare('SELECT advance_inr_actual FROM order_payment_status WHERE order_id = :id');
        $status->execute(['id' => $orderId]);
        self::assertSame('41000.00', $status->fetchColumn());
    }
}
