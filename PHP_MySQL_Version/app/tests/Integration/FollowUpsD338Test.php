<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\ProductController;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderAnnexureTermsRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderReorderRequestRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\AnnexureChoice;
use App\Services\OrderDuplicationService;
use App\Services\OrderLinePricing;
use App\Services\ThicknessPrices;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-338 (12 Nov 2026, owner "will go with recommendation") — follow-ups to
 * D-335 / D-336:
 *  1a reorder request lines "As per annexure" (never with the quantity-not-decided box, Qty
 *     required, no Dimensions), carried onto the order;
 *  1b a duplicated order copies Annexure A (products, same image files, terms, include, mode),
 *     never Annexure B or the CI annexure choice;
 *  1c one wording when a line "As per annexure" needs its piece size for the price check;
 *  2a no new "add up the cost parts" supplier price; older ones keep working;
 *  2b a line priced from a per-CBM price only CALCULATED from a thinner base goes to the MD;
 *  2d Pricing settings headings and legends in Title Case.
 * Node twin: tests/integration/followUpsD338.test.js.
 */
final class FollowUpsD338Test extends DbTestCase
{
    private const APP = __DIR__ . '/../..';

    /** Natural Stone's seeded rules (pure tests), as in D335SupplierPricesBaseTest. */
    private static function rules(): array
    {
        return [
            'grid' => true, 'base_mm' => 20, 'k' => 40.0, 'cbm_from' => 50,
            'rows' => [['mm' => 15, 'pct' => -20.0, 'option' => '15 mm'], ['mm' => 18, 'pct' => -5.0, 'option' => '18 mm'], ['mm' => 20, 'pct' => 0.0, 'option' => '20 mm'],
                ['mm' => 30, 'pct' => 35.0, 'option' => '30 mm'], ['mm' => 40, 'pct' => 70.0, 'option' => '40 mm']],
            'container_cbm' => 8.2, 'container_max_t' => 27.0, 't_per_cbm' => 2.7, 'own' => [], 'category_id' => 1, 'category_name' => 'Natural Stone', 'unit' => 'CBM',
        ];
    }

    /** Fixed line figures (price_snapshot) so a check needs no live supplier price. */
    private static function figures(string $unit, bool $cbmCalculated): array
    {
        return ['cost' => 1000.0, 'minimum' => 1250.0, 'reserve' => 37.5, 'staff_floor' => 1287.5, 'staff_quote' => 1326.13, 'floor' => 1287.5, 'quote' => 1326.13,
            'sold_by' => 'staff', 'sold_by_label' => 'Staff', 'margin_pct' => 20.0, 'unit' => $unit,
            'cbm_calculated' => $cbmCalculated, 'cbm_calc_from_mm' => $cbmCalculated ? 20 : null, 'cbm_from_mm' => 50];
    }

    /** A USD order with one goods line picked from Products and fixed figures. */
    private function checkedLine(string $lineUnit, string $price, array $figures, bool $perAnnexure = false): array
    {
        $admin = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $productId = ProductRepository::create(['name' => 'D338 Granite ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022310', 'reference_unit' => $figures['unit']], $admin);
        $lineId = OrderProductRepository::add($orderId, 1, 'Absolute black granite', null, 'Polished', '8.801', false, $lineUnit, $price, '68022310');
        OrderProductRepository::setCatalogProduct($lineId, $productId);
        OrderProductRepository::setAsPerAnnexure($lineId, $perAnnexure);
        Database::connection()->prepare('UPDATE order_products SET price_snapshot = :s WHERE id = :id')
            ->execute(['s' => json_encode($figures, JSON_PRESERVE_ZERO_FRACTION), 'id' => $lineId]);
        return [$orderId, $lineId, $admin];
    }

    // ------------------------------------------------------------------ 1a

    public function testReorderLineAnnexureRulesAndWording(): void
    {
        self::assertNull(AnnexureChoice::reorderLineError(1, false, true, '', 'Qty To Be Confirmed'), 'not ticked: nothing to check');
        self::assertSame('Line 2: tick either "Qty To Be Confirmed" or "As per annexure", not both.',
            AnnexureChoice::reorderLineError(2, true, true, '5', 'Qty To Be Confirmed'));
        self::assertSame('Line 1: tick either "Quantity not decided yet" or "As per annexure", not both.',
            AnnexureChoice::reorderLineError(1, true, true, '5', 'Quantity not decided yet'));
        self::assertSame('Line 3: a line "As per annexure" still needs its quantity — enter the Qty.',
            AnnexureChoice::reorderLineError(3, true, false, '  ', 'Qty To Be Confirmed'));
        self::assertNull(AnnexureChoice::reorderLineError(1, true, false, '8.801', 'Qty To Be Confirmed'));
    }

