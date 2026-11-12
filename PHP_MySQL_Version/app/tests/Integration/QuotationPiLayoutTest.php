<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (1 Nov 2026, confirmed twice): the Quotation and the
 * Proforma Invoice no longer print a heading-less table of Certificate of
 * Origin Type / Port of Discharge / Buyer's PO between the party sections
 * and Product / Order Details (it is not in the owner's reference
 * templates and repeated values printed elsewhere). Nothing is lost:
 * - Port of Discharge stays in Shipping / Commercial Terms;
 * - the Certificate of Origin line (with the selected type) stays in
 *   Documents to be provided (QT) / Export Documentation (PI);
 * - the PI's Buyer's PO / Ref No. moves into Buyer Details, printed only
 *   when one has been entered.
 * Same in the PDF and the Word version. Node twin: quotationPiLayout.test.js.
 */
final class QuotationPiLayoutTest extends DbTestCase
{
    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return (string) shell_exec('pdftotext -layout ' . escapeshellarg((string) $file['server_path']) . ' -');
    }

    private function docxText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) $file['server_path']) . ' word/document.xml');
        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', $xml));
    }

    private function order(?string $buyersPo, string $coo = 'Non-Preferential'): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare(
            'UPDATE orders SET port_of_discharge_id = NULL, port_of_discharge_text = :pod, coo_type = :coo, buyers_po_ref = :po WHERE id = :id'
        )->execute(['pod' => 'Poti, Georgia', 'coo' => $coo, 'po' => $buyersPo, 'id' => $orderId]);
        return $orderId;
    }

    /** @return array{pdf:string,docx:string} */
    private function generate(int $orderId, string $code): array
    {
        $r = DocumentGenerationService::generate($orderId, $code, $this->createTestUser('Admin'), null, true, true);
        return ['pdf' => $this->pdfText((int) $r['pdf_file_id']), 'docx' => $this->docxText((int) $r['docx_file_id'])];
    }

    public function testQuotationHasNoUntitledTableAndKeepsBothValuesInTheirSections(): void
    {
        $doc = $this->generate($this->order('PO-2026-0457'), 'QT');

        foreach ($doc as $format => $text) {
            self::assertStringNotContainsString('Certificate of Origin Type', $text, "QT {$format}: no untitled CoO row");
            self::assertSame(1, substr_count($text, 'Port of Discharge'), "QT {$format}: Port of Discharge printed once, in Shipping / Commercial Terms");
            self::assertStringContainsString('Poti, Georgia', $text);
            self::assertMatchesRegularExpression('/Certificate of Origin — Non-Preferential — Issued by CAPEXIL/u', $text, "QT {$format}: CoO line keeps the selected type");
        }
        // PDF: Consignee Details runs straight into Product / Order Details.
        self::assertMatchesRegularExpression('/CONSIGNEE DETAILS(?:(?!Certificate of Origin|Port of Discharge).)*PRODUCT \/ ORDER DETAILS/su', $doc['pdf']);
        // Port of Discharge sits after the Shipping / Commercial Terms heading.
        self::assertGreaterThan(strpos($doc['pdf'], 'SHIPPING / COMMERCIAL TERMS'), strpos($doc['pdf'], 'Port of Discharge'));
    }

    public function testQuotationCooLineFollowsTheSelectedType(): void
    {
        $doc = $this->generate($this->order(null, 'GSP Form A (Preferential)'), 'QT');
        foreach ($doc as $format => $text) {
            self::assertStringContainsString('Certificate of Origin — GSP Form A (Preferential) — Issued by CAPEXIL', $text, "QT {$format}");
        }
    }

    public function testProformaShowsBuyersPoInBuyerDetailsOnlyWhenEntered(): void
    {
        $doc = $this->generate($this->order('PO-2026-0457'), 'PI');
        foreach ($doc as $format => $text) {
            self::assertStringNotContainsString('Certificate of Origin Type', $text, "PI {$format}: no untitled CoO row");
            self::assertSame(1, substr_count($text, "Buyer's PO / Ref No."), "PI {$format}: Buyer's PO printed once");
            self::assertStringContainsString('PO-2026-0457', $text);
            self::assertStringContainsString('Certificate of Origin — Non-Preferential — Issued by CAPEXIL', $text, "PI {$format}");
            self::assertStringContainsString('Poti, Georgia', $text);
        }
        // PDF: the PO row is inside Buyer Details (before Consignee Details).
        $pdf = $doc['pdf'];
        self::assertLessThan(strpos($pdf, 'CONSIGNEE DETAILS'), strpos($pdf, "Buyer's PO / Ref No."));
        self::assertGreaterThan(strpos($pdf, 'BUYER DETAILS'), strpos($pdf, "Buyer's PO / Ref No."));

        foreach ([null, '', 'NIL'] as $empty) {
            $blank = $this->generate($this->order($empty), 'PI');
            foreach ($blank as $format => $text) {
                self::assertStringNotContainsString("Buyer's PO / Ref No.", $text, "PI {$format}: no PO row when none entered (" . var_export($empty, true) . ')');
            }
        }
    }
}
