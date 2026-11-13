<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderReorderRequestRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\DocumentDataAssembler;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Tests\Support\DbTestCase;
use App\Tests\Support\PricingFixtures;

/**
 * HNT-011 — owner decision 13 Nov 2026 (item 8, option A, confirm 1 + confirm 2):
 * a unit price typed as 0 is a price of 0.00 (free of charge) on both stacks; a blank
 * price still means "no price yet / To Be Confirmed". PHP stored NULL for "0" (its
 * `?: null`), Node stored 0.00. Node twin: tests/integration/hnt011ZeroPrice.test.js.
 */
final class Hnt011ZeroPriceTest extends DbTestCase
{
    use PricingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        self::pinWorkedExamplePricing();
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $_SESSION = [];
        $_POST = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function quietly(callable $fn): void
    {
        ob_start();
        $fn();
        ob_end_clean();
    }

    private function flash(): string
    {
        $m = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $m === [] ? '' : (string) end($m)['message'];
    }

    /** cost 1,000.00, minimum 1,250.00, staff floor 1,287.50 (the D-245 worked example). */
    private function product(): int
    {
        $admin = $this->createTestUser('Admin');
        $id = ProductRepository::create(['name' => 'HNT011 Black Galaxy ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '25161100', 'reference_unit' => 'CBM'], $admin);
        $s = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'TEST-Supplier', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        self::configureProductForQuoting($id, $admin);
        return $id;
    }

    /** @return array{unit_price: ?string, fob_value: ?string} */
    private static function stored(int $lineId): array
    {
        $row = Database::connection()->query("SELECT unit_price, fob_value FROM order_products WHERE id = {$lineId}")->fetch();
        return ['unit_price' => $row['unit_price'], 'fob_value' => $row['fob_value']];
    }

    public function testHnt011ATypedZeroIsStoredAsZeroAndABlankStaysNoPrice(): void
    {
        $sales = $this->createTestUser('Export Executive');
        $this->actAs($sales);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$sales]);
        $charge = ['description' => 'TEST-QA charge line', 'quantity' => '1', 'unit' => 'LOT', 'line_type' => 'charge'];

        // Add line: "0" → 0.00 / 0.00, no MD approval asked (charge lines are not price-checked).
        $_POST = $charge + ['unit_price' => '0'];
        $this->quietly(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('Product line added', $this->flash());
        $zero = (int) OrderProductRepository::forOrder($orderId)[0]['id'];
        self::assertSame(['unit_price' => '0.00', 'fob_value' => '0.00'], self::stored($zero));
        self::assertNull(OrderProductRepository::find($zero)['price_approval_requested_at']);
        self::assertNotNull(OrderProductRepository::find($zero)['price_set_by'], 'a typed 0 is a price someone set');

        // Add line: blank → NULL / NULL ("To Be Confirmed").
        $_POST = $charge + ['description' => 'TEST-QA charge line blank', 'unit_price' => ''];
        $this->quietly(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        $blank = (int) OrderProductRepository::forOrder($orderId)[1]['id'];
        self::assertSame(['unit_price' => null, 'fob_value' => null], self::stored($blank));

        // Edit line: blank → 0 stores 0.00; 0 → blank stores NULL again.
        $_POST = $charge + ['description' => 'TEST-QA charge line blank', 'unit_price' => '0'];
        $this->quietly(fn () => (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $blank]));
        self::assertSame(['unit_price' => '0.00', 'fob_value' => '0.00'], self::stored($blank));
        $_POST = $charge + ['unit_price' => ''];
        $this->quietly(fn () => (new OrderController())->updateProduct(['id' => (string) $orderId, 'productId' => (string) $zero]));
        self::assertSame(['unit_price' => null, 'fob_value' => null], self::stored($zero));

        // The repository itself (order create rows, duplication, reorder) keeps 0 as 0.00.
        $direct = OrderProductRepository::add($orderId, 9, 'TEST-QA direct', null, null, '2', false, 'LOT', '0', '680293');
        self::assertSame(['unit_price' => '0.00', 'fob_value' => '0.00'], self::stored($direct));
        $copy = OrderProductRepository::duplicate($direct);
        self::assertSame(['unit_price' => '0.00', 'fob_value' => '0.00'], self::stored($copy));
        $empty = OrderProductRepository::add($orderId, 10, 'TEST-QA direct blank', null, null, '2', false, 'LOT', '', '680293');
        self::assertSame(['unit_price' => null, 'fob_value' => null], self::stored($empty));
    }

    public function testHnt011EveryLinePriceInputKeepsAZero(): void
    {
        // Order create rows, add line, edit line and reorder lines all read the price through priceInput().
        self::assertSame('0', \App\Helpers\OrderLineValidator::priceInput('0'));
        self::assertSame('0', \App\Helpers\OrderLineValidator::priceInput(' 0 '));
        self::assertNull(\App\Helpers\OrderLineValidator::priceInput(''));
        self::assertNull(\App\Helpers\OrderLineValidator::priceInput(null));
        foreach (['Controllers/OrderController.php', 'Controllers/ReorderRequestController.php', 'Repositories/OrderProductRepository.php', 'Repositories/OrderReorderRequestRepository.php'] as $file) {
            $src = (string) file_get_contents(__DIR__ . '/../../src/' . $file);
            self::assertDoesNotMatchRegularExpression('/unit_price\'\](?:\[\$i\])? \?\? \'\'\)\) \?: null|\$unitPrice \?: null/', $src, "{$file}: a typed 0 must not become NULL");
        }

        $pdo = Database::connection();
        $client = $this->createTestClient();
        $source = $this->createTestOrder($client);
        $pdo->prepare("INSERT INTO order_reorder_requests (client_id, source_order_id, status) VALUES (:c, :o, 'pending')")->execute(['c' => $client, 'o' => $source]);
        $requestId = (int) $pdo->lastInsertId();
        OrderReorderRequestRepository::addProductLine($requestId, 1, 'TEST-QA reorder free line', null, null, '1', false, 'LOT', '0', '680293');
        self::assertSame('0.00', (string) $pdo->query("SELECT unit_price FROM order_reorder_request_products WHERE reorder_request_id = {$requestId}")->fetchColumn());
    }

    public function testHnt011AGoodsLineAtZeroGetsThePriceCheckAChargeLineDoesNot(): void
    {
        $productId = $this->product();
        $sales = $this->createTestUser('Export Executive');
        $this->actAs($sales);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$sales]);

