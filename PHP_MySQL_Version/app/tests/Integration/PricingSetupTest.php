<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociateAdminController;
use App\Controllers\PricingController;
use App\Controllers\ProductController;
use App\Repositories\IncentiveRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\PermissionService;
use App\Services\PricingService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-245 (4 Nov 2026), phase P1: pricing settings (MD only,
 * audited), product overrides, Procurement's market prices, the commission
 * levels and ladder, an associate's commission level, and who sees which
 * price: MD/ED the whole build-up, selling-price staff only the quote and
 * their lowest price, Procurement no selling price at all.
 * Node twin: tests/integration/pricingSetup.test.js.
 */
final class PricingSetupTest extends DbTestCase
{
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

    private function programOn(): void
    {
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
    }

    /** A product with a ₹80,000 supplier price used for pricing, quoted today. */
    private function product(string $name = 'Black Galaxy Granite Block'): int
    {
        $admin = $this->createTestUser('Admin');
        $id = ProductRepository::create(['name' => $name . ' ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '25161100', 'reference_unit' => 'CBM'], $admin);
        $s = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'Sri Balaji Granites', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($s, date('Y-m-d'), null);
        return $id;
    }

    public function testWhoSeesWhichPrice(): void
    {
        $this->programOn();
        $productId = $this->product();

        $this->actAs($this->createTestUser('Managing Director'));
        $page = $this->render(fn () => (new ProductController())->show(['id' => (string) $productId]));
        foreach (['Price build-up', 'USD 1,000.00', 'USD 1,250.00', 'USD 37.50', 'USD 1,287.50', 'USD 1,326.13', '1,355.26', '1,395.92', '1,430.56', "Save this product's settings"] as $s) {
            self::assertStringContainsString($s, $page, "MD sees {$s}");
        }
        $list = $this->render(fn () => (new ProductController())->index([]));
        self::assertStringContainsString('Staff floor', $list);
        self::assertStringContainsString('1,313.78', $list, 'the lowest active level (2%) floor');

        $this->actAs($this->createTestUser('Executive Director'));
        $page = $this->render(fn () => (new ProductController())->show(['id' => (string) $productId]));
        self::assertStringContainsString('USD 1,250.00', $page, 'ED sees the build-up');
        self::assertStringNotContainsString("Save this product's settings", $page, 'only the MD changes it');

        $this->actAs($this->createTestUser('Export Executive'));
        $page = $this->render(fn () => (new ProductController())->show(['id' => (string) $productId]));
        self::assertStringContainsString('USD 1,326.13', $page, 'sales sees the quote price');
        self::assertStringContainsString('USD 1,287.50', $page, 'and their lowest price');
        foreach (['Price build-up', '1,250.00', '1,000.00', '37.50', '80,000', '1,355.26', 'Market prices'] as $s) {
            self::assertStringNotContainsString($s, $page, "sales never sees {$s}");
        }
        $list = $this->render(fn () => (new ProductController())->index([]));
        self::assertStringContainsString('1,326.13', $list);
        self::assertStringNotContainsString('Staff floor', $list);
        self::assertStringNotContainsString('1,250.00', $list);

        $procurement = $this->createTestUser('Procurement Executive');
        $this->actAs($procurement);
        $market = $this->render(fn () => (new PricingController())->marketPrices(['id' => (string) $productId]));
        self::assertStringContainsString('80,000.00', $market, 'Procurement sees the supplier price');
        foreach (['1,326.13', '1,287.50', '1,250.00', 'Staff floor', 'margin'] as $s) {
            self::assertStringNotContainsString($s, $market, "Procurement never sees {$s}");
        }
    }

    public function testSettingsAreMdOnlyValidatedAndAudited(): void
    {
        foreach (['Admin' => true, 'Managing Director' => true, 'Executive Director' => false, 'Export Executive' => false, 'Procurement Manager' => false] as $role => $can) {
            $u = $this->createTestUser($role);
            $roleId = (int) Database::connection()->query('SELECT role_id FROM users WHERE id = ' . $u)->fetchColumn();
            self::assertSame($can, PermissionService::can($u, $roleId, 'manage_pricing'), "{$role} manage_pricing");
            self::assertSame(in_array($role, ['Admin', 'Managing Director', 'Executive Director'], true), PermissionService::can($u, $roleId, 'view_price_buildup'), "{$role} view_price_buildup");
        }

        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $_POST = ['margin_pct' => '95', 'other_cost_pct' => '2.5', 'pricing_fx_rate' => '82', 'buffer_pct' => '3', 'incentive_mode' => 'pct', 'incentive_value' => '15', 'stale_days' => '60'];
        $this->render(fn () => (new PricingController())->saveSettings([]));
        self::assertStringContainsString('Minimum margin (% of price): enter a number from 0 to 90', $this->flash());
        self::assertSame('20.00', PricingRepository::settings()['margin_pct']);

        $_POST['margin_pct'] = '22.5';
        $this->render(fn () => (new PricingController())->saveSettings([]));
        self::assertStringContainsString('Pricing settings saved', $this->flash());
        self::assertSame('22.50', PricingRepository::settings()['margin_pct']);
        $log = Database::connection()->query("SELECT user_id, field_name, old_value, new_value FROM audit_log WHERE action_type = 'PRICING_SETTING_CHANGED' ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame([$md, 'margin_pct', '20.00', '22.50'], [(int) $log['user_id'], $log['field_name'], $log['old_value'], $log['new_value']]);
    }

    public function testProductOverridesChangeOnlyThatProduct(): void
    {
        $this->programOn();
        $productId = $this->product();
        $other = $this->product('Other Block');
        $this->actAs($this->createTestUser('Managing Director'));
        $_POST = ['pricing_margin_pct' => '25', 'pricing_buffer_pct' => '', 'pricing_other_cost_mode' => 'usd', 'pricing_other_cost_value' => '24.39',
            'pricing_incentive_mode' => 'usd', 'pricing_incentive_value' => '1'];
        $this->render(fn () => (new PricingController())->saveProductOverrides(['id' => (string) $productId]));
        self::assertStringContainsString('saved', $this->flash());
        $p = PricingService::forProduct(ProductRepository::find($productId));
        // 975.61 + 24.39 = 1,000.00; ÷ 0.75 = 1,333.33; + USD 1 = 1,334.33; × 1.03 = 1,374.36.
        self::assertSame([1000.0, 1333.33, 1.0, 1334.33, 1374.36], [$p['build']['cost'], $p['build']['minimum'], $p['build']['reserve'], $p['build']['staff_floor'], $p['build']['staff_quote']]);
        self::assertContains('margin 25%', $p['overrides']);
        self::assertSame(1326.13, PricingService::forProduct(ProductRepository::find($other))['build']['staff_quote'], 'the other product keeps the global rules');
        self::assertSame(5, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'PRODUCT_PRICING_CHANGED' AND entity_id = {$productId}")->fetchColumn(), 'margin, other cost mode and value, incentive mode and value: one row per field that changed');

        $_POST = ['pricing_margin_pct' => '95'];
        $this->render(fn () => (new PricingController())->saveProductOverrides(['id' => (string) $productId]));
        self::assertStringContainsString('Margin %', $this->flash());
    }

    public function testMarketPricesUsedForPricingAndStale(): void
    {
        $productId = $this->product();
        $procurement = $this->createTestUser('Procurement Executive');
        $this->actAs($procurement);
        $_POST = ['supplier_name' => 'Raghavendra Stones', 'price_inr' => '82000', 'quoted_on' => date('Y-m-d', strtotime('-70 days')), 'valid_until' => '', 'notes' => 'Ex-works', 'used_for_pricing' => '1'];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        self::assertSame('Supplier price recorded and used for pricing.', $this->flash());
        $p = PricingService::forProduct(ProductRepository::find($productId));
        self::assertSame('Raghavendra Stones', $p['supplier']['supplier_name']);
        self::assertSame(82000.0, $p['supplier_inr']);
        self::assertSame('stale', $p['staleness'], 'older than 60 days');
        self::assertCount(2, ProductSupplierRepository::forProduct($productId), 'the old price stays as history');

        $_POST = ['supplier_name' => 'X', 'price_inr' => '100', 'quoted_on' => date('Y-m-d', strtotime('+1 day'))];
        $this->render(fn () => (new PricingController())->addMarketPrice(['id' => (string) $productId]));
        self::assertSame('The quoted date cannot be in the future.', $this->flash());

        $first = null;
        foreach (ProductSupplierRepository::forProduct($productId) as $s) {
            if ($s['supplier_name'] === 'Sri Balaji Granites') {
                $first = (int) $s['id'];
            }
        }
        $this->render(fn () => (new PricingController())->useForPricing(['supplierId' => (string) $first]));
        self::assertSame(80000.0, PricingService::forProduct(ProductRepository::find($productId))['supplier_inr']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'MARKET_PRICE_USED_FOR_PRICING' AND entity_id = {$first}")->fetchColumn());
    }

    public function testCommissionLevelsAndLadder(): void
    {
        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $_POST = ['name' => 'Level 5', 'kind' => 'pct', 'value' => '12'];
        $this->render(fn () => (new PricingController())->addLevel([]));
        self::assertSame('Level "Level 5" added.', $this->flash());
        $id = (int) Database::connection()->query("SELECT id FROM commission_levels WHERE name = 'Level 5'")->fetchColumn();

        $_POST = ['name' => 'Level 5', 'kind' => 'pct', 'value' => '3'];
        $this->render(fn () => (new PricingController())->addLevel([]));
        self::assertSame('A level called "Level 5" already exists.', $this->flash());
        $_POST = ['name' => 'Level 6', 'kind' => 'pct', 'value' => '60'];
        $this->render(fn () => (new PricingController())->addLevel([]));
        self::assertStringContainsString('up to 50', $this->flash());

        // A level used by the ladder cannot be deleted, only deactivated.
        $level1 = (int) Database::connection()->query("SELECT id FROM commission_levels WHERE name = 'Level 1'")->fetchColumn();
        $this->render(fn () => (new PricingController())->deleteLevel(['id' => (string) $level1]));
        self::assertStringContainsString('is in use', $this->flash());
        $this->render(fn () => (new PricingController())->toggleLevel(['id' => (string) $level1]));
        self::assertStringContainsString('deactivated', $this->flash());
        $this->render(fn () => (new PricingController())->toggleLevel(['id' => (string) $level1]));
        $this->flash();
        $this->render(fn () => (new PricingController())->deleteLevel(['id' => (string) $id]));
        self::assertSame('Level "Level 5" deleted.', $this->flash());

        $ids = Database::connection()->query("SELECT name, id FROM commission_levels")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $post = static fn (array $rows): array => ['from' => array_column($rows, 0), 'to' => array_column($rows, 1), 'level_id' => array_column($rows, 2)];
        $_POST = $post([['1', '5', $ids['Level 1']], ['7', '', $ids['Level 2']]]);
        $this->render(fn () => (new PricingController())->saveLadder([]));
        self::assertSame('The ladder has a gap: no step covers order 6.', $this->flash());
        $_POST = $post([['1', '5', $ids['Level 1']], ['5', '', $ids['Level 2']]]);
        $this->render(fn () => (new PricingController())->saveLadder([]));
        self::assertSame('Steps overlap at order 5.', $this->flash());
        $_POST = $post([['1', '5', $ids['Level 1']], ['6', '10', $ids['Level 2']]]);
        $this->render(fn () => (new PricingController())->saveLadder([]));
        self::assertStringContainsString('Leave the last step', $this->flash());
        $_POST = $post([['1', '3', $ids['Level 1']], ['4', '', $ids['Level 4']], ['', '', '']]);
        $this->render(fn () => (new PricingController())->saveLadder([]));
        self::assertStringContainsString('Ladder saved', $this->flash());
        self::assertSame([[1, 3, 'Level 1'], [4, null, 'Level 4']], array_map(static fn (array $s): array => [(int) $s['from_order'], $s['to_order'] === null ? null : (int) $s['to_order'], $s['level_name']], PricingRepository::ladder()));
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'COMMISSION_LADDER_CHANGED'")->fetchColumn() >= 1 ? 1 : 0);

        // Put the seeded ladder back for other tests.
        $_POST = $post([['1', '5', $ids['Level 1']], ['6', '10', $ids['Level 2']], ['11', '20', $ids['Level 3']], ['21', '', $ids['Level 4']]]);
        $this->render(fn () => (new PricingController())->saveLadder([]));
    }

    public function testAssociateCommissionLevelIsMdOnly(): void
    {
        $md = $this->createTestUser('Managing Director');
        $this->actAs($md);
        $level2 = (int) Database::connection()->query("SELECT id FROM commission_levels WHERE name = 'Level 2'")->fetchColumn();
        $_POST = ['name' => 'Jest Associate B', 'based_in' => 'india', 'currency_code' => 'INR', 'commission_mode' => 'fixed', 'commission_level_id' => ''];
        $this->render(fn () => (new AssociateAdminController())->save([]));
        self::assertStringContainsString('Choose an active commission level', $this->flash());

        $_POST['commission_level_id'] = (string) $level2;
        $this->render(fn () => (new AssociateAdminController())->save([]));
        $a = Database::connection()->query("SELECT id, commission_mode, commission_level_id FROM associates WHERE name = 'Jest Associate B' ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame(['fixed', $level2], [$a['commission_mode'], (int) $a['commission_level_id']]);
        self::assertSame('Fixed: Level 2 — 5%', Database::connection()->query("SELECT new_value FROM audit_log WHERE action_type = 'ASSOCIATE_COMMISSION_LEVEL_CHANGED' AND entity_id = {$a['id']}")->fetchColumn());

        // The ED may edit an associate but not the commission level: what they post does not count.
        $this->actAs($this->createTestUser('Executive Director'));
        $_POST = ['name' => 'Jest Associate B', 'based_in' => 'india', 'currency_code' => 'INR', 'commission_mode' => 'ladder'];
        $this->render(fn () => (new AssociateAdminController())->save(['id' => (string) $a['id']]));
        $after = Database::connection()->query("SELECT commission_mode, commission_level_id FROM associates WHERE id = {$a['id']}")->fetch();
        self::assertSame(['fixed', $level2], [$after['commission_mode'], (int) $after['commission_level_id']]);

        // A used level can only be deactivated.
        $this->actAs($md);
        $this->render(fn () => (new PricingController())->deleteLevel(['id' => (string) $level2]));
        self::assertStringContainsString('1 associate(s)', $this->flash());
    }

    public function testThePagesOpen(): void
    {
        $this->actAs($this->createTestUser('Managing Director'));
        $settings = $this->render(fn () => (new PricingController())->settings([]));
        self::assertStringContainsString('Pricing Settings', $settings);
        self::assertStringContainsString('name="margin_pct"', $settings);
        $levels = $this->render(fn () => (new PricingController())->levels([]));
        self::assertStringContainsString('Associate Commission Levels', $levels);
        self::assertStringContainsString('Level 4', $levels);
        self::assertStringContainsString('id="ladder"', $levels);

        $this->actAs($this->createTestUser('Executive Director'));
        $settings = $this->render(fn () => (new PricingController())->settings([]));
        self::assertStringContainsString('Only the MD can change these settings.', $settings);
    }
}
