<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AssetRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\WatermarkSettingsRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-04: continuation pages carried neither the logo
 * watermark nor the document reference — only the doc-header table (an
 * ordinary in-flow element existing once, on page 1) ever showed the
 * reference, and CSS `position: fixed` does not reliably repeat a
 * replaced element (the watermark <img>) across every DOMPDF page.
 * Confirmed exactly as reported: with watermark mode 'both', the logo
 * painted on page 1 only.
 */
final class ContinuationPageFooterTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    /** @return string|null extracted PDF text for one page (1-indexed), or null if pdftotext isn't available */
    private function pdfTextForPage(int $fileId, int $page): ?string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertTrue(is_file($file['server_path']), 'Generated PDF must actually exist on disk.');
        $text = @shell_exec('pdftotext -f ' . $page . ' -l ' . $page . ' ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null');
        if ($text === null || $text === false) {
            return null;
        }
        return $text;
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

    public function testQuotationContinuationPageCarriesTheReferenceAndPageNumber(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Enough product lines to force the Quotation past a single page —
        // the exact scenario DOC-QT-06 tested against.
        for ($i = 1; $i <= 60; $i++) {
            OrderProductRepository::add($orderId, $i, "Continuation page test slab #{$i}", '600x600x18mm', 'Polished', '100', false, 'sqm', '25.00');
        }
        $userId = $this->createTestUser('Admin');

        $result = DocumentGenerationService::generate($orderId, 'QT', $userId);
        $document = DocumentRepository::find((int) $result['document_id']);
        $pageCount = $this->pdfPageCount((int) $result['pdf_file_id']);

        if ($pageCount === null) {
            self::fail('Could not read the generated PDF with pdfinfo — install poppler-utils (e.g. apt install poppler-utils), which these PDF checks require, or the PDF itself is unreadable.');
        }
        self::assertGreaterThan(1, $pageCount, 'this many product lines must force the Quotation past one page for this test to mean anything');

        $page2Text = $this->pdfTextForPage((int) $result['pdf_file_id'], 2);
        if ($page2Text === null) {
            self::fail('Could not read the generated PDF with pdftotext — install poppler-utils (e.g. apt install poppler-utils), which these PDF checks require, or the PDF itself is unreadable.');
        }
        self::assertStringContainsString($document['document_reference'], $page2Text, 'the running footer must carry the document reference on every continuation page');
        self::assertStringContainsString('Page 2 of ' . $pageCount, $page2Text, 'the running footer must carry the page x/y on every continuation page');
    }

    public function testWatermarkLogoDrawCallbackRunsWithoutErrorAcrossMultiplePages(): void
    {
        // Exercises the exact reported scenario — mode 'both' — end to
        // end. There is no practical way to assert an EMBEDDED IMAGE is
        // present on a specific page from a PHPUnit test (pdftotext only
        // extracts text); the real assertion is that generation with a
        // real on-disk watermark image, across a multi-page document,
        // completes without the page_script callback throwing, and that
        // the resulting PDF still has every page the text check above
        // already confirms.
        $realPath = sys_get_temp_dir() . '/phpunit-watermark-contpage-' . bin2hex(random_bytes(4)) . '.png';
        // A minimal real 1x1 PNG (not just a magic-number stub) so
        // getimagesize() inside addRunningHeaderFooter() can read real
        // dimensions rather than failing to parse.
        file_put_contents($realPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        try {
            $assetId = AssetRepository::insert('watermark', 'Continuation Page Test Watermark', $realPath, 'image/png', null, true);
            $adminUserId = $this->createTestUser('Admin');
            WatermarkSettingsRepository::upsertGlobal(true, [
                'mode' => 'both', 'text_content' => 'DRAFT', 'font' => 'Helvetica', 'font_size' => 60,
                'color' => '#a8701f', 'opacity' => 0.15, 'angle' => 45,
                'image_asset_id' => $assetId, 'image_opacity' => 0.1, 'image_position' => 'center',
            ], $adminUserId);

            $orderId = $this->createTestOrder($this->createTestClient());
            for ($i = 1; $i <= 60; $i++) {
                OrderProductRepository::add($orderId, $i, "Watermark test slab #{$i}", '600x600x18mm', 'Polished', '100', false, 'sqm', '25.00');
            }

            $result = DocumentGenerationService::generate($orderId, 'QT', $adminUserId);

            self::assertNotNull(FileStoreRepository::find((int) $result['pdf_file_id']));
            $pageCount = $this->pdfPageCount((int) $result['pdf_file_id']);
            if ($pageCount !== null) {
                self::assertGreaterThan(1, $pageCount);
            }
        } finally {
            @unlink($realPath);
            WatermarkSettingsRepository::upsertGlobal(true, [
                'mode' => 'text', 'text_content' => 'DRAFT', 'font' => 'Helvetica', 'font_size' => 60,
                'color' => '#a8701f', 'opacity' => 0.15, 'angle' => 45,
                'image_asset_id' => null, 'image_opacity' => 0.05, 'image_position' => 'center',
            ], $adminUserId);
        }
    }
}
