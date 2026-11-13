<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociateAdminController;
use App\Controllers\PricingController;
use App\Controllers\ProductController;
use App\Helpers\Flash;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\SpecRepository;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Services\PriceRequestService;
use App\Services\PricingService;
use App\Services\ProductSpecs;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-256 (5 Nov 2026): pricing made easier to set up and check — an option
 * worth 0 reads "Included in base price", "Pricing ready?" with fix links, "Try a price",
 * help text with examples, exact supplier prices for a specific combination, and the GST
 * tax on an associate's commission bill in Zoho. Figures are the ones in the directive:
 * ₹3,200 ÷ 82 + 2.5% = USD 40.00. Node twin: tests/integration/d256PricingUsability.test.js.
 */
final class D256PricingUsabilityTest extends DbTestCase
{
    private int $admin;
    private int $product;
    private int $associate;
    private int $unitSupplier;
    private int $cbmSupplier;

    private const SQM = ['Grade / quality' => 'Standard', 'Thickness' => '30 mm', 'Surface finish' => 'Leather', 'Quantity' => '2–4 containers'];
    private const PREMIUM = ['Grade / quality' => 'Premium', 'Thickness' => '30 mm', 'Surface finish' => 'Leather', 'Quantity' => '2–4 containers'];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
        $pdo = Database::connection();
        $pdo->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
        $this->product = ProductRepository::create(['name' => 'D256 Black Galaxy ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], $this->admin);
        SpecRepository::setProductFields($this->product, null, 'slab,tile', null);
        $this->unitSupplier = $this->supplier(3200.0, 'unit');
        $this->cbmSupplier = $this->supplier(53300.0, 'cbm');
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id) VALUES (:n, 'active', 'fixed', :l)")
            ->execute(['n' => 'D256 Associate ' . bin2hex(random_bytes(3)), 'l' => (int) $pdo->query("SELECT id FROM commission_levels WHERE name = 'Level 2'")->fetchColumn()]);
        $this->associate = (int) $pdo->lastInsertId();
        PriceRequestService::saveAccess($this->associate, [(int) SpecRepository::systemCategoryId() => ['slab', 'tile']]);
    }

    protected function tearDown(): void
    {
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
        IncentiveRepository::setSetting('zoho_gst_commission_tax_id', '', $this->admin);
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        Flash::pull();
        PermissionService::resetCache();
        parent::tearDown();
    }

