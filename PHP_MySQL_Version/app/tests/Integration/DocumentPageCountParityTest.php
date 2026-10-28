<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-14/L-17, QA Round 5 R5-12 — "Node quotation PDF 5
 * pages vs PHP 6 pages... owner requires identical look."
 *
 * Root cause (QA Round 5): DOMPDF builds a text line from the font's full
 * height (Carlito ascender-descender x its 1.1 font-height ratio) and then
 * multiplies that by line-height, while Chromium uses the font's own line
 * metrics and rounds to whole pixels. Left at "normal", every PHP line and
 * table row came out ~15% taller. _layout.html.twig now sets
 * `line-height: 0.86` on body, which lands DOMPDF on Chromium's line pitch,
 * and both layouts keep a section heading with the content that follows
 * it. Both stacks now give the same page counts — these pins are the same
 * numbers as Node's tests/manual/documentPageCountParity.js, so a future
 * template change can't drift the two stacks apart without a test noticing.
 */
final class DocumentPageCountParityTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    private function pdfPageCount(int $fileId): ?int
    {
        $file = FileStoreRepository::find($fileId);
        $info = @shell_exec('pdfinfo ' . escapeshellarg($file['server_path']) . ' 2>/dev/null');
        if (!$info || !preg_match('/Pages:\s*(\d+)/', $info, $m)) {
            return null;
        }
        return (int) $m[1];
    }

    public function testQuotationPageCountIsPinnedForASmallOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'Parity test slab #1', '600x600x18mm', 'Polished', '100', false, 'sqm', '25.00');
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);
        $pageCount = $this->pdfPageCount((int) $result['pdf_file_id']);

        if ($pageCount === null) {
            self::fail('Could not read the generated PDF with pdfinfo — install poppler-utils (e.g. apt install poppler-utils), which these PDF checks require, or the PDF itself is unreadable.');
        }
        self::assertSame(5, $pageCount, 'PHP Quotation page count for a 1-line order drifted from Node\'s (5) — see class docblock.');
    }

    public function testQuotationPageCountIsPinnedForALargeOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($i = 1; $i <= 60; $i++) {
            OrderProductRepository::add($orderId, $i, "Parity test slab #{$i}", '600x600x18mm', 'Polished', '100', false, 'sqm', '25.00');
        }
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);
        $pageCount = $this->pdfPageCount((int) $result['pdf_file_id']);

        if ($pageCount === null) {
            self::fail('Could not read the generated PDF with pdfinfo — install poppler-utils (e.g. apt install poppler-utils), which these PDF checks require, or the PDF itself is unreadable.');
        }
        self::assertSame(8, $pageCount, 'PHP Quotation page count for a 60-line order drifted from Node\'s (8) — see class docblock.');
    }
}
