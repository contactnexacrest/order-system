<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CatalogueMasterController;
use App\Controllers\PricingController;
use App\Controllers\ProductCategoryController;
use App\Controllers\ProductController;
use App\Controllers\SupplierController;
use App\Repositories\PricingRepository;
use App\Repositories\ProductCategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\SpecRepository;
use App\Repositories\SupplierRepository;
use App\Services\FxRateService;
use App\Services\PermissionService;
use App\Services\PriceRules;
use App\Services\PricingService;
use App\Services\ProductSpecs;
use App\Services\ThicknessPrices;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions of 11 Nov 2026, double-confirmed (products and pricing):
 *  - D-300 (point 12): one supplier price for every thickness, TYPED / CALCULATED, CHA + transport
 *    per container → per unit, packing and loading, price history; the category's Price rules
 *    (MD only, with a reason, audited).
 *  - D-301 (point 4): the daily exchange rate — main source, backup, failure keeps the last rate,
 *    a move over 2 % waits for the MD; pricing rate = market × (1 − cushion %); Manual mode.
 *  - D-302 / D-303 (points 11 and 15): HS code only from the master list (Fix HS codes); the
 *    supplier of a price from the Suppliers directory (quick add "Details pending", Link suppliers).
 *  - D-304 / D-305 (points 14 and 19): catalogue labels and the rate line; "for this order".
 *  - D-306 (point 20): the category page with tabs.
 * The network is never used: the exchange-rate fetcher is replaced. Node twin:
 * tests/integration/d300ProductsPricing.test.js.
 */
