<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\PricingController;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\SupplierRepository;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Services\ProductService;
use App\Services\ThicknessPrices;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-335 (12 Nov 2026, mockups approved "OK build") — THE ONE RULE:
 *  1. a price the user TYPED always wins — no rule ever changes it;
 *  2. each supplier price has a BASE thickness the user chose; the product-level price and order
 *     lines without a thickness use it;
 *  3. the price rules are only a CALCULATOR: a thickness ticked while adding a price is stored as
 *     CALCULATED ("from 20 mm"); an unticked one keeps its price (or stays "no price").
 * Also: rows saved before D-335 (auto_fill 1) keep the old on-the-fly completion until the first
 * new price; migration 2026-11-12b turns "add up the cost parts" rows into typed prices with the
 * same total. Owner example: 50 mm+ typed ₹1,80,000 / CBM, CHA ₹80,000 per container of 8.2 CBM,
 * rate 95, margin 8 %, other 0 %, buffer 3 %.
 * Node twin: tests/integration/d335SupplierPricesBase.test.js.
 */
final class D335SupplierPricesBaseTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
        $pdo = Database::connection();
        foreach (['fx_mode' => 'manual', 'pricing_fx_rate' => '95'] as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        http_response_code(200);
        parent::tearDown();
    }

    /** Natural Stone's seeded rules (pure tests). */
    private static function rules(array $extra = []): array
    {
        return $extra + [
            'grid' => true, 'base_mm' => 20, 'k' => 40.0, 'cbm_from' => 50,
            'rows' => [['mm' => 15, 'pct' => -20.0, 'option' => '15 mm'], ['mm' => 18, 'pct' => -5.0, 'option' => '18 mm'], ['mm' => 20, 'pct' => 0.0, 'option' => '20 mm'],
                ['mm' => 30, 'pct' => 35.0, 'option' => '30 mm'], ['mm' => 40, 'pct' => 70.0, 'option' => '40 mm']],
            'container_cbm' => 8.2, 'container_max_t' => 27.0, 't_per_cbm' => 2.7, 'own' => [], 'category_id' => 1, 'category_name' => 'Natural Stone', 'unit' => 'CBM',
        ];
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

    /** The owner's product: sold per CBM, CHA + transport ₹80,000 per container of 8.2 CBM, margin 8 %, other 0 %, buffer 3 %. */
    private function product(): int
    {
        $id = ProductRepository::create(['name' => 'D335 Absolute Black ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022310', 'reference_unit' => 'CBM'], $this->admin);
        PricingRepository::saveProductOverrides($id, ['pricing_margin_pct' => '8', 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
            'pricing_incentive_mode' => 'pct', 'pricing_incentive_value' => '0', 'pricing_buffer_pct' => '3']);
        ProductRepository::setPriceRuleFields($id, ['cha_transport_per_container' => '80000.00', 'container_cbm' => '8.20']);
        return $id;
    }

    private function gridRow(int $productId): array
    {
        return array_values(array_filter(ProductSupplierRepository::forProduct($productId), static fn ($s) => $s['price_basis'] === 'grid'))[0];
    }

    /** @return array<int, array{0: float, 1: string, 2: ?int}> */
    private static function stored(int $rowId): array
    {
        return array_map(static fn (array $s): array => [$s['price'], $s['how'], $s['calc_from_mm']], ThicknessPrices::storedFor($rowId));
    }

    public function testTheCalculatorShowsTheResultAndHowItIsWorkedOut(): void
    {
        $c = ThicknessPrices::calcFrom(self::rules(), 20, 4600.0);
        self::assertTrue($c['ok']);
        self::assertSame([3680.0, '= 4,600 − 20 %', '−20 %'], [$c['values'][15]['price'], $c['values'][15]['how'], $c['values'][15]['change']]);
        self::assertSame([4370.0, 6210.0, 7820.0], [$c['values'][18]['price'], $c['values'][30]['price'], $c['values'][40]['price']]);
        self::assertSame([184000.0, '= 20 mm price ₹4,600 per SQM × 40', '× 40'], [$c['values'][0]['price'], $c['values'][0]['how'], $c['values'][0]['change']]);
        self::assertArrayNotHasKey(20, $c['values'], 'the typed thickness itself is not worked out');
        // From the 50 mm+ price per CBM: 20 mm = CBM ÷ 40, then the %.
        $c = ThicknessPrices::calcFrom(self::rules(), 0, 180000.0);
        self::assertSame([4500.0, 3600.0], [$c['values'][20]['price'], $c['values'][15]['price']]);
        self::assertSame('= 20 mm price ₹4,500 (= 180,000 per CBM ÷ 40)', $c['values'][20]['how']);
        self::assertSame('= 20 mm price ₹4,500 (= 180,000 per CBM ÷ 40) − 20 %', $c['values'][15]['how']);
        self::assertSame('Choose a thickness from the list.', ThicknessPrices::calcFrom(self::rules(), 25, 100.0)['error']);
    }

    public function testTheBaseIsUsedAndAThicknessWithoutAStoredPriceHasNoPrice(): void
    {
        $r = self::rules();
        $row = ['id' => 1, 'cha_transport_per_container' => '80000', 'includes_packing_loading' => 1, 'auto_fill' => 0, 'base_thickness_mm' => 0];
        $stored = [0 => ['price' => 180000.0, 'how' => 'typed'], 20 => ['price' => 4600.0, 'how' => 'typed'], 30 => ['price' => 6210.0, 'how' => 'calculated', 'calc_from_mm' => 20]];
        // No thickness: the base (50 mm and above, per CBM) — 1,80,000 + 9,756.10.
        $c = ThicknessPrices::cost($r, $stored, $row, [], 'unit', null);
        self::assertEqualsWithDelta(189756.10, $c['total'], 0.005);
        self::assertSame('typed', $c['how']);
        // 60 mm block (per CBM): the same price.
        self::assertEqualsWithDelta(189756.10, ThicknessPrices::cost($r, $stored, $row, [], 'cbm', 60.0)['total'], 0.005);
        // A stored 30 mm price is used as it is; 25 mm has no price (goes to the MD).
        self::assertEqualsWithDelta(6210.0 / 0.03 + 9756.0976, ThicknessPrices::cost($r, $stored, $row, [], 'unit', 30.0)['total'], 0.01);
        self::assertNull(ThicknessPrices::cost($r, $stored, $row, [], 'unit', 25.0));
        self::assertNull(ThicknessPrices::cost($r, [20 => 4600.0], $row, [], 'unit', 40.0), 'not stored, not worked out in the background');
        // Base 20 mm: the 20 mm price (per CBM for a product sold per CBM).
        self::assertEqualsWithDelta(4600.0 / 0.02 + 9756.0976, ThicknessPrices::cost($r, $stored, ['base_thickness_mm' => 20] + $row, [], 'unit', null)['total'], 0.01);
        // A row saved before D-335 (auto_fill 1) still works out a missing thickness on the fly.
        self::assertEqualsWithDelta(7820.0 / 0.04 + 9756.0976, ThicknessPrices::cost($r, [20 => 4600.0], ['auto_fill' => 1, 'base_thickness_mm' => 20] + $row, [], 'unit', 40.0)['total'], 0.01);
    }

    public function testOwnerExampleTypedCbmPriceIsTheBaseAndA20mmPriceDoesNotChangeIt(): void
    {
        $productId = $this->product();
        $supId = SupplierRepository::create(['supplier_legal_name' => 'D335 Granites ' . bin2hex(random_bytes(3)), 'city' => 'Test town', 'types' => []], $this->admin);
        $sup = SupplierRepository::find($supId);
        $this->actAs($this->admin);
        // Step 1: the CBM price is typed and becomes the base; nothing is worked out.
        $_POST = ['supplier_id' => (string) $sup['id'], 'supplier_name' => $sup['supplier_legal_name'], 'location' => 'Test town', 'quoted_on' => '2026-11-11', 'valid_until' => '',
            'price_basis' => 'grid', 'thickness_mm' => '0', 'thickness_price' => '180000', 'includes_packing_loading' => '1', 'cha_transport_per_container' => '',
            'used_for_pricing' => '1', 'notes' => ''];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        self::assertSame('Supplier price recorded and used for pricing.', $this->flash());
        $row = $this->gridRow($productId);
        self::assertSame([0, 0], [(int) $row['base_thickness_mm'], (int) $row['auto_fill']]);
        self::assertSame([0 => [180000.0, 'typed', null]], self::stored((int) $row['id']), 'only what was typed exists');

        $p = PricingService::forProduct(ProductRepository::find($productId));
        self::assertEqualsWithDelta(189756.10, $p['supplier_inr'], 0.005);
        self::assertSame([1997.43, 1997.43, 2171.12], [$p['build']['supplier_usd'], $p['build']['cost'], $p['build']['minimum']]);
        self::assertEqualsWithDelta(2236.25, $p['build']['staff_quote'], 0.011);
        self::assertSame(1894.74, round(180000 / 95, 2));

        // Step 2: a 20 mm price of 4,600 with the default ticks (no typed price) — the CBM price is kept.
        $_POST = ['thickness_mm' => '20', 'thickness_price' => '4600.00', 'price_quoted_on' => '2026-11-11', 'also' => ['15', '18', '30', '40'], 'base' => 'keep'];
        $this->render(fn () => (new PricingController())->addThicknessPrice(['supplierId' => (string) $row['id']]));
        self::assertSame('Price saved and 4 worked out from it.', $this->flash());
        self::assertEquals([15 => [3680.0, 'calculated', 20], 18 => [4370.0, 'calculated', 20], 20 => [4600.0, 'typed', null],
            30 => [6210.0, 'calculated', 20], 40 => [7820.0, 'calculated', 20], 0 => [180000.0, 'typed', null]], self::stored((int) $row['id']));
        $row = ProductSupplierRepository::find((int) $row['id']);
        self::assertSame(0, (int) $row['base_thickness_mm'], 'the base stays as chosen');
        $p = PricingService::forProduct(ProductRepository::find($productId));
        self::assertEqualsWithDelta(189756.10, $p['supplier_inr'], 0.005);

        $history = array_map(static fn ($h) => $h['what'] . ': ' . $h['old_value'] . ' → ' . $h['new_value'], ThicknessPrices::historyFor((int) $row['id']));
        self::assertContains('20 mm per SQM: — → 4,600.00 typed', $history);
        self::assertContains('15 mm per SQM: — → 3,680.00 calculated from 20 mm', $history);
        self::assertContains('Base: 20 mm (category) → 50 mm and above', $history);

        // The price card: 20 mm costs 4,600 + CHA 195.12 = 4,795.12 per m²; the summary is from the base.
        $page = $this->render(fn () => (new PricingController())->marketPrices(['id' => (string) $productId]));
        foreach (['4,795.12', '4,600.00 + CHA 195.12', '189,756.10', 'BASE', 'TYPED', 'CALCULATED', 'from 20 mm − 20 %',
            'Product price (from the base):', '₹189,756.10 per CBM · 50 mm and above', 'USD 1,997.43 at ₹95', 'minimum (8 % margin) USD 2,171.12',
            'you typed ₹180,000.00 on 11 Nov 2026 — kept unless you tick'] as $s) {
            self::assertStringContainsString($s, $page);
        }

        // Step 3: a new 20 mm price; 50 mm+ ticked this time → replaced (calculated); 15 mm unticked → kept.
        $_POST = ['thickness_mm' => '20', 'thickness_price' => '5000', 'price_quoted_on' => '2026-11-11', 'also' => ['0'], 'base' => 'keep'];
        $this->render(fn () => (new PricingController())->addThicknessPrice(['supplierId' => (string) $row['id']]));
        $s = self::stored((int) $row['id']);
        self::assertSame([200000.0, 'calculated', 20], $s[0]);
        self::assertSame([3680.0, 'calculated', 20], $s[15], 'an unticked thickness keeps its price');
        self::assertSame([5000.0, 'typed', null], $s[20]);

        // Making 20 mm the base.
        $_POST = ['mm' => '20'];
        $this->render(fn () => (new PricingController())->setBase(['supplierId' => (string) $row['id']]));
        self::assertSame('The base for orders is now 20 mm.', $this->flash());
        self::assertSame(20, (int) ProductSupplierRepository::find((int) $row['id'])['base_thickness_mm']);
    }

    /** Owner bug (11 Nov 2026): "Try a price" starts on the supplier's BASE, the same figure as the product list. */
    public function testTryAPriceStartsOnTheSuppliersBaseAndAnExplicitThicknessPricesThatThickness(): void
    {
        $productId = $this->product();
        $supId = SupplierRepository::create(['supplier_legal_name' => 'D335 Try ' . bin2hex(random_bytes(3)), 'city' => 'Test town', 'types' => []], $this->admin);
        $sup = SupplierRepository::find($supId);
        $this->actAs($this->admin);
        $_POST = ['supplier_id' => (string) $sup['id'], 'supplier_name' => $sup['supplier_legal_name'], 'location' => 'Test town', 'quoted_on' => '2026-11-11', 'valid_until' => '',
            'price_basis' => 'grid', 'thickness_mm' => '0', 'thickness_price' => '180000', 'includes_packing_loading' => '1', 'cha_transport_per_container' => '',
            'used_for_pricing' => '1', 'notes' => ''];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        $row = $this->gridRow($productId);
        $_POST = ['thickness_mm' => '20', 'thickness_price' => '4600.00', 'price_quoted_on' => '2026-11-11', 'base' => 'keep'];
        $this->render(fn () => (new PricingController())->addThicknessPrice(['supplierId' => (string) $row['id']]));
        $_POST = [];
        $thick = null;
        foreach (\App\Services\ProductSpecs::offered(ProductRepository::find($productId)) as $sp) {
            if ($sp['kind'] === 'thickness') {
                $thick = $sp;
            }
        }
        self::assertNotNull($thick, 'the product offers a Thickness');
        $mm20 = array_values(array_filter($thick['options'], static fn ($o) => $o['thickness_mm'] === 20))[0]['id'];

        // Default: the supplier's base (50 mm and above, per CBM) — 1,80,000 + CHA, as on the product list.
        $page = $this->render(fn () => (new \App\Controllers\ProductController())->show(['id' => (string) $productId]));
        $try = substr($page, (int) strpos($page, 'id="try-price"'));
        self::assertStringContainsString('<option value="" selected>Supplier&#039;s base price (50 mm and above)</option>', $try);
        self::assertStringNotContainsString('<option value="' . $mm20 . '" selected>', $try);
        self::assertStringContainsString('Supplier price &#8377;189,756.10', $try);
        self::assertStringContainsString('cost USD 1,997.43', $try);
        self::assertStringContainsString('Per CBM, FOB, USD.', $try);

        // An explicit 20 mm prices the 20 mm price (4,600 per m² = 2,30,000 per CBM) and shows it chosen.
        $_GET = ['try' => [$thick['id'] => (string) $mm20]];
        $page = $this->render(fn () => (new \App\Controllers\ProductController())->show(['id' => (string) $productId]));
        $try = substr($page, (int) strpos($page, 'id="try-price"'));
        self::assertStringContainsString('<option value="' . $mm20 . '" selected>', $try);
        self::assertStringNotContainsString('<option value="" selected>', $try);
        self::assertStringContainsString('Supplier price &#8377;239,756.10', $try);
    }

    public function testARowSavedBeforeD335KeepsWhatItShowedWhenSwitchedToStoredPrices(): void
    {
        $productId = $this->product();
        $supId = SupplierRepository::create(['supplier_legal_name' => 'D335 Old ' . bin2hex(random_bytes(3)), 'city' => 'Test town', 'types' => []], $this->admin);
        $sup = SupplierRepository::find($supId);
        $this->actAs($this->admin);
        // The older grid form: 20 mm typed 1,600 — the rest worked out each time (auto_fill 1).
        $_POST = ['supplier_id' => (string) $sup['id'], 'supplier_name' => $sup['supplier_legal_name'], 'quoted_on' => date('Y-m-d'), 'valid_until' => '', 'price_basis' => 'grid',
            'price' => [20 => '1600'], 'how' => [20 => 'typed'], 'includes_packing_loading' => '1', 'cha_transport_per_container' => '', 'used_for_pricing' => '1', 'notes' => ''];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        $row = $this->gridRow($productId);
        self::assertSame(1, (int) $row['auto_fill']);
        // A CBM price is typed with nothing ticked: 30 mm keeps the 2,160 it showed (now stored).
        $_POST = ['thickness_mm' => '0', 'thickness_price' => '70000', 'price_quoted_on' => date('Y-m-d'), 'base' => 'keep'];
        $this->render(fn () => (new PricingController())->addThicknessPrice(['supplierId' => (string) $row['id']]));
        $s = self::stored((int) $row['id']);
        self::assertSame([2160.0, 'calculated', 20], $s[30]);
        self::assertSame([1600.0, 'typed', null], $s[20]);
        self::assertSame([70000.0, 'typed', null], $s[0]);
        self::assertSame(0, (int) ProductSupplierRepository::find((int) $row['id'])['auto_fill']);
    }

}
