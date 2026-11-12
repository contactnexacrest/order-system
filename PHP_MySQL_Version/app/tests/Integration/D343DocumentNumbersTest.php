<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Services\CaFinancialSnapshotService;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Services\LineTable;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-343 (12 Nov 2026, owner "1 yes, 2 yes, 3 yes" and "confirm 2"):
 * (1) the money on an INR order's documents in Indian grouping (1,75,000.00), every other
 * currency and the CAFIN as before; (2) "Total CBM (m³)" of a detailed order's QT / PI worked
 * out from the lines; (3) the Word copy numbers its sections exactly as the PDF.
 * Node twin: tests/integration/d343DocumentNumbers.test.js (same cases, same wording).
 */
final class D343DocumentNumbersTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
    }

    private function order(string $layout, string $currency): int
    {
        $this->allowHandTypedGoodsLines();
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', $currency);
        Database::connection()->prepare('UPDATE orders SET line_table_layout = :l WHERE id = :id')->execute(['l' => $layout, 'id' => $orderId]);
        return $orderId;
    }

    /** The owner's example: CHM Black P6, 180 × 80 × 13 × 25, 140 × 70 × 13 × 25, 120 × 60 × 13 × 10 at 1,75,000 per CBM. */
    private function ownersExample(int $orderId): void
    {
        foreach ([[180, 80, 25], [140, 70, 25], [120, 60, 10]] as $i => [$l, $w, $pcs]) {
            $qty = LineTable::quantityFromPieces('CBM', $l, $w, 13, $pcs);
            $id = OrderProductRepository::add($orderId, $i + 1, 'CHM Black', "{$l} × {$w} × 13 cm", 'P6', $qty, false, 'CBM', '175000.00', '68022310');
            OrderProductRepository::setPieceSize($id, ['piece_length_cm' => (string) $l, 'piece_width_cm' => (string) $w, 'piece_thickness_cm' => '13']);
            OrderProductRepository::setPieces($id, $pcs, null);
        }
    }

    private static function flat(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }

    /** @return array{pdf: string, docx: string} the PDF text (reading order) and the Word text (one line per paragraph) */
    private function texts(int $orderId, string $code): array
    {
        $r = DocumentGenerationService::generate($orderId, $code, $this->admin, null, true, true);
        $pdf = (string) shell_exec('pdftotext -raw ' . escapeshellarg((string) FileStoreRepository::find((int) $r['pdf_file_id'])['server_path']) . ' -');
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) FileStoreRepository::find((int) $r['docx_file_id'])['server_path']) . ' word/document.xml');
        $docx = html_entity_decode((string) preg_replace('/<[^>]+>/', '', (string) preg_replace('#</w:p>#', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return ['pdf' => str_replace("\u{00A0}", ' ', $pdf), 'docx' => str_replace("\u{00A0}", ' ', $docx)];
    }

    /** The numbered section headings in reading order: "5. SHIPPING / COMMERCIAL TERMS", "8. BANK DETAILS (for balance T/T payment)". */
    public static function headings(string $text): array
    {
        preg_match_all('/(?:^|(?<=\s))(\d{1,2})\.[ \t]+((?=[A-Z(])(?:[A-Z0-9()&\/\x{2014}.,\'-]+(?:[ \t]+|(?=\n)|$))+)(\([^)\n]*\))?/mu', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            if (mb_strlen($x[2]) >= 5) {
                $out[] = $x[1] . '. ' . self::flat($x[2] . ($x[3] ?? ''));
            }
        }
        return $out;
    }

    public function testAnInrOrderPrintsIndianGroupingOnThePdfAndTheWordCopy(): void
    {
        $orderId = $this->order('detailed', 'INR');
        $this->ownersExample($orderId);
        foreach (['QT', 'PI', 'CI'] as $code) {
            foreach ($this->texts($orderId, $code) as $format => $text) {
                $flat = self::flat($text);
                foreach (['1,75,000.00', '8,19,000.00', '5,57,375.00', '1,63,800.00', '15,40,175.00'] as $amount) {
                    self::assertStringContainsString($amount, $flat, "{$code} {$format}");
                }
                foreach (['175,000.00', '819,000.00', '1,540,175.00'] as $international) {
                    self::assertStringNotContainsString($international, $flat, "{$code} {$format}");
                }
            }
        }
        // The advance (40%) and balance on the PI; the amount in words keeps its own wording.
        $pi = self::flat($this->texts($orderId, 'PI')['pdf']);
        self::assertStringContainsString('INR 6,16,070.00', $pi);
        self::assertStringContainsString('INR 9,24,105.00', $pi);
        $data = DocumentDataAssembler::assemble($orderId, 'CI');
        self::assertSame('15,40,175.00', $data['financial']['fob_value']);
        self::assertSame('Indian Rupees One Million Five Hundred Forty Thousand One Hundred Seventy-Five Only', $data['financial']['fob_value_in_words']);
    }

    public function testAUsdOrderAndTheCafinKeepTheirGrouping(): void
    {
        $usd = $this->order('detailed', 'USD');
        $this->ownersExample($usd);
        $qt = self::flat($this->texts($usd, 'QT')['docx']);
        self::assertStringContainsString('1 CHM Black P6 180 80 13 0.1872 25 4.680 175,000.00 32,760.00 819,000.00', $qt);
        self::assertStringContainsString('TOTAL 60 8.801 1,540,175.00', $qt);
        self::assertStringNotContainsString('15,40,175.00', $qt);

        // The CAFIN (internal) is not a client document: its figures are unchanged, INR order or not.
        $inr = $this->order('detailed', 'INR');
        $this->ownersExample($inr);
        $cafin = json_encode(CaFinancialSnapshotService::build($inr), JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('1,540,175.00', (string) $cafin);
        self::assertStringNotContainsString('15,40,175.00', (string) $cafin);
    }

    public function testTotalCbmInTheWeightAndVolumeBox(): void
    {
        $orderId = $this->order('detailed', 'INR');
        $this->ownersExample($orderId);
        // Owner, 12 Nov 2026: an old typed estimate (8.2) never wins over the lines (8.801).
        \App\Config\Database::connection()->prepare('UPDATE orders SET estimated_total_cbm = 8.2 WHERE id = :id')->execute(['id' => $orderId]);
        foreach (['QT', 'PI'] as $code) {
            self::assertSame('8.801', DocumentDataAssembler::assemble($orderId, $code)['order']['line_table_total_cbm'], $code);
        }
        $qt = $this->texts($orderId, 'QT');
        self::assertStringContainsString('Total CBM (m³) 8.801 m³', self::flat($qt['pdf']));
        self::assertStringContainsString('Total CBM (m³) 8.801 m³', self::flat($qt['docx']));

        // A goods line that cannot be turned into CBM (SQM without a thickness): "To Be Confirmed".
        $id = OrderProductRepository::add($orderId, 4, 'Kashmir White', '60 × 60 cm', 'Flamed', '18.00', false, 'SQM', '2500.00', '68022310');
        OrderProductRepository::setPieceSize($id, ['piece_length_cm' => '60', 'piece_width_cm' => '60', 'piece_thickness_cm' => null]);
        OrderProductRepository::setPieces($id, 50, null);
        self::assertNull(DocumentDataAssembler::assemble($orderId, 'QT')['order']['line_table_total_cbm']);
        $qt = $this->texts($orderId, 'QT');
        self::assertStringContainsString('Total CBM (m³) To Be Confirmed m³', self::flat($qt['pdf']));
        self::assertStringContainsString('Total CBM (m³) To Be Confirmed m³', self::flat($qt['docx']));

        // A classic order prints what it printed before.
        $classic = $this->order('classic', 'INR');
        $this->ownersExample($classic);
        self::assertNull(DocumentDataAssembler::assemble($classic, 'QT')['order']['line_table_total_cbm']);
        self::assertStringContainsString('Total CBM (m³) To Be Confirmed m³', self::flat($this->texts($classic, 'QT')['pdf']));
    }

    public function testTheWordCopyNumbersItsSectionsAsThePdf(): void
    {
        $expected = [
            'QT' => '1. SELLER / EXPORTER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. PRODUCT / ORDER DETAILS|5. SHIPPING / COMMERCIAL TERMS|6. PAYMENT TERMS|7. DOCUMENTS TO BE PROVIDED|8. TERMS & CONDITIONS|9. LEGAL TERMS & DEFINITIONS',
            'PI' => '1. SELLER / EXPORTER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. NOTIFY PARTY|5. PRODUCT / ORDER DETAILS|6. SHIPPING / COMMERCIAL TERMS|7. PAYMENT TERMS|8. BANK DETAILS|9. EXPORT DOCUMENTATION|10. TERMS & CONDITIONS|11. LEGAL TERMS & DEFINITIONS',
            'OC' => '1. SELLER / EXPORTER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. ORDER SUMMARY|5. PAYMENT STATUS|6. PRODUCTION & ESTIMATED SHIPMENT|7. DOCUMENTS TO BE PROVIDED|8. ORDER CONDITIONS|9. LEGAL TERMS & DEFINITIONS',
            'CI' => '1. EXPORTER / SELLER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. NOTIFY PARTY|5. SHIPPING DETAILS|6. PRODUCT / ORDER DETAILS|7. INVOICE VALUE & PAYMENT SETTLEMENT|8. BANK DETAILS (for balance T/T payment)|9. DOCUMENTS PROVIDED WITH THIS SHIPMENT|10. DECLARATION|11. LEGAL TERMS & DEFINITIONS',
            'PL' => '1. EXPORTER|2. BUYER DETAILS|3. CONSIGNEE DETAILS|4. NOTIFY PARTY|5. PRODUCT SUMMARY|6. CRATE-LEVEL BREAKDOWN (from factory packing data)|7. DECLARATION|8. LEGAL TERMS & DEFINITIONS',
        ];
        foreach (['detailed', 'classic'] as $layout) {
            $orderId = $this->order($layout, 'USD');
            $this->ownersExample($orderId);
            foreach ($expected as $code => $list) {
                $t = $this->texts($orderId, $code);
                self::assertSame(explode('|', $list), self::headings($t['pdf']), "{$code} PDF ({$layout})");
                self::assertSame(self::headings($t['pdf']), self::headings($t['docx']), "{$code} Word copy = PDF ({$layout})");
            }
        }
    }
}