        // A goods line from Products at 0 is below the product's lowest price: the existing D-245 rule
        // refuses it (before the fix PHP saved it as "no price" with no check at all).
        $_POST = ['catalog_product_id' => (string) $productId, 'description' => 'Black Galaxy block', 'quantity' => '10', 'unit' => 'CBM', 'line_type' => 'goods',
            'hs_code' => '25161100', 'spec_json' => self::baseSpecJson($productId), 'unit_price' => '0'];
        $this->quietly(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('below what NexaCrest can accept', $this->flash());
        self::assertSame([], OrderProductRepository::forOrder($orderId), 'nothing saved');

        // The line check itself: 0.00 is below the minimum — never approvable; blank is "No price yet".
        $lineId = OrderProductRepository::add($orderId, 1, 'Black Galaxy block', null, null, '10', false, 'CBM', '0', '25161100');
        OrderProductRepository::setCatalogProduct($lineId, $productId);
        self::assertSame(OrderLinePricing::BELOW_MINIMUM, OrderLinePricing::forOrder($orderId)['lines'][$lineId]['status']);
        self::assertStringContainsString('the MD cannot approve it either', (string) OrderLinePricing::requestApproval($orderId, $lineId, $sales));
        OrderProductRepository::softDelete($lineId);

        $blank = OrderProductRepository::add($orderId, 2, 'Black Galaxy block', null, null, '10', false, 'CBM', '', '25161100');
        OrderProductRepository::setCatalogProduct($blank, $productId);
        self::assertSame(OrderLinePricing::UNCHECKED, OrderLinePricing::forOrder($orderId)['lines'][$blank]['status']);

        // A charge line at 0 on the same order: saved, no approval.
        $_POST = ['description' => 'TEST-QA free pallets', 'quantity' => '1', 'unit' => 'LOT', 'line_type' => 'charge', 'unit_price' => '0'];
        $this->quietly(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        $charge = array_values(array_filter(OrderProductRepository::forOrder($orderId), static fn (array $l): bool => $l['line_type'] === 'charge'))[0];
        self::assertSame('0.00', $charge['unit_price']);
        self::assertNull($charge['price_approval_requested_at']);
    }

    public function testHnt011DocumentsAndZohoShowAZeroLineAtZero(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $paid = OrderProductRepository::add($orderId, 1, 'TEST-QA granite', null, null, '2', false, 'CBM', '100.00', '680293');
        $free = OrderProductRepository::add($orderId, 2, 'TEST-QA free sample', null, null, '1', false, 'LOT', '0', '680293');
        OrderProductRepository::setLineType($free, 'charge', null);

        $products = DocumentDataAssembler::assemble($orderId, 'PI')['products'];
        self::assertSame('0.00', $products[1]['unit_price']);
        self::assertSame('0.00', $products[1]['amount']);
        self::assertStringNotContainsString('To Be Confirmed', $products[1]['unit_price'] . $products[1]['amount']);

        $lines = (new \ReflectionMethod(\App\Services\ZohoInvoiceData::class, 'ciLines'))->invoke(null, $orderId, 'Invoice');
        self::assertCount(2, $lines, 'the 0.00 line goes to Zoho');
        self::assertSame(0.0, $lines[1]['rate']);
        self::assertSame(1.0, $lines[1]['quantity']);

        // A blank price is still "To Be Confirmed" on the document and holds the invoice back.
        OrderProductRepository::update($free, 'TEST-QA free sample', null, null, '1', false, 'LOT', null, '680293');
        $products = DocumentDataAssembler::assemble($orderId, 'PI')['products'];
        self::assertSame(['To Be Confirmed', 'To Be Confirmed'], [$products[1]['unit_price'], $products[1]['amount']]);
        try {
            (new \ReflectionMethod(\App\Services\ZohoInvoiceData::class, 'ciLines'))->invoke(null, $orderId, 'Invoice');
            self::fail('a line without a price is not sent');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('line 2 has no confirmed quantity or unit price', $e->getMessage());
        }
        self::assertNotNull($paid);
    }
}
