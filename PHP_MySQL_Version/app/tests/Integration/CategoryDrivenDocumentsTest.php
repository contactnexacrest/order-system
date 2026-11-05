<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\TermsClauseRepository;
use App\Services\CategoryDocumentProfile;
use App\Services\DocumentGenerationService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-257 Phase 1 (5 Nov 2026): documents follow the product
 * category. A test-only "Engineering" category (per piece, its own HS code,
 * wooden boxes, MTC 3.1 + drawing approval, the engineering clause set)
 * prints its own attributes and HS code, its packing words, the quality
 * documents it needs and its own clauses — and none of the stone ones. A
 * mixed order prints both sets. The stone side is StoneDocumentsUnchangedTest.
 * Node twin: categoryDrivenDocuments.test.js.
 */
final class CategoryDrivenDocumentsTest extends DbTestCase
{
    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        return (string) preg_replace('/\s+/u', ' ', (string) shell_exec('pdftotext ' . escapeshellarg((string) $file['server_path']) . ' -'));
    }

    private function docxText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        self::assertNotNull($file);
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) $file['server_path']) . ' word/document.xml');
        return html_entity_decode((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/<[^>]+>/', ' ', $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function engineeringCategory(): int
    {
        $id = ProductCategoryRepository::create('Engineering Products ' . bin2hex(random_bytes(3)), ['Material', 'Size'], $this->createTestUser('Admin'));
        ProductCategoryRepository::updateSettings($id, [
            'default_unit' => 'PCS', 'allowed_units' => 'PCS,SET,KG', 'default_hs_code' => '848180',
            'packing_type' => 'Wooden Boxes', 'quality_documents' => "Mill Test Certificate 3.1\nDrawing Approval",
            'tc_clause_set' => 'engineering',
        ]);
        return $id;
    }

    private function order(bool $withStone = false): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $lineId = OrderProductRepository::add($orderId, 1, 'Ball Valve', null, null, '200', false, 'PCS', '42.00', '848180');
        ProductCategoryRepository::setLineCategory($lineId, $this->engineeringCategory(), ['Material' => 'SS316', 'Size' => 'DN50']);
        if ($withStone) {
            OrderProductRepository::add($orderId, 2, 'Black Galaxy Granite Slabs', '300 x 60 x 2 cm', 'Polished', '120', false, 'SQM', '45.50');
        }
        Database::connection()->prepare('UPDATE orders SET estimated_package_count = 8 WHERE id = :id')->execute(['id' => $orderId]);
        return $orderId;
    }

    /** @return array<string,string> format => text */
    private function generate(int $orderId, string $code): array
    {
        $r = DocumentGenerationService::generate($orderId, $code, $this->createTestUser('Admin'), null, true, true);
        return ['pdf' => $this->pdfText((int) $r['pdf_file_id']), 'docx' => $this->docxText((int) $r['docx_file_id'])];
    }

    public function testTheProfileOfAnEngineeringOrder(): void
    {
        $p = CategoryDocumentProfile::forOrder($this->order());
        self::assertFalse($p['stone_only']);
        self::assertSame(['engineering'], $p['clause_sets']);
        self::assertSame('Wooden Boxes', $p['packing_label']);
        self::assertSame(['Mill Test Certificate 3.1', 'Drawing Approval'], array_column($p['quality_documents'], 'name'));
        self::assertSame(CategoryDocumentProfile::stone(), CategoryDocumentProfile::fromCategories([]));
    }

    public function testNaturalStoneSettingsNeverChangeItsDocuments(): void
    {
        $stone = ['id' => 1, 'name' => 'Natural Stone', 'is_system' => 1, 'tc_clause_set' => 'engineering',
                  'packing_type' => 'Steel Pallets', 'quality_documents' => 'Inspection report'];
        self::assertSame(CategoryDocumentProfile::stone(), CategoryDocumentProfile::fromCategories([$stone]));
    }

    public function testAMixedOrderUsesBothClauseSetsAndNeutralPackingWords(): void
    {
        $p = CategoryDocumentProfile::forOrder($this->order(true));
        self::assertSame(['engineering', 'natural_stone'], $p['clause_sets']);
        self::assertSame('Packages', $p['packing_label']);
        self::assertTrue(TermsClauseRepository::inClauseSets(null, ['engineering']));
        self::assertTrue(TermsClauseRepository::inClauseSets('general_goods,engineering', ['engineering']));
        self::assertFalse(TermsClauseRepository::inClauseSets('natural_stone', ['engineering']));
    }

    public function testQuotationPiAndOcPrintTheCategorysOwnLinesQualityDocumentsAndClauses(): void
    {
        $orderId = $this->order();
        foreach (['QT', 'PI', 'OC'] as $code) {
            foreach ($this->generate($orderId, $code) as $format => $text) {
                $where = "{$code} {$format}";
                self::assertStringContainsString('Mill Test Certificate 3.1 — required for Engineering Products', $text, $where);
                self::assertStringContainsString('Drawing Approval — required for Engineering Products', $text, $where);
                self::assertStringContainsString('Specification & Drawings: Goods are made to the buyer-approved drawing', $text, $where);
                self::assertStringContainsString('may occur due to production yield', $text, $where);
                self::assertStringContainsString('Quality Documents: The quality documents listed in this document', $text, $where);
                foreach (['minor variation in shade, grain and veining', 'natural stone production characteristics', 'Product Characteristics:'] as $gone) {
                    self::assertStringNotContainsString($gone, $text, "{$where}: {$gone}");
                }
                if ($code !== 'OC') {
                    self::assertStringContainsString('SS316', $text, $where);
                    self::assertStringContainsString('848180', $text, $where);
                    self::assertStringContainsString('Net Weight (goods only)', $text, $where);
                    self::assertStringNotContainsString('stone only', $text, $where);
                    self::assertStringNotContainsString('Packages / Crates', $text, $where);
                }
            }
        }
        foreach ($this->generate($orderId, 'QT') as $format => $text) {
            self::assertStringContainsString('6. Mill Test Certificate 3.1', (string) preg_replace('/\.\s+/u', '. ', $text), "QT {$format}: numbered after item 5");
        }
    }

    public function testCommercialInvoiceAndPackingListUsePackageWords(): void
    {
        $orderId = $this->order();
        foreach ($this->generate($orderId, 'CI') as $format => $text) {
            self::assertStringContainsString('Mill Test Certificate 3.1 — required for Engineering Products', $text, "CI {$format}");
        }
        foreach ($this->generate($orderId, 'PL') as $format => $text) {
            self::assertStringContainsString('Total No. of Packages', $text, "PL {$format}");
            self::assertStringContainsString('PACKAGE-LEVEL BREAKDOWN', $text, "PL {$format}");
            self::assertStringContainsString('Package-level breakdown not yet recorded.', $text, "PL {$format}");
            self::assertStringNotContainsString('Crate', $text, "PL {$format}");
        }
    }

    public function testSupplierPoAsksForTheQualityDocumentsAndUsesEngineeringClauses(): void
    {
        foreach ($this->generate($this->order(), 'SUPPO') as $format => $text) {
            self::assertStringContainsString('Material / Product Type', $text, "SUPPO {$format}");
            self::assertStringContainsString('As per the approved drawing / specification', $text, "SUPPO {$format}");
            self::assertStringContainsString('Quality Documents Required', $text, "SUPPO {$format}");
            self::assertStringContainsString('Mill Test Certificate 3.1; Drawing Approval — to be supplied with the goods', $text, "SUPPO {$format}");
            self::assertStringContainsString('All items must conform exactly to the specification and drawing', $text, "SUPPO {$format}");
            self::assertStringContainsString('traceable by its heat or serial number', $text, "SUPPO {$format}");
            foreach (['Grade A material only', 'same quarry batch', 'Stone Type', '±0.5 mm on thickness'] as $gone) {
                self::assertStringNotContainsString($gone, $text, "SUPPO {$format}: {$gone}");
            }
        }
    }

    public function testAMixedOrderPrintsTheStoneAndTheEngineeringClauses(): void
    {
        foreach ($this->generate($this->order(true), 'PI') as $format => $text) {
            self::assertStringContainsString('minor variation in shade, grain and veining', $text, "PI {$format}");
            self::assertStringContainsString('Specification & Drawings:', $text, "PI {$format}");
            self::assertStringContainsString('Mill Test Certificate 3.1 — required for Engineering Products', $text, "PI {$format}");
        }
    }
}
