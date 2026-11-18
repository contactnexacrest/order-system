<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\OrderProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\OrderLineDisplay;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;
use App\Tests\Support\PricingFixtures;

/**
 * Owner-approved layout of the order page's Products table (mockup 2, 18 Nov 2026) — display only:
 * the size on one line with "25 pcs · 0.1274 CBM each" under it, the Qty without decimals for pieces,
 * option chips side by side ("Edge work: None" for a bare None), the price check with its badge first
 * and the reference folded under "Per CBM ▸", and D-374's record line shortened to "₹90.00 / USD · <date>".
 * Node twin: tests/integration/orderProductsTableLayout.test.js.
 */
final class OrderProductsTableLayoutTest extends DbTestCase
{
    use PricingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        self::pinWorkedExamplePricing(['fx_mode' => 'manual', 'pricing_fx_rate' => '90.0000']);
    }

    protected function tearDown(): void
    {
        self::resetPricingSettings();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        http_response_code(200);
        parent::tearDown();
    }

    public function testTheQuantityAndTheOtherFiguresAreShownAsApproved(): void
    {
        self::assertSame('25', OrderLineDisplay::quantityText('25.000', 'PCS'));
        self::assertSame('1,200', OrderLineDisplay::quantityText('1200.000', 'Nos'));
        self::assertSame('2', OrderLineDisplay::quantityText('2.000', 'SET'));
        self::assertSame('3', OrderLineDisplay::quantityText('3.000', 'Lot'));
        self::assertSame('2.5', OrderLineDisplay::quantityText('2.500', 'SET'), 'a part piece keeps its decimals');
        self::assertSame('12.500', OrderLineDisplay::quantityText('12.500', 'CBM'));
        self::assertSame('12.35', OrderLineDisplay::quantityText('12.345', 'SQM'));
        self::assertSame('40.00', OrderLineDisplay::quantityText('40.000', 'SQFT'));
        self::assertSame('1.500', OrderLineDisplay::quantityText('1.500', 'TON'), 'other units as stored');
        self::assertSame('0.1274 CBM each', OrderLineDisplay::cbmEachText(['piece_length_cm' => '140.00', 'piece_width_cm' => '70.00', 'piece_thickness_cm' => '13.00']));
        self::assertSame('', OrderLineDisplay::cbmEachText(['piece_length_cm' => '140.00', 'piece_width_cm' => '70.00', 'piece_thickness_cm' => null]));
        self::assertSame('₹91.61 / USD · 18 Nov 2026', OrderLineDisplay::pricedAtShort(['unit_price' => '368.11', 'priced_fx_rate' => '91.6100', 'priced_at' => '2026-11-18 10:15:00']));
        self::assertSame('Rate not recorded', OrderLineDisplay::pricedAtShort(['unit_price' => '10.00', 'priced_fx_rate' => null, 'priced_at' => null]));
        self::assertSame('', OrderLineDisplay::pricedAtShort(['unit_price' => null, 'priced_fx_rate' => null, 'priced_at' => null]));
        self::assertSame('Edge work: None', OrderLineDisplay::chipText(['name' => 'None', 'gone' => false, 'spec' => 'Edge work']));
        self::assertSame('Premium', OrderLineDisplay::chipText(['name' => 'Premium', 'gone' => false, 'spec' => 'Grade / quality']));
    }

    public function testTheOrderPageShowsTheApprovedRow(): void
    {
        $admin = $this->createTestUser('Admin');
        $productId = ProductRepository::create(['name' => 'Layout Black Granite ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '68029300', 'reference_unit' => 'CBM'], $admin);
        $s = ProductSupplierRepository::create(['product_id' => $productId, 'supplier_name' => 'TEST-Supplier', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        self::configureProductForQuoting($productId, $admin);

        $orderId = $this->createTestOrder($this->createTestClient());
        $line = OrderProductRepository::add($orderId, 1, 'Absolute Black Granite', '140 × 70 × 13 cm', null, '25', false, 'PCS', '368.11', '68029300');
        OrderProductRepository::setCatalogProduct($line, $productId);
        OrderLinePricing::saveSelection($line, json_decode(self::baseSpecJson($productId), true), null);
        OrderProductRepository::setPieceSize($line, ['piece_length_cm' => '140', 'piece_width_cm' => '70', 'piece_thickness_cm' => '13']);
        OrderProductRepository::setPieces($line, 25, null);
        OrderLinePricing::afterSave($line, null, $admin);

        $_SESSION = ['_auth_user_id' => $admin];
        PermissionService::resetCache();
        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $html = (string) ob_get_clean();

        $row = substr($html, (int) strpos($html, 'id="line-' . $line . '"'));
        $row = substr($row, 0, (int) strpos($row, '</tr>'));
        self::assertStringContainsString('<td class="num line-qty">25</td>', $row, 'Qty "25", not "25.000", for a PCS line');
        self::assertStringContainsString('<span class="line-dims">140 × 70 × 13 cm</span><div class="muted small line-pcs">25 pcs · 0.1274 CBM each</div>', $row);
        self::assertStringContainsString('<span class="chip">Edge work: None</span>', $row);
        self::assertStringContainsString('<details class="price-ref-more"><summary>Per CBM ▸</summary><span class="price-ref muted">Product reference per CBM:', $row);
        self::assertLessThan(strpos($row, 'class="price-range'), strpos($row, 'class="price-badge'), 'the badge comes first');
        self::assertStringContainsString('>₹90.00 / USD · ' . date('j M Y') . '</div>', $row);
        self::assertStringContainsString('title="Priced at ₹90.00 per USD on ' . date('j M Y') . '"', $row, 'the full D-374 wording stays as the title');
        foreach (['<td class="line-actions">', 'data-open-panel="line-panel-' . $line . '">Edit</button>', '/products/' . $line . '/duplicate"', '/products/' . $line . '/delete"'] as $hook) {
            self::assertStringContainsString($hook, $row, 'Edit / Duplicate / Delete stay');
        }
    }
}
