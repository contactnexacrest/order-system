<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog, P0 blocker (#23/#77): the Stage 4 "Order Confirmation:
 * Buyer Acknowledgement" section on the order page only ever rendered the
 * "Record Buyer's Email Acknowledgement" form when an order_oc_acknowledgments
 * row already existed — i.e. only once a real send had completed via
 * EmailDispatchService (see OrderOcAcknowledgmentRepository::recordSent()).
 * The backend action (OrderController::recordOcAcknowledgment(), tested in
 * OcAckAndMailTogglesTest) already handled the "OC generated but never
 * actually sent" case via ensureRowExists() — staff simply had no button to
 * trigger it from, leaving the gate stuck with no visible way forward
 * whenever mail sending was broken or still awaiting Level-2 approval.
 */
final class OcAckButtonVisibilityTest extends DbTestCase
{
    private function unlockStage4(int $orderId): void
    {
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        StageGateService::passAndUnlockNext($orderId, 3, null);
        self::assertTrue(StageGateService::isUnlocked($orderId, 4));
    }

    public function testShowsTheOverrideFormWhenOcGeneratedButNeverSent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockStage4($orderId);
        $this->createTestDocument($orderId, 'OC', 'draft');
        self::assertNull(OrderOcAcknowledgmentRepository::find($orderId), 'no ack row should exist — the OC was never actually sent');
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString(
            "Record Buyer's Acknowledgement (unlocks Stage 5)",
            $output,
            'the override button must be visible even though the OC was only generated, not emailed'
        );
        self::assertStringContainsString('name="acknowledged_note"', $output);
        self::assertStringNotContainsString(
            'Generate the Order Confirmation above and send it to the buyer',
            $output,
            'this dead-end message must not show once the OC document already exists'
        );
    }

    public function testStillShowsTheNoOcYetMessageWhenNoOcDocumentExistsAtAll(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockStage4($orderId);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Generate the Order Confirmation above and send it to the buyer', $output);
        self::assertStringNotContainsString('acknowledged_note', $output);
    }

    public function testStillShowsTheOriginalBannerAndFormOnceTheOcHasActuallyBeenSent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockStage4($orderId);
        $documentId = $this->createTestDocument($orderId, 'OC', 'approved');
        OrderOcAcknowledgmentRepository::recordSent($orderId, $documentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Order Confirmation emailed to the buyer on', $output);
        self::assertStringContainsString("Record Buyer's Email Acknowledgement (unlocks Stage 5)", $output);
    }
}
