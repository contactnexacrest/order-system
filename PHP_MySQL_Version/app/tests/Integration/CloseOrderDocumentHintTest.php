<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderShippingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #57: before this, Stage 9 gave staff no indication that
 * the BL Instruction Sheet or COO Prep Sheet hadn't been generated/approved
 * yet — only the courier tracking number and the Stage 9 gate itself stood
 * between "just unlocked" and "Close Order". A hard block in
 * OrderController::closeOrder() was drafted and then deliberately reverted:
 * docs/SOP/09-stage9-despatch-closure.md documents Stage 9's close gate as
 * an intentional staff-trust decision (see the 8543e0c precedent, which
 * investigated and rejected exactly this kind of hard gate), so this is a
 * non-blocking hint only — the same "tracking aid, not a lock" treatment
 * the existing Compliance Checklist already gets in this same section.
 * Close Order itself must remain available regardless of document state.
 */
final class CloseOrderDocumentHintTest extends DbTestCase
{
    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function renderAtStage9(int $orderId): string
    {
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');
        $this->unlockThrough($orderId, 8);
        self::assertTrue(StageGateService::isUnlocked($orderId, 9));

        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testWarnsWhenNeitherDocumentHasBeenApproved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $output = $this->renderAtStage9($orderId);

        self::assertStringContainsString('Before closing: generate and get approval for', $output);
        self::assertStringContainsString('BL Instruction Sheet', $output);
        self::assertStringContainsString('COO Prep Sheet', $output);
    }

    public function testWarnsOnlyAboutTheStillOutstandingDocument(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'BLI', 'approved');

        $output = $this->renderAtStage9($orderId);

        self::assertStringContainsString('Before closing:', $output);
        $warningPos = strpos($output, 'Before closing:');
        $warningLine = substr($output, $warningPos, 160);
        self::assertStringContainsString('COO Prep Sheet', $warningLine);
        self::assertStringNotContainsString('BL Instruction Sheet', $warningLine);
    }

    public function testNoWarningAndCloseOrderFormStillAvailableOnceBothAreApproved(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'BLI', 'approved');
        $this->createTestDocument($orderId, 'COOPREP', 'sent');

        $output = $this->renderAtStage9($orderId);

        self::assertStringNotContainsString('Before closing:', $output);
        self::assertStringContainsString('Close Order', $output);
        self::assertStringContainsString('name="courier_tracking_number"', $output);
    }

    public function testCloseOrderFormIsStillAvailableEvenWithoutEitherDocument(): void
    {
        // The hint is informational only — Close Order must never be
        // gated on document state. This is the whole point of the revert.
        $orderId = $this->createTestOrder($this->createTestClient());
        $output = $this->renderAtStage9($orderId);

        self::assertStringContainsString('Close Order', $output);
        self::assertStringContainsString('name="courier_tracking_number"', $output);
    }
}
