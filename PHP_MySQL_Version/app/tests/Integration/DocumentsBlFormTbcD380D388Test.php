<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Services\ProductsAmendmentService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions of 18 Nov 2026 (document changes, each confirmed twice), PDF and Word:
 *  - D-380: the Bill of Lading wording — QT / PI / CI section "Documents" item 4 (the Word QT / PI now
 *    say the same as the PDF), the BL Instruction (FULL SET), T&C clause 95 and the reference library;
 *  - D-386: "Product Form: <form>" in the grey line of a line with a Sold as — QT, PI, OC, CI only,
 *    immediately before "HS Code:" (detailed table) or first (simple table); lines without a Sold as
 *    and every other document print as before;
 *  - D-387: Supplier PO Section 3 "Product Form" (prefilled from the order lines' form, printed only
 *    when filled) and the Products Amendment's second line "Product Form: <form>";
 *  - D-388: a quantity "To Be Confirmed" — the line total never 0.00; TOTAL, FOB Value, TOTAL QUOTED
 *    VALUE and the amount in words: "To Be Confirmed" / "<known> + line 2 To Be Confirmed" /
 *    "<known> + lines 2, 4 To Be Confirmed".
 * Node twin: tests/integration/documentsBlFormTbcD380D388.test.js.
 */
