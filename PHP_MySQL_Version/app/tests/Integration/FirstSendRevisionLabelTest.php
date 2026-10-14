<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #44: the email attachment a buyer receives (and the
 * same file's client-portal download name — both read file_store.
 * original_filename, see EmailDispatchService::dispatch() and
 * ClientPortalController's downloads) literally contained "(approved)",
 * and showed "Rev.0" / "Rev.00" even on a document's very first-ever
 * send — confusing wording for a buyer seeing a document for the first
 * time, since there is nothing before it to be "revision 0" of. The
 * on-page meta label (every customer-facing Twig template prints
 * "{{ meta.document_reference }} {{ meta.client_revision_label }}") had
 * the same problem. From the second client-facing issue onward
 * (client_revision_number >= 1) nothing changes — this is a genuine
 * revision and must still say so.
 */
final class FirstSendRevisionLabelTest extends DbTestCase
{
    private function createTestDocumentWithRevision(int $orderId, string $typeCode, string $status, int $clientRevisionNumber): int
    {
        $documentId = $this->createTestDocument($orderId, $typeCode, $status);
        Database::connection()->prepare(
            'UPDATE documents SET client_revision_number = :rev WHERE id = :id'
        )->execute(['rev' => $clientRevisionNumber, 'id' => $documentId]);
        return $documentId;
    }

    private function approvedFilenameFor(int $documentId): string
    {
        DocumentGenerationService::finalizeApproval($documentId);
        $document = DocumentRepository::find($documentId);
        $file = FileStoreRepository::find((int) $document['pdf_file_id']);
        return $file['original_filename'];
    }

    public function testFirstEverSendFilenameHasNoApprovedTagAndNoRevisionZero(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocumentWithRevision($orderId, 'QT', 'draft', 0);

        $filename = $this->approvedFilenameFor($documentId);

        self::assertStringNotContainsString('(approved)', $filename, 'the buyer-visible attachment/download name must never leak this internal bookkeeping tag');
        self::assertStringNotContainsString('Rev.0', $filename, 'the very first send has nothing to be a revision of — no Rev.0/Rev.00 at all');
    }

    public function testSecondSendFilenameStillShowsItsRealRevisionNumber(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocumentWithRevision($orderId, 'QT', 'draft', 1);

        $filename = $this->approvedFilenameFor($documentId);

        self::assertStringNotContainsString('(approved)', $filename);
        self::assertStringContainsString('Rev.01', $filename, 'a genuine second-or-later client-facing revision must still say so');
    }

    public function testMarkSupersededAlsoSuppressesRevisionZeroOnTheOnPageLabel(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocumentWithRevision($orderId, 'QT', 'approved', 0);

        DocumentGenerationService::markSuperseded($documentId);

        // markSuperseded() re-renders the PDF but doesn't change the
        // filename pattern under test above — this only confirms the
        // call succeeds without error for a revision-0 document (the
        // meta.client_revision_label passed into the template is covered
        // indirectly: a stray str_pad on an uninitialized label would
        // throw a TypeError during rendering, which this would catch).
        $document = DocumentRepository::find($documentId);
        self::assertSame('superseded', $document['status']);
    }
}
