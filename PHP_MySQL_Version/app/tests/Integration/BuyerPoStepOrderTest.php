<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2026-10-25): Step 4 runs 4a Generate Buyer's PO -> 4b
 * Attach Buyer's PO Copy (staff upload, or the buyer's own portal/Buyer
 * Action Link upload) -> 4c Confirm Buyer's PO Received, which completes
 * the step. The confirm gate already refused without a copy
 * (wet_signature_required_buyer_po, on by default), but the page used to
 * show Confirm (4b) before Attach (4c), walking staff into that error.
 */
final class BuyerPoStepOrderTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['_auth_user_id']);
        parent::tearDown();
    }

    private function orderAtStage2(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        StageGateService::passAndUnlockNext($orderId, 1, $this->userId);
        return $orderId;
    }

    private function render(int $orderId): string
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    public function testAttachCopyComesBeforeConfirmReceived(): void
    {
        $output = $this->render($this->orderAtStage2());

        $attach = strpos($output, 'id="step-4b-buyer-po-attach"');
        $confirm = strpos($output, 'id="step-4c-buyer-po-confirm"');
        self::assertNotFalse($attach);
        self::assertNotFalse($confirm);
        self::assertLessThan($confirm, $attach, '4b Attach must come before 4c Confirm');
    }

    public function testConfirmAsksForTheCopyFirstWhenNoneIsAttached(): void
    {
        $output = $this->render($this->orderAtStage2());

        self::assertStringContainsString("Attach the buyer's signed PO copy in Step 4b first", $output);
        self::assertStringNotContainsString('Confirm Buyer PO Received</button>', $output);
    }

    public function testConfirmFormAppearsOnceTheBuyerHasUploadedACopy(): void
    {
        $orderId = $this->orderAtStage2();
        // file_store.uploaded_by NULL = the buyer's own portal/Buyer Action
        // Link upload (BuyerActionService::uploadBuyerPo()).
        OrderBuyerPoDocumentRepository::attach($orderId, $this->createTestFile($orderId));

        $output = $this->render($orderId);

        self::assertStringContainsString('Confirm Buyer PO Received</button>', $output);
        self::assertStringContainsString('The buyer (self-uploaded)', $output);
    }

    public function testAStaffUploadIsLabelledAsStaff(): void
    {
        $orderId = $this->orderAtStage2();
        $fileId = $this->createTestFile($orderId);
        Database::connection()->exec("UPDATE file_store SET uploaded_by = {$this->userId} WHERE id = {$fileId}");
        OrderBuyerPoDocumentRepository::attach($orderId, $fileId);

        $output = $this->render($orderId);

        self::assertMatchesRegularExpression('/test-file\.pdf<\/td>\s*<td>[^<]*<\/td>\s*<td>Staff<\/td>/', $output);
    }
}
