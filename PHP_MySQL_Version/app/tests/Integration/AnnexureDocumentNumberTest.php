<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #71: Annexure A's own generated document never printed
 * its own document reference ("ANNEXURE NO.") in its meta bar, unlike
 * every other document type (QT prints "QUOTATION NO.", PI prints "PI
 * NUMBER", BLI prints "BL INSTRUCTION NO.", etc.). Root cause: unlike
 * investigated, document_types.ref_format for ANNEXA was seeded NULL —
 * the same NULL used for genuinely internal/non-generated types
 * (CHECKLIST, SOP_*, STAGEGATE, WALLREF) — even though ANNEXA is
 * 'customer_facing' and goes through the exact same generate/approve/send
 * lifecycle as QT/PI/OC. With ref_format NULL,
 * ReferenceNumberService::generateDocumentRef() always returned null, so
 * there was never a reference to print in the first place. Fixed with a
 * DB data correction (document_types.ref_format = 'SC/ANX/{YYYY}/{DDMM}
 * {NNN}' for code='ANNEXA' — see docs/seed.sql) plus adding an
 * "ANNEXURE NO. *" entry (document_reference + revision_label, the same
 * convention every other document type's meta bar already uses) to both
 * the PDF template's meta_table and the DOCX builder's renderAnnexa().
 */
final class AnnexureDocumentNumberTest extends DbTestCase
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

    public function testAnnexaPdfPrintsItsOwnDocumentReferenceAsAnnexureNo(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        OrderRepository::setIncludeAnnexureA($orderId, true);
        OrderAnnexureRepository::createProduct($orderId, [
            'name' => 'Marble Side Table',
            'description' => '',
            'dimensions' => '',
            'finish' => '',
            'components' => '',
            'technical_notes' => '',
        ]);

        $result = DocumentGenerationService::generate($orderId, 'ANNEXA', $userId);
        $document = \App\Repositories\DocumentRepository::find((int) $result['document_id']);

        self::assertNotNull($document['document_reference'], 'ANNEXA must now be assigned a real document reference, not NULL.');
        self::assertMatchesRegularExpression('#^SC/ANX/#', $document['document_reference']);

        $text = $this->pdfText((int) $result['pdf_file_id']);
        if ($text !== null) {
            self::assertStringContainsString('ANNEXURE NO.', $text);
            self::assertStringContainsString($document['document_reference'], $text);
        }
    }
}