    public function testReorderLineIsStoredAndCarriedOntoTheNewOrder(): void
    {
        $clientId = $this->createTestClient();
        $sourceId = $this->createTestOrder($clientId);
        $requestId = OrderReorderRequestRepository::create($clientId, $sourceId, null);
        OrderReorderRequestRepository::addProductLine($requestId, 1, 'Slab as per annexure', null, null, '8.801', false, 'CBM', null, null, true);
        OrderReorderRequestRepository::addProductLine($requestId, 2, 'Slab with sizes', '60 x 40 x 3 cm', null, '5', false, 'SQM', null, null);
        $lines = OrderReorderRequestRepository::productLines($requestId);
        self::assertSame([1, 0], array_map(static fn (array $l): int => (int) $l['as_per_annexure'], $lines));

        $newOrderId = OrderDuplicationService::duplicate($sourceId, $this->createTestUser('Admin'), [
            ['description' => 'Slab as per annexure', 'dimensions' => null, 'finish' => null, 'quantity' => '8.801', 'quantity_is_tbc' => false,
                'unit' => 'CBM', 'unit_price' => '100', 'hs_code' => '68022310', 'as_per_annexure' => true],
            ['description' => 'Slab with sizes', 'dimensions' => '60 x 40 x 3 cm', 'finish' => null, 'quantity' => '5', 'quantity_is_tbc' => false,
                'unit' => 'SQM', 'unit_price' => '50', 'hs_code' => '68022310'],
        ]);
        $newLines = OrderProductRepository::forOrder($newOrderId);
        self::assertSame([1, 0], array_map(static fn (array $l): int => (int) $l['as_per_annexure'], $newLines));
    }

    public function testReorderFormsHaveTheTickBoxWiredToAppJs(): void
    {
        $staff = (string) file_get_contents(self::APP . '/views/reorder_requests/show.php');
        self::assertStringContainsString('name="as_per_annexure[<?= $i ?>]"', $staff);
        self::assertStringContainsString('data-annexure-line', $staff);
        self::assertStringContainsString('data-annexure-tbc', $staff);
        self::assertStringContainsString('data-annexure-size', $staff);
        self::assertStringContainsString('data-annexure-scope', $staff);
        $portal = (string) file_get_contents(self::APP . '/views/client_portal/reorder.php');
        self::assertStringContainsString('name="product_as_per_annexure[<?= $idx ?>]"', $portal);
        self::assertStringContainsString('data-annexure-scope', $portal);
        $js = (string) file_get_contents(self::APP . '/../public_html/assets/js/app.js');
        self::assertStringContainsString("el.closest('[data-annexure-scope]')", $js);
        self::assertStringContainsString('[data-annexure-size]', $js);
    }

    // ------------------------------------------------------------------ 1b

