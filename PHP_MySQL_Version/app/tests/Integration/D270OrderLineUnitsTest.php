<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\SpecRepository;
use App\Services\OrderDuplicationService;
use App\Services\OrderLinePricing;
use App\Services\PriceRequestService;
use App\Services\ProductSpecs;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions of 11 Nov 2026, double-confirmed:
 *  - D-270 (point 25): an order line in any unit of its category; its price is converted
 *    to the product's reference unit (per CBM from 50 mm) with the piece size for every
 *    check; a missing size is asked for, never "too low"; Set / Lot go to the MD.
 *  - D-271/D-272 (points 13 and 24): options shown as labels, a switched-off option is never
 *    replaced silently; Duplicate is an exact copy right below, approvals not copied.
 *  - D-273 (point 17): the summary bar and the totals row with CBM and containers.
 * Node twin: tests/integration/d270OrderLineUnits.test.js.
 */
final class D270OrderLineUnitsTest extends DbTestCase
{
    private int $admin;
    private int $product;
    private int $associate;
    private int $client;

    private const THICK = ['Grade / quality' => 'Standard', 'Surface finish' => 'Natural Polish'];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
        $pdo = Database::connection();
        $pdo->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
        $this->product = ProductRepository::create(['name' => 'D270 Absolute Black ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], $this->admin);
        PricingRepository::saveProductOverrides($this->product, ['pricing_margin_pct' => null, 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
            'pricing_incentive_mode' => null, 'pricing_incentive_value' => null, 'pricing_buffer_pct' => null]);
        SpecRepository::setProductFields($this->product, null, 'slab,tile', null);
        $this->supplier(3280.0, 'unit');
        $this->supplier(53300.0, 'cbm');
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id) VALUES (:n, 'active', 'fixed', :l)")
            ->execute(['n' => 'D270 Associate ' . bin2hex(random_bytes(3)), 'l' => (int) $pdo->query("SELECT id FROM commission_levels WHERE name = 'Level 2'")->fetchColumn()]);
        $this->associate = (int) $pdo->lastInsertId();
        $this->client = $this->createTestClient();
        PriceRequestService::saveAccess($this->associate, [(int) SpecRepository::systemCategoryId() => ['slab', 'tile']]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function supplier(float $inr, string $basis): void
    {
        $s = ProductSupplierRepository::create(['product_id' => $this->product, 'supplier_name' => 'Test Quarry ' . $basis, 'location' => 'Test location', 'contact_person' => null,
            'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => $inr, 'factory_cost' => null, 'transportation_cost' => null,
            'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => $basis === 'unit' ? 1 : 0, 'notes' => null], $this->admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        if ($basis === 'cbm') {
            PricingRepository::setSupplierBasis($s, 'cbm');
            PricingRepository::setCbmPrimary($this->product, $s);
        }
    }

    /** @param array<string,string> $byName */
    private function sel(array $byName): array
    {
        $out = [];
        foreach (ProductSpecs::offered(ProductRepository::find($this->product)) as $s) {
            foreach ($s['options'] as $o) {
                if (($byName[$s['name']] ?? null) === $o['name']) {
                    $out[(int) $s['id']] = (int) $o['id'];
                }
            }
        }
        return $out;
    }

    private function order(): array
    {
        return Database::connection()->query('SELECT * FROM orders WHERE id = ' . $this->createTestOrder($this->client, 'FOB', 'Standard — New Buyer', 'USD'))->fetch();
    }

    private function lineSize(?string $l, ?string $w, ?string $t): array
    {
        return ['piece_length_cm' => $l, 'piece_width_cm' => $w, 'piece_thickness_cm' => $t];
    }

    /** A line on the order picked from the product, with options, unit, piece size and price. */
    private function line(int $orderId, ?array $selection, string $unit, array $size, ?string $price, string $qty = '40'): int
    {
        $id = OrderProductRepository::add($orderId, OrderProductRepository::nextLineNo($orderId), 'Absolute black granite', null, null, $qty, false, $unit, $price, '68022390');
        OrderProductRepository::setCatalogProduct($id, $this->product);
        OrderLinePricing::saveSelection($id, $selection, null);
        OrderProductRepository::setPieceSize($id, $size);
        return $id;
    }

    private function thick13(): array
    {
        [$sel, $err] = OrderLinePricing::syncThickness($this->product, $this->sel(self::THICK), '13');
        self::assertNull($err);
        return $sel;
    }

    public function testTheThicknessBoxChoosesTheThicknessOption(): void
    {
        $names = static function (array $sel): array {
            return array_values(array_map(static fn (int $o): string => (string) SpecRepository::findOption($o)['name'], $sel));
        };
        self::assertContains('30 mm', $names(OrderLinePricing::syncThickness($this->product, $this->sel(self::THICK), '3')[0]));
        self::assertContains('Other, 50 mm or more', $names($this->thick13()), '13 cm is thick stone, priced per CBM');
        [, $err] = OrderLinePricing::syncThickness($this->product, $this->sel(self::THICK), '2.5');
        self::assertStringContainsString('There is no 25 mm Thickness option', (string) $err);
    }

    public function testThirteenCmNeedsNoManualPriceAndIsPricedPerCbm(): void
    {
        $f = OrderLinePricing::liveFigures(ProductRepository::find($this->product), OrderLinePricing::context($this->order()), $this->thick13(), 130.0);
        self::assertArrayNotHasKey('missing', $f, 'no manual price for 130 mm');
        self::assertSame('CBM', $f['unit']);
    }

    public function testAPiecePriceIsComparedWithThePerCbmPriceNotRefusedAsTooLow(): void
    {
        $order = $this->order();
        $sel = $this->thick13();
        $f = OrderLinePricing::liveFigures(ProductRepository::find($this->product), OrderLinePricing::context($order), $sel, 130.0);
        $perPieceMin = (float) $f['minimum'] * 0.1872;
        $size = $this->lineSize('180.00', '80.00', '13.00');
        self::assertNull(OrderLinePricing::saveRefusal($order, $this->product, 'goods', number_format($perPieceMin + 1, 2, '.', ''), true, $sel, 'PCS', null, $size));
        $refusal = (string) OrderLinePricing::saveRefusal($order, $this->product, 'goods', number_format($perPieceMin - 1, 2, '.', ''), true, $sel, 'PCS', null, $size);
        self::assertStringContainsString('below what NexaCrest can accept', $refusal);
        self::assertStringContainsString('per piece', $refusal, 'the minimum is shown per piece, with the per-CBM figure');
        self::assertStringNotContainsString('set the unit to CBM', $refusal);
    }

    public function testAMissingSizeIsAskedForNeverTooLow(): void
    {
        $refusal = (string) OrderLinePricing::saveRefusal($this->order(), $this->product, 'goods', '1.00', true, $this->thick13(), 'PCS', null, $this->lineSize(null, '80.00', '13.00'));
        self::assertSame('Enter the length (cm) — needed to compare a price per piece with the product\'s price per CBM.', $refusal);
    }

    public function testASavedLineWithoutItsSizeHoldsTheQuotationButIsNotSentToTheMd(): void
    {
        $orderId = (int) $this->order()['id'];
        $line = $this->line($orderId, $this->thick13(), 'PCS', $this->lineSize(null, null, '13.00'), '495.00');
        OrderLinePricing::afterSave($line, null, $this->admin);
        $check = OrderLinePricing::forOrder($orderId)['lines'][$line];
        self::assertSame(OrderLinePricing::NEEDS_SIZE, $check['status']);
        self::assertEmpty(OrderProductRepository::find($line)['price_approval_requested_at'], 'not sent to the MD');
        self::assertStringContainsString('needs its piece size', (string) OrderLinePricing::documentBlockReason($orderId, 'QT'));
    }

    public function testASetCannotBeConvertedAndGoesToTheMd(): void
    {
        $orderId = (int) $this->order()['id'];
        $line = $this->line($orderId, $this->thick13(), 'SET', $this->lineSize(null, null, '13.00'), '495.00');
        OrderLinePricing::afterSave($line, null, $this->admin);
        $check = OrderLinePricing::forOrder($orderId)['lines'][$line];
        self::assertSame(OrderLinePricing::NO_PRICE, $check['status']);
        self::assertStringContainsString('cannot be converted', (string) $check['note']);
        self::assertNotEmpty(OrderProductRepository::find($line)['price_approval_requested_at'], 'sent to the MD');
    }

    public function testTheCheckShowsTheReferenceAndTheConvertedFigures(): void
    {
        $orderId = (int) $this->order()['id'];
        $sel = $this->thick13();
        $ref = OrderLinePricing::liveFigures(ProductRepository::find($this->product), OrderLinePricing::context($this->order()), $sel, 130.0);
        $line = $this->line($orderId, $sel, 'PCS', $this->lineSize('180.00', '80.00', '13.00'), number_format((float) $ref['quote'] * 0.1872, 2, '.', ''));
        $check = OrderLinePricing::forOrder($orderId)['lines'][$line];
        self::assertSame(OrderLinePricing::OK, $check['status']);
        self::assertSame('CBM', $check['reference']['unit']);
        self::assertSame('PCS', $check['figures']['unit']);
        self::assertEqualsWithDelta((float) $ref['quote'] * 0.1872, (float) $check['figures']['quote'], 0.0001);
        self::assertEqualsWithDelta(0.1872, $check['conversion']['factor'], 1e-9);
        self::assertEqualsWithDelta((float) $ref['quote'], (float) $check['conversion']['price_ref'], 0.03);
    }

    public function testTonUsesTheCategoryWeightAndTheProductOverride(): void
    {
        self::assertSame(2.7, OrderLinePricing::weightPerCbm(ProductRepository::find($this->product)), 'Natural Stone 2.7 t per CBM');
        SpecRepository::setProductFields($this->product, null, 'slab,tile', null, '3.000');
        self::assertSame(3.0, OrderLinePricing::weightPerCbm(ProductRepository::find($this->product)));
    }

    public function testDuplicateIsAnExactCopyRightBelowWithoutTheApproval(): void
    {
        $orderId = (int) $this->order()['id'];
        $sel = $this->thick13();
        $first = $this->line($orderId, $sel, 'PCS', $this->lineSize('180.00', '80.00', '13.00'), '495.00');
        $second = $this->line($orderId, null, 'SQM', $this->lineSize(null, null, null), '58.00');
        [$prId] = PriceRequestService::create(AssociateRepository::find($this->associate), ProductRepository::find($this->product),
            $this->sel(['Grade / quality' => 'Standard', 'Thickness' => '30 mm', 'Surface finish' => 'Leather']), false, null);
        self::assertNotNull($prId);
        Database::connection()->prepare("UPDATE order_products SET material = 'Granite', zoho_item_id = 'TEST-ITEM-1', price_request_id = :pr,
            price_snapshot = '{\"quote\":1}', price_approved_by = :u, price_approved_at = NOW(), price_approved_unit_price = 495.00, price_approval_reason = 'Test approval reason' WHERE id = :id")
            ->execute(['pr' => $prId, 'u' => $this->admin, 'id' => $first]);

        $copy = OrderProductRepository::duplicate($first);
        $c = OrderProductRepository::find($copy);
        $src = OrderProductRepository::find($first);
        self::assertSame(2, (int) $c['line_no'], 'right below line 1');
        self::assertSame(3, (int) OrderProductRepository::find($second)['line_no'], 'the next line moves down');
        foreach (['spec_selection', 'piece_length_cm', 'piece_width_cm', 'piece_thickness_cm', 'material', 'price_request_id', 'zoho_item_id', 'catalog_product_id', 'unit', 'unit_price', 'quantity'] as $k) {
            self::assertEquals($src[$k], $c[$k], "copies {$k}");
        }
        self::assertSame($first, (int) $c['copied_from_id']);
        self::assertSame(1, OrderProductRepository::copiedFromLineNo($c, OrderProductRepository::forOrder($orderId)));
        self::assertNull($c['price_approved_at'], 'the MD\'s approval is not copied');
        self::assertNull($c['price_snapshot'], 'the figures fixed at PI are not copied');
    }

    public function testACopyBelowTheLowestPriceGoesToTheMdAtOnce(): void
    {
        $orderId = (int) $this->order()['id'];
        $sel = $this->thick13();
        $ref = OrderLinePricing::liveFigures(ProductRepository::find($this->product), OrderLinePricing::context($this->order()), $sel, 130.0);
        $belowLowest = number_format(((float) $ref['floor'] + (float) $ref['minimum']) / 2 * 0.1872, 2, '.', '');
        $first = $this->line($orderId, $sel, 'PCS', $this->lineSize('180.00', '80.00', '13.00'), $belowLowest);
        $line = OrderProductRepository::find($first);
        Database::connection()->prepare('UPDATE order_products SET price_approved_by = :u, price_approved_at = NOW(), price_approved_unit_price = :p, price_approved_selection = :k, price_approval_reason = :r WHERE id = :id')
            ->execute(['u' => $this->admin, 'p' => $belowLowest, 'k' => OrderLinePricing::approvalSelectionKey($line), 'r' => 'Test approval reason', 'id' => $first]);
        self::assertSame(OrderLinePricing::APPROVED, OrderLinePricing::forOrder($orderId)['lines'][$first]['status']);

        $copy = OrderProductRepository::duplicate($first);
        OrderLinePricing::afterSave($copy, null, $this->admin);
        self::assertSame(OrderLinePricing::BELOW, OrderLinePricing::forOrder($orderId)['lines'][$copy]['status']);
        self::assertNotEmpty(OrderProductRepository::find($copy)['price_approval_requested_at'], 'the copy is sent to the MD');
    }

    public function testWholeOrderDuplicateCarriesTheOptionsAndSizes(): void
    {
        $orderId = (int) $this->order()['id'];
        $first = $this->line($orderId, $this->thick13(), 'PCS', $this->lineSize('180.00', '80.00', '13.00'), '495.00');
        $newOrder = OrderDuplicationService::duplicate($orderId, $this->admin);
        $copy = OrderProductRepository::forOrder($newOrder)[0];
        $src = OrderProductRepository::find($first);
        self::assertSame($src['spec_selection'], $copy['spec_selection']);
        self::assertEquals([$src['piece_length_cm'], $src['piece_width_cm'], $src['piece_thickness_cm']], [$copy['piece_length_cm'], $copy['piece_width_cm'], $copy['piece_thickness_cm']]);
        self::assertNull($copy['copied_from_id'], 'a copy on another order is not "copy of line N"');
    }

    public function testOptionsAreShownAndASwitchedOffOptionIsNeverReplaced(): void
    {
        $orderId = (int) $this->order()['id'];
        $sel = $this->sel(['Grade / quality' => 'Standard', 'Thickness' => '30 mm', 'Surface finish' => 'Honed']);
        $line = $this->line($orderId, $sel, 'SQM', $this->lineSize(null, null, null), '58.00');
        $chips = OrderLinePricing::optionChips(OrderProductRepository::find($line));
        self::assertSame(['Standard', '30 mm', 'Honed'], array_column($chips, 'name'));
        $honed = $sel[array_search('Honed', array_map(static fn (int $o): string => (string) SpecRepository::findOption($o)['name'], $sel), true)];
        SpecRepository::setOptionOverride($this->product, $honed, true, null);
        $chips = OrderLinePricing::optionChips(OrderProductRepository::find($line));
        self::assertSame(['name' => '"Honed" is no longer offered — choose again', 'gone' => true], $chips[2]);
        self::assertSame(OrderLinePricing::NO_PRICE, OrderLinePricing::forOrder($orderId)['lines'][$line]['status'], 'never priced as another option');
    }

    public function testTheSummaryBarAndTheTotalsRow(): void
    {
        $l = static fn (int $no, string $status): array => ['status' => $status, 'line' => ['line_no' => $no]];
        self::assertSame('2 lines OK · 1 line needs MD approval (line 3) — the Quotation waits for it.',
            OrderLinePricing::summary([$l(1, 'ok'), $l(2, 'approved'), $l(3, 'below')])['text']);
        self::assertSame('1 line OK · 2 lines need their piece size (lines 2, 3) — the Quotation waits for them.',
            OrderLinePricing::summary([$l(1, 'ok'), $l(2, 'needs_size'), $l(3, 'needs_size')])['text']);
        self::assertSame(['text' => '1 line OK.', 'blocking' => false], OrderLinePricing::summary([$l(1, 'ok')]));
        self::assertNull(OrderLinePricing::summary([$l(1, 'unchecked')]));

        $lines = [
            ['id' => 1, 'line_type' => 'goods', 'quantity' => '40', 'quantity_is_tbc' => 0, 'unit' => 'PCS', 'fob_value' => '19800.00', 'piece_length_cm' => '180', 'piece_width_cm' => '80', 'piece_thickness_cm' => '13'],
            ['id' => 2, 'line_type' => 'goods', 'quantity' => '60', 'quantity_is_tbc' => 0, 'unit' => 'PCS', 'fob_value' => '19200.00', 'piece_length_cm' => '140', 'piece_width_cm' => '70', 'piece_thickness_cm' => '13'],
            ['id' => 3, 'line_type' => 'goods', 'quantity' => '400', 'quantity_is_tbc' => 0, 'unit' => 'SQM', 'fob_value' => '23200.00', 'piece_length_cm' => null, 'piece_width_cm' => null, 'piece_thickness_cm' => null],
            ['id' => 4, 'line_type' => 'charge', 'quantity' => '1', 'quantity_is_tbc' => 0, 'unit' => 'LOT', 'fob_value' => '500.00'],
        ];
        $t = OrderLinePricing::totals($lines, [3 => ['reference' => ['thickness_mm' => 20]]]);
        self::assertSame(62200.0, $t['amount'], 'goods only');
        self::assertSame(23.13, $t['cbm'], '7.488 + 7.644 + 400 m² × 2 cm');
        self::assertSame(3, $t['containers'], '8 CBM each');
        self::assertSame(0, $t['uncounted']);
    }
}
