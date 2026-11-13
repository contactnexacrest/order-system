<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Owner decision 13 Nov 2026 (QA item 11, confirm 1 + confirm 2): the Word copies of the Quotation, Proforma
 * Invoice and Order Confirmation use the PDF's wording — "Debit Note", never "Freight Debit Note" or "FDN".
 * Before, the Word QT/PI CIF insurance row said "Confirmed by Freight Debit Note when cargo is ready." and the
 * Word OC freight row said "A Freight Debit Note will be raised …". The PDFs did not change.
 * Follows MTX-005 (item 3: Word CI and Debit Note). Node twin: tests/integration/docxDebitNoteWordingItem11.test.js.
 */
final class DocxDebitNoteWordingItem11Test extends DbTestCase
{
    private const OC_FREIGHT = 'A Debit Note will be raised when cargo is packed and ready, stating the actual confirmed freight amount. Payment required within 3 Calendar Days of the Debit Note date. NexaCrest will confirm shipment booking and hand over cargo to the shipping line only upon receipt of full freight payment.';

    private static function flat(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function docxText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) $file['server_path']) . ' word/document.xml');
        $xml = (string) preg_replace('#<w:br/>|</w:p>|</w:tc>#', ' ', $xml);
        return self::flat(html_entity_decode((string) preg_replace('/<[^>]+>/', '', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return self::flat((string) shell_exec('pdftotext ' . escapeshellarg((string) $file['server_path']) . ' -'));
    }

    public static function incoterms(): array
    {
        return [['CFR'], ['CIF']];
    }

    #[DataProvider('incoterms')]
    public function testItem11WordQuotationPiAndOcSayDebitNoteLikeThePdf(string $incoterm): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), $incoterm, 'Standard — New Buyer', 'USD');
        OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');

        $evidenceDir = (string) getenv('ITEM11_EVIDENCE_DIR');
        $texts = [];
        foreach (['QT', 'PI', 'OC'] as $code) {
            $doc = DocumentGenerationService::generate($orderId, $code, $userId, null, true, true);
            $texts[$code] = [$this->docxText((int) $doc['docx_file_id']), $this->pdfText((int) $doc['pdf_file_id'])];
            if ($evidenceDir !== '') {
                // Evidence only (before/after diff): dates, document numbers and the random buyer ref are masked.
                $mask = static fn (string $t): string => (string) preg_replace(['/\b\d{4}-\d{2}-\d{2}\b/', '/\b\d{1,2} [A-Z][a-z]+ \d{4}\b/', '/SC\/[A-Z]+\/[0-9A-Za-z\/\-]+/', '/PHPUNIT-[0-9a-f]+/', '/lient-[0-9a-f]{8}@/'], ['<DATE>', '<DATE>', '<REF>', 'PHPUNIT-<ID>', 'lient-<ID>@'], $t);
                file_put_contents("{$evidenceDir}/php_{$incoterm}_{$code}_word.txt", $mask($texts[$code][0]) . "\n");
                file_put_contents("{$evidenceDir}/php_{$incoterm}_{$code}_pdf.txt", $mask($texts[$code][1]) . "\n");
            }
        }
        foreach ($texts as $code => [$word, $pdf]) {

            if ($code === 'OC') {
                self::assertStringContainsString(self::OC_FREIGHT, $word, "Item 11: Word OC freight row ({$incoterm})");
                self::assertStringContainsString('A Debit Note will be raised when cargo is packed and ready', $pdf, "Item 11: the PDF OC says the same ({$incoterm})");
            } elseif ($incoterm === 'CIF') {
                self::assertStringContainsString('Confirmed by Debit Note when cargo is ready.', $word, "Item 11: Word {$code} CIF insurance row");
                self::assertStringContainsString('Confirmed by Debit Note when cargo is ready.', $pdf, "Item 11: the PDF {$code} says the same");
            } else {
                self::assertStringContainsString('NIL — buyer arranges insurance (CFR).', $word, "Item 11: Word {$code} CFR insurance row unchanged");
            }

            self::assertDoesNotMatchRegularExpression('/\bFDN\b/', $word, "Item 11: the buyer-facing Word {$code} never prints the internal code \"FDN\"");
            self::assertStringNotContainsStringIgnoringCase('Freight Debit Note', $word, "Item 11: Word {$code} ({$incoterm}) says \"Debit Note\" like the PDF");
            self::assertStringNotContainsStringIgnoringCase('Freight Debit Note', $pdf);
        }
    }
}