    public function testDuplicateCopiesAnnexureAButNotAnnexureB(): void
    {
        $admin = $this->createTestUser('Admin');
        $sourceId = $this->createTestOrder($this->createTestClient());
        $a = OrderAnnexureRepository::createProduct($sourceId, ['name' => 'Slab A', 'description' => 'Slab', 'dimensions' => '180 x 120 x 15 cm', 'finish' => 'Polished', 'components' => '', 'technical_notes' => ''], 'A');
        $fileId = $this->createTestFile($sourceId);
        OrderAnnexureRepository::addImage($a, $fileId);
        OrderAnnexureRepository::createProduct($sourceId, ['name' => 'Slab B', 'description' => 'Shipped', 'dimensions' => '1 x 1 x 1 cm', 'finish' => '', 'components' => '', 'technical_notes' => ''], 'B');
        OrderAnnexureTermsRepository::upsert($sourceId, '<p>Terms A</p>', $admin, 'A');
        OrderAnnexureTermsRepository::upsert($sourceId, '<p>Terms B</p>', $admin, 'B');
        OrderRepository::setIncludeAnnexureA($sourceId, true);
        OrderRepository::setAnnexureMode($sourceId, 'BOTH');
        OrderRepository::setIncludeAnnexureB($sourceId, true);
        OrderRepository::setCiAnnexure($sourceId, 'B');

        $newId = OrderDuplicationService::duplicate($sourceId, $admin);
        $copyA = OrderAnnexureRepository::forOrder($newId, 'A');
        self::assertCount(1, $copyA);
        self::assertSame(['Slab A', '180 x 120 x 15 cm'], [$copyA[0]['name'], $copyA[0]['dimensions']]);
        self::assertSame($fileId, (int) $copyA[0]['images'][0]['file_id'], 'the same stored file, as "Copy everything from Annexure A"');
        self::assertSame('<p>Terms A</p>', OrderAnnexureTermsRepository::find($newId, 'A')['content_html'] ?? null);
        $new = OrderRepository::find($newId);
        self::assertSame([1, 'BOTH'], [(int) $new['include_annexure_a'], (string) $new['annexure_mode']]);
        self::assertSame([], OrderAnnexureRepository::forOrder($newId, 'B'), 'Annexure B is not copied');
        self::assertNull(OrderAnnexureTermsRepository::find($newId, 'B'));
        self::assertSame(0, (int) $new['include_annexure_b']);
        self::assertNull($new['ci_annexure'], 'the CI annexure choice is not copied');
        self::assertCount(1, OrderAnnexureRepository::forOrder($sourceId, 'A'), 'the source keeps its Annexure A');
    }

    // ------------------------------------------------------------------ 1c

    public function testAnnexureSizeMessageFollowsTheLineUnit(): void
    {
        self::assertSame('Line 3 says "As per annexure", so its size is not known — type the price per CBM for this line.', OrderLinePricing::annexureSizeMessage(3, 'CBM'));
        self::assertSame('Line 4 says "As per annexure", so its size is not known — type the price per SQM for this line.', OrderLinePricing::annexureSizeMessage(4, 'SQM'));
        self::assertSame('Line 1 says "As per annexure", so its size is not known — type the price per piece for this line.', OrderLinePricing::annexureSizeMessage(1, 'PCS'));
        self::assertSame('Line 2 says "As per annexure", so its size is not known — type the price per CBM for this line.', OrderLinePricing::annexureSizeMessage(2, ''));
    }

    public function testAnnexureLineNeedingItsSizeGetsTheSameMessageEverywhere(): void
    {
        // A product priced per m² on a CBM line ticked "As per annexure": no thickness to convert with.
        [$orderId, $lineId, $admin] = $this->checkedLine('CBM', '2834.54', self::figures('SQM', false), true);
        $expected = 'Line 1 says "As per annexure", so its size is not known — type the price per CBM for this line.';
        $check = OrderLinePricing::checkLine(OrderProductRepository::find($lineId), OrderLinePricing::context(OrderRepository::find($orderId)));
        self::assertSame([OrderLinePricing::NEEDS_SIZE, $expected], [$check['status'], $check['note']]);
        self::assertSame($expected, OrderLinePricing::documentBlockReason($orderId, 'QT'));
        self::assertSame($expected, OrderLinePricing::documentBlockReason($orderId, 'PI'));
        self::assertSame($expected, OrderLinePricing::approve($orderId, $lineId, $admin, 'Checked with the buyer and agreed'));

        // The save refusal is shown as it is (no "Product line:" prefix); other refusals keep it.
        $text = new \ReflectionMethod(OrderController::class, 'lineRefusalText');
        self::assertSame($expected, $text->invoke(null, $expected));
        self::assertSame('Product line: Enter the thickness (cm).', $text->invoke(null, 'Enter the thickness (cm).'));

        // Without the tick the old wording stays.
        OrderProductRepository::setAsPerAnnexure($lineId, false);
        $check = OrderLinePricing::checkLine(OrderProductRepository::find($lineId), OrderLinePricing::context(OrderRepository::find($orderId)));
        self::assertSame(OrderLinePricing::NEEDS_SIZE, $check['status']);
        self::assertStringNotContainsString('As per annexure', (string) $check['note']);
    }

    // ------------------------------------------------------------------ 2a

