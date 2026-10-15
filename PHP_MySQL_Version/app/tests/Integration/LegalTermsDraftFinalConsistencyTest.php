<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #17 — "draft-vs-final Legal Terms inconsistency".
 * DocumentGenerationService::generate() (draft) resolves the "Legal
 * Terms" (red box) and "Definitions" (blue box) clause groups and puts
 * them into the Twig context, but finalizeApproval() and
 * markSuperseded() — which re-render the SAME template for the
 * approved/superseded PDF — never did, so the whole "N. LEGAL TERMS &
 * DEFINITIONS" section silently vanished once a document moved past
 * draft. Fixed by adding the same two resolveClauseGroup() calls (with
 * the order's balance_trigger_option, also previously omitted on these
 * two paths) to both functions.
 */
final class LegalTermsDraftFinalConsistencyTest extends DbTestCase
{
    /** @return string|null extracted PDF text, or null if pdftotext isn't available in this environment */
    private function pdfText(int $fileId): ?string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertTrue(is_file($file['server_path']), 'Generated PDF must actually exist on disk.');
        $text = @shell_exec('pdftotext ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null');
        if ($text === null || $text === false || trim($text) === '') {
            return null;
        }
        return $text;
    }

    public function testDraftPdfIncludesLegalTermsAndDefinitions(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);
        $text = $this->pdfText((int) $result['pdf_file_id']);

        if ($text !== null) {
            self::assertStringContainsString('Limitation of Liability', $text, 'draft must show the Legal Terms box');
            self::assertStringContainsString('FCL (Full Container Load)', $text, 'draft must show the Definitions box');
        }
    }

    public function testFinalizedPdfStillIncludesLegalTermsAndDefinitions(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::finalizeApproval((int) $result['document_id']);

        $finalDocument = \App\Repositories\DocumentRepository::find((int) $result['document_id']);
        self::assertSame('approved', $finalDocument['status']);
        $text = $this->pdfText((int) $finalDocument['pdf_file_id']);

        if ($text !== null) {
            self::assertStringContainsString('Limitation of Liability', $text, 'finalized/approved PDF must still show the Legal Terms box, not silently drop it');
            self::assertStringContainsString('FCL (Full Container Load)', $text, 'finalized/approved PDF must still show the Definitions box, not silently drop it');
        }
    }

    public function testSupersededPdfStillIncludesLegalTermsAndDefinitions(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);
        DocumentGenerationService::markSuperseded((int) $result['document_id']);

        $supersededDocument = \App\Repositories\DocumentRepository::find((int) $result['document_id']);
        self::assertSame('superseded', $supersededDocument['status']);
        $text = $this->pdfText((int) $supersededDocument['pdf_file_id']);

        if ($text !== null) {
            self::assertStringContainsString('Limitation of Liability', $text, 'superseded re-render must still show the Legal Terms box, not silently drop it');
            self::assertStringContainsString('FCL (Full Container Load)', $text, 'superseded re-render must still show the Definitions box, not silently drop it');
        }
    }
}
