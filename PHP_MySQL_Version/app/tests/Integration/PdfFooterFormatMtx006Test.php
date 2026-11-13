<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * MTX-006 (owner decision 13 Nov 2026, item 4, confirmed twice): the Node PDF footer was changed to read exactly
 * as this PHP footer. The PHP footer itself does NOT change — this test pins it, so the two stacks stay the same:
 * one left-aligned line "{ref}  —  Page n of N", 8pt, colour rgb(0.35,0.37,0.45) (= #596075), 42.5pt from the
 * left edge (DocumentGenerationService::addRunningHeaderFooter). Node twin: tests/unit/pdfRenderServiceFooter.test.js.
 */
final class PdfFooterFormatMtx006Test extends DbTestCase
{
    public function testMtx006PhpFooterSourceIsTheAgreedFormat(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/DocumentGenerationService.php');

        self::assertStringContainsString(
            '$canvas->text(42.5, $pageHeight - 24, "{$documentReference}  —  Page {$pageNumber} of {$pageCount}", $footerFont, 8, [0.35, 0.37, 0.45]);',
            $src,
            'MTX-006: the PHP footer is the reference both stacks print — change it only together with Node pdfRenderService.js'
        );
    }

    public function testMtx006EveryPageCarriesOneLeftAlignedFooterLine(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'USD');
        OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        $r = DocumentGenerationService::generate($orderId, 'PI', $this->createTestUser('Admin'), null, false, true);
        $path = (string) FileStoreRepository::find((int) $r['pdf_file_id'])['server_path'];
        $ref = (string) \App\Config\Database::connection()->query('SELECT document_reference FROM documents WHERE id = ' . (int) $r['document_id'])->fetchColumn();
        self::assertNotSame('', $ref);

        preg_match('/Pages:\s*(\d+)/', (string) shell_exec('pdfinfo ' . escapeshellarg($path)), $m);
        $pages = (int) ($m[1] ?? 0);
        self::assertGreaterThan(0, $pages);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' -');
        for ($p = 1; $p <= $pages; $p++) {
            self::assertMatchesRegularExpression('/^' . preg_quote($ref, '/') . ' — Page ' . $p . ' of ' . $pages . '$/m', $text, "MTX-006: footer of page {$p}");
        }

        // Left edge 42.5pt, 8pt type: the reference word starts at x = 42.5 on page 1.
        $bbox = (string) shell_exec('pdftotext -f 1 -l 1 -bbox ' . escapeshellarg($path) . ' -');
        self::assertMatchesRegularExpression('/<word xMin="42\.5\d*" yMin="[\d.]+" xMax="[\d.]+" yMax="[\d.]+">' . preg_quote($ref, '/') . '<\/word>/', $bbox);
    }
}