    public function testNoNewAddUpTheCostPartsPriceOlderOnesKeepWorking(): void
    {
        $controller = (new \ReflectionClass(ProductController::class))->newInstanceWithoutConstructor();
        $validate = new \ReflectionMethod(ProductController::class, 'validateSupplier');
        $read = new \ReflectionMethod(ProductController::class, 'readSupplierForm');

        self::assertSame(ProductController::COST_PARTS_REFUSAL, $validate->invoke($controller, ['supplier_name' => 'Quarry', 'fob_source' => 'computed'], null), 'a new price');
        self::assertSame(ProductController::COST_PARTS_REFUSAL, $validate->invoke($controller, ['supplier_name' => 'Quarry', 'fob_source' => 'computed'], ['fob_source' => 'direct']), 'a typed price cannot become one');
        self::assertNull($validate->invoke($controller, ['supplier_name' => 'Quarry', 'fob_source' => 'computed'], ['fob_source' => 'computed']), 'an older one stays');
        self::assertNull($validate->invoke($controller, ['supplier_name' => 'Quarry', 'fob_source' => 'direct'], ['fob_source' => 'computed']), 'and may become a typed price');
        self::assertSame("Adding up the cost parts is no longer offered for a new price — type the supplier's price in FOB Value (₹). Prices saved that way earlier keep working.", ProductController::COST_PARTS_REFUSAL);

        // A typed price's Edit form has no cost-part fields: what is stored is kept, nothing is cleared.
        $_POST = ['supplier_name' => 'Quarry', 'fob_source' => 'direct', 'fob_value' => '4500'];
        $data = $read->invoke($controller, ['fob_source' => 'direct', 'factory_cost' => '350000.00', 'transportation_cost' => null, 'packing_cost' => '12000.00', 'loading_cost' => null, 'cha_cost' => null]);
        self::assertSame([350000.0, null, 12000.0], [$data['factory_cost'], $data['transportation_cost'], $data['packing_cost']]);
        $_POST = [];

        $view = (string) file_get_contents(self::APP . '/views/products/show.php');
        self::assertSame(1, substr_count($view, '<option value="computed"'), 'only in the Edit form of an older computed price');
        self::assertStringContainsString("<?php if (\$s['fob_source'] === 'computed'): ?>", $view);
        self::assertStringContainsString('<label>FOB Value (₹)<input type="number" step="0.01" min="0" name="fob_value" placeholder="e.g. 4500.00"></label>', $view);
    }

    // ------------------------------------------------------------------ 2b

    public function testThicknessCostTellsWhenTheCbmPriceIsOnlyWorkedOut(): void
    {
        $product = ['default_packing_cost' => 0, 'default_loading_cost' => 0];
        $row = ['auto_fill' => 0, 'base_thickness_mm' => 20, 'includes_packing_loading' => 1];
        $calc = ThicknessPrices::cost(self::rules(), [20 => 4600.0, 0 => ['price' => 184000.0, 'how' => 'calculated', 'calc_from_mm' => 20]], $row, $product, 'cbm', null);
        self::assertSame(['CBM', 'calculated', 20, 50], [$calc['unit'], $calc['how'], $calc['calc_from_mm'], $calc['cbm_from']]);
        $typed = ThicknessPrices::cost(self::rules(), [20 => 4600.0, 0 => 180000.0], $row, $product, 'cbm', null);
        self::assertSame(['typed', null], [$typed['how'], $typed['calc_from_mm']]);
        // An older row (auto_fill 1) works the per-CBM price out from its base on the fly.
        $old = ThicknessPrices::cost(self::rules(), [20 => 4600.0], ['auto_fill' => 1, 'base_thickness_mm' => 20, 'includes_packing_loading' => 1], $product, 'cbm', null);
        self::assertSame(['calculated', 20], [$old['how'], $old['calc_from_mm']]);

        self::assertSame("CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted", OrderLinePricing::cbmCalculatedReason(self::figures('CBM', true)));
        self::assertNull(OrderLinePricing::cbmCalculatedReason(self::figures('CBM', false)));
    }

