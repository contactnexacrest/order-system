<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * QA matrix MTX-005 — owner decision 13 Nov 2026 (item 3, Option A, confirmed twice): the Word copies of the
 * Commercial Invoice and the Debit Note use exactly the PDF's wording. Before, the Word copy still said
 * "FREIGHT DEBIT NOTE", "Freight Debit Note No.", "PAID VIA FDN", "Against FDN No.", "+ FDN (if applicable)",
 * "paid separately via FDN", "Please quote FDN No. … in your wire transfer remarks", "immediately after payment",
 * "TBC — must match this invoice date", the old Bill of Lading line, and printed "+ — (freight)" when no freight
 * amount was recorded. The PDFs did not change. Node twin: tests/integration/docxDebitNoteWordingMtx005.test.js.
 */
final class DocxDebitNoteWordingMtx005Test extends DbTestCase
{
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

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>,3:string} FDN, CI (freight pending), CI (freight paid), FDN ref */
    private function generate(string $incoterm): array
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), $incoterm, 'Standard — New Buyer', 'USD');
        OrderProductRepository::add($orderId, 1, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        $fdn = DocumentGenerationService::generate($orderId, 'FDN', $userId, null, true, true);
        $ciPending = DocumentGenerationService::generate($orderId, 'CI', $userId, null, true, true);
        $pdo = Database::connection();
        $u = $pdo->prepare('UPDATE order_payment_status SET freight_cleared_at = NOW(), freight_amount = 1250 WHERE order_id = :id');
        $u->execute(['id' => $orderId]);
        if ($u->rowCount() === 0) {
            $pdo->prepare('INSERT INTO order_payment_status (order_id, freight_cleared_at, freight_amount) VALUES (:id, NOW(), 1250)')->execute(['id' => $orderId]);
        }
        $ciPaid = DocumentGenerationService::generate($orderId, 'CI', $userId, null, true, true);
        $ref = (string) $pdo->query("SELECT d.document_reference FROM documents d JOIN document_types t ON t.id = d.document_type_id WHERE t.code = 'FDN' AND d.order_id = {$orderId} ORDER BY d.id DESC LIMIT 1")->fetchColumn();
        self::assertNotSame('', $ref);
        return [$fdn, $ciPending, $ciPaid, $ref];
    }

    public static function incoterms(): array
    {
        return [['CFR'], ['CIF']];
    }

    #[DataProvider('incoterms')]
    public function testMtx005WordDebitNoteAndCommercialInvoiceUseThePdfWording(string $incoterm): void
    {
        [$fdn, $ciPending, $ciPaid, $fdnRef] = $this->generate($incoterm);
        $freightLabel = $incoterm === 'CIF' ? 'Freight & Insurance' : 'Freight';

        $fdnWord = $this->docxText((int) $fdn['docx_file_id']);
        $fdnPdf = $this->pdfText((int) $fdn['pdf_file_id']);
        foreach ([
            'NEXACREST INTERNATIONAL PRIVATE LIMITED DEBIT NOTE FREIGHT COST RECOVERY',
            'Payment of this Debit Note is required within 3 Calendar Days of the date above.',
            'Share bank remittance copy as per Section 1 contact details above within 24 hours of payment.',
            "Please quote Debit Note No. {$fdnRef} in your T/T remarks.",
        ] as $line) {
            self::assertStringContainsString($line, $fdnWord, "MTX-005: Word Debit Note ({$incoterm})");
        }
        foreach ([
            'Payment of this Debit Note is required within 3 Calendar Days of the date above.',
            'Share bank remittance copy as per Section 1 contact details above within 24 hours of payment.',
            "Please quote Debit Note No. {$fdnRef} in your T/T remarks.",
        ] as $line) {
            self::assertStringContainsString($line, $fdnPdf, "MTX-005: the PDF Debit Note says the same ({$incoterm})");
        }

        $ciWord = $this->docxText((int) $ciPaid['docx_file_id']);
        $ciPdf = $this->pdfText((int) $ciPaid['pdf_file_id']);
        foreach ([
            "Paid separately via Debit Note No. {$fdnRef} dated",
            "√ {$freightLabel} — PAID VIA DEBIT NOTE",
            "CFR/CIF: Against Debit Note No. {$fdnRef} dated",
            'USD 2,184.00 + 1,250.00 (freight)',
            'Advance + Debit Note (if applicable)',
            "{$freightLabel} is paid separately via Debit Note, not through this Advance/Balance schedule",
            'Bill of Lading — 3 originals issued by the shipping line | 2 originals couriered to Buyer within 3 Working Days of Balance Payment being confirmed as Payment Cleared | 1 original retained by NexaCrest',
            'Bill of Lading Date To Be Confirmed — must match this invoice date',
        ] as $line) {
            self::assertStringContainsString($line, $ciWord, "MTX-005: Word Commercial Invoice ({$incoterm})");
        }
        foreach (["Paid separately via Debit Note No. {$fdnRef}", 'PAID VIA DEBIT NOTE', "Against Debit Note No. {$fdnRef}", 'Advance + Debit Note (if applicable)', 'paid separately via Debit Note, not through'] as $line) {
            self::assertStringContainsString($line, $ciPdf, "MTX-005: the PDF Commercial Invoice says the same ({$incoterm})");
        }

        // Freight not yet paid / no freight amount recorded: no "+ — (freight)" (the PDF prints nothing there).
        $pendingWord = $this->docxText((int) $ciPending['docx_file_id']);
        self::assertStringContainsString("√ {$freightLabel} — PENDING", $pendingWord);
        self::assertStringNotContainsString('(freight)', $pendingWord, 'MTX-005: no "+ — (freight)" without a freight amount');

        foreach ([$fdnWord, $ciWord, $pendingWord] as $text) {
            self::assertDoesNotMatchRegularExpression('/\bFDN\b/', $text, 'MTX-005: the buyer-facing Word copy never prints the internal code "FDN"');
            self::assertStringNotContainsStringIgnoringCase('Freight Debit Note', $text);
            self::assertStringNotContainsString('TBC', $text);
        }
    }
}
