<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\DocumentGenerationService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog Phase 6 Item 3 — SUPERSEDES the old
 * CloseOrderDocumentHintTest.php. That file locked in a deliberate
 * decision (documented in docs/SOP/09-stage9-despatch-closure.md) that
 * Close Order must never be blocked on BLI/COO Prep Sheet state — a
 * display-only reminder was the whole point. The business owner has now
 * explicitly reversed that decision: Stage 9 only unlocks after the
 * Stage 8 balance payment clears (itself downstream of the freight/BL
 * stages), so by construction any order reaching Close Order has
 * already shipped — BLI + COOPREP are now a real, server-side hard
 * requirement, with a narrow edit_locked_data + reason override escape
 * hatch (DocumentGenerationService::closureBlockedBy(),
 * OrderController::closeOrder()). Deliberately NOT exempted for a
 * disputed order — disputes never touch order status/stage progression
 * (docs/SOP/11-disputes.md), so a disputed-but-shipped order hits this
 * exact same gate. OrderController::markLost() gets a companion guard:
 * an order with a real Bill of Lading already on file can no longer be
 * marked "lost" (that side door would otherwise let staff dodge this
 * requirement entirely).
 */
final class CloseOrderDocumentGateTest extends DbTestCase
{
    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function prepareShippedOrderAtStage9(int $orderId): void
    {
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');
        $this->unlockThrough($orderId, 8);
        self::assertTrue(StageGateService::isUnlocked($orderId, 9));
    }

    public function testClosureBlockedByListsBothTypesWhenNeitherIsApproved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertSame(['BL Instruction Sheet', 'COO Prep Sheet'], DocumentGenerationService::closureBlockedBy($orderId));
    }

    public function testClosureBlockedByListsOnlyTheStillOutstandingType(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'BLI', 'approved');
        self::assertSame(['COO Prep Sheet'], DocumentGenerationService::closureBlockedBy($orderId));
    }

    public function testClosureBlockedByIsEmptyOnceBothAreApprovedOrSent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'BLI', 'approved');
        $this->createTestDocument($orderId, 'COOPREP', 'sent');
        self::assertSame([], DocumentGenerationService::closureBlockedBy($orderId));
    }

    public function testCloseOrderRefusesWithoutBothDocumentsApproved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->prepareShippedOrderAtStage9($orderId);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['courier_tracking_number' => 'DHL-TEST-1'];

        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('active', OrderRepository::find($orderId)['status'], 'closing must be refused server-side, even when posted directly, not just hidden in the UI');
    }

    public function testCloseOrderSucceedsOnceBothDocumentsApproved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->prepareShippedOrderAtStage9($orderId);
        $this->createTestDocument($orderId, 'BLI', 'approved');
        $this->createTestDocument($orderId, 'COOPREP', 'sent');
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['courier_tracking_number' => 'DHL-TEST-2'];

        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();

        // docs/schema.sql Section BU — closeOrder() now only reaches the
        // intermediate 'shipped_docs_sent' phase (documents couriered,
        // still open internally for government export benefits); the
        // real final lock is markFullySettled(), tested separately.
        self::assertSame('shipped_docs_sent', OrderRepository::find($orderId)['status']);
    }

    public function testCloseOrderOverrideRequiresEditLockedDataPermission(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->prepareShippedOrderAtStage9($orderId);
        $userId = $this->createTestUser('Export Executive'); // holds close_orders, not edit_locked_data
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['courier_tracking_number' => 'DHL-TEST-3', 'override_gate' => '1', 'override_reason' => 'Trying to force it through.'];

        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('active', OrderRepository::find($orderId)['status']);
    }

    public function testCloseOrderOverrideRequiresAReason(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->prepareShippedOrderAtStage9($orderId);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['courier_tracking_number' => 'DHL-TEST-4', 'override_gate' => '1', 'override_reason' => 'short'];

        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('active', OrderRepository::find($orderId)['status']);
    }

    public function testCloseOrderOverrideSucceedsAndIsAuditLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->prepareShippedOrderAtStage9($orderId);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = [
            'courier_tracking_number' => 'DHL-TEST-5',
            'override_gate' => '1',
            'override_reason' => 'COO Prep genuinely not required for this shipment — cleared by MD.',
        ];

        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('shipped_docs_sent', OrderRepository::find($orderId)['status']);

        $stmt = Database::connection()->prepare(
            "SELECT * FROM audit_log WHERE action_type = 'CLOSE_ORDER_DOCUMENT_GATE_OVERRIDE' AND entity_type = 'orders' AND entity_id = :id"
        );
        $stmt->execute(['id' => $orderId]);
        self::assertNotFalse($stmt->fetch());
    }

    public function testMarkLostRefusesOnceARealBillOfLadingExists(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk']);
        OrderShippingRepository::recordBl($orderId, 'MSCU7654321', '2026-06-15');
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'Buyer disappeared after shipment — trying to mark lost.'];

        ob_start();
        (new OrderController())->markLost(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('active', OrderRepository::find($orderId)['status'], 'an order that has actually shipped must go through Close Order, not be side-doored as "lost"');
    }

    public function testMarkLostStillSucceedsWithoutABillOfLading(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'Buyer walked away before shipment.'];

        ob_start();
        (new OrderController())->markLost(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame('lost', OrderRepository::find($orderId)['status']);
    }
}