    public function testWorkedOutCbmPriceGoesToTheMdLikeALineBelowTheFloor(): void
    {
        [$orderId, $lineId, $admin] = $this->checkedLine('CBM', '1300.00', self::figures('CBM', true));
        $line = OrderProductRepository::find($lineId);
        $check = OrderLinePricing::checkLine($line, OrderLinePricing::context(OrderRepository::find($orderId)));
        self::assertSame(OrderLinePricing::CBM_CALCULATED, $check['status'], 'at or above the floor, but worked out');
        self::assertSame("CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted", $check['cbm_reason']);
        self::assertContains(OrderLinePricing::CBM_CALCULATED, OrderLinePricing::BLOCKING);

        OrderLinePricing::afterSave($lineId, null, $admin);
        self::assertNotEmpty(OrderProductRepository::find($lineId)['price_approval_requested_at'], 'sent to the MD');
        self::assertSame("Line 1 needs MD approval: CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted — the MD must approve it before the Quotation (order page → Products).",
            OrderLinePricing::documentBlockReason($orderId, 'QT'));
        self::assertNotNull(OrderLinePricing::documentBlockReason($orderId, 'PI'));
        $summary = OrderLinePricing::summary(OrderLinePricing::forOrder($orderId)['lines']);
        self::assertSame('1 line needs MD approval (line 1) — the Quotation waits for it.', $summary['text']);

        $view = OrderLinePricing::approvalView($orderId, $lineId);
        self::assertSame("CBM price worked out from the supplier's 20 mm price — no 50 mm+ price quoted", $view['check']['cbm_reason'], 'the approval page shows the reason');

        self::assertNull(OrderLinePricing::approve($orderId, $lineId, $admin, 'Supplier confirmed the 50 mm price by phone'));
        $check = OrderLinePricing::checkLine(OrderProductRepository::find($lineId), OrderLinePricing::context(OrderRepository::find($orderId)));
        self::assertSame(OrderLinePricing::APPROVED, $check['status']);
        self::assertNull(OrderLinePricing::documentBlockReason($orderId, 'QT'));
    }

    public function testATypedCbmPriceNeedsNoApproval(): void
    {
        [$orderId, $lineId] = $this->checkedLine('CBM', '1300.00', self::figures('CBM', false));
        $check = OrderLinePricing::checkLine(OrderProductRepository::find($lineId), OrderLinePricing::context(OrderRepository::find($orderId)));
        self::assertSame([OrderLinePricing::OK, null], [$check['status'], $check['cbm_reason']]);
        self::assertNull(OrderLinePricing::documentBlockReason($orderId, 'QT'));
    }

    public function testApprovalScreensKnowTheNewStatus(): void
    {
        $approval = (string) file_get_contents(self::APP . '/views/orders/price_approval.php');
        self::assertStringContainsString("in_array(\$check['status'], OrderLinePricing::APPROVABLE, true)", $approval);
        self::assertStringContainsString("<tr><td>Reason</td><td><strong><?= htmlspecialchars((string) \$check['cbm_reason']) ?></strong></td></tr>", $approval);
        $show = (string) file_get_contents(self::APP . '/views/orders/show.php');
        self::assertStringContainsString('CBM price worked out — needs MD approval', $show);
        self::assertStringContainsString('\App\Services\OrderLinePricing::APPROVABLE', $show);
        self::assertStringContainsString('CBM price worked out — needs MD approval', (string) file_get_contents(self::APP . '/views/orders/_line_price.php'));
        self::assertStringContainsString("'Price — CBM worked out'", (string) file_get_contents(self::APP . '/src/Services/ApprovalsInbox.php'));
    }

    // ------------------------------------------------------------------ 2d

    public function testPricingSettingsHeadingsAreInTitleCase(): void
    {
        $view = (string) file_get_contents(self::APP . '/views/pricing/settings.php');
        foreach (['<legend>Margin and Costs</legend>', '<legend>Exchange Rate (USD → INR)</legend>', '<legend>Team Incentive Reserve</legend>',
            '<legend>Supplier Prices</legend>', '<legend>Order Lines</legend>', '<legend>Specifications and Associate Prices (D-255)</legend>', '<h2>Who Can Do What</h2>'] as $heading) {
            self::assertStringContainsString($heading, $view);
        }
        foreach (['<h2>Exchange rate</h2>', '<legend>Margin and costs</legend>', '<legend>Team incentive reserve</legend>', '<h2>Who can do what</h2>'] as $old) {
            self::assertStringNotContainsString($old, $view);
        }
    }
}
