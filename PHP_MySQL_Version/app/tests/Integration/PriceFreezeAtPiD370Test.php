<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Config\Env;
use App\Controllers\OrderController;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\DocumentGenerationService;
use App\Services\FxRateService;
use App\Services\PermissionService;
use App\Services\PriceLock;
use App\Services\PricingService;
use App\Services\ProductsAmendmentService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-370 (13 Nov 2026, owner "go"; QA HNT-L05 — a gap in D-342): prices,
 * products and the exchange rate are fixed at the first approved Quotation OR, if none
 * was approved, at the first approved Proforma Invoice — whichever comes first. A
 * PI-fixed order is locked exactly like a Quotation-fixed one (order page, amendments,
 * document guard), and the screen names the document that fixed it. Migration
 * 2026-11-13g fixes existing orders the same way.
 * Node twin: tests/integration/priceFreezeAtPiD370.test.js (same wording).
 */
final class PriceFreezeAtPiD370Test extends DbTestCase
{
    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function flash(): string
    {
        $m = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $m === [] ? '' : (string) end($m)['message'];
    }

    /** @return array{0:int,1:list<int>} the D-342 mockup order (three CBM lines at USD 1,950.00), line ids */
    private function order(): array
    {
        $admin = $this->createTestUser('Admin');
        $productId = ProductRepository::create(['name' => 'D370 CHM Black P6 ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite slab', 'hs_code' => '68022310', 'reference_unit' => 'CBM'], $admin);
        $s = ProductSupplierRepository::create(['product_id' => $productId, 'supplier_name' => 'Test Supplier', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        $orderId = $this->createTestOrder($this->createTestClient());
        $ids = [];
        foreach ([[180, 80, 13, 25, '4.680'], [140, 70, 13, 25, '3.185'], [120, 60, 13, 10, '0.936']] as $i => [$l, $w, $t, $pcs, $qty]) {
            $id = OrderProductRepository::add($orderId, $i + 1, 'CHM Black P6', "{$l} × {$w} × {$t} cm", null, $qty, false, 'CBM', '1950.00', '68022310');
            OrderProductRepository::setCatalogProduct($id, $productId);
            OrderProductRepository::setPieceSize($id, ['piece_length_cm' => (string) $l, 'piece_width_cm' => (string) $w, 'piece_thickness_cm' => (string) $t]);
            OrderProductRepository::setPieces($id, $pcs, null);
            $ids[] = $id;
        }
        return [$orderId, $ids];
    }

    private function approve(int $orderId, string $type): int
    {
        $doc = DocumentGenerationService::generate($orderId, $type, $this->createTestUser('Admin'), null, false, true);
        DocumentGenerationService::finalizeApproval((int) $doc['document_id']);
        return (int) $doc['document_id'];
    }

    public function testHntL05APiApprovedWithoutAnApprovedQuotationFixesTheRateAndTheLines(): void
    {
        [$orderId, $ids] = $this->order();
        $this->createTestDocument($orderId, 'QT', 'draft');
        $rateNow = FxRateService::rate();
        $piId = $this->approve($orderId, 'PI');

        $order = OrderRepository::find($orderId);
        self::assertTrue(PriceLock::isFixed($order), 'D-370: the first approved PI fixes the prices when no Quotation was approved');
        self::assertSame($piId, (int) $order['prices_fixed_by_document_id'], 'fixed by the PI');
        self::assertSame('PI', $order['prices_fixed_by_type']);
        self::assertSame($rateNow, (float) $order['pricing_fx_rate_at_qt']);
        self::assertSame((string) $order['pricing_fx_rate_at_pi'], (string) $order['pricing_fx_rate_at_qt'], 'the same rate as the PI keeps');
        foreach ($ids as $id) {
            $line = OrderProductRepository::find($id);
            self::assertSame('1950.00', (string) $line['qt_fixed_unit_price']);
            self::assertSame($rateNow, (float) json_decode((string) $line['price_snapshot'], true)['fx_rate']);
        }
        $audit = Database::connection()->prepare("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PRICES_FIXED_AT_PI' AND entity_type = 'orders' AND entity_id = :o");
        $audit->execute(['o' => $orderId]);
        self::assertSame(1, (int) $audit->fetchColumn());
        self::assertSame('Prices and products are fixed by the approved Proforma Invoice of ' . date('j M Y') . ' — change them through an Amendment (Order page › Amendments › Products & prices).',
            PriceLock::message($order));
        self::assertStringStartsWith('Prices worked out at ₹' . number_format($rateNow, 2) . ' per USD — fixed by the approved Proforma Invoice of ' . date('j M Y'),
            (string) FxRateService::orderLine($order, \App\Services\OrderLinePricing::forOrder($orderId)['lines']));

        // A new rate, a later Quotation approval, a second PI approval: nothing changes.
        Database::connection()->exec("UPDATE pricing_settings SET setting_value = 'manual' WHERE setting_key = 'fx_mode'");
        Database::connection()->exec("UPDATE pricing_settings SET setting_value = '99.5000' WHERE setting_key = 'pricing_fx_rate'");
        $this->approve($orderId, 'QT');
        self::assertFalse(PriceLock::fixAtPiApproval($orderId, $piId));
        $after = OrderRepository::find($orderId);
        self::assertSame([$piId, $rateNow], [(int) $after['prices_fixed_by_document_id'], (float) $after['pricing_fx_rate_at_qt']]);
    }

    public function testAQuotationApprovedFirstStillFixesThePricesThePiChangesNothing(): void
    {
        [$orderId] = $this->order();
        $qtId = $this->approve($orderId, 'QT');
        $fixedAt = OrderRepository::find($orderId)['prices_fixed_at'];
        $this->approve($orderId, 'PI');
        $order = OrderRepository::find($orderId);
        self::assertSame([$qtId, 'QT', $fixedAt], [(int) $order['prices_fixed_by_document_id'], $order['prices_fixed_by_type'], $order['prices_fixed_at']]);
        self::assertStringStartsWith('Prices and products are fixed by the approved Quotation of ', PriceLock::message($order));
        $audit = Database::connection()->prepare("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PRICES_FIXED_AT_PI' AND entity_type = 'orders' AND entity_id = :o");
        $audit->execute(['o' => $orderId]);
        self::assertSame(0, (int) $audit->fetchColumn());
    }

    public function testAfterAPiFreezeLinesAreRefusedAndOnlyTheAmendmentChangesThem(): void
    {
        [$orderId, $ids] = $this->order();
        $this->approve($orderId, 'PI');
        $expected = 'Prices and products are fixed by the approved Proforma Invoice of ' . date('j M Y') . ' — change them through an Amendment (Order page › Amendments › Products & prices).';

        $superAdmin = $this->createTestUser('Admin');
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $superAdmin]);
        $this->actAs($superAdmin);
        $c = new OrderController();
        $_POST = ['description' => 'CHM Black P6', 'quantity' => '9', 'unit' => 'CBM', 'unit_price' => '2500.00', 'reason' => 'Super Admin override attempt for the test'];
        foreach ([['addProduct', ['id' => $orderId]], ['updateProduct', ['id' => $orderId, 'productId' => $ids[0]]],
                  ['duplicateProduct', ['id' => $orderId, 'productId' => $ids[0]]], ['deleteProduct', ['id' => $orderId, 'productId' => $ids[1]]]] as [$action, $params]) {
            $c->{$action}($params);
            self::assertSame($expected, $this->flash(), $action);
        }
        self::assertSame('1950.00', (string) OrderProductRepository::find($ids[0])['unit_price']);
        self::assertCount(3, OrderProductRepository::forOrder($orderId));

        ob_start();
        $c->show(['id' => $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('id="price-lock-note"', $html);
        self::assertStringContainsString(htmlspecialchars($expected), $html);
        self::assertStringNotContainsString('data-open-panel="line-panel-new"', $html, 'no Add line / Pick from Products');

        ob_start();
        (new \App\Controllers\AmendmentController())->newProducts(['id' => $orderId]);
        $amdPage = (string) ob_get_clean();
        self::assertStringContainsString('Prices fixed by the approved Proforma Invoice of ' . date('j M Y'), $amdPage);
        self::assertStringContainsString('is the price the buyer accepted in the Proforma Invoice — a price can only go down', $amdPage);
        self::assertStringContainsString('the buyer accepted the Proforma Invoice at USD', $amdPage);

        $order = OrderRepository::find($orderId);
        self::assertNull(ProductsAmendmentService::cannotStart($order), 'the Products & prices amendment is the way');
        $up = ProductsAmendmentService::draft($order, ['line' => [$ids[0] => ['length_cm' => '180', 'width_cm' => '80', 'thickness_cm' => '13', 'pieces' => '25', 'unit_price' => '2000.00']]]);
        self::assertContains('Line 1: USD 1,950.00 is the price the buyer accepted in the Proforma Invoice — a price can only go down, with the MD\'s approval.', $up['errors']);

        Database::connection()->prepare("UPDATE order_products SET unit_price = '2000.00' WHERE id = :id")->execute(['id' => $ids[1]]);
        self::assertSame('Line 2: USD 2,000.00 is higher than USD 1,950.00, the price the buyer accepted in the approved Proforma Invoice — the Commercial Invoice cannot be generated. Prices change only through an Amendment (Order page › Amendments › Products & prices).',
            PriceLock::documentGuard($orderId, 'CI'));
    }

    public function testNothingApprovedYetTheLinesStayEditable(): void
    {
        [$orderId] = $this->order();
        $this->createTestDocument($orderId, 'PI', 'draft');
        $order = OrderRepository::find($orderId);
        self::assertFalse(PriceLock::isFixed($order));
        self::assertSame('Neither a Quotation nor a Proforma Invoice of this order is approved yet — change its lines on the order page (Products).', ProductsAmendmentService::cannotStart($order));
    }

}
