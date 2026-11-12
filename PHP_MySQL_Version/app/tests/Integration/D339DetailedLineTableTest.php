<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\SpecRepository;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Services\LineTable;
use App\Services\UnitPrices;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-339 (12 Nov 2026, owner "confirm 1" and "confirm 2 with option a"):
 * the detailed product table on the documents of a new order, the classic table on every
 * order from before (its documents never change), the No. of pieces worked out on the
 * server, and the product card "Prices in other units".
 * Node twin: tests/integration/d339DetailedLineTable.test.js (same cases, same wording).
 */
final class D339DetailedLineTableTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function order(string $layout, string $currency = 'INR'): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', $currency);
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
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function docxText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        $xml = (string) shell_exec('unzip -p ' . escapeshellarg((string) $file['server_path']) . ' word/document.xml');
        return self::flat(html_entity_decode((string) preg_replace('/<[^>]+>/', ' ', $xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function pdfText(int $fileId): string
    {
        $file = FileStoreRepository::find($fileId);
        return self::flat((string) shell_exec('pdftotext ' . escapeshellarg((string) $file['server_path']) . ' -'));
    }

    public function testNewOrdersGetTheDetailedTableAndTheMigrationKeepsOldOnesClassic(): void
    {
        $default = Database::connection()->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'line_table_layout'")->fetchColumn();
        self::assertSame('detailed', trim((string) $default, "'"));
        $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/docs/migrations/2026-11-12g-detailed-line-table.sql');
        self::assertStringContainsString("SET @s := IF(@add_layout, 'UPDATE orders SET line_table_layout = ''classic''', 'SELECT 1');", $sql);
    }

    public function testAClassicOrderPrintsExactlyAsBefore(): void
    {
        $orderId = $this->order('classic');
        $this->ownersExample($orderId);
        foreach (LineTable::DOCUMENTS as $code) {
            self::assertNull(DocumentDataAssembler::assemble($orderId, $code)['line_table'], $code);
        }
        $r = DocumentGenerationService::generate($orderId, 'QT', $this->admin, null, true, true);
        $docx = $this->docxText((int) $r['docx_file_id']);
        self::assertStringContainsString('Product Description *', $docx);
        self::assertStringNotContainsString('No. of pcs', $docx);
    }

    public function testTheOwnersExampleOnTheDetailedTable(): void
    {
        $orderId = $this->order('detailed');
        $this->ownersExample($orderId);
        $t = DocumentDataAssembler::assemble($orderId, 'QT')['line_table'];
        self::assertSame('full', $t['variant']);
        self::assertSame(['60', '8.801', '1,540,175.00'], [$t['total']['pcs'], $t['total']['measure'], $t['total']['amount']]);
        self::assertNull(DocumentDataAssembler::assemble($orderId, 'SUPPO')['line_table'], 'only QT, PI, OC, CI, Buyer PO and PL');
        self::assertFalse(DocumentDataAssembler::assemble($orderId, 'PL')['line_table']['with_prices']);

        $qt = DocumentGenerationService::generate($orderId, 'QT', $this->admin, null, true, true);
        $row = '1 CHM Black P6 180 80 13 0.1872 25 4.680 175,000.00 32,760.00 819,000.00';
        self::assertStringContainsString($row, $this->docxText((int) $qt['docx_file_id']));
        self::assertStringContainsString('TOTAL 60 8.801 1,540,175.00', $this->docxText((int) $qt['docx_file_id']));
        $pdf = $this->pdfText((int) $qt['pdf_file_id']);
        foreach (['0.1872', '32,760.00', '819,000.00', 'No. of', 'per piece', '1,540,175.00'] as $word) {
            self::assertStringContainsString($word, $pdf);
        }
        $pl = DocumentGenerationService::generate($orderId, 'PL', $this->admin, null, true, true);
        $plText = $this->docxText((int) $pl['docx_file_id']);
        self::assertStringContainsString('1 CHM Black P6 180 80 13 0.1872 25 4.680', $plText);
        self::assertStringNotContainsString('175,000.00', $plText, 'the Packing List carries no prices');
    }

    public function testTheServerWorksOutTheQuantityFromThePieces(): void
    {
        $orderId = $this->order('detailed');
        $_SESSION['_auth_user_id'] = $this->admin;
        $post = ['description' => 'CHM Black', 'line_type' => 'goods', 'hs_code' => '68022310', 'unit' => 'CBM', 'unit_price' => '175000.00',
            'piece_length_cm' => '180', 'piece_width_cm' => '80', 'piece_thickness_cm' => '13', 'finish' => 'P6'];
        // Without the pieces: refused, nothing saved.
        $_POST = $post + ['quantity' => '4.68'];
        ob_start();
        (new OrderController())->addProduct(['id' => $orderId]);
        ob_end_clean();
        self::assertSame([], OrderProductRepository::forOrder($orderId));
        self::assertStringContainsString('enter the No. of pieces', (string) ($_SESSION['_flash']['error'] ?? json_encode($_SESSION)));

        // The browser's quantity is never trusted.
        $_POST = $post + ['quantity' => '999', 'pieces_count' => '25', 'size_note' => ''];
        ob_start();
        (new OrderController())->addProduct(['id' => $orderId]);
        ob_end_clean();
        $line = OrderProductRepository::forOrder($orderId)[0];
        self::assertSame(['4.680', 25, '819000.00'], [(string) $line['quantity'], (int) $line['pieces_count'], (string) $line['fob_value']]);

        // PCS: the quantity is the pieces. Without a size: typed as before, with the size note.
        $_POST = ['description' => 'Planter', 'line_type' => 'goods', 'hs_code' => '68022310', 'unit' => 'PCS', 'unit_price' => '85.00', 'quantity' => '3', 'pieces_count' => '20'];
        ob_start();
        (new OrderController())->addProduct(['id' => $orderId]);
        ob_end_clean();
        $_POST = ['description' => 'Blocks', 'line_type' => 'goods', 'hs_code' => '68022310', 'unit' => 'CBM', 'unit_price' => '1950.00', 'quantity' => '25', 'pieces_count' => '', 'size_note' => 'Random sizes, as available'];
        ob_start();
        (new OrderController())->addProduct(['id' => $orderId]);
        ob_end_clean();
        $lines = OrderProductRepository::forOrder($orderId);
        self::assertSame('20.000', (string) $lines[1]['quantity']);
        self::assertSame(['25.000', 'Random sizes, as available'], [(string) $lines[2]['quantity'], $lines[2]['size_note']]);

        // A duplicated line keeps its pieces and size note.
        OrderProductRepository::duplicate((int) $lines[0]['id']);
        $copy = OrderProductRepository::forOrder($orderId)[1];
        self::assertSame(25, (int) $copy['pieces_count']);
    }

    public function testAClassicOrderLineFormIsUnchanged(): void
    {
        $orderId = $this->order('classic');
        $_SESSION['_auth_user_id'] = $this->admin;
        $_POST = ['description' => 'CHM Black', 'line_type' => 'goods', 'hs_code' => '68022310', 'unit' => 'CBM', 'unit_price' => '175000.00',
            'piece_length_cm' => '180', 'piece_width_cm' => '80', 'piece_thickness_cm' => '13', 'quantity' => '4.5'];
        ob_start();
        (new OrderController())->addProduct(['id' => $orderId]);
        ob_end_clean();
        $line = OrderProductRepository::forOrder($orderId)[0];
        self::assertSame(['4.500', null], [(string) $line['quantity'], $line['pieces_count']]);
    }

    public function testThePriceFilledInNeverOverwritesATypedPrice(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/views/orders/_product_picker_js.php');
        self::assertStringContainsString("if (price.dataset.auto !== '0') {", $js);
        self::assertStringContainsString("if (price.value.trim() === '' || (price.dataset.auto !== '0' && (fill || price.dataset.auto === '1'))) {", $js);
        self::assertStringContainsString("if (base === 'unit_price') { el.dataset.auto = '0'; }", $js);
    }

    public function testPricesInOtherUnitsFollowThePermissions(): void
    {
        $productId = ProductRepository::create(['name' => 'D339 CHM Black ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], $this->admin);
        PricingRepository::saveProductOverrides($productId, ['pricing_margin_pct' => null, 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
            'pricing_incentive_mode' => null, 'pricing_incentive_value' => null, 'pricing_buffer_pct' => null]);
        SpecRepository::setProductFields($productId, null, 'slab,tile', null);
        $s = ProductSupplierRepository::create(['product_id' => $productId, 'supplier_name' => 'Test Quarry', 'location' => 'Test location', 'contact_person' => null,
            'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => 3280.0, 'factory_cost' => null, 'transportation_cost' => null,
            'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => 1, 'notes' => null], $this->admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        $product = ProductRepository::find($productId);

        $staff = UnitPrices::card($product, ['supplier' => false, 'staff' => true, 'levels' => false]);
        self::assertSame(['staff_quote', 'staff_floor'], array_column($staff['sets'], 'key'));
        self::assertNotEmpty($staff['rows']);
        $mdEd = UnitPrices::card($product, ['supplier' => true, 'staff' => true, 'levels' => true]);
        self::assertContains('supplier', array_column($mdEd['sets'], 'key'));
        self::assertGreaterThan(3, count($mdEd['sets']), 'MD / ED see every level');
        $purchase = UnitPrices::card($product, ['supplier' => true, 'staff' => false, 'levels' => false]);
        self::assertSame(['supplier'], array_column($purchase['sets'], 'key'));
        // A 20 mm row converts the per-SQM price to per SQFT (1 SQM = 10.7639 SQFT).
        foreach ($staff['rows'] as $r) {
            if ($r['missing'] === null && $r['prices']['staff_quote']['SQM'] !== null) {
                self::assertEqualsWithDelta($r['prices']['staff_quote']['SQM'] / 10.7639, (float) $r['prices']['staff_quote']['SQFT'], 0.01);
            }
        }
        // An associate sees only their own two prices, converted (the price request page).
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/AssociatePortalController.php');
        self::assertStringContainsString("'quote' => \\App\\Services\\UnitPrices::convert((float) \$r['quote']", $controller);
        self::assertStringNotContainsString('UnitPrices::card', $controller);
    }
}