final class DocumentsBlFormTbcD380D388Test extends DbTestCase
{
    private const BL_LINE = 'Bill of Lading — full set of original Bills of Lading as issued by the shipping line, couriered to the Buyer within 3 Working Days of the Balance Payment being confirmed as Payment Cleared (or Telex Release / surrendered BL on request)';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
    }

    private static function flat(string $text): string
    {
        // pdftotext prints &nbsp; as U+00A0: treat it as a space too.
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return self::flat((string) shell_exec('pdftotext ' . escapeshellarg((string) $file['server_path']) . ' -'));
    }

    private function docxText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) $file['server_path']) . ' word/document.xml');
        return self::flat(html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** @return array{pdf: string, docx: string} */
    private function generate(int $orderId, string $code): array
    {
        $r = DocumentGenerationService::generate($orderId, $code, $this->admin, null, true, true);
        return ['pdf' => $this->pdfText((int) $r['pdf_file_id']), 'docx' => !empty($r['docx_file_id']) ? $this->docxText((int) $r['docx_file_id']) : ''];
    }

    private function order(string $layout): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        Database::connection()->prepare('UPDATE orders SET line_table_layout = :l WHERE id = :id')->execute(['l' => $layout, 'id' => $orderId]);
        return $orderId;
    }

    private static function soldAs(int $lineId, string $specName): void
    {
        $pdo = Database::connection();
        $st = $pdo->prepare('SELECT id, hs_code FROM hs_specifications WHERE name = :n');
        $st->execute(['n' => $specName]);
        $spec = $st->fetch();
        self::assertNotFalse($spec, "seeded specification {$specName}");
        $pdo->prepare('UPDATE order_products SET hs_spec_id = :s, hs_code = :h WHERE id = :id')->execute(['s' => (int) $spec['id'], 'h' => $spec['hs_code'], 'id' => $lineId]);
    }

    // ------------------------------------------------------------------ D-380

    public function testD380QtPiCiPrintTheFullSetWordingInPdfAndWord(): void
    {
        $orderId = $this->order('classic');
        OrderProductRepository::add($orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '1250.00');
        foreach (['QT', 'PI', 'CI'] as $code) {
            foreach ($this->generate($orderId, $code) as $format => $text) {
                self::assertStringContainsString('4. ' . self::BL_LINE, $text, "{$code} {$format}");
                foreach (['3 originals', 'Original Negotiable, 3 originals', '1 original retained by NexaCrest'] as $gone) {
                    self::assertStringNotContainsString($gone, $text, "{$code} {$format}: '{$gone}' gone");
                }
            }
        }
    }

    public function testD380BlInstructionAsksForTheFullSet(): void
    {
        $orderId = $this->order('classic');
        OrderProductRepository::add($orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '1250.00');
        foreach ($this->generate($orderId, 'BLI') as $format => $text) {
            self::assertStringContainsString('ORIGINAL NEGOTIABLE BILL OF LADING — FULL SET', $text, "BLI {$format}");
            self::assertStringContainsString('No. of Original Copies Full set as issued by the shipping line (the number is stated on the BL)', $text, "BLI {$format}");
            self::assertStringContainsString('No. of Non-Negotiable Copies 3', $text, "BLI {$format}: unchanged");
            self::assertStringContainsString('MANDATORY INSTRUCTION — DO NOT ISSUE SEA WAYBILL OR EXPRESS BL', $text, "BLI {$format}: unchanged");
            self::assertStringContainsString('CHA to hand the full set of originals to NexaCrest only — never to the buyer or anyone else.', $text, "BLI {$format}");
            self::assertStringNotContainsString('3 ORIGINALS', $text, "BLI {$format}");
            self::assertStringNotContainsString('three originals', $text, "BLI {$format}");
        }
    }

    public function testD380Clause95AndTheReferenceLibraryAsSeeded(): void
    {
        $pdo = Database::connection();
        $clause = (string) $pdo->query("SELECT clause_text FROM tc_clauses WHERE clause_title = 'Bill of Lading Release'")->fetchColumn();
        self::assertStringStartsWith('Bill of Lading: The full set of original negotiable Bills of Lading, as issued by the shipping line, will be released to the buyer only after {balance_pct}% balance payment is CLEARED', $clause);
        self::assertStringContainsString('Remittance copy alone does not constitute payment receipt.', $clause, 'the rest of the clause is unchanged');
        $library = implode("\n", $pdo->query('SELECT content FROM internal_reference_docs')->fetchAll(\PDO::FETCH_COLUMN));
        foreach (['3 Originals ONLY', '3 originals ONLY', 'all 3 originals', 'HS Code: 6802.93', 'HS CODE: 6802.93', 'PI and CI: 6802.93', '60% balance is cleared', 'until 60% balance'] as $gone) {
            self::assertStringNotContainsString($gone, $library, "reference library: '{$gone}' gone");
        }
        foreach ([
            'BL TYPE: Original Negotiable Bill of Lading — full set of originals. Telex Release / surrendered BL only on the buyer\'s request and with MD approval, after the balance is cleared.',
            'NexaCrest retains the full set of originals until the balance T/T is cleared',
            'NEVER request Sea Waybill or Express BL',
            'HS Code: 68029300 for all granite/monument products',
            'HS Code on PL matches PI and CI: 68029300',
            'HS CODE: 68029300 for all worked monumental granite products',
        ] as $expected) {
            self::assertStringContainsString($expected, $library);
        }
    }

    // ------------------------------------------------------------------ D-386

    public function testD386ProductFormOnTheDetailedTableOfQtPiOcCiOnly(): void
    {
        $orderId = $this->order('detailed');
        $block = OrderProductRepository::add($orderId, 1, 'CHM Black block', null, 'Roughly trimmed', '25.000', false, 'CBM', '600.00', '25161100');
        OrderProductRepository::add($orderId, 2, 'CHM Black slab', null, 'Polished', '100.00', false, 'SQM', '40.00', '68022310');
        self::soldAs($block, 'Raw block');

        foreach (['QT', 'PI', 'OC', 'CI'] as $code) {
            $rows = DocumentDataAssembler::assemble($orderId, $code)['line_table']['rows'];
            self::assertSame(['label' => 'Product Form:', 'text' => 'Raw block'], $rows[0]['detail'][0], $code);
            self::assertSame('HS Code:', $rows[0]['detail'][1]['label'], "{$code}: immediately before the HS Code");
            self::assertNotContains('Product Form:', array_column($rows[1]['detail'], 'label'), "{$code}: a line without a Sold as is unchanged");
            foreach ($this->generate($orderId, $code) as $format => $text) {
                self::assertStringContainsString('Product Form: Raw block · HS Code: 25161100', $text, "{$code} {$format}");
                self::assertSame(1, substr_count($text, 'Product Form:'), "{$code} {$format}: only the line with a Sold as");
            }
        }
        foreach (['PL', 'BUYERPO'] as $code) {
            foreach (DocumentDataAssembler::assemble($orderId, $code)['line_table']['rows'] as $r) {
                self::assertNotContains('Product Form:', array_column($r['detail'], 'label'), "{$code}: unchanged");
            }
        }
        foreach (['SUPPO', 'BLI', 'COOPREP', 'PL', 'BUYERPO'] as $code) {
            self::assertSame([], array_values(array_filter(array_column(DocumentDataAssembler::assemble($orderId, $code)['products'], 'product_form'))), "{$code}: no Product Form");
        }
    }

    public function testD386ProductFormFirstInTheSimpleTablesGreyLine(): void
    {
        $orderId = $this->order('classic');
        $line = OrderProductRepository::add($orderId, 1, 'Black Galaxy Monument', '180 x 80 x 13 cm', 'Polished', '20', false, 'PCS', '300.00', '68029300');
        OrderProductRepository::add($orderId, 2, 'Kashmir White Tiles', '60 x 60 x 2 cm', 'Flamed', '80', false, 'SQM', '32.25');
        self::soldAs($line, 'Monument slab');
        foreach (['QT', 'PI', 'CI'] as $code) {
            foreach ($this->generate($orderId, $code) as $format => $text) {
                self::assertStringContainsString('Product Form: Monument slab · Dimensions: 180 x 80 x 13 cm', $text, "{$code} {$format}");
                self::assertSame(1, substr_count($text, 'Product Form:'), "{$code} {$format}");
            }
        }
    }

    // ------------------------------------------------------------------ D-387

    public function testD387SupplierPoProductFormRowPrefilledAndOnlyWhenFilled(): void
    {
        $orderId = $this->order('classic');
        $line = OrderProductRepository::add($orderId, 1, 'CHM Black block', null, 'Roughly trimmed', '25.000', false, 'CBM', '600.00', '25161100');
        self::soldAs($line, 'Raw block');
        self::assertSame('Raw block', OrderSupplierPoRepository::defaultProductForm($orderId));
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'D387 Quarry ' . bin2hex(random_bytes(3)), 'types' => []], $this->admin);
        // Without the field: the order lines' form.
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SC/SPO/2026/D387' . random_int(100, 999), ['material_stone_type' => 'CHM Black', 'quantity' => '25', 'unit_price_inr' => '45000', 'basic_value_inr' => '1125000', 'total_payable_inr' => '1125000', 'balance_amount_inr' => '1125000', 'required_delivery_date' => '']);
        foreach ($this->generate($orderId, 'SUPPO') as $format => $text) {
            self::assertStringContainsString('CHM Black Product Form Raw block Grade', $text, "SUPPO {$format}: right after the material row");
        }
        // Emptied on the form: no row.
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SC/SPO/2026/D387' . random_int(1000, 9999), ['material_stone_type' => 'CHM Black', 'product_form' => '  ', 'quantity' => '25', 'unit_price_inr' => '45000', 'basic_value_inr' => '1125000', 'total_payable_inr' => '1125000', 'balance_amount_inr' => '1125000', 'required_delivery_date' => '']);
        foreach ($this->generate($orderId, 'SUPPO') as $format => $text) {
            self::assertStringNotContainsString('Product Form', $text, "SUPPO {$format}: printed only when filled");
        }
        // Typed on the form: as typed.
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SC/SPO/2026/D387' . random_int(10000, 99999), ['material_stone_type' => 'CHM Black', 'product_form' => 'Sawn block', 'quantity' => '25', 'unit_price_inr' => '45000', 'basic_value_inr' => '1125000', 'total_payable_inr' => '1125000', 'balance_amount_inr' => '1125000', 'required_delivery_date' => '']);
        self::assertSame('Sawn block', DocumentDataAssembler::assemble($orderId, 'SUPPO')['supplier_po']['product_form']);
    }

    public function testD387ProductsAmendmentSecondLine(): void
    {
        $orderId = $this->order('classic');
        $withForm = OrderProductRepository::add($orderId, 1, 'Black Galaxy Monument', null, 'Polished', '20', false, 'PCS', '300.00', '68029300');
        $plain = OrderProductRepository::add($orderId, 2, 'Kashmir White Tiles', null, 'Flamed', '80', false, 'SQM', '32.25');
        self::soldAs($withForm, 'Monument slab');
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO amendments (amendment_reference, order_id, reason, requested_by, original_terms_snapshot, amendment_type, products_totals_json)
            VALUES (:r, :o, 'Buyer asked for more headstones', 'importer', '{}', 'products', :t)")
            ->execute(['r' => 'SC/AMD/2026/D387' . random_int(1000, 9999), 'o' => $orderId, 't' => json_encode(['currency' => 'USD', 'old_total' => 8580, 'new_total' => 11580])]);
        $amendmentId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO amendment_product_lines (amendment_id, line_no, order_product_id, change_kind, description, unit, old_quantity, new_quantity, old_unit_price, new_unit_price, old_line_total, new_line_total)
            VALUES (:a, :n, :op, 'change', :d, :u, :oq, :nq, :p, :p2, :ot, :nt)");
        $ins->execute(['a' => $amendmentId, 'n' => 1, 'op' => $withForm, 'd' => 'Black Galaxy Monument', 'u' => 'PCS', 'oq' => '20', 'nq' => '30', 'p' => '300.00', 'p2' => '300.00', 'ot' => '6000.00', 'nt' => '9000.00']);
        $ins->execute(['a' => $amendmentId, 'n' => 2, 'op' => $plain, 'd' => 'Kashmir White Tiles', 'u' => 'SQM', 'oq' => '80', 'nq' => '80', 'p' => '32.25', 'p2' => '32.25', 'ot' => '2580.00', 'nt' => '2580.00']);
        $st = $pdo->prepare('SELECT * FROM amendments WHERE id = :id');
        $st->execute(['id' => $amendmentId]);
        $rows = ProductsAmendmentService::documentBlock($st->fetch())['rows'];
        self::assertSame(['Monument slab', null], array_column($rows, 'product_form'));
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/AMD/products_amendment.html.twig');
        self::assertStringContainsString('{{ r.product }}{% if r.product_form %}<br><span class="amd-form">Product Form: {{ r.product_form }}</span>{% endif %}', $template);
    }

    // ------------------------------------------------------------------ D-388

    public function testD388EveryLineToBeConfirmed(): void
    {
        $orderId = $this->order('classic');
        OrderProductRepository::add($orderId, 1, 'Granite Memorial Headstone', null, null, null, true, 'PCS', '1250.00');
        $f = DocumentDataAssembler::assemble($orderId, 'QT')['financial'];
        self::assertSame(['To Be Confirmed', 'To Be Confirmed'], [$f['fob_value'], $f['total_value']]);
        self::assertSame('To Be Confirmed', DocumentDataAssembler::assemble($orderId, 'CI')['financial']['total_invoice_value_in_words']);
        self::assertSame('To Be Confirmed', DocumentDataAssembler::assemble($orderId, 'QT')['products'][0]['amount'], 'the line total is never 0.00');
        foreach ($this->generate($orderId, 'QT') as $format => $text) {
            // PDF text extraction can move the FOB Value cell away from its label; the TOTAL row stays together.
            if ($format !== 'pdf') {
                self::assertStringContainsString('FOB Value (USD) To Be Confirmed', $text, "QT {$format}");
            }
            self::assertStringContainsString('TOTAL QUOTED VALUE (USD) To Be Confirmed', $text, "QT {$format}");
        }
    }

    public function testD388SomeLinesToBeConfirmed(): void
    {
        $orderId = $this->order('classic');
        OrderProductRepository::add($orderId, 1, 'Granite Memorial Headstone', null, null, '10', false, 'PCS', '100.00');
        OrderProductRepository::add($orderId, 2, 'Granite Kerb', null, null, null, true, 'PCS', '50.00');
        $f = DocumentDataAssembler::assemble($orderId, 'QT')['financial'];
        self::assertSame('1,000.00 + line 2 To Be Confirmed', $f['fob_value']);
        self::assertSame('1,000.00 + line 2 To Be Confirmed', $f['total_value']);
        self::assertStringEndsWith(' + line 2 To Be Confirmed', DocumentDataAssembler::assemble($orderId, 'CI')['financial']['total_invoice_value_in_words']);
        foreach ($this->generate($orderId, 'QT') as $format => $text) {
            self::assertStringContainsString('TOTAL QUOTED VALUE (USD) 1,000.00 + line 2 To Be Confirmed', $text, "QT {$format}");
        }
        OrderProductRepository::add($orderId, 3, 'Granite Step', null, null, '5', false, 'PCS', '200.00');
        OrderProductRepository::add($orderId, 4, 'Granite Cobble', null, null, null, true, 'PCS', '5.00');
        foreach (['QT', 'PI', 'OC', 'CI'] as $code) {
            $d = DocumentDataAssembler::assemble($orderId, $code);
            self::assertSame('2,000.00 + lines 2, 4 To Be Confirmed', $d['financial']['fob_value'], $code);
            self::assertSame('2,000.00 + lines 2, 4 To Be Confirmed', $d['financial']['total_value'], $code);
            self::assertSame('To Be Confirmed', $d['products'][1]['amount'], "{$code}: the line total of a TBC line");
        }
        foreach ($this->generate($orderId, 'PI') as $format => $text) {
            self::assertStringContainsString('2,000.00 + lines 2, 4 To Be Confirmed', $text, "PI {$format}");
        }
    }

    public function testD388TheDetailedTablesTotalRow(): void
    {
        $orderId = $this->order('detailed');
        OrderProductRepository::add($orderId, 1, 'CHM Black block', null, 'Roughly trimmed', '5.000', false, 'CBM', '600.00', '25161100');
        OrderProductRepository::add($orderId, 2, 'CHM Black block', null, 'Roughly trimmed', null, true, 'CBM', '600.00', '25161100');
        self::assertSame('3,000.00 + line 2 To Be Confirmed', DocumentDataAssembler::assemble($orderId, 'QT')['line_table']['total']['amount']);
        foreach ($this->generate($orderId, 'QT') as $format => $text) {
            self::assertStringContainsString('3,000.00 + line 2 To Be Confirmed', $text, "QT {$format}");
        }
    }
}