    private function supplier(float $inr, string $basis, string $name = 'Ongole Quarry'): int
    {
        $s = ProductSupplierRepository::create(['product_id' => $this->product, 'supplier_name' => $name . ' ' . $basis, 'location' => 'Andhra Pradesh', 'contact_person' => null,
            'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => $inr, 'factory_cost' => null, 'transportation_cost' => null,
            'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => $basis === 'unit' ? 1 : 0, 'notes' => null], $this->admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        if ($basis === 'cbm') {
            PricingRepository::setSupplierBasis($s, 'cbm');
            PricingRepository::setCbmPrimary($this->product, $s);
        }
        return $s;
    }

    /** An exact supplier price for the named options (specification name => option name), per unit, in use. */
    private function exact(float $inr, array $byName, string $quotedOn, ?string $validUntil = null): int
    {
        $s = $this->supplier($inr, 'unit', 'Exact quarry');
        PricingRepository::setCombo($s, $this->sel($byName), 'unit', true);
        PricingRepository::setSupplierDates($s, $quotedOn, $validUntil);
        return $s;
    }

    /** @param array<string,string> $byName specification name => option name */
    private function sel(array $byName): array
    {
        $out = [];
        foreach (ProductSpecs::offered(ProductRepository::find($this->product)) as $s) {
            if (isset($byName[$s['name']])) {
                foreach ($s['options'] as $o) {
                    if ($o['name'] === $byName[$s['name']]) {
                        $out[(int) $s['id']] = (int) $o['id'];
                    }
                }
            }
        }
        self::assertCount(count($byName), $out, 'every named option is offered');
        return $out;
    }

    private function opt(string $spec, string $option): array
    {
        $stmt = Database::connection()->prepare('SELECT o.* FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id WHERE s.name = :s AND o.name = :o AND o.product_id IS NULL AND s.product_id IS NULL');
        $stmt->execute(['s' => $spec, 'o' => $option]);
        return $stmt->fetch();
    }

    private function price(array $byName): array
    {
        return PricingService::forProduct(ProductRepository::find($this->product), null, null, null, $this->sel($byName));
    }

    private static function level(array $p, string $name): array
    {
        foreach ($p['levels'] as $l) {
            if ($l['name'] === $name) {
                return $l;
            }
        }
        self::fail("no {$name}");
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function render(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private function readyItem(array $r, string $key): ?array
    {
        foreach ($r['items'] as $i) {
            if ($i['key'] === $key) {
                return $i;
            }
        }
        return null;
    }

    public function testAnOptionWorthZeroReadsIncludedInBasePrice(): void
    {
        self::assertSame('Included in base price', ProductSpecs::changeLabel('pct', 0.0));
        self::assertSame('Included in base price', ProductSpecs::changeLabel('usd_m2', 0.0));
        self::assertSame('+35%', ProductSpecs::changeLabel('pct', 35.0));
        $standard = $this->opt('Grade / quality', 'Standard');
        SpecRepository::setOptionOverride($this->product, (int) $standard['id'], false, '0');
        $me = AssociateRepository::find($this->associate);
        $w = PriceRequestService::work($me, ProductRepository::find($this->product), $this->sel(self::SQM));
        self::assertContains(['label' => 'Standard (grade / quality)', 'change' => 'Included in base price'], $w['breakdown']);
        // Standard at 0 prices the same as Premium (the base).
        self::assertSame((float) PriceRequestService::work($me, ProductRepository::find($this->product), $this->sel(self::PREMIUM))['quote'], (float) $w['quote']);
    }

    public function testTryAPriceGivesTheSameFiguresAsGetPriceAndTheOrderPage(): void
    {
        $p = $this->price(self::SQM);
        self::assertSame(69.76, $p['build']['staff_quote']);
        self::assertSame([71.30, 73.43], [self::level($p, 'Level 2')['floor'], self::level($p, 'Level 2')['quote']]);
        $w = PriceRequestService::work(AssociateRepository::find($this->associate), ProductRepository::find($this->product), $this->sel(self::SQM));
        self::assertSame([73.43, 71.30], [(float) $w['quote'], (float) $w['lowest']], 'the associate\'s Get price');
        $staffOrder = Database::connection()->query('SELECT * FROM orders WHERE id = ' . $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD'))->fetch();
        self::assertSame(65.76, $p['build']['minimum']);
        self::assertStringContainsString('Minimum price: USD 65.76', (string) OrderLinePricing::saveRefusal($staffOrder, $this->product, 'goods', '65.75', true, $this->sel(self::SQM), 'SQM'), 'the order page refuses below the same minimum');
        self::assertNull(OrderLinePricing::saveRefusal($staffOrder, $this->product, 'goods', '65.76', true, $this->sel(self::SQM), 'SQM'));

        // The product page: the MD works it out with Try a price.
        $this->actAs($this->createTestUser('Managing Director'));
        $_GET = ['try' => array_map('strval', $this->sel(self::SQM))];
        $page = $this->render(fn () => (new ProductController())->show(['id' => (string) $this->product]));
        foreach (['Try a price', 'Pricing ready?', 'USD 69.76', '71.30 · <strong>73.43</strong>', 'Leather +USD 4.00'] as $s) {
            self::assertStringContainsString($s, $page, "MD sees {$s}");
        }
    }

    public function testTheExactPriceForACombinationIsUsed(): void
    {
        $this->exact(4900.0, ['Thickness' => '30 mm', 'Surface finish' => 'Leather'], date('Y-m-d'));
        $p = $this->price(self::PREMIUM);
        self::assertSame('30 mm · Leather', $p['exact']);
        self::assertSame(4900.0, (float) $p['supplier_inr']);
        self::assertEqualsWithDelta(60.025, $p['build']['raw']['cost'], 0.0001, '₹4,900 ÷ 82 + 2.5%, −2% for 2–4 containers');
        self::assertSame([77.28, 79.60], [$p['build']['staff_floor'], $p['build']['staff_quote']]);
        self::assertSame([81.35, 83.79], [self::level($p, 'Level 2')['floor'], self::level($p, 'Level 2')['quote']]);
        $w = PriceRequestService::work(AssociateRepository::find($this->associate), ProductRepository::find($this->product), $this->sel(self::PREMIUM));
        self::assertSame([83.79, 81.35], [(float) $w['quote'], (float) $w['lowest']]);
        self::assertContains(['label' => 'Exact price for 30 mm · Leather', 'change' => 'used'], $w['breakdown']);
        self::assertContains(['label' => 'Leather (surface finish)', 'change' => 'Included (in the exact price)'], $w['breakdown']);
        // Options it does not name still apply: Standard −8%.
        self::assertEqualsWithDelta(61.25 * 0.92 * 0.98, $this->price(self::SQM)['build']['raw']['cost'], 0.0001);
        // A combination it does not match: the ordinary price.
        $polished = $this->price(['Grade / quality' => 'Premium', 'Thickness' => '30 mm', 'Surface finish' => 'Natural Polish']);
        self::assertNull($polished['exact']);
        self::assertSame(3200.0, (float) $polished['supplier_inr']);
    }

    public function testTheMostSpecificThenTheNewestWinsAndStaleOrExpiredOnesAreIgnored(): void
    {
        $this->exact(4000.0, ['Thickness' => '30 mm'], date('Y-m-d'));
        $both = $this->exact(4900.0, ['Thickness' => '30 mm', 'Surface finish' => 'Leather'], date('Y-m-d', strtotime('-10 days')));
        self::assertSame(4900.0, (float) $this->price(self::PREMIUM)['supplier_inr'], 'two named options beat one, even if older');
        $newer = $this->exact(5100.0, ['Thickness' => '30 mm', 'Surface finish' => 'Leather'], date('Y-m-d', strtotime('-2 days')));
        self::assertSame(5100.0, (float) $this->price(self::PREMIUM)['supplier_inr'], 'a tie goes to the most recently quoted');
        // Expired: ignored, back to the older one; that one stale: ignored too.
        PricingRepository::setSupplierDates($newer, date('Y-m-d', strtotime('-2 days')), date('Y-m-d', strtotime('-1 day')));
        self::assertSame(4900.0, (float) $this->price(self::PREMIUM)['supplier_inr']);
        PricingRepository::setSupplierDates($both, date('Y-m-d', strtotime('-200 days')), null);
        $p = $this->price(self::PREMIUM);
        self::assertSame(['30 mm', 4000.0], [$p['exact'], (float) $p['supplier_inr']], 'falls back to the 30 mm price');
        self::assertEqualsWithDelta((50.0 + 4.0) * 0.98, $p['build']['raw']['cost'], 0.0001, 'Leather +USD 4 still applies on top');
        // Stop using it: the ordinary price again.
        PricingRepository::setComboInUse((int) array_key_first(array_filter(
            array_column(ProductSupplierRepository::forProduct($this->product), 'fob_value', 'id'), static fn ($v) => (float) $v === 4000.0)), false);
        self::assertSame([null, 3200.0], [$this->price(self::PREMIUM)['exact'], (float) $this->price(self::PREMIUM)['supplier_inr']]);
        // A per-unit exact price is never used for a per-CBM request.
        $cbm = $this->price(['Grade / quality' => 'Premium', 'Thickness' => '80 mm', 'Surface finish' => 'Leather']);
        self::assertSame([null, 53300.0], [$cbm['exact'], (float) $cbm['supplier_inr']]);
    }

    public function testPricingReadyChecklist(): void
    {
        // D-365: the configured base price replaces "supplier price per unit used for pricing".
        Database::connection()->prepare("UPDATE catalog_products SET configured_base_follows_supplier = 0, configured_base_price_inr = 3200, configured_base_thickness_mm = NULL, configured_base_unit = 'SQM' WHERE id = :id")->execute(['id' => $this->product]);
        $r = PricingService::readiness(ProductRepository::find($this->product));
        self::assertSame(0, $r['to_fix'], 'base price, active supplier, current, specifications, CBM, open to associates');
        self::assertSame(['base', 'suppliers', 'current', 'specs', 'cbm', 'associates'], array_column($r['items'], 'key'));
        self::assertSame('Configured base price set (₹3,200 / SQM)', $this->readyItem($r, 'base')['label']);
        // No CBM price, a stale unit price, no associate access.
        Database::connection()->prepare('DELETE FROM catalog_product_suppliers WHERE id = :id')->execute(['id' => $this->cbmSupplier]);
        PricingRepository::setSupplierDates($this->unitSupplier, date('Y-m-d', strtotime('-90 days')), null);
        Database::connection()->prepare('DELETE FROM associate_price_access WHERE category_id = :c')->execute(['c' => (int) SpecRepository::systemCategoryId()]);
        $r = PricingService::readiness(ProductRepository::find($this->product));
        self::assertFalse($this->readyItem($r, 'cbm')['ok']);
        self::assertSame("/products/{$this->product}/market-prices?basis=cbm#add-price", $this->readyItem($r, 'cbm')['link'], 'D-365: "Add a 50 mm+ price"');
        self::assertFalse($this->readyItem($r, 'current')['ok']);
        self::assertStringContainsString('stale', $this->readyItem($r, 'current')['detail']);
        self::assertFalse($this->readyItem($r, 'associates')['ok']);
        self::assertSame(3, $r['to_fix']);
        // An exact price that has expired is flagged.
        $this->exact(4900.0, ['Thickness' => '30 mm'], date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('-1 day')));
        self::assertFalse($this->readyItem(PricingService::readiness(ProductRepository::find($this->product)), 'exact')['ok']);

        // The list badge for the MD; Procurement sees only the supplier-price lines; sales sees none of it.
        $this->actAs($this->createTestUser('Managing Director'));
        $list = $this->render(fn () => (new ProductController())->index([]));
        self::assertStringContainsString("/products/{$this->product}#pricing-ready", $list);
        self::assertStringContainsString('Pricing: 4 to fix', $list);
        $this->actAs($this->createTestUser('Procurement Executive'));
        $page = $this->render(fn () => (new ProductController())->show(['id' => (string) $this->product]));
        self::assertStringContainsString('Pricing ready?', $page);
        self::assertStringContainsString('No price for 50 mm and above (per CBM)', $page);
        foreach (['Product open to associates', 'Specifications checked', 'Try a price', 'Staff floor', 'Minimum price'] as $s) {
            self::assertStringNotContainsString($s, $page, "Procurement does not see {$s}");
        }
        $this->actAs($this->createTestUser('Export Executive'));
        $page = $this->render(fn () => (new ProductController())->show(['id' => (string) $this->product]));
        foreach (['Pricing ready?', 'Try a price', '3,200', 'Ongole', 'Exact quarry'] as $s) {
            self::assertStringNotContainsString($s, $page, "sales never sees {$s}");
        }
        $list = $this->render(fn () => (new ProductController())->index([]));
        self::assertStringNotContainsString('to fix', $list);
    }

    public function testExactPricesInMarketPricesForProcurementWithoutSellingPrices(): void
    {
        $this->actAs($this->createTestUser('Procurement Executive'));
        // D-303 (point 15): the supplier is a record of the Suppliers directory (matched by its exact name).
        $quarry = 'Exact quarry ' . bin2hex(random_bytes(3));
        \App\Repositories\SupplierRepository::create(['supplier_legal_name' => $quarry, 'types' => []], null);
        $_POST = ['supplier_name' => $quarry, 'price_inr' => '4900', 'quoted_on' => date('Y-m-d'), 'valid_until' => '', 'price_basis' => 'combo', 'used_for_pricing' => '1',
            'combo' => array_map('strval', $this->sel(['Thickness' => '30 mm', 'Surface finish' => 'Leather'])), 'location' => '', 'notes' => ''];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $this->product]));
        $row = array_values(array_filter(ProductSupplierRepository::forProduct($this->product), static fn ($s) => $s['price_basis'] === 'combo'));
        self::assertCount(1, $row, json_encode(Flash::pull()));
        self::assertSame(['unit', 1], [(string) $row[0]['combo_per'], (int) $row[0]['combo_in_use']]);
        $_POST = [];
        $page = $this->render(fn () => (new PricingController())->marketPrices(['id' => (string) $this->product]));
        self::assertStringContainsString('Exact', $page);
        self::assertStringContainsString('30 mm · Leather', $page);
        foreach (['79.60', '77.28', '83.79', 'Staff floor', 'margin'] as $s) {
            self::assertStringNotContainsString($s, $page, "Procurement never sees {$s}");
        }
        // Naming nothing is refused.
        $_POST = ['supplier_name' => $quarry, 'price_inr' => '4900', 'quoted_on' => date('Y-m-d'), 'valid_until' => '', 'price_basis' => 'combo', 'combo' => [], 'location' => '', 'notes' => ''];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $this->product]));
        self::assertCount(1, array_filter(ProductSupplierRepository::forProduct($this->product), static fn ($s) => $s['price_basis'] === 'combo'));
    }

    public function testHelpTextWithAnExampleBesideEverySetting(): void
    {
        $this->actAs($this->createTestUser('Managing Director'));
        $settings = $this->render(fn () => (new PricingController())->settings([]));
        self::assertGreaterThanOrEqual(11, substr_count($settings, 'class="help-line"'));
        self::assertStringContainsString('Example:', $settings);
        $ladder = $this->render(fn () => (new AssociateAdminController())->ladder([]));
        self::assertGreaterThanOrEqual(7, substr_count($ladder, 'class="help-line"'));
        self::assertStringContainsString('Zoho GST tax for commission', $ladder);
    }

    public function testTheZohoCommissionBillCarriesTheGstTaxAndIsRefusedWithoutOne(): void
    {
        $pdo = Database::connection();
        $pdo->prepare("UPDATE associates SET name = :n WHERE id = :id")->execute(['n' => 'TEST-D256 Associate ' . bin2hex(random_bytes(2)), 'id' => $this->associate]);
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo->prepare("INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status, paid_inr, gst_inr, paid_on, paid_reference)
                       VALUES (:o, 'associate', :a, 'associate', 10000, '2026-10', 'paid', 10000, 1800, '2026-11-02', 'UTR778899')")
            ->execute(['o' => $orderId, 'a' => $this->associate]);
        $ref = (string) $pdo->query("SELECT buyer_inquiry_ref FROM orders WHERE id = {$orderId}")->fetchColumn();
        $cost = ['order_id' => $orderId, 'buyer_inquiry_ref' => $ref, 'buyer_name' => 'TEST-Buyer'];
        $c = ZohoBooksService::associateCommission($cost);
        self::assertSame([1800.0, null], [$c['gst_inr'], $c['gst_tax_id']]);
        try {
            ZohoBooksService::pushAssociateCommission($cost);
            self::fail('a bill with GST and no Zoho GST tax must be refused');
        } catch (\RuntimeException $e) {
            self::assertSame("Associate commission on order {$ref} not sent: GST of INR 1800.00 was paid on the associate's tax invoice, but no Zoho GST tax is chosen for commission (Associates → Commission settings → Zoho GST tax for commission). Choose it, then sync again.", $e->getMessage());
        }

        // The setting is saved from Commission settings (MD), validated.
        $this->actAs($this->createTestUser('Managing Director'));
        IncentiveRepository::setSetting('zoho_gst_commission_tax_id', '460000000067890', $this->admin);
        $c = ZohoBooksService::associateCommission($cost);
        self::assertSame('460000000067890', $c['gst_tax_id']);
        $bill = ZohoBooksService::associateBillPayload($c, $cost, 'V1', 'ACC1');
        self::assertSame(['account_id' => 'ACC1', 'name' => 'Associate commission', 'description' => $c['bill_description'], 'rate' => 10000.0, 'quantity' => 1, 'tax_id' => '460000000067890'],
            $bill['line_items'][0]);
        self::assertArrayNotHasKey('tds_tax_id', $bill);
        $pay = ZohoBooksService::associatePaymentPayload($c, 'V1', 'B1', 'BANK1');
        self::assertSame([11800.0, [['bill_id' => 'B1', 'amount_applied' => 11800.0]]], [$pay['amount'], $pay['bills']], 'commission + GST paid on top');
        // No GST: no tax on the line, the payment is what was paid.
        $c['gst_inr'] = 0.0;
        self::assertArrayNotHasKey('tax_id', ZohoBooksService::associateBillPayload($c, $cost, 'V1', 'ACC1')['line_items'][0]);
        self::assertSame(10000.0, ZohoBooksService::associatePaymentPayload($c, 'V1', 'B1', 'BANK1')['amount']);
    }
}
