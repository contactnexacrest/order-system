<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\ProductRepository;
use App\Services\DocumentGenerationService;
use App\Services\PermissionService;
use App\Services\ReferencePrice;
use App\Services\SelfApprovalRules;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2 Nov 2026 ("reference price yes with approval", D-240): a
 * reference selling price on Products-menu products, "Pick from Products" on
 * order lines, the configurable note, the MD/ED approval before the PI, and
 * the Zoho item rate / invoice item link. Node twin: referencePrice.test.js.
 */
final class ReferencePriceTest extends DbTestCase
{
    protected function tearDown(): void
    {
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

    private function product(string $price = '45.00', string $currency = 'USD', ?string $unit = 'SQFT', string $name = 'Ref Granite'): int
    {
        return ProductRepository::create([
            'name' => $name, 'specifications' => 'Granite slab, polished, 20 mm', 'hs_code' => '68022310',
            'default_factory_cost' => 1234.56, 'reference_price' => $price, 'reference_currency' => $currency, 'reference_unit' => $unit,
        ], $this->createTestUser('Admin'));
    }

    /** @return array{0:int,1:int} order id, line id */
    private function orderLine(int $productId, string $price, ?int $setBy = null): array
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $lineId = OrderProductRepository::add($orderId, 1, 'Ref Granite', null, null, '100', false, 'SQFT', $price, '68022310');
        OrderProductRepository::setCatalogProduct($lineId, $productId);
        if ($setBy !== null) {
            OrderProductRepository::setPriceSetBy($lineId, $setBy);
        }
        return [$orderId, $lineId];
    }

