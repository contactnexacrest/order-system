<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Tests\Support\DbTestCase;
use App\Tests\Support\PricingFixtures;

/**
 * Owner decision D-374 (14 Nov 2026): each order line remembers the pricing rate (₹ per USD) and the day its
 * price was set, until the approved Quotation fixes the prices; the order page shows it under the price and
 * offers "Use today's price". Node twin: tests/integration/orderLinePricedRate.test.js.
 */
final class OrderLinePricedRateTest extends DbTestCase
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
        http_response_code(200);
        parent::tearDown();
    }

    private static function rate(string $r): void
    {
        Database::connection()->prepare("UPDATE pricing_settings SET setting_value = :r WHERE setting_key = 'pricing_fx_rate'")->execute(['r' => $r]);
    }

    private function product(int $admin): int
    {
        $id = ProductRepository::create(['name' => 'D374 Black Galaxy ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '25161100', 'reference_unit' => 'CBM'], $admin);
        $s = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'TEST-Supplier', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        self::configureProductForQuoting($id, $admin);
        return $id;
    }

    public function testTheRateIsRecordedWhenThePriceIsSetKeptOnACopyAndNotAfterThePricesAreFixed(): void
    {
        $admin = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $line = OrderProductRepository::add($orderId, OrderProductRepository::nextLineNo($orderId), 'Black Galaxy block', null, null, '10', false, 'CBM', '1500.00', '25161100');
        OrderLinePricing::afterSave($line, null, $admin);
        $row = OrderProductRepository::find($line);
        self::assertSame('90.0000', (string) $row['priced_fx_rate']);
        self::assertNotNull($row['priced_at']);
        self::assertSame('Priced at ₹90.00 per USD on ' . date('j M Y'), OrderLinePricing::pricedAtText($row));

        // The rate moves; saving the line without changing its price keeps the old record.
        self::rate('91.5000');
        $before = OrderProductRepository::find($line);
        OrderLinePricing::afterSave($line, $before, $admin);
        self::assertSame('90.0000', (string) OrderProductRepository::find($line)['priced_fx_rate']);

        // A changed price records today's rate.
        OrderProductRepository::setUnitPrice($line, '1490.00');
        OrderLinePricing::afterSave($line, $before, $admin);
        self::assertSame('91.5000', (string) OrderProductRepository::find($line)['priced_fx_rate']);
        self::assertSame('14900.00', (string) OrderProductRepository::find($line)['fob_value'], 'the total follows the price');

        // A copy keeps its original's rate (same price, same day).
        self::rate('93.0000');
        $copy = OrderProductRepository::duplicate($line);
        OrderLinePricing::afterSave($copy, null, $admin);
        self::assertSame('91.5000', (string) OrderProductRepository::find($copy)['priced_fx_rate']);

        // Once the approved Quotation fixes the prices, nothing is recorded any more.
        Database::connection()->prepare('UPDATE orders SET prices_fixed_at = NOW(), pricing_fx_rate_at_qt = 91.5 WHERE id = :o')->execute(['o' => $orderId]);
        $before = OrderProductRepository::find($line);
        OrderProductRepository::setUnitPrice($line, '1480.00');
        OrderLinePricing::afterSave($line, $before, $admin);
        self::assertSame('91.5000', (string) OrderProductRepository::find($line)['priced_fx_rate']);

        // A line from before D-374 says so; a line with no price says nothing.
        self::assertSame('Rate not recorded', OrderLinePricing::pricedAtText(['unit_price' => '10.00', 'priced_fx_rate' => null, 'priced_at' => null]));
        self::assertSame('', OrderLinePricing::pricedAtText(['unit_price' => null, 'priced_fx_rate' => null, 'priced_at' => null]));
    }

    public function testUseTodaysPriceTakesTheQuoteAndRecordsTodaysRate(): void
    {
        $admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $admin];
        PermissionService::resetCache();
        $productId = $this->product($admin);
        $orderId = $this->createTestOrder($this->createTestClient());
        $line = OrderProductRepository::add($orderId, 1, 'Black Galaxy block', null, null, '10', false, 'CBM', '1500.00', '25161100');
        OrderProductRepository::setCatalogProduct($line, $productId);
        self::rate('92.0000');

        $quote = OrderLinePricing::forOrder($orderId)['lines'][$line]['figures']['quote'];
        $_POST = ['reason' => ''];
        ob_start();
        (new OrderController())->useTodaysPrice(['id' => (string) $orderId, 'productId' => (string) $line]);
        ob_end_clean();

        $row = OrderProductRepository::find($line);
        self::assertSame(number_format((float) $quote, 2, '.', ''), (string) $row['unit_price']);
        self::assertSame('92.0000', (string) $row['priced_fx_rate']);
        self::assertStringContainsString("now uses today's price", (string) end($_SESSION['_flash'])['message']);
        $log = Database::connection()->prepare("SELECT old_value, new_value FROM audit_log WHERE action_type = 'ORDER_LINE_TODAYS_PRICE' AND entity_id = :id");
        $log->execute(['id' => $line]);
        self::assertSame(['old_value' => '1500.00', 'new_value' => (string) $row['unit_price']], $log->fetch(\PDO::FETCH_ASSOC));

        // Refused once the prices are fixed (D-342): the price does not change.
        Database::connection()->prepare('UPDATE orders SET prices_fixed_at = NOW(), pricing_fx_rate_at_qt = 92 WHERE id = :o')->execute(['o' => $orderId]);
        OrderProductRepository::setUnitPrice($line, '1500.00');
        ob_start();
        (new OrderController())->useTodaysPrice(['id' => (string) $orderId, 'productId' => (string) $line]);
        ob_end_clean();
        self::assertSame('1500.00', (string) OrderProductRepository::find($line)['unit_price']);
    }
}
