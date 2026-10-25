<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderStageRepository;
use App\Services\BuyerActionService;
use App\Services\DocumentGenerationService;
use App\Services\ReviewWorkflowService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2026-10-25): "if in any stage we generate the document,
 * unless that document is approved that stage is not considered complete —
 * it completes when the document is reviewed when send email is disabled;
 * when send email is enabled, only when that document is sent." Each stage
 * gate action is refused until its document is complete; internal
 * never-shown-to-buyer documents (Supplier PO, BLI) only ever need approval.
 */
final class StageDocumentGateTest extends DbTestCase
{
    private ?string $originalMailSendingEnabled = null;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMailSendingEnabled = CompanySettingsRepository::get('mail_sending_enabled');
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', $this->originalMailSendingEnabled, null);
        unset($_SESSION['_auth_user_id']);
        $_POST = [];
        parent::tearDown();
    }

    private function orderAtStage2(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        StageGateService::passAndUnlockNext($orderId, 1, $this->userId);
        return $orderId;
    }

    public function testADraftDocumentBlocksItsStageAndApprovalUnblocksItWhileMailIsOff(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        $orderId = $this->orderAtStage2();

        $blockedBy = DocumentGenerationService::stageDocumentBlockedBy($orderId, 2);
        self::assertCount(1, $blockedBy, 'not generated yet');
        self::assertStringContainsString('Buyer PO', $blockedBy[0]);

        $documentId = $this->createTestDocument($orderId, 'BUYERPO', 'draft');
        self::assertNotEmpty(DocumentGenerationService::stageDocumentBlockedBy($orderId, 2), 'a draft is not complete');

        \App\Config\Database::connection()->exec("UPDATE documents SET status = 'approved' WHERE id = {$documentId}");
        self::assertSame([], DocumentGenerationService::stageDocumentBlockedBy($orderId, 2), 'approved is complete while email is off');
    }

    public function testWhileMailIsOnABuyerDocumentMustBeSentNotJustApproved(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        $orderId = $this->orderAtStage2();
        $documentId = $this->createTestDocument($orderId, 'BUYERPO', 'approved');

        self::assertNotEmpty(DocumentGenerationService::stageDocumentBlockedBy($orderId, 2), 'approved but not sent');

        \App\Config\Database::connection()->exec("UPDATE documents SET status = 'sent' WHERE id = {$documentId}");
        self::assertSame([], DocumentGenerationService::stageDocumentBlockedBy($orderId, 2));
    }

    public function testAnInternalDocumentOnlyNeedsApprovalEvenWhileMailIsOn(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        $orderId = $this->orderAtStage2();
        $this->createTestDocument($orderId, 'SUPPO', 'approved');

        self::assertSame([], DocumentGenerationService::stageDocumentBlockedBy($orderId, 5), 'a Supplier PO is never sent to the buyer');
    }

    public function testConfirmBuyerPoReceivedIsRefusedUntilTheBuyerPoIsApproved(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        $orderId = $this->orderAtStage2();
        $this->createTestDocument($orderId, 'BUYERPO', 'draft');
        OrderBuyerPoDocumentRepository::attach($orderId, $this->createTestFile($orderId));
        $_POST = ['buyers_po_ref' => 'PO-123'];

        (new OrderController())->recordBuyerPo(['id' => (string) $orderId]);

        self::assertStringContainsString('only be completed once its document is approved', (string) ($_SESSION['_flash'][0]['message'] ?? json_encode($_SESSION['_flash'] ?? null)));
        self::assertSame('in_progress', OrderStageRepository::findByOrderAndStageNumber($orderId, 2)['status'], 'the stage must not pass');
    }

    public function testWhileMailIsOnApprovingTheQuotationDoesNotPassStage1(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');
        $reviewer = $this->createTestUser('Export Executive');
        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($documentId)[0];

        ReviewWorkflowService::approve((int) $review['id'], (int) $review['reviewer_id'], null);

        self::assertSame('in_progress', OrderStageRepository::findByOrderAndStageNumber($orderId, 1)['status'], 'Stage 1 waits for the Quotation to be sent');
    }

    public function testWhileMailIsOffApprovingTheQuotationPassesStage1(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');
        $reviewer = $this->createTestUser('Export Executive');
        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($documentId)[0];

        ReviewWorkflowService::approve((int) $review['id'], (int) $review['reviewer_id'], null);

        self::assertSame('gate_passed', OrderStageRepository::findByOrderAndStageNumber($orderId, 1)['status']);
    }

    public function testWhileMailIsOnTheBuyerCannotAcknowledgeAnApprovedButUnsentOc(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 3; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, $this->userId);
        }
        $documentId = $this->createTestDocument($orderId, 'OC', 'approved');

        self::assertFalse(BuyerActionService::acknowledgeOc($orderId)['ok']);
        self::assertNull(OrderOcAcknowledgmentRepository::find($orderId), 'no acknowledgement offered before the OC is sent');

        \App\Config\Database::connection()->exec("UPDATE documents SET status = 'sent' WHERE id = {$documentId}");
        self::assertTrue(BuyerActionService::acknowledgeOc($orderId)['ok']);
        self::assertSame('gate_passed', OrderStageRepository::findByOrderAndStageNumber($orderId, 4)['status']);
    }
}