final class D300ProductsPricingTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
        Database::connection()->exec('DELETE FROM fx_rates');
    }

    protected function tearDown(): void
    {
        FxRateService::setHttp(null);
        $pdo = Database::connection();
        $pdo->exec('DELETE FROM fx_rates');
        foreach (PricingService::DEFAULTS as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
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

    /** A Natural Stone product sold per m² (no other costs, so the cost is the FOB cost ÷ the rate). */
    private function product(string $unit = 'SQM'): int
    {
        $id = ProductRepository::create(['name' => 'D300 Absolute Black ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022310', 'reference_unit' => $unit], $this->admin);
        PricingRepository::saveProductOverrides($id, ['pricing_margin_pct' => null, 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
            'pricing_incentive_mode' => null, 'pricing_incentive_value' => null, 'pricing_buffer_pct' => null]);
        return $id;
    }

    private function directorySupplier(string $name = 'D300 Granites'): array
    {
        $id = SupplierRepository::create(['supplier_legal_name' => $name . ' ' . bin2hex(random_bytes(3)), 'city' => 'Test town', 'types' => []], $this->admin);
        return SupplierRepository::find($id);
    }

    /** @param array<string,string> $byName */
    private function sel(int $productId, array $byName): array
    {
        $out = [];
        foreach (ProductSpecs::offered(ProductRepository::find($productId)) as $s) {
            foreach ($s['options'] as $o) {
                if (($byName[$s['name']] ?? null) === $o['name']) {
                    $out[(int) $s['id']] = (int) $o['id'];
                }
            }
        }
        return $out;
    }

    private function gridRow(int $productId): array
    {
        return array_values(array_filter(ProductSupplierRepository::forProduct($productId), static fn ($s) => $s['price_basis'] === 'grid'))[0];
    }

    public function testOneSupplierPriceForEveryThicknessWithChaAndTransport(): void
    {
        $productId = $this->product();
        $sup = $this->directorySupplier();
        $this->actAs($this->createTestUser('Procurement Executive'));
        // p12.png: 20 mm typed 1,600; 30 mm left as calculated; ₹60,000 CHA + transport per container.
        $_POST = ['supplier_id' => (string) $sup['id'], 'supplier_name' => $sup['supplier_legal_name'], 'location' => 'Test town', 'quoted_on' => date('Y-m-d'), 'valid_until' => '',
            'price_basis' => 'grid', 'price' => [20 => '1600', 30 => '2160.00'], 'how' => [20 => 'typed', 30 => 'calculated'],
            'includes_packing_loading' => '1', 'cha_transport_per_container' => '60000', 'used_for_pricing' => '1', 'notes' => ''];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        self::assertSame('Supplier price recorded and used for pricing.', $this->flash());
        $row = $this->gridRow($productId);
        self::assertSame([(int) $sup['id'], 1, '60000.00', 1], [(int) $row['supplier_id'], (int) $row['is_primary'], (string) $row['cha_transport_per_container'], (int) $row['includes_packing_loading']]);
        self::assertSame([20 => 1600.0], ThicknessPrices::typedFor((int) $row['id']), 'only the typed price is stored');

        // The base product: 1,600 + 150 (₹60,000 ÷ 400 m²) = ₹1,750 per m² FOB.
        $p = PricingService::forProduct(ProductRepository::find($productId));
        self::assertEqualsWithDelta(1750.0, $p['supplier_inr'], 1e-6);
        self::assertEqualsWithDelta(150.0, $p['inr_parts']['cha'], 1e-6);
        self::assertSame(\App\Services\PricingEngine::round2(1750 / 82), $p['build']['cost']);
        // 30 mm: the grid's 2,160 + 225 — the Thickness option's +35 % is not applied a second time.
        $p = PricingService::forProduct(ProductRepository::find($productId), [], null, null, $this->sel($productId, ['Thickness' => '30 mm']));
        self::assertEqualsWithDelta(2385.0, $p['supplier_inr'], 1e-6);
        self::assertSame([], $p['spec']['adj']['pct']);
        self::assertSame(\App\Services\PricingEngine::round2(2385 / 82), $p['build']['cost']);
        // 60 mm: per CBM — 64,000 (20 mm × 40) + 7,500.
        $p = PricingService::forProduct(ProductRepository::find($productId), [], null, null, $this->sel($productId, ['Thickness' => '60 mm']));
        self::assertSame('CBM', $p['unit']);
        self::assertEqualsWithDelta(71500.0, $p['supplier_inr'], 1e-6);

        // The market prices page shows every thickness, TYPED and calculated, and the FOB cost.
        $_POST = [];
        $page = $this->render(fn () => (new PricingController())->marketPrices(['id' => (string) $productId]));
        foreach (['every thickness', '1,600.00', '2,160.00', '64,000.00', 'TYPED', 'CALCULATED', '1,750.00', 'Price includes packing and loading', 'CHA + transport per container'] as $s) {
            self::assertStringContainsString($s, $page);
        }
        self::assertStringNotContainsString('margin', $page, 'Procurement never sees selling-price wording');
    }

    public function testChangingATypedPriceMovesTheCalculatedOnesAndKeepsHistory(): void
    {
        $productId = $this->product();
        $sup = $this->directorySupplier();
        $proc = $this->createTestUser('Procurement Executive');
        $this->actAs($proc);
        $base = ['supplier_id' => (string) $sup['id'], 'supplier_name' => $sup['supplier_legal_name'], 'quoted_on' => date('Y-m-d'), 'valid_until' => '', 'price_basis' => 'grid',
            'includes_packing_loading' => '1', 'cha_transport_per_container' => '', 'used_for_pricing' => '1', 'notes' => ''];
        $_POST = $base + ['price' => [20 => '1600'], 'how' => [20 => 'typed']];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        $row = $this->gridRow($productId);
        // p12b.png step 3: 20 mm becomes 1,700; 30 mm typed 2,100 stays.
        $_POST = $base + ['price' => [20 => '1700', 30 => '2100', 40 => '2890.00'], 'how' => [20 => 'typed', 30 => 'typed', 40 => 'calculated']];
        $this->render(fn () => (new PricingController())->savePrice(['supplierId' => (string) $row['id']]));
        self::assertSame('Supplier price saved.', $this->flash());
        $done = ThicknessPrices::complete(ThicknessPrices::rules(ProductRepository::find($productId)), ThicknessPrices::typedFor((int) $row['id']));
        self::assertSame([1700.0, 'typed'], [$done['values'][20]['price'], $done['values'][20]['how']]);
        self::assertSame([2100.0, 'typed', 2295.0], [$done['values'][30]['price'], $done['values'][30]['how'], $done['values'][30]['calc']]);
        self::assertSame([2890.0, 'calculated'], [$done['values'][40]['price'], $done['values'][40]['how']]);
        self::assertSame(68000.0, $done['values'][0]['price']);
        $history = array_map(static fn ($h) => $h['what'] . ': ' . $h['old_value'] . ' → ' . $h['new_value'], ThicknessPrices::historyFor((int) $row['id']));
        self::assertContains('20 mm per m²: 1600.00 typed → 1700.00 typed', $history);
        self::assertContains('40 mm per m²: 2720.00 calculated → 2890.00 calculated', $history);
        // Every box empty or 0 is refused.
        $_POST = $base + ['price' => [20 => '', 30 => '0'], 'how' => [20 => 'typed', 30 => 'typed']];
        $this->render(fn () => (new PricingController())->savePrice(['supplierId' => (string) $row['id']]));
        self::assertSame('Enter at least one price.', $this->flash());
        // The edit page shows the history and "Change these rules" only for the MD.
        $_POST = [];
        $page = $this->render(fn () => (new PricingController())->editPrice(['supplierId' => (string) $row['id']]));
        self::assertStringContainsString('Price history', $page);
        self::assertStringContainsString('Add / update a price', $page); // D-335: the thickness prices are changed there
        self::assertStringNotContainsString('Change these rules', $page);
    }

    public function testAPriceNeedsADirectorySupplierAndQuickAddMarksDetailsPending(): void
    {
        $productId = $this->product();
        $this->actAs($this->createTestUser('Procurement Executive'));
        $_POST = ['supplier_name' => 'Not In The Directory ' . bin2hex(random_bytes(3)), 'quoted_on' => date('Y-m-d'), 'price_basis' => 'grid', 'price' => [20 => '1600']];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        self::assertStringStartsWith('Pick the supplier from the Suppliers directory', $this->flash());
        self::assertSame([], ProductSupplierRepository::forProduct($productId));

        $name = 'D300 Quick Quarry ' . bin2hex(random_bytes(3));
        $_POST = ['quick_name' => $name, 'quick_location' => 'Kota, Rajasthan', 'quick_phone' => '+91-0000000000', 'quick_type' => '', 'product_id' => (string) $productId];
        $this->render(fn () => (new SupplierController())->quickAdd([]));
        self::assertStringContainsString('(details pending)', $this->flash());
        $s = SupplierRepository::findByExactName($name);
        self::assertNotNull($s);
        self::assertSame(1, (int) $s['details_pending']);
        self::assertSame(['Kota, Rajasthan'], array_values(array_filter(SupplierRepository::pickList(), static fn ($x) => $x['id'] === (int) $s['id']))[0]['locations']);
        // The same name twice is refused; a phone without digits is refused.
        $this->render(fn () => (new SupplierController())->quickAdd([]));
        self::assertStringContainsString('is already in the Suppliers directory', $this->flash());
        $_POST['quick_name'] = 'Another ' . $name;
        $_POST['quick_phone'] = 'call me';
        $this->render(fn () => (new SupplierController())->quickAdd([]));
        self::assertStringStartsWith('Phone:', $this->flash());
        // Its prices are listed on the supplier's page ("Prices given for products").
        $_POST = ['supplier_id' => (string) $s['id'], 'supplier_name' => $name, 'quoted_on' => date('Y-m-d'), 'price_basis' => 'grid', 'price' => [20 => '1600'], 'how' => [20 => 'typed']];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        self::assertCount(1, ProductSupplierRepository::forDirectorySupplier((int) $s['id']));
    }

    public function testLinkSuppliersMatchesTypedNamesAndFixHsCodes(): void
    {
        $productId = $this->product();
        $sup = $this->directorySupplier('D300 Linked Stones');
        // A price typed before the directory link, with the supplier's exact name (other capitals).
        $legacy = ProductSupplierRepository::create(['product_id' => $productId, 'supplier_name' => strtoupper((string) $sup['supplier_legal_name']), 'location' => null, 'contact_person' => null,
            'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null,
            'loading_cost' => null, 'cha_cost' => null, 'is_primary' => 0, 'notes' => null], $this->admin);
        $this->actAs($this->createTestUser('Procurement Manager'));
        $page = $this->render(fn () => (new CatalogueMasterController())->linkSuppliers([]));
        self::assertStringContainsString('Match by name', $page);
        $_POST = ['action' => 'match_all'];
        $this->render(fn () => (new CatalogueMasterController())->saveLinks([]));
        self::assertStringContainsString('linked to their supplier by name', $this->flash());
        $row = ProductSupplierRepository::find($legacy);
        self::assertSame([(int) $sup['id'], (string) $sup['supplier_legal_name']], [(int) $row['supplier_id'], (string) $row['supplier_name']]);

        // Fix HS codes: a product typed with a code that is not on the master list.
        $bad = ProductRepository::create(['name' => 'D300 Odd HS ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '12345678'], $this->admin);
        $ids = array_map(static fn ($p) => (int) $p['id'], CatalogueMasterController::productsToFix());
        self::assertContains($bad, $ids);
        self::assertNotContains($productId, $ids);
        $this->actAs($this->admin);
        $_POST = ['hs_code' => '99999999'];
        $this->render(fn () => (new CatalogueMasterController())->saveHsCode(['id' => (string) $bad]));
        self::assertStringContainsString('choose an HS code from the HS Code Master List', $this->flash());
        $_POST = ['hs_code' => '68022310'];
        $this->render(fn () => (new CatalogueMasterController())->saveHsCode(['id' => (string) $bad]));
        self::assertSame('68022310', (string) ProductRepository::find($bad)['hs_code']);
    }

    public function testAProductHsCodeComesFromTheMasterList(): void
    {
        $this->actAs($this->admin);
        $_POST = ['name' => 'D300 HS Product ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '99999999', 'origin' => 'India', 'is_active' => '1'];
        $this->render(fn () => (new ProductController())->store([]));
        self::assertStringContainsString('is not on the HS Code Master List', $this->flash());
        $_POST['hs_code'] = '68022310';
        $_POST['cha_transport_per_container'] = '55000';
        $this->render(fn () => (new ProductController())->store([]));
        self::assertSame('Product added to the catalog.', $this->flash());
        $p = Database::connection()->query("SELECT * FROM catalog_products WHERE name = " . Database::connection()->quote($_POST['name']))->fetch();
        self::assertSame('55000.00', (string) $p['cha_transport_per_container']);
        // The form offers the master list, pre-filled with the category's default.
        $_POST = [];
        $form = $this->render(fn () => (new ProductController())->create([]));
        self::assertStringContainsString('data-in-list="product_hs_codes"', $form);
        self::assertStringContainsString('<datalist id="product_hs_codes">', $form);
    }

    public function testLegacyPricesGetChaAndTransportFromTheProductDefault(): void
    {
        $productId = $this->product('CBM');
        $s = ProductSupplierRepository::create(['product_id' => $productId, 'supplier_name' => 'D300 Legacy', 'location' => null, 'contact_person' => null, 'contact_phone' => null,
            'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null,
            'cha_cost' => null, 'is_primary' => 1, 'notes' => null], $this->admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        self::assertEqualsWithDelta(80000.0, PricingService::forProduct(ProductRepository::find($productId))['supplier_inr'], 1e-6, 'no CHA set: as before');
        ProductRepository::setPriceRuleFields($productId, ['cha_transport_per_container' => '60000.00']);
        self::assertEqualsWithDelta(87500.0, PricingService::forProduct(ProductRepository::find($productId))['supplier_inr'], 1e-6, '+ ₹60,000 ÷ 8 CBM');
        // The product's own container (6 CBM): ₹10,000 per CBM, shown with an "own rule" badge.
        ProductRepository::setPriceRuleFields($productId, ['container_cbm' => '6.00']);
        self::assertEqualsWithDelta(90000.0, PricingService::forProduct(ProductRepository::find($productId))['supplier_inr'], 1e-6);
        self::assertContains('container', ThicknessPrices::rules(ProductRepository::find($productId))['own']);
    }

    public function testPriceRulesPageMdOnlyWithAReasonAndAudited(): void
    {
        $cat = ProductCategoryRepository::create('D300 Rules ' . bin2hex(random_bytes(3)), ['Size'], $this->admin);
        $spec = SpecRepository::createSpec(['category_id' => $cat, 'name' => 'Thickness', 'kind' => 'thickness', 'applies_to' => null, 'sort_order' => 1], $this->admin);
        $o20 = SpecRepository::createOption(['spec_id' => $spec, 'name' => '20 mm', 'is_base' => 1, 'change_type' => 'none', 'value' => null, 'thickness_mm' => 20, 'sort_order' => 1], $this->admin);
        $o30 = SpecRepository::createOption(['spec_id' => $spec, 'name' => '30 mm', 'is_base' => 0, 'change_type' => 'pct', 'value' => '35.0000', 'thickness_mm' => 30, 'sort_order' => 2], $this->admin);
        $md = $this->createTestUser('Managing Director');
        [$n, $e] = PriceRules::save($cat, ['pct' => [$o30 => '40'], 'thick_k_factor' => '40'], $md);
        self::assertSame(0, $n);
        self::assertStringStartsWith('Reason for the change:', (string) $e);
        [$n, $e] = PriceRules::save($cat, ['pct' => [$o30 => '40'], 'thick_k_factor' => '42', 'container_cbm' => '8', 'container_max_t' => '27', 'weight_t_per_cbm' => '2.7',
            'new_mm' => '18', 'new_change' => '-5', 'reason' => 'Supplier rates for 30 mm went up'], $md);
        self::assertNull($e);
        self::assertSame(6, $n, '30 mm %, × K, container CBM, tonnes, weight, and the new 18 mm');
        $r = PriceRules::forCategory($cat);
        self::assertSame([[18, -5.0], [20, 0.0], [30, 40.0]], array_map(static fn ($t) => [$t['mm'], $t['pct']], $r['thickness']));
        self::assertSame([42.0, 8.0, 27.0, 2.7], [$r['k'], $r['container_cbm'], $r['container_max_t'], $r['weight']]);
        $audit = Database::connection()->prepare("SELECT COUNT(*) FROM audit_log WHERE action_type IN ('PRICE_RULE_CHANGED', 'PRICE_RULE_ADDED') AND reason = 'Supplier rates for 30 mm went up'");
        $audit->execute();
        self::assertSame(6, (int) $audit->fetchColumn());
        // A thickness already listed, or one of 50 mm or more, is refused.
        self::assertStringContainsString('already in the list', (string) PriceRules::save($cat, ['new_mm' => '30', 'new_change' => '10', 'reason' => 'Trying a duplicate thickness'], $md)[1]);
        self::assertStringContainsString('from 50 mm the price is per CBM', (string) PriceRules::save($cat, ['new_mm' => '60', 'new_change' => '10', 'reason' => 'Trying a thick thickness'], $md)[1]);

        // The category page: the tabs, the rules form for the MD; the old Specifications address opens the Options tab.
        $this->actAs($md);
        $_GET = ['tab' => 'rules'];
        $page = $this->render(fn () => (new ProductCategoryController())->show(['id' => (string) $cat]));
        foreach (['General (name, units, HS code, packing, documents)', 'Options and their price effect', 'Price rules (thickness %, container)', 'Products in this category',
            'Container and weight', 'Save rules', 'data-rules-example'] as $s) {
            self::assertStringContainsString($s, $page);
        }
        $_GET = ['tab' => 'options'];
        $page = $this->render(fn () => (new ProductCategoryController())->show(['id' => (string) $cat]));
        self::assertStringContainsString('What changes the price in this category', $page);
    }

    public function testDailyExchangeRateFetchFailureJumpAndApproval(): void
    {
        $md = $this->createTestUser('Managing Director');
        $calls = [];
        $answers = [];
        FxRateService::setHttp(static function (string $url) use (&$calls, &$answers): ?string {
            $calls[] = $url;
            $host = str_contains($url, 'frankfurter') ? 'f' : 'e';
            return $answers[$host] ?? null;
        });
        // Nothing fetched yet: the manual rate, with a warning.
        self::assertSame(82.0, PricingService::settings()['pricing_fx_rate']);
        self::assertStringContainsString('No market rate has been fetched yet', FxRateService::current()['warnings'][0]);
        // Both sources fail: nothing in use, the failure is in the history.
        $r = FxRateService::fetchDaily('2026-11-08');
        self::assertSame('failed', $r['status']);
        self::assertSame(['https://api.frankfurter.app/latest?from=USD&to=INR', 'https://open.er-api.com/v6/latest/USD'], $calls);
        self::assertSame(82.0, FxRateService::rate());
        // The main source: ₹88.40 → pricing rate ₹86.632 (2 % cushion).
        $answers = ['f' => '{"amount":1.0,"base":"USD","date":"2026-11-09","rates":{"INR":88.40}}'];
        $r = FxRateService::fetchDaily('2026-11-09');
        self::assertSame(['in_use', 'Frankfurter (European Central Bank)'], [$r['status'], $r['source']]);
        self::assertSame(86.632, PricingService::settings()['pricing_fx_rate']);
        // The main source fails, the backup answers.
        $answers = ['e' => '{"result":"success","rates":{"INR":88.50}}'];
        $r = FxRateService::fetchDaily('2026-11-10');
        self::assertSame(['in_use', 'open.er-api.com'], [$r['status'], $r['source']]);
        self::assertSame(86.73, FxRateService::rate());
        // A move of more than 2 % waits for the MD; prices keep ₹88.50 − 2 %.
        $answers = ['f' => '{"rates":{"INR":91.00}}'];
        $r = FxRateService::fetchDaily('2026-11-11');
        self::assertSame('waiting_md', $r['status']);
        self::assertSame(86.73, FxRateService::rate());
        $waiting = FxRateService::forDate('2026-11-11');
        self::assertEqualsWithDelta(2.825, (float) $waiting['change_pct'], 0.001);
        self::assertNotNull(FxRateService::decide((int) $waiting['id'], true, 'ok', $md), 'a reason is needed');
        self::assertNull(FxRateService::decide((int) $waiting['id'], true, 'Checked the move with the bank', $md));
        self::assertSame(89.18, FxRateService::rate());
        self::assertNotNull(FxRateService::decide((int) $waiting['id'], true, 'Checked the move with the bank', $md), 'decided once');
        // The cushion is a setting (0–5 %, one decimal); Manual uses the typed rate.
        PricingRepository::setSetting('fx_cushion_pct', '2.5', $md);
        self::assertSame(88.725, FxRateService::rate());
        PricingRepository::setSetting('fx_mode', 'manual', $md);
        self::assertSame(82.0, PricingService::settings()['pricing_fx_rate']);
        // Pricing settings shows the source, the date and the history.
        PricingRepository::setSetting('fx_mode', 'auto', $md);
        $this->actAs($md);
        $page = $this->render(fn () => (new PricingController())->settings([]));
        foreach (['Pricing rate ₹', 'below today\'s market rate (₹91.00)', 'Why?', 'History (last 30 fetches)', 'Fetch failed — last rate kept', 'Frankfurter (European Central Bank)', 'open.er-api.com'] as $s) {
            self::assertStringContainsString($s, $page);
        }
    }

    public function testCatalogueLabelsAndWhoSeesWhichPrice(): void
    {
        $productId = $this->product('CBM');
        $s = ProductSupplierRepository::create(['product_id' => $productId, 'supplier_name' => 'D300 Labels', 'location' => null, 'contact_person' => null, 'contact_phone' => null,
            'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => 180000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null,
            'cha_cost' => null, 'is_primary' => 1, 'notes' => null], $this->admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        $_GET = ['q' => (string) ProductRepository::find($productId)['name']];
        $this->actAs($this->createTestUser('Managing Director'));
        $list = $this->render(fn () => (new ProductController())->index([]));
        foreach (['Supplier price<br>₹ / CBM', 'Cost<br>USD / CBM', 'Staff lowest', 'Associate lowest', 'Pricing rate ₹82.00 per USD', 'Why?'] as $x) {
            self::assertStringContainsString($x, $list, "MD sees {$x}");
        }
        self::assertStringNotContainsString('Headline FOB', $list);
        $this->actAs($this->createTestUser('Export Executive'));
        $list = $this->render(fn () => (new ProductController())->index([]));
        foreach (['Quote price<br>USD / CBM', 'Your lowest<br>USD / CBM'] as $x) {
            self::assertStringContainsString($x, $list, "staff see {$x}");
        }
        foreach (['Pricing rate', 'Staff lowest', 'Associate lowest', 'Cost<br>', '180,000'] as $x) {
            self::assertStringNotContainsString($x, $list, "staff never see {$x}");
        }
    }

    public function testAnAssociateOrderShowsThisOrdersFiguresWithoutTheAssociate(): void
    {
        $__lc = ['status' => 'ok', 'figures' => ['quote' => 2881.88, 'floor' => 2797.95, 'minimum' => 2662.12, 'sold_by' => 'L2'], 'conversion' => null, 'reference' => null,
            'fixed' => false, 'requested' => false, 'note' => null];
        $seesPriceBuildup = false;
        ob_start();
        include dirname(__DIR__, 2) . '/views/orders/_line_price.php';
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Quote 2,881.88 · lowest 2,797.95 for this order', $html);
        self::assertStringNotContainsString('minimum', $html);
        $__lc['figures']['sold_by'] = 'staff';
        ob_start();
        include dirname(__DIR__, 2) . '/views/orders/_line_price.php';
        self::assertStringNotContainsString('for this order', (string) ob_get_clean());
    }
}

