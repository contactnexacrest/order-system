<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\AmendmentRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Repositories\EmailLogRepository;
use App\Services\AmendmentService;
use App\Services\EmailDispatchService;
use App\Services\ReviewWorkflowService;
use App\Tests\Support\DbTestCase;

/**
 * QA-4 P0.4 (docs/QA/TEST_PLAN.md Section 6): "an amendment cannot activate
 * without MD approval; a document cannot be marked sent without passing
 * through document_reviews; an email cannot dispatch without
 * approve_email_send approval where required."
 */
final class AmendmentDocumentEmailGatesTest extends DbTestCase
{
    // ---------------------------------------------------------------
    // Gate 1: an amendment cannot activate without MD approval.
    // ---------------------------------------------------------------

    private function createTestAmendment(int $orderId): int
    {
        return AmendmentRepository::create(
            'AMD-TEST-' . bin2hex(random_bytes(4)),
            $orderId,
            'PHPUnit test amendment',
            'importer',
            ['note' => 'snapshot'],
            null, null, null, null, null, null, null
        );
    }

    public function testGenerateDocumentRefusesAPendingAmendment(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $amendmentId = $this->createTestAmendment($orderId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be MD-approved');
        AmendmentService::generateDocument($amendmentId, 1);
    }

    public function testAttachSignedCopyRefusesAPendingAmendment(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $amendmentId = $this->createTestAmendment($orderId);
        $fileId = $this->createTestFile($orderId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MD-approved first');
        AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, 1);
    }

    public function testAttachSignedCopyRefusesWhenNoDocumentGeneratedYet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $amendmentId = $this->createTestAmendment($orderId);
        AmendmentService::approveByMd($amendmentId, 1);
        $fileId = $this->createTestFile($orderId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Generate the Amendment Agreement document');
        AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, 1);
    }

    public function testApproveByMdRefusesAnAlreadyApprovedAmendment(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $amendmentId = $this->createTestAmendment($orderId);
        AmendmentService::approveByMd($amendmentId, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only a pending amendment');
        AmendmentService::approveByMd($amendmentId, 1);
    }

    public function testFullPathActivatesOnlyAfterMdApprovalAndDocumentGeneration(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $amendmentId = $this->createTestAmendment($orderId);

        AmendmentService::approveByMd($amendmentId, 1);
        self::assertSame('md_approved', AmendmentRepository::find($amendmentId)['status']);

        $documentId = $this->createTestDocument($orderId, 'QT');
        AmendmentRepository::attachDocument($amendmentId, $documentId);
        $fileId = $this->createTestFile($orderId);

        AmendmentService::attachSignedCopyAndActivate($amendmentId, $fileId, 1);

        $amendment = AmendmentRepository::find($amendmentId);
        self::assertSame('active', $amendment['status']);
        self::assertSame($fileId, (int) $amendment['signed_copy_file_id']);
    }

    // ---------------------------------------------------------------
    // Gate 2: a document cannot be marked sent (or approved) without
    // passing through document_reviews.
    // ---------------------------------------------------------------

    public function testDocumentDoesNotAutoApproveWhileAReviewerIsStillPending(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');
        $reviewer1 = $this->createTestUser('Export Executive');
        $reviewer2 = $this->createTestUser('Export Executive');
        $this->setMinReviewers($documentId, 2);

        ReviewWorkflowService::assignReviewers($documentId, [$reviewer1, $reviewer2], 1);
        $review1 = DocumentReviewRepository::forDocument($documentId)[0]['id'];
        ReviewWorkflowService::approve((int) $review1, $reviewer1, null);

        self::assertSame('in_review', DocumentRepository::find($documentId)['status'], 'one pending reviewer must keep the document short of approved');
    }

    public function testDocumentApprovesOnlyOnceEveryRequiredReviewerApproves(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');
        $reviewer1 = $this->createTestUser('Export Executive');
        $reviewer2 = $this->createTestUser('Export Executive');
        $this->setMinReviewers($documentId, 2);

        ReviewWorkflowService::assignReviewers($documentId, [$reviewer1, $reviewer2], 1);
        $reviews = DocumentReviewRepository::forDocument($documentId);
        ReviewWorkflowService::approve((int) $reviews[0]['id'], (int) $reviews[0]['reviewer_id'], null);
        ReviewWorkflowService::approve((int) $reviews[1]['id'], (int) $reviews[1]['reviewer_id'], null);

        $document = DocumentRepository::find($documentId);
        self::assertSame('approved', $document['status']);
        self::assertNotNull($document['pdf_file_id']);
    }

    public function testARejectionSendsTheDocumentBackToDraftRatherThanApproving(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'QT', 'draft');
        $reviewer1 = $this->createTestUser('Export Executive');
        $reviewer2 = $this->createTestUser('Export Executive');
        $this->setMinReviewers($documentId, 2);

        ReviewWorkflowService::assignReviewers($documentId, [$reviewer1, $reviewer2], 1);
        $reviews = DocumentReviewRepository::forDocument($documentId);
        ReviewWorkflowService::approve((int) $reviews[0]['id'], (int) $reviews[0]['reviewer_id'], null);
        ReviewWorkflowService::reject((int) $reviews[1]['id'], (int) $reviews[1]['reviewer_id'], 'PHPUnit test rejection reason');

        self::assertSame('draft', DocumentRepository::find($documentId)['status']);
    }

