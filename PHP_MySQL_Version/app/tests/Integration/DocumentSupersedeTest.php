<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\DocumentRepository;
use App\Repositories\DocumentReviewRepository;
use App\Services\DocumentGenerationService;
use App\Services\ReviewWorkflowService;
use App\Tests\Support\DbTestCase;

/**
 * User-reported bug (2026-10-01): "if multiple documents get created, and
 * if anyone got approved, then all other are invalid, and should not be
 * approved, until new document get generated - only this newly generated
 * document may get approved, but if newly get document is approved, the
 * earlier approved document gets invalid and the watermark must contain
 * INVALID DOCUMENT". documents.status already had a 'superseded' value in
 * the schema but no code path ever set it — this covers the fix:
 * ReviewWorkflowService::finalizeIfFullyApproved() now calls
 * DocumentGenerationService::supersedeOtherApprovedRevisions() right after
 * approving a document, and ReviewWorkflowService::approve() refuses to
 * approve a review whose document is no longer the latest revision.
 */
final class DocumentSupersedeTest extends DbTestCase
{
    public function testApprovingANewerRevisionSupersedesThePreviousApprovedRevision(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $oldFileId = $this->createTestFile($orderId);
        $oldDocumentId = $this->createTestDocumentWithRevision($orderId, 'QT', 'approved', 0, $oldFileId);

        $newDocumentId = $this->createTestDocumentWithRevision($orderId, 'QT', 'draft', 1);
        $reviewer = $this->createTestUser('Export Executive');
        $this->setMinReviewers($newDocumentId, 1);
        ReviewWorkflowService::assignReviewers($newDocumentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($newDocumentId)[0];

        ReviewWorkflowService::approve((int) $review['id'], $reviewer, null);

        $oldDocument = DocumentRepository::find($oldDocumentId);
        self::assertSame('superseded', $oldDocument['status'], 'the earlier approved revision must be invalidated once a newer one is approved');
        self::assertNotSame($oldFileId, (int) $oldDocument['pdf_file_id'], 'the superseded revision must be re-rendered as a new file, never overwriting the original approved PDF');

        $newDocument = DocumentRepository::find($newDocumentId);
        self::assertSame('approved', $newDocument['status'], 'the newly approved revision itself must stay approved, not get swept up by its own supersede pass');
    }

    public function testASentRevisionIsAlsoSupersededByANewerApproval(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $sentFileId = $this->createTestFile($orderId);
        $sentDocumentId = $this->createTestDocumentWithRevision($orderId, 'PI', 'sent', 0, $sentFileId);

        $newDocumentId = $this->createTestDocumentWithRevision($orderId, 'PI', 'draft', 1);
        $reviewer = $this->createTestUser('Export Executive');
        $this->setMinReviewers($newDocumentId, 1);
        ReviewWorkflowService::assignReviewers($newDocumentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($newDocumentId)[0];

        ReviewWorkflowService::approve((int) $review['id'], $reviewer, null);

        self::assertSame('superseded', DocumentRepository::find($sentDocumentId)['status'], 'a document already sent to the buyer must also be invalidated once a newer revision is approved');
    }

    public function testCannotApproveAStaleRevisionOnceANewerOneHasBeenGenerated(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $staleDocumentId = $this->createTestDocumentWithRevision($orderId, 'QT', 'draft', 0);
        $reviewer = $this->createTestUser('Export Executive');
        $this->setMinReviewers($staleDocumentId, 1);
        ReviewWorkflowService::assignReviewers($staleDocumentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($staleDocumentId)[0];

        // A newer revision is generated while that review is still pending
        // (e.g. a mistake was found and fixed out of band before the
        // reviewer got to it).
        $this->createTestDocumentWithRevision($orderId, 'QT', 'draft', 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('newer revision');
        ReviewWorkflowService::approve((int) $review['id'], $reviewer, null);
    }

    public function testApprovingTheActualLatestRevisionStillWorksNormally(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        // Only one revision exists — it IS the latest, so approval must not
        // be blocked by the new guard.
        $documentId = $this->createTestDocumentWithRevision($orderId, 'QT', 'draft', 0);
        $reviewer = $this->createTestUser('Export Executive');
        $this->setMinReviewers($documentId, 1);
        ReviewWorkflowService::assignReviewers($documentId, [$reviewer], 1);
        $review = DocumentReviewRepository::forDocument($documentId)[0];

        ReviewWorkflowService::approve((int) $review['id'], $reviewer, null);

        self::assertSame('approved', DocumentRepository::find($documentId)['status']);
    }

    public function testMarkSupersededDirectlyFlipsStatusAndRepointsThePdf(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $originalFileId = $this->createTestFile($orderId);
        $documentId = $this->createTestDocumentWithRevision($orderId, 'QT', 'approved', 0, $originalFileId);

        DocumentGenerationService::markSuperseded($documentId);

        $document = DocumentRepository::find($documentId);
        self::assertSame('superseded', $document['status']);
        self::assertNotNull($document['pdf_file_id']);
        self::assertNotSame($originalFileId, (int) $document['pdf_file_id']);
    }

    private function createTestDocumentWithRevision(int $orderId, string $typeCode, string $status, int $revisionNumber, ?int $pdfFileId = null): int
    {
        $documentId = $this->createTestDocument($orderId, $typeCode, $status, $pdfFileId);
        Database::connection()->prepare(
            'UPDATE documents SET revision_number = :rev1, client_revision_number = :rev2 WHERE id = :id'
        )->execute(['rev1' => $revisionNumber, 'rev2' => $revisionNumber, 'id' => $documentId]);
        return $documentId;
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
}
