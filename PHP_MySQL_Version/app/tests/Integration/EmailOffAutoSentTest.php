<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Services\EmailDispatchService;
use App\Services\ReviewWorkflowService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2026-10-25): with mail_sending_enabled off — now the
 * seed default — there is no "Send to Buyer" action; staff send the PDF
 * themselves, so a buyer-sendable document counts as sent the moment it
 * is approved (EmailDispatchService::markSentIfMailDisabled()). Before
 * this, a disabled send could never mark anything sent, so Step 2 (Send
 * Quotation) could never be completed at all.
 */
final class EmailOffAutoSentTest extends DbTestCase
{
    private ?string $originalMailSendingEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalMailSendingEnabled = CompanySettingsRepository::get('mail_sending_enabled');
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', $this->originalMailSendingEnabled, null);
        unset($_SESSION['_auth_user_id']);
        parent::tearDown();
    }

    private function approveThroughReview(int $documentId): void
    {
        $reviewer = $this->createTestUser('Export Executive');
        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($documentId)[0];
        ReviewWorkflowService::approve((int) $review['id'], (int) $review['reviewer_id'], null);
    }

    public function testSeedDefaultIsMailSendingOff(): void
    {
        self::assertSame('0', $this->originalMailSendingEnabled);
    }

    public function testApprovedQuotationCountsAsSentWhenMailIsOff(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');

        $this->approveThroughReview($documentId);

        self::assertSame('sent', DocumentRepository::find($documentId)['status']);
        $audit = Database::connection()->query(
            "SELECT COUNT(*) FROM audit_log WHERE action_type = 'DOCUMENT_MARKED_SENT_MAIL_DISABLED' AND entity_id = {$documentId}"
        )->fetchColumn();
        self::assertSame(1, (int) $audit);
    }

    public function testApprovedQuotationStaysApprovedWhenMailIsOn(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');

        $this->approveThroughReview($documentId);

        self::assertSame('approved', DocumentRepository::find($documentId)['status'], 'with mail on it waits for a real Send to Buyer');
    }

    public function testInternalNeverShownToBuyerDocumentIsNotMarkedSent(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'SUPPO', 'approved');

        EmailDispatchService::markSentIfMailDisabled($documentId);

        self::assertSame('approved', DocumentRepository::find($documentId)['status'], 'a Supplier PO is never sent to the buyer');
    }

    public function testOrderConfirmationMarkedSentStartsTheAcknowledgmentClock(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'OC', 'approved');

        EmailDispatchService::markSentIfMailDisabled($documentId);

        self::assertSame('sent', DocumentRepository::find($documentId)['status']);
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNotNull($ack, 'the 48-hour acknowledgment clock must start exactly as a real OC send would');
        self::assertSame($documentId, (int) $ack['document_id']);
    }

    public function testSendFallsBackToTheLiveClientEmailWhenTheOrderSnapshotHasNone(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $pdo = Database::connection();
        $pdo->exec("UPDATE orders SET client_email = NULL WHERE id = {$orderId}");
        $pdo->exec("UPDATE clients SET email = 'added-later@example.test' WHERE id = {$clientId}");
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved');
        $userId = $this->createTestUser('Admin');

        $preview = EmailDispatchService::buildPreview($orderId, $documentId, 'send_qt', $userId);

        self::assertSame('added-later@example.test', $preview['recipient_email']);
    }

    public function testOrderPageHidesSendToBuyerWhenMailIsOff(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'QT', 'approved');
        $_SESSION['_auth_user_id'] = $userId;

        CompanySettingsRepository::set('mail_sending_enabled', '1', null);
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $mailOn = ob_get_clean();

        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $mailOff = ob_get_clean();

        self::assertStringContainsString('Send to Buyer (deferred', $mailOn);
        self::assertStringNotContainsString('Send to Buyer (deferred', $mailOff);
        self::assertStringContainsString('Email sending is turned off', $mailOff);
    }
}
