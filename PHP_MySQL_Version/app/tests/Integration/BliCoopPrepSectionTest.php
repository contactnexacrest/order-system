<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderShippingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #38/#55: "BL Instruction Sheet creation screen missing"
 * and "Missing BLI/COO/BL-endorsement steps in order-detail sidebar".
 * Investigation found both BLI and COOPREP already generate real,
 * data-complete documents (DocumentGenerationService already reads
 * shipping/packing/consignee/company/product data for every field the
 * Twig templates need) — what was actually missing was a discoverable,
 * explained place to trigger them. Both used to be a single generic
 * "Generate X" button lost among a dozen identical ones in the Documents
 * & Approvals section (anchored at Stage 1), disconnected from the stage
 * where their source data is actually entered. This moves BL Instruction
 * Sheet into its own explained section under Stage 7 (Packing & BL
 * Instruction, right after Shipping/Vessel Details) and COO Prep Sheet
 * into its own explained section under Stage 9 (Document Despatch &
 * Closure) — the same treatment BL Endorsement already got.
 */
final class BliCoopPrepSectionTest extends DbTestCase
{
    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function render(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testBlInstructionSheetGetsItsOwnExplainedSectionUnderStage7(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 6);
        self::assertTrue(StageGateService::isUnlocked($orderId, 7));
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);

        $output = $this->render($orderId);

        self::assertStringContainsString(
            'BL Instruction Sheet (for your CHA / shipping line)',
            $output,
            'the generate action needs its own labeled, explained section — not a bare button'
        );
        self::assertStringContainsString('document_type" value="BLI"', $output);

        // Exactly one trigger must exist — the old generic one must be gone,
        // not duplicated alongside the new dedicated section.
        self::assertSame(1, substr_count($output, 'document_type" value="BLI"'));
    }

    public function testBlInstructionSheetSectionAppearsRightAfterShippingDetails(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 6);
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);

        $output = $this->render($orderId);

        $shippingPos = strpos($output, 'Save Shipping Details');
        $bliPos = strpos($output, 'BL Instruction Sheet (for your CHA');
        self::assertNotFalse($shippingPos);
        self::assertNotFalse($bliPos);
        self::assertGreaterThan($shippingPos, $bliPos, 'BL Instruction Sheet must sit after Shipping/Vessel Details, where its source data is entered');
    }

    public function testCooPrepSheetGetsItsOwnExplainedSectionUnderStage9(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Stage 7's gate only ever passes in the real app once BL Issued
        // has been recorded (see show.php's $blIssued, computed from
        // stage7 having passed) — walking the gate directly to Stage 9
        // without this would leave the page reading order_shipping's
        // bl_number/bl_date on a row that was never created.
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');
        $this->unlockThrough($orderId, 8);
        self::assertTrue(StageGateService::isUnlocked($orderId, 9));

        $output = $this->render($orderId);

        self::assertStringContainsString(
            'COO Prep Sheet (internal — for the Chamber of Commerce application)',
            $output,
            'the generate action needs its own labeled, explained section — not a bare button'
        );
        self::assertStringContainsString('document_type" value="COOPREP"', $output);
        self::assertSame(1, substr_count($output, 'document_type" value="COOPREP"'));
    }

    public function testNeitherDedicatedSectionLeaksIntoAnOrderWhereItsStageIsStillLocked(): void
    {
        // A freshly-created order has only Stage 1 unlocked — neither
        // section should render yet.
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->render($orderId);

        self::assertStringNotContainsString('document_type" value="BLI"', $output);
        self::assertStringNotContainsString('document_type" value="COOPREP"', $output);
    }
}
