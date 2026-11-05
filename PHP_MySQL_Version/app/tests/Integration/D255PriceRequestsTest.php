<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociatePortalController;
use App\Helpers\Flash;
use App\Repositories\AssociateRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Repositories\SpecRepository;
use App\Services\OrderLinePricing;
use App\Services\PermissionService;
use App\Services\PriceRequestService;
use App\Services\ProductSpecs;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-255 (5 Nov 2026): specifications that change the price, CBM for
 * 50 mm and above, and associate price requests. The worked figures are the ones the
 * owner approved on the mockups (mock8/s3, mock9/t1). Node twin: d255PriceRequests.test.js.
 */
final class D255PriceRequestsTest extends DbTestCase
{
    private int $admin;
    private int $product;
    private int $associate;
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
        $pdo = Database::connection();
        $pdo->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
        $this->product = ProductRepository::create(['name' => 'D255 Black Galaxy ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], $this->admin);
        PricingRepository::saveProductOverrides($this->product, ['pricing_margin_pct' => null, 'pricing_other_cost_mode' => 'pct', 'pricing_other_cost_value' => '0',
            'pricing_incentive_mode' => null, 'pricing_incentive_value' => null, 'pricing_buffer_pct' => null]);
        SpecRepository::setProductFields($this->product, null, 'slab,tile', null);
        $this->supplier(3280.0, 'unit');
        $this->supplier(53300.0, 'cbm');
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id) VALUES (:n, 'active', 'fixed', :l)")
            ->execute(['n' => 'D255 Associate ' . bin2hex(random_bytes(3)), 'l' => (int) $pdo->query("SELECT id FROM commission_levels WHERE name = 'Level 2'")->fetchColumn()]);
        $this->associate = (int) $pdo->lastInsertId();
        $this->client = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c")->execute(['a' => $this->associate, 'c' => $this->client]);
        PriceRequestService::saveAccess($this->associate, [(int) SpecRepository::systemCategoryId() => ['slab', 'tile']]);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function supplier(float $inr, string $basis): int
    {
        $s = ProductSupplierRepository::create(['product_id' => $this->product, 'supplier_name' => 'Ongole Quarry ' . $basis, 'location' => 'Andhra Pradesh', 'contact_person' => null,
            'contact_phone' => null, 'contact_email' => null, 'fob_source' => 'direct', 'fob_value' => $inr, 'factory_cost' => null, 'transportation_cost' => null,
            'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null, 'is_primary' => $basis === 'unit' ? 1 : 0, 'notes' => null], $this->admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        if ($basis === 'cbm') {
            PricingRepository::setSupplierBasis($s, 'cbm');
            PricingRepository::setCbmPrimary($this->product, $s);
        }
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
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT o.* FROM spec_options o JOIN spec_definitions s ON s.id = o.spec_id WHERE s.name = :s AND o.name = :o AND o.product_id IS NULL AND s.product_id IS NULL');
        $stmt->execute(['s' => $spec, 'o' => $option]);
        return $stmt->fetch();
    }

    private function me(): array
    {
        return AssociateRepository::find($this->associate);
    }

    private const SQM = ['Grade / quality' => 'Standard', 'Thickness' => '30 mm', 'Surface finish' => 'Leather', 'Quantity' => '2–4 containers'];
    private const CBM = ['Grade / quality' => 'Standard', 'Thickness' => '80 mm', 'Surface finish' => 'Leather'];

    public function testTheApprovedWorkedExamplesPerSquareMetreAndPerCbm(): void
    {
        $w = PriceRequestService::work($this->me(), ProductRepository::find($this->product), $this->sel(self::SQM));
        self::assertTrue($w['ok']);
        self::assertSame(['SQM', 73.43, 71.30], [$w['unit'], (float) $w['quote'], (float) $w['lowest']], 'mock8/s3');
        $c = PriceRequestService::work($this->me(), ProductRepository::find($this->product), $this->sel(self::CBM));
        self::assertSame(['CBM', 904.56, 878.21], [$c['unit'], (float) $c['quote'], (float) $c['lowest']], 'mock9/t1: ₹53,300 ÷ 82 per CBM, USD 4/m² × 1000/80');
        self::assertEqualsWithDelta(72.36, (float) $c['per_m2'], 0.01, 'the ≈ per m² helper at 80 mm');
    }

    public function testCategoryChangesFlowToProductsUnlessTheProductChangedThatOption(): void
    {
        $standard = $this->opt('Grade / quality', 'Standard');
        $product = ProductRepository::find($this->product);
        SpecRepository::updateOption((int) $standard['id'], ['name' => 'Standard', 'is_base' => 0, 'change_type' => 'pct', 'value' => '-10', 'thickness_mm' => null], $this->admin);
        $w = PriceRequestService::work($this->me(), $product, $this->sel(self::SQM));
        self::assertNotSame(73.43, (float) $w['quote'], 'the category value now applies');
        // The product keeps its own value: back to the approved figure.
        SpecRepository::setOptionOverride($this->product, (int) $standard['id'], false, '-8');
        self::assertSame(73.43, (float) PriceRequestService::work($this->me(), $product, $this->sel(self::SQM))['quote']);
        // Reset: follows the category again.
        SpecRepository::resetSpecForProduct($this->product, (int) $standard['spec_id']);
        try {
            self::assertNotSame(73.43, (float) PriceRequestService::work($this->me(), $product, $this->sel(self::SQM))['quote']);
        } finally {
            SpecRepository::updateOption((int) $standard['id'], ['name' => 'Standard', 'is_base' => 0, 'change_type' => 'pct', 'value' => '-8', 'thickness_mm' => null], $this->admin);
        }
    }

    public function testHiddenOptionsAreNotOfferedAndProductOnlyOptionsAre(): void
    {
        $leather = $this->opt('Surface finish', 'Leather');
        SpecRepository::setOptionOverride($this->product, (int) $leather['id'], true, null);
        $product = ProductRepository::find($this->product);
        [, $errors] = ProductSpecs::normalize(ProductSpecs::offered($product), [(int) $leather['spec_id'] => (int) $leather['id']]);
        self::assertNotSame([], $errors, 'a hidden option cannot be chosen');
        $own = SpecRepository::createOption(['spec_id' => (int) $leather['spec_id'], 'product_id' => $this->product, 'name' => 'Antique', 'is_base' => 0,
            'change_type' => 'usd_m2', 'value' => '5', 'thickness_mm' => null, 'sort_order' => 99], $this->admin);
        $names = [];
        foreach (ProductSpecs::offered($product) as $s) {
            if ((int) $s['id'] === (int) $leather['spec_id']) {
                $names = array_column($s['options'], 'name');
            }
        }
        self::assertContains('Antique', $names);
        self::assertNotContains('Leather', $names);
        // Another product does not get this product's own option.
        $other = ProductRepository::create(['name' => 'D255 other ' . bin2hex(random_bytes(3)), 'specifications' => 'x', 'hs_code' => '68022390', 'reference_unit' => 'SQM'], $this->admin);
        foreach (ProductSpecs::offered(ProductRepository::find($other)) as $s) {
            self::assertNotContains($own, array_map('intval', array_column($s['options'], 'id')));
        }
        // A hidden specification disappears from the product.
        SpecRepository::setSpecHidden($this->product, (int) $leather['spec_id'], true);
        self::assertNotContains((int) $leather['spec_id'], array_map(static fn ($s) => (int) $s['id'], ProductSpecs::offered($product)));
    }

    public function testOnlyAdminAndMdManageAProductsSpecifications(): void
    {
        $roles = Database::connection()->query(
            "SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
              WHERE p.permission_key = 'manage_product_specifications' ORDER BY r.name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['Admin', 'Managing Director'], $roles);
    }

    public function testAnAutomaticRequestIsNumberedValidForSevenDaysAndCountsTowardTheDailyLimit(): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE associates SET price_request_daily_limit = 1 WHERE id = :a')->execute(['a' => $this->associate]);
        $product = ProductRepository::find($this->product);
        [$id, $err] = PriceRequestService::create($this->me(), $product, $this->sel(self::SQM), false, null);
        self::assertNull($err);
        $r = PriceRequestService::find($id);
        self::assertMatchesRegularExpression('/^PR-\d{2}-\d{2}-\d{4}$/', $r['number']);
        self::assertSame(['auto', 'valid', date('Y-m-d', strtotime('+7 days'))], [$r['kind'], $r['status'], $r['valid_until']]);
        self::assertSame(0, PriceRequestService::leftToday($this->me()));
        [, $err2] = PriceRequestService::create($this->me(), $product, $this->sel(self::SQM), false, null);
        self::assertNotNull($err2, 'over the daily limit');
        // A manual request does not count and is still accepted.
        [$m, $err3] = PriceRequestService::create($this->me(), $product, $this->sel(self::SQM), true, 'Buyer needs 25 mm for a hotel lobby');
        self::assertNull($err3);
        self::assertSame('pending', PriceRequestService::find($m)['status']);
        // Expired once the valid-until date has passed.
        $pdo->prepare("UPDATE price_requests SET valid_until = '2026-01-01' WHERE id = :id")->execute(['id' => $id]);
        self::assertSame('expired', PriceRequestService::displayStatus(PriceRequestService::find($id)));
    }

    /**
     * Fresh-eyes review L4 (5 Nov 2026): the count and the insert run in one transaction that locks
     * the associate's row, so the limit holds. (PHP serves one request per process; the Node twin
     * fires the requests at the same moment.)
     */
    public function testTheDailyLimitIsCheckedUnderALockOnTheAssociate(): void
    {
        Database::connection()->prepare('UPDATE associates SET price_request_daily_limit = 2 WHERE id = :a')->execute(['a' => $this->associate]);
        $product = ProductRepository::find($this->product);
        $errors = [];
        for ($i = 0; $i < 4; $i++) {
            $errors[] = PriceRequestService::create($this->me(), $product, $this->sel(self::SQM), false, null)[1];
        }
        self::assertSame([null, null], array_slice($errors, 0, 2));
        self::assertSame(["You have used today's 2 price requests. Ask NexaCrest if you need more today.", "You have used today's 2 price requests. Ask NexaCrest if you need more today."], array_slice($errors, 2));
        self::assertSame(2, PriceRequestService::requestsToday($this->associate));
        self::assertFalse(Database::connection()->inTransaction(), 'the transaction is always closed');
    }

    public function testManualFlowStaffAreToldAndTheAssociateIsToldWhenPriced(): void
    {
        $pdo = Database::connection();
        [, $short] = PriceRequestService::create($this->me(), ProductRepository::find($this->product), [], true, 'short');
        self::assertNotNull($short, 'a note of at least 10 characters is needed');
        $before = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'price_request'")->fetchColumn();
        [$id] = PriceRequestService::create($this->me(), ProductRepository::find($this->product), [], true, 'Buyer needs 25 mm leather, one container');
        $r = PriceRequestService::find($id);
        self::assertSame(['manual', 'pending', null], [$r['kind'], $r['status'], $r['quote']]);
        self::assertSame(date('Y-m-d H', strtotime($r['requested_at'] . ' +24 hours')), substr((string) $r['due_at'], 0, 13));
        self::assertGreaterThan($before, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'price_request'")->fetchColumn(), 'MD told in the app');
        self::assertNotNull(PriceRequestService::priceManual($id, $this->admin, '70', '75', 'SQM', ''), 'lowest above the quote is refused');
        self::assertNull(PriceRequestService::priceManual($id, $this->admin, '80.00', '77.50', 'SQM', 'priced for 25 mm'));
        $r = PriceRequestService::find($id);
        self::assertSame(['priced', '80.00', '77.50'], [$r['status'], (string) $r['quote'], (string) $r['lowest']]);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM associate_events WHERE associate_id = {$this->associate} AND event = 'price_ready'")->fetchColumn());
    }

    public function testAccessByCategoryAndForm(): void
    {
        $product = ProductRepository::find($this->product);
        self::assertTrue(PriceRequestService::mayPrice($this->me(), $product));
        PriceRequestService::saveAccess($this->associate, [(int) SpecRepository::systemCategoryId() => ['block']]);
        self::assertFalse(PriceRequestService::mayPrice($this->me(), $product), 'slabs and tiles not open to them');
        PriceRequestService::saveAccess($this->associate, []);
        self::assertFalse(PriceRequestService::mayPrice($this->me(), $product));
        [, $err] = PriceRequestService::create($this->me(), $product, $this->sel(self::SQM), false, null);
        self::assertNotNull($err);
    }

    public function testThePortalNeverShowsCostOrSupplierNames(): void
    {
        $_SESSION = ['_associate_portal_id' => $this->associate];
        [$id] = PriceRequestService::create($this->me(), ProductRepository::find($this->product), $this->sel(self::SQM), false, null);
        foreach ([fn () => (new AssociatePortalController())->catalogue([]), fn () => (new AssociatePortalController())->getPriceForm(['id' => $this->product]),
            fn () => (new AssociatePortalController())->priceRequest(['id' => $id])] as $call) {
            ob_start();
            try {
                $call();
            } finally {
                $html = (string) ob_get_clean();
            }
            self::assertStringNotContainsStringIgnoringCase('cost', strip_tags($html));
            self::assertStringNotContainsString('Ongole', $html);
            self::assertStringNotContainsString('Andhra', $html);
            self::assertStringNotContainsString('3280', $html);
        }
        self::assertStringContainsString('73.43', $html);
        Flash::pull();
    }

    public function testOrderLineUnitMustBeCbmFromFiftyMmAndTheFloorUsesCbm(): void
    {
        $order = Database::connection()->query('SELECT * FROM orders WHERE id = ' . $this->createTestOrder($this->client, 'FOB', 'Standard — New Buyer', 'USD'))->fetch();
        $sel = $this->sel(self::CBM);
        self::assertStringContainsString('set the unit to CBM', (string) OrderLinePricing::saveRefusal($order, $this->product, 'goods', '904.56', true, $sel, 'SQM'));
        self::assertNull(OrderLinePricing::saveRefusal($order, $this->product, 'goods', '904.56', true, $sel, 'CBM'));
        self::assertNotNull(OrderLinePricing::saveRefusal($order, $this->product, 'goods', '500.00', true, $sel, 'CBM'), 'below the CBM minimum');
        self::assertNull(OrderLinePricing::saveRefusal($order, $this->product, 'goods', '73.43', true, $this->sel(self::SQM), 'SQM'));
    }

    public function testAnOrderLineFromAPriceRequestOnlyForThatAssociatesBuyers(): void
    {
        [$id] = PriceRequestService::create($this->me(), ProductRepository::find($this->product), $this->sel(self::SQM), false, null);
        $number = PriceRequestService::find($id)['number'];
        $mine = Database::connection()->query('SELECT * FROM orders WHERE id = ' . $this->createTestOrder($this->client, 'FOB', 'Standard — New Buyer', 'USD'))->fetch();
        [$r, $err] = PriceRequestService::usableForOrder(strtolower($number), $mine);
        self::assertNull($err);
        self::assertSame($id, (int) $r['id']);
        $other = Database::connection()->query('SELECT * FROM orders WHERE id = ' . $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD'))->fetch();
        self::assertNotNull(PriceRequestService::usableForOrder($number, $other)[1]);
        PriceRequestService::markOrdered($id, (int) $mine['id']);
        self::assertSame('ordered', PriceRequestService::find($id)['status']);
        self::assertNotNull(PriceRequestService::usableForOrder($number, $mine)[1], 'used once');
    }

    public function testStaffQueueAndReportWarnOnManyRequestsWithoutOrders(): void
    {
        $pdo = Database::connection();
        for ($i = 0; $i < PriceRequestService::WARN_REQUESTS; $i++) {
            $pdo->prepare("INSERT INTO price_requests (number, associate_id, product_id, selection, selection_text, kind, status, unit, requested_at)
                           VALUES (:n, :a, :p, '{}', 'x', 'auto', 'valid', 'SQM', NOW())")
                ->execute(['n' => 'PR-99-' . bin2hex(random_bytes(4)), 'a' => $this->associate, 'p' => $this->product]);
        }
        $row = array_values(array_filter(PriceRequestService::report(), fn ($r) => $r['associate_id'] === $this->associate))[0];
        self::assertSame([PriceRequestService::WARN_REQUESTS, 0, true], [$row['requests'], $row['orders'], $row['warn']]);
        PermissionService::resetCache();
    }
}
