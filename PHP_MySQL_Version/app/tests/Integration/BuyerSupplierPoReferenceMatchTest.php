<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\TermsClauseRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Regression coverage for the Buyer PO / Supplier PO cross-check against
 * the real reference documents the business actually uses (Point 1 of the
 * original 10-point feedback list, addressed here after the earlier
 * "Section 1 is missing" diagnosis turned out to be wrong — Section 1 was
 * already correctly inherited from _layout.html.twig's shared block; the
 * two real gaps were the Buyer PO's page title and a duplicated Supplier
 * PO clause, both fixed here).
 */
final class BuyerSupplierPoReferenceMatchTest extends DbTestCase
{
    private function extractPdfText(int $pdfFileId): string
    {
        $file = FileStoreRepository::find($pdfFileId);
        self::assertNotNull($file, 'generated PDF file_store row must exist');
        $process = proc_open(
            ['pdftotext', $file['server_path'], '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $text = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return (string) $text;
    }

    public function testBuyerPoTitleMatchesTheRealReferenceDocumentExactly(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'BUYERPO', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('PURCHASE ORDER', $text);
        self::assertStringNotContainsString('PURCHASE ORDER — ORDER ACCEPTANCE', $text, 'the reference document titles this plainly "PURCHASE ORDER", not "...— ORDER ACCEPTANCE"');
    }

    public function testSupplierPoTitleStillMatchesTheReferenceDocument(): void
    {
        // SUPPO's title already matched the reference before this fix —
        // pinned here so a future change can't silently regress it.
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'SUPPO', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('PURCHASE ORDER — MATERIAL PROCUREMENT', $text);
    }

    public function testSupplierPoSection1IsNexaCrestsOwnCompanyBlockLabelledBuyer(): void
    {
        // Confirms the shared _layout.html.twig Section 1 block actually
        // renders for SUPPO — this is exactly the behavior an earlier,
        // incorrect manual read of the child template alone concluded was
        // missing. It was not: Twig block inheritance supplies it.
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'SUPPO', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('1. BUYER (NexaCrest International Private Limited)', $text);
        self::assertStringContainsString('NexaCrest International Private Limited', $text);
        self::assertStringContainsString('29AAKCN8733G1ZZ', $text); // GSTIN
    }

    public function testBuyerPoSection1IsNexaCrestsOwnCompanyBlockLabelledSupplier(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'BUYERPO', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertStringContainsString('1. SUPPLIER', $text);
        self::assertStringContainsString('NexaCrest International Private Limited', $text);
    }

    public function testSupplierPoQualityAndInspectionNoLongerDuplicatesTheDeliveryTermsClause(): void
    {
        $clauses = TermsClauseRepository::forDocumentTypeCode('SUPPO');
        $titles = array_column($clauses, 'clause_title');

        self::assertNotContains('Time Is of the Essence', $titles, 'that exact text is already a static row in Section 5 (Delivery Terms) — a clause row duplicated it in Section 6');
        self::assertCount(5, $clauses, 'the real reference document lists exactly 5 Quality & Inspection bullets');
    }

    public function testSupplierPoGeneratedPdfMentionsTimeIsOfTheEssenceExactlyOnce(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'SUPPO', $userId, null, false, true);
        $text = $this->extractPdfText((int) $result['pdf_file_id']);

        self::assertSame(1, substr_count($text, 'of the essence'));
    }
}
