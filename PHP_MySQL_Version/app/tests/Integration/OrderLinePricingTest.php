<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\DocumentGenerationService;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-245, phase P2 (4 Nov 2026): prices on order lines.
 * Picking a product fills the quote price of whoever sells the order (staff,
 * or the client's associate at their level); below that seller's floor the
 * line goes to the MD, who may approve it down to the minimum price and never
 * below; the Proforma Invoice waits for it; a changed price voids the
 * approval; the PI fixes the figures on the line. D-240's reference price is
 * gone. Node twin: tests/integration/orderLinePricing.test.js.
 */
final class OrderLinePricingTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Team incentive program on: staff floor USD 1,287.50 sits above the minimum USD 1,250.00.
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $pdo->exec("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
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

    private function render(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    private function flash(): string
    {
        $m = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $m === [] ? '' : (string) end($m)['message'];
    }

    /** A product whose ₹80,000 supplier price is used for pricing: cost 1,000.00, minimum 1,250.00, staff floor 1,287.50, quote 1,326.13. */
    private function product(): int
    {
        $admin = $this->createTestUser('Admin');
        $id = ProductRepository::create(['name' => 'P2 Black Galaxy ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '25161100', 'reference_unit' => 'CBM'], $admin);
        $s = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'Sri Balaji Granites', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        return $id;
    }

    /** A client tied to an associate on a fixed level (5% unless given). */
    private function associateClient(string $levelName = 'Level 2'): int
    {
        $pdo = Database::connection();
        $level = (int) $pdo->query("SELECT id FROM commission_levels WHERE name = " . $pdo->quote($levelName))->fetchColumn();
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id) VALUES ('P2 Associate B', 'active', 'fixed', :l)")->execute(['l' => $level]);
        $associate = (int) $pdo->lastInsertId();
        $client = $this->createTestClient();
        $pdo->prepare('UPDATE clients SET associate_id = :a WHERE id = :c')->execute(['a' => $associate, 'c' => $client]);
        return $client;
    }

    /** @return array{0:int,1:int} order id, line id */
    private function line(int $productId, string $price, ?int $client = null, string $currency = 'USD', array $assignees = []): array
    {
        $orderId = $this->createTestOrder($client ?? $this->createTestClient(), 'FOB', 'Standard — New Buyer', $currency, null, null, $assignees);
        $lineId = OrderProductRepository::add($orderId, 1, 'Black Galaxy block', null, null, '10', false, 'CBM', $price, '25161100');
        OrderProductRepository::setCatalogProduct($lineId, $productId);
        return [$orderId, $lineId];
    }

    private function lineStatus(int $orderId, int $lineId): string
    {
        return OrderLinePricing::forOrder($orderId)['lines'][$lineId]['status'];
    }

    public function testThePickerCarriesEachSellersQuoteAndFloor(): void
    {
        $productId = $this->product();
        $prices = OrderLinePricing::pickerPrices([['id' => $productId]])[$productId];
        self::assertSame([1326.13, 1287.5], $prices['staff']);
        $level2 = (int) Database::connection()->query("SELECT id FROM commission_levels WHERE name = 'Level 2'")->fetchColumn();
        self::assertSame([1395.92, 1355.26], $prices['L' . $level2]);

        $client = $this->associateClient();
        $keys = OrderLinePricing::clientKeys([['id' => $client, 'associate_id' => (int) Database::connection()->query("SELECT associate_id FROM clients WHERE id = {$client}")->fetchColumn()], ['id' => 0, 'associate_id' => null]]);
        self::assertSame(['L' . $level2, 'staff'], [$keys[$client], $keys[0]]);
    }

    public function testEachLineIsCheckedAgainstTheFloorOfWhoeverSells(): void
    {
        $productId = $this->product();
        [$staffOrder, $staffLine] = $this->line($productId, '1300.00');
        self::assertSame(OrderLinePricing::OK, $this->lineStatus($staffOrder, $staffLine));
        $f = OrderLinePricing::forOrder($staffOrder)['lines'][$staffLine]['figures'];
        self::assertSame([1000.0, 1250.0, 1287.5, 1326.13, 'staff'], [$f['cost'], $f['minimum'], $f['floor'], $f['quote'], $f['sold_by']]);

        // The same price on the associate's client: below their 5% floor of 1,355.26.
        [$assocOrder, $assocLine] = $this->line($productId, '1300.00', $this->associateClient());
        self::assertSame(OrderLinePricing::BELOW, $this->lineStatus($assocOrder, $assocLine));
        self::assertSame(1355.26, OrderLinePricing::forOrder($assocOrder)['lines'][$assocLine]['figures']['floor']);

        // Below the minimum; no price set for the product; a hand-typed line; another currency.
        [$o3, $l3] = $this->line($productId, '1200.00');
        self::assertSame(OrderLinePricing::BELOW_MINIMUM, $this->lineStatus($o3, $l3));
        $bare = ProductRepository::create(['name' => 'P2 unpriced ' . bin2hex(random_bytes(3)), 'specifications' => 'x', 'hs_code' => '25161100'], $this->createTestUser('Admin'));
        [$o4, $l4] = $this->line($bare, '1300.00');
        self::assertSame(OrderLinePricing::NO_PRICE, $this->lineStatus($o4, $l4));
        $o5 = $this->createTestOrder($this->createTestClient());
        $l5 = OrderProductRepository::add($o5, 1, 'Typed by hand', null, null, '1', false, 'CBM', '1.00', '25161100');
        self::assertSame(OrderLinePricing::UNCHECKED, $this->lineStatus($o5, $l5));
        [$o6, $l6] = $this->line($productId, '1.00', null, 'EUR');
        self::assertSame(OrderLinePricing::UNCHECKED, $this->lineStatus($o6, $l6));
    }

    public function testTheServerRefusesAPriceBelowTheMinimumAndSendsABelowFloorLineToTheMd(): void
    {
        $productId = $this->product();
        $sales = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $this->actAs($sales);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$sales]);
        $post = ['catalog_product_id' => (string) $productId, 'description' => 'Black Galaxy block', 'quantity' => '10', 'unit' => 'CBM', 'line_type' => 'goods', 'hs_code' => '25161100'];

        $_POST = $post + ['unit_price' => '1240.00'];
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('below what NexaCrest can accept', $this->flash());
        self::assertStringNotContainsString('1,250', (string) ($_SESSION['_flash'][0]['message'] ?? ''), 'staff are not told the minimum price');
        self::assertSame([], OrderProductRepository::forOrder($orderId), 'nothing saved');

        $_POST = $post + ['unit_price' => '1270.00'];
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('sent to the MD for approval', $this->flash());
        $line = OrderProductRepository::forOrder($orderId)[0];
        self::assertNotNull($line['price_approval_requested_at']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$md} AND type = 'line_price_approval' AND related_order_id = {$orderId}")->fetchColumn());

        // The PI waits for it.
        self::assertStringContainsString('below the lowest price', (string) OrderLinePricing::piBlockReason($orderId));
        try {
            DocumentGenerationService::generate($orderId, 'PI', $md, null, false, true);
            self::fail('the PI must wait for the approval');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Line 1', $e->getMessage());
        }
    }

    public function testTheMdApprovesDownToTheMinimumOnlyAndAPriceChangeVoidsIt(): void
    {
        $productId = $this->product();
        $md = $this->createTestUser('Managing Director');
        [$orderId, $lineId] = $this->line($productId, '1270.00');

        self::assertSame('Give a reason of at least 10 characters for approving this price.', OrderLinePricing::approve($orderId, $lineId, $md, 'short'));
        $view = OrderLinePricing::approvalView($orderId, $lineId);
        self::assertSame([21.3, 17.5, 375.0, 200.0], [$view['effect']['margin_pct'], $view['effect']['short_per_unit'], $view['effect']['reserve_line'], $view['effect']['after_line']]);
        self::assertNull(OrderLinePricing::approve($orderId, $lineId, $md, 'Repeat buyer, matched a competitor offer'));
        self::assertSame(OrderLinePricing::APPROVED, $this->lineStatus($orderId, $lineId));
        $row = OrderProductRepository::find($lineId);
        self::assertSame(['1270.00', '175.00', 0], [(string) $row['price_approved_unit_price'], (string) $row['price_approval_incentive_cut'], (int) $row['price_approval_commission_zero']]);
        self::assertNull(OrderLinePricing::piBlockReason($orderId));

        // The price drops after the approval: the approval no longer holds.
        Database::connection()->exec("UPDATE order_products SET unit_price = 1260.00 WHERE id = {$lineId}");
        OrderLinePricing::afterSave($lineId, $row, $md);
        self::assertSame(OrderLinePricing::BELOW, $this->lineStatus($orderId, $lineId));
        self::assertNull(OrderProductRepository::find($lineId)['price_approved_at']);
        self::assertNotNull(OrderLinePricing::piBlockReason($orderId));

        // Below the minimum: refused even for the MD, and logged.
        Database::connection()->exec("UPDATE order_products SET unit_price = 1249.99 WHERE id = {$lineId}");
        self::assertStringContainsString('nobody can approve it, not even the MD', (string) OrderLinePricing::approve($orderId, $lineId, $md, 'Trying to approve below the minimum'));
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'LINE_PRICE_APPROVAL_REFUSED' AND entity_id = {$lineId}")->fetchColumn());

        // An associate's line approved below their floor earns no commission on it.
        [$o2, $l2] = $this->line($productId, '1300.00', $this->associateClient());
        self::assertNull(OrderLinePricing::approve($o2, $l2, $md, 'Trial container for a new distributor'));
        self::assertSame(1, (int) OrderProductRepository::find($l2)['price_approval_commission_zero']);
    }

    public function testOnlyTheMdMayApproveAndTheMdMayRefuse(): void
    {
        $productId = $this->product();
        [$orderId, $lineId] = $this->line($productId, '1270.00');
        $ed = $this->createTestUser('Executive Director');
        $sales = $this->createTestUser('Export Executive');
        self::assertFalse(PermissionService::can($ed, (int) Database::connection()->query("SELECT role_id FROM users WHERE id = {$ed}")->fetchColumn(), 'manage_pricing'));
        OrderLinePricing::requestApproval($orderId, $lineId, $sales);
        $md = $this->createTestUser('Managing Director');
        self::assertNull(OrderLinePricing::refuse($orderId, $lineId, $md, 'Too low for this buyer'));
        self::assertNull(OrderProductRepository::find($lineId)['price_approval_requested_at']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$sales} AND type = 'line_price_refused'")->fetchColumn());
    }

    public function testThePiFixesTheFiguresOnTheLine(): void
    {
        $productId = $this->product();
        [$orderId, $lineId] = $this->line($productId, '1300.00');
        $admin = $this->createTestUser('Admin');
        DocumentGenerationService::generate($orderId, 'PI', $admin, null, false, true);
        $snapshot = json_decode((string) OrderProductRepository::find($lineId)['price_snapshot'], true);
        self::assertSame([1287.5, 1250.0, 'staff'], [$snapshot['floor'], $snapshot['minimum'], $snapshot['sold_by']]);

        // A higher margin afterwards would put 1,300 below the floor — but this order keeps its PI figures.
        Database::connection()->exec("UPDATE pricing_settings SET setting_value = '30.00' WHERE setting_key = 'margin_pct'");
        $check = OrderLinePricing::forOrder($orderId)['lines'][$lineId];
        self::assertSame([OrderLinePricing::OK, true, 1287.5], [$check['status'], $check['fixed'], $check['figures']['floor']]);
        // An amendment that lowers the price is checked against the fixed figures.
        Database::connection()->exec("UPDATE order_products SET unit_price = 1280.00 WHERE id = {$lineId}");
        self::assertSame(OrderLinePricing::BELOW, $this->lineStatus($orderId, $lineId));
    }

    public function testTheOrderPageShowsEachPersonOnlyWhatTheyMaySee(): void
    {
        $productId = $this->product();
        $sales = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $logistics = $this->createTestUser('Logistics Executive');
        [$orderId] = $this->line($productId, '1270.00', null, 'USD', [$sales, $md, $logistics]);
        $this->actAs($sales);
        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('Quote 1,326.13 · lowest 1,287.50', $page);
        self::assertStringContainsString('data-floor="1287.50"', $page, 'the browser shows the floor note beside the price');
        self::assertStringContainsString('Below your lowest price', $page);
        self::assertStringContainsString('Ask MD to approve line 1', $page);
        self::assertStringNotContainsString('minimum 1,250.00', $page, 'staff never see the minimum price');
        self::assertStringNotContainsString('1,000.00', $page, 'nor the cost');

        $this->actAs($md);
        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('minimum 1,250.00', $page);
        self::assertStringContainsString('Review and approve line 1', $page);

        $this->actAs($logistics);
        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringNotContainsString('1,287.50', $page, 'no selling prices for a department without them');
        self::assertStringNotContainsString('data-floor=', $page);
    }

    public function testGoodsLinesOnAUsdOrderMustBePickedFromProductsWhileTheSettingIsOn(): void
    {
        $productId = $this->product();
        $sales = $this->createTestUser('Export Executive');
        $this->actAs($sales);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$sales]);
        $hand = ['description' => 'Granite typed by hand', 'quantity' => '10', 'unit' => 'CBM', 'unit_price' => '1300.00', 'line_type' => 'goods', 'hs_code' => '25161100'];

        // On (seeded): a goods line typed by hand is refused, beside the picker in the browser too.
        self::assertTrue(PricingService::settings()['require_product_pick']);
        $_POST = $hand;
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertStringContainsString('Pick this goods line from Products', $this->flash());
        self::assertSame([], OrderProductRepository::forOrder($orderId));
        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('data-required-when="line_type=goods" data-required-when-message="Pick this goods line from Products', $page);
        $create = $this->render(fn () => (new OrderController())->create([]));
        $usd = (int) Database::connection()->query("SELECT id FROM currencies WHERE code = 'USD'")->fetchColumn();
        self::assertStringContainsString('data-required-when="currency_id=' . $usd . '"', $create);

        // Service and charge lines can still be typed; a picked goods line is accepted.
        $_POST = ['description' => 'Fumigation', 'quantity' => '1', 'unit' => 'CBM', 'unit_price' => '40.00', 'line_type' => 'service', 'sac_code' => '998719'];
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        $_POST = ['description' => 'Pallets', 'quantity' => '1', 'unit' => 'CBM', 'unit_price' => '25.00', 'line_type' => 'charge'];
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        $_POST = ['catalog_product_id' => (string) $productId] + $hand;
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertSame(['service', 'charge', 'goods'], array_column(OrderProductRepository::forOrder($orderId), 'line_type'));

        // An EUR order is not affected.
        $eur = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'EUR', null, null, [$sales]);
        $_POST = $hand;
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $eur]));
        self::assertCount(1, OrderProductRepository::forOrder($eur));

        // Off: typed by hand is accepted again, and the browser rule is gone.
        Database::connection()->exec("UPDATE pricing_settings SET setting_value = '0' WHERE setting_key = 'require_product_pick'");
        $_POST = $hand;
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        self::assertCount(4, OrderProductRepository::forOrder($orderId));
        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringNotContainsString('data-required-when-message="Pick this goods line from Products', $page);
    }

    public function testOnlyTheMdChangesTheSettingAndAFormWithoutItKeepsIt(): void
    {
        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $base = ['margin_pct' => '20.00', 'other_cost_pct' => '2.50', 'pricing_fx_rate' => '82.0000', 'buffer_pct' => '3.00', 'incentive_mode' => 'pct', 'incentive_value' => '15', 'stale_days' => '60'];
        $_POST = $base;
        $this->render(fn () => (new \App\Controllers\PricingController())->saveSettings([]));
        self::assertTrue(PricingService::settings()['require_product_pick'], 'a form without the field keeps it');
        $_POST = $base + ['require_product_pick' => '0'];
        $this->render(fn () => (new \App\Controllers\PricingController())->saveSettings([]));
        self::assertFalse(PricingService::settings()['require_product_pick']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PRICING_SETTING_CHANGED' AND field_name = 'require_product_pick' AND new_value = '0'")->fetchColumn());
        $page = $this->render(fn () => (new \App\Controllers\PricingController())->settings([]));
        self::assertStringContainsString('Goods lines must be picked from Products', $page);
    }

    public function testTheQuotationWaitsLikeThePi(): void
    {
        $productId = $this->product();
        $md = $this->createTestUser('Managing Director');
        [$orderId, $lineId] = $this->line($productId, '1270.00');
        self::assertStringContainsString('before the Quotation', (string) OrderLinePricing::documentBlockReason($orderId, 'QT'));
        try {
            DocumentGenerationService::generate($orderId, 'QT', $md, null, false, true);
            self::fail('the Quotation must wait for the approval');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Line 1 is below the lowest price for this order — raise it, or ask the MD to approve it, before the Quotation', $e->getMessage());
        }
        self::assertNull(OrderLinePricing::approve($orderId, $lineId, $md, 'Agreed with the buyer on the call'));
        $r = DocumentGenerationService::generate($orderId, 'QT', $md, null, false, true);
        self::assertNotEmpty($r['document_id']);
        // A product without a price set keeps the Quotation back too.
        $bare = ProductRepository::create(['name' => 'P2 unpriced ' . bin2hex(random_bytes(3)), 'specifications' => 'x', 'hs_code' => '25161100'], $md);
        [$o2] = $this->line($bare, '1300.00');
        self::assertStringContainsString('has no price set for its product — the MD must approve it before the Quotation', (string) OrderLinePricing::documentBlockReason($o2, 'QT'));
    }

    public function testTheReferencePriceOfD240IsGone(): void
    {
        $pdo = Database::connection();
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'catalog_products' AND COLUMN_NAME IN ('reference_price','reference_currency')")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_price_approvals'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key = 'approve_price_discount'")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM company_settings WHERE setting_key LIKE 'ref_price%'")->fetchColumn());
        self::assertFalse(class_exists('App\\Services\\ReferencePrice'));
        self::assertStringNotContainsString("/orders/{id}/price-approval'", (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/index.php'));
        // The Zoho item carries no selling price any more.
        $payload = ZohoBooksService::itemPayload(['name' => 'Granite', 'specifications' => 'Slab', 'hs_code' => '68022310'], true);
        self::assertSame(0, $payload['rate']);
    }
}