    public function testEmailDispatchServiceRefusesToPreviewANonApprovedDocument(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->setClientEmail($clientId, 'buyer@example.test');
        $documentId = $this->createTestDocument($orderId, 'QT', 'in_review');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must clear internal review first');
        EmailDispatchService::buildPreview($orderId, $documentId, 'send_qt', 1);
    }

    // ---------------------------------------------------------------
    // Gate 3: an approved-then-later-reverted document must never
    // actually dispatch a stale send (the QA-4 fix to dispatch()).
    // ---------------------------------------------------------------

    public function testDispatchRefusesAndMarksFailedWhenDocumentWasRevertedToDraftAfterSendWasQueued(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->setClientEmail($clientId, 'buyer@example.test');

        $filePath = tempnam(sys_get_temp_dir(), 'phpunit_dispatch_test_');
        file_put_contents($filePath, 'fake pdf bytes');
        $fileId = $this->createTestFileAtPath($filePath);

        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $fileId);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);

        // A new reviewer is assigned to the already-approved document and
        // rejects it — ReviewWorkflowService::reject() unconditionally
        // reverts status to 'draft', regardless of the document's current
        // status, without touching pdf_file_id or looking at any pending
        // send already queued against it.
        $reviewer = $this->createTestUser('Export Executive');
        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($documentId)[0];
        ReviewWorkflowService::reject((int) $review['id'], $reviewer, 'PHPUnit: found an error after approval');
        self::assertSame('draft', DocumentRepository::find($documentId)['status']);

        $logFile = tempnam(sys_get_temp_dir(), 'phpunit_error_log_');
        $previous = ini_set('error_log', $logFile);
        try {
            $result = EmailDispatchService::dispatch(EmailLogRepository::find($emailLogId));
        } finally {
            ini_set('error_log', $previous !== false ? $previous : '');
        }
        $logContents = (string) file_get_contents($logFile);
        unlink($logFile);
        unlink($filePath);

        self::assertFalse($result);
        self::assertSame('failed', EmailLogRepository::find($emailLogId)['status']);
        self::assertStringNotContainsString('EMAIL NOT SENT', $logContents, 'must be blocked by the status re-check, before ever reaching the actual mail-send attempt');
    }

    public function testDispatchStillAttemptsToSendAGenuinelyApprovedDocument(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->setClientEmail($clientId, 'buyer@example.test');

        $filePath = tempnam(sys_get_temp_dir(), 'phpunit_dispatch_test_');
        file_put_contents($filePath, 'fake pdf bytes');
        $fileId = $this->createTestFileAtPath($filePath);

        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $fileId);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);

        $logFile = tempnam(sys_get_temp_dir(), 'phpunit_error_log_');
        $previous = ini_set('error_log', $logFile);
        try {
            EmailDispatchService::dispatch(EmailLogRepository::find($emailLogId));
        } finally {
            ini_set('error_log', $previous !== false ? $previous : '');
        }
        $logContents = (string) file_get_contents($logFile);
        unlink($logFile);
        unlink($filePath);

        // This dev/test environment has no SMTP configured, so the send
        // itself always fails here too — but it must actually reach that
        // deep code path (proving my fix's status check doesn't also
        // block a genuinely still-approved document).
        self::assertStringContainsString('EMAIL NOT SENT', $logContents);
    }

    private function setMinReviewers(int $documentId, int $count): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT document_type_id FROM documents WHERE id = :id');
        $stmt->execute(['id' => $documentId]);
        $typeId = (int) $stmt->fetchColumn();

        $pdo->prepare('UPDATE document_types SET min_reviewers_default = :count WHERE id = :id')
            ->execute(['count' => $count, 'id' => $typeId]);
    }

    private function setClientEmail(int $clientId, string $email): void
    {
        Database::connection()->prepare('UPDATE clients SET email = :email WHERE id = :id')
            ->execute(['email' => $email, 'id' => $clientId]);
    }

    private function createTestFileAtPath(string $path): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "INSERT INTO file_store (file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
             VALUES ('GENERATED_AUTO', :path, :uuid, 'test.pdf', 14, 'application/pdf')"
        );
        $stmt->execute(['path' => $path, 'uuid' => bin2hex(random_bytes(16)) . '.pdf']);
        return (int) $pdo->lastInsertId();
    }
}