    private function render(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    public function testOnlySellingVisiblePeopleSeeOrEditTheReferencePrice(): void
    {
        $productId = $this->product('77.31', 'USD');
        $sales = $this->createTestUser('Export Executive');
        $procurement = $this->createTestUser('Procurement Executive');

        $this->actAs($sales);
        $page = $this->render(fn () => (new ProductController())->show(['id' => (string) $productId]));
        self::assertStringContainsString('Reference Selling Price', $page);
        self::assertStringContainsString('USD 77.31 per SQFT', $page);

        $this->actAs($procurement);
        $page = $this->render(fn () => (new ProductController())->show(['id' => (string) $productId]));
        self::assertStringNotContainsString('77.31', $page);
        self::assertStringNotContainsString('Reference Selling Price', $page);

        // A Procurement manager saving the product keeps the reference price (nothing they post counts).
        Database::connection()->prepare("INSERT INTO role_permissions (role_id, permission_id) SELECT u.role_id, p.id FROM users u JOIN permissions p ON p.permission_key = 'manage_product_catalog' WHERE u.id = :u AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = u.role_id AND x.permission_id = p.id)")->execute(['u' => $procurement]);
        PermissionService::resetCache();
        $_POST = ['name' => 'Ref Granite', 'specifications' => 'Granite slab', 'hs_code' => '68022310', 'reference_price' => '1.00', 'reference_currency' => 'EUR', 'is_active' => '1'];
        $this->render(fn () => (new ProductController())->update(['id' => (string) $productId]));
        $row = ProductRepository::find($productId);
        self::assertSame('77.31', (string) $row['reference_price']);
        self::assertSame('USD', $row['reference_currency']);
    }

    public function testTheReferencePriceIsValidatedAndAudited(): void
    {
        $productId = $this->product();
        $this->actAs($this->createTestUser('Admin'));
        $_POST = ['name' => 'Ref Granite', 'specifications' => 'Granite slab', 'hs_code' => '68022310', 'reference_price' => '50.5', 'reference_currency' => '', 'is_active' => '1'];
        $this->render(fn () => (new ProductController())->update(['id' => (string) $productId]));
        self::assertSame('Choose the currency of the reference selling price.', $_SESSION['_flash'][0]['message']);
        self::assertSame('45.00', (string) ProductRepository::find($productId)['reference_price']);

        $_SESSION['_flash'] = [];
        $_POST['reference_currency'] = 'INR';
        $_POST['reference_unit'] = 'SQM';
        $this->render(fn () => (new ProductController())->update(['id' => (string) $productId]));
        $row = ProductRepository::find($productId);
        self::assertSame(['50.50', 'INR', 'SQM'], [(string) $row['reference_price'], $row['reference_currency'], $row['reference_unit']]);
        $audit = Database::connection()->query("SELECT old_value, new_value FROM audit_log WHERE action_type = 'PRODUCT_REFERENCE_PRICE_CHANGED' AND entity_id = {$productId} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame(['USD 45.00 per SQFT', 'INR 50.50 per SQM'], [$audit['old_value'], $audit['new_value']]);
    }

    public function testPickFromProductsStoresTheProductAndTheNoteFollowsTheSettings(): void
    {
        $productId = $this->product('45.00', 'USD');
        $admin = $this->createTestUser('Admin');
        $this->actAs($admin);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $_POST = ['catalog_product_id' => (string) $productId, 'description' => 'Ref Granite', 'quantity' => '10', 'unit' => 'SQFT', 'unit_price' => '42.00', 'line_type' => 'goods', 'hs_code' => '68022310'];
        $this->render(fn () => (new OrderController())->addProduct(['id' => (string) $orderId]));
        $line = OrderProductRepository::forOrder($orderId)[0];
        self::assertSame($productId, (int) $line['catalog_product_id']);
        self::assertSame($admin, (int) $line['price_set_by']);

        $product = ProductRepository::find($productId);
        $note = static fn (string $price) => ReferencePrice::note(ReferencePrice::lineDifference(['unit_price' => $price], $product, 'USD'));
        self::assertNull($note('43.00'), '4.4% below is under the 5% note level');
        self::assertSame(['kind' => 'note', 'text' => '6.7% below reference price (USD 45.00 per SQFT)'], $note('42.00'));
        self::assertSame(['kind' => 'warning', 'text' => '⚠ 20% below reference price (USD 45.00 per SQFT)'], $note('36.00'));
        self::assertSame(['kind' => 'above', 'text' => '11.1% above reference price (USD 45.00 per SQFT)'], $note('50.00'));
        self::assertNull(ReferencePrice::lineDifference(['unit_price' => '36'], $product, 'EUR'), 'a different currency is not compared');

        CompanySettingsRepository::set('ref_price_note_pct', '8');
        self::assertNull($note('42.00'));
        CompanySettingsRepository::set('ref_price_note_enabled', '0');
        self::assertNull($note('36.00'));
        CompanySettingsRepository::set('ref_price_note_enabled', '1');
        CompanySettingsRepository::set('ref_price_note_pct', '5');

        // The order page shows the note to Sales, never to Procurement.
        Database::connection()->prepare('UPDATE order_products SET unit_price = 36, fob_value = 360 WHERE order_id = :o')->execute(['o' => $orderId]);
        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('⚠ 20% below reference price (USD 45.00 per SQFT)', $page);
        self::assertStringContainsString('Pick from Products', $page);
        self::assertStringContainsString('data-price="45.00"', $page);
    }

    public function testThePiIsRefusedUntilApprovedAndTheApprovalLapsesOnAFurtherDrop(): void
    {
        $productId = $this->product('45.00', 'USD');
        $sales = $this->createTestUser('Export Executive');
        [$orderId, $lineId] = $this->orderLine($productId, '36.00', $sales);
        $md = $this->createTestUser('Managing Director');

        try {
            DocumentGenerationService::generate($orderId, 'PI', $md, null, false, true);
            self::fail('the PI must be refused');
        } catch (\RuntimeException $e) {
            self::assertSame('Line 1 is 20% below its reference price — an MD/ED must approve the discount first (order page → Price check).', $e->getMessage());
        }

        self::assertSame('Give a reason of at least 10 characters for approving the discount.', ReferencePrice::approve($orderId, $md, 'ok'));
        self::assertNull(ReferencePrice::approve($orderId, $md, 'Volume order, agreed on call'));
        self::assertNull(ReferencePrice::piBlockReason($orderId));
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PRICE_DISCOUNT_APPROVED' AND entity_id = {$orderId}")->fetchColumn());

        // Raising the price again keeps the approval; dropping it further lapses it.
        Database::connection()->prepare('UPDATE order_products SET unit_price = 38 WHERE id = :id')->execute(['id' => $lineId]);
        self::assertNull(ReferencePrice::piBlockReason($orderId));
        Database::connection()->prepare('UPDATE order_products SET unit_price = 30 WHERE id = :id')->execute(['id' => $lineId]);
        self::assertStringContainsString('Line 1 is 33.3% below its reference price', (string) ReferencePrice::piBlockReason($orderId, $md));
        self::assertNull(ReferencePrice::currentApproval($orderId));
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PRICE_DISCOUNT_LAPSED' AND entity_id = {$orderId}")->fetchColumn());

        // A new line below the warning level also needs a fresh approval.
        self::assertNull(ReferencePrice::approve($orderId, $md, 'Second look, still agreed'));
        $second = OrderProductRepository::add($orderId, 2, 'Ref Granite B', null, null, '5', false, 'SQFT', '35.00', '68022310');
        OrderProductRepository::setCatalogProduct($second, $productId);
        self::assertStringContainsString('Line 2 is', (string) ReferencePrice::piBlockReason($orderId, $md));

        // The controller refuses with the same words and points to Price check.
        $this->actAs($md);
        $_POST = ['document_type' => 'PI'];
        $this->render(fn () => (new \App\Controllers\DocumentController())->generate(['id' => (string) $orderId]));
        self::assertStringContainsString('must approve the discount first', end($_SESSION['_flash'])['message']);
    }

    public function testWhoSetTheDiscountApprovesOnlyWhenTheSelfApprovalRuleAllows(): void
    {
        $productId = $this->product('45.00', 'USD');
        $md = $this->createTestUser('Managing Director');
        [$orderId] = $this->orderLine($productId, '36.00', $md);
        self::assertContains('price_discount', array_keys(SelfApprovalRules::RULES));

        SelfApprovalRules::set('price_discount', []);
        self::assertSame('You set a discounted price on this order — a different MD/ED must approve the discount.', ReferencePrice::approve($orderId, $md, 'Agreed with the buyer'));

        $mdRole = (int) Database::connection()->query("SELECT id FROM roles WHERE name = 'Managing Director'")->fetchColumn();
        $edRole = (int) Database::connection()->query("SELECT id FROM roles WHERE name = 'Executive Director'")->fetchColumn();
        SelfApprovalRules::set('price_discount', [$mdRole]);
        self::assertNull(ReferencePrice::approve($orderId, $md, 'Agreed with the buyer'));
        SelfApprovalRules::set('price_discount', [$mdRole, $edRole]); // back to the default for the tests after this one
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PRICE_DISCOUNT_SELF_APPROVED' AND entity_id = {$orderId}")->fetchColumn());
    }

    public function testZohoItemCarriesAnInrRateOrTheReferenceInItsDescriptionAndInvoiceLinesLinkTheItem(): void
    {
        $inr = ProductRepository::find($this->product('3500.00', 'INR', 'SQM', 'Ref INR'));
        $usd = ProductRepository::find($this->product('45.00', 'USD', 'SQFT', 'Ref USD'));
        $inrPayload = ZohoBooksService::itemPayload($inr, true);
        self::assertSame(3500.0, (float) $inrPayload['rate']);
        self::assertStringNotContainsString('Reference price', $inrPayload['description']);
        $usdPayload = ZohoBooksService::itemPayload($usd, true);
        self::assertSame(0, $usdPayload['rate']);
        self::assertStringContainsString('Reference price: USD 45.00 per SQFT', $usdPayload['description']);
        self::assertStringNotContainsString('1234.56', json_encode($usdPayload), 'no cost is ever sent');
        self::assertNotSame(\App\Services\ZohoMasterDataSync::productHash($usd), \App\Services\ZohoMasterDataSync::productHash(['reference_price' => '46.00'] + $usd), 'a reference price change is pushed again');

        Database::connection()->prepare("UPDATE catalog_products SET zoho_item_id = 'ITEM-77' WHERE id = :id")->execute(['id' => (int) $usd['id']]);
        [$orderId] = $this->orderLine((int) $usd['id'], '45.00');
        $method = new \ReflectionMethod(\App\Services\ZohoInvoiceData::class, 'ciLines');
        $lines = $method->invoke(null, $orderId, 'Commercial Invoice');
        self::assertSame('ITEM-77', $lines[0]['item_id']);
    }
}
