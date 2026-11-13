<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierRepository;
use App\Services\OrderLinePricing;
use App\Services\PricingService;
use App\Tests\Support\DbTestCase;
use App\Tests\Support\PricingFixtures;

/**
 * QA 13 Nov 2026, SUI-006: the code fallbacks for the pricing settings (used only when a
 * pricing_settings row is missing) said margin 20 % / other costs 2.5 % while D-347 made the
 * installed values 15 % / 5.5 %. The fallbacks now equal seed.sql; a stored row still wins,
 * so no existing product's price changes. Node twin: tests/integration/sui006PricingDefaults.test.js.
 */
final class Sui006PricingDefaultsTest extends DbTestCase
{
    use PricingFixtures;

    protected function tearDown(): void
    {
        self::resetPricingSettings();
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '0' WHERE setting_key = 'program_enabled'");
        parent::tearDown();
    }

    /** @return array<string,string> setting_key => value, from docs/seed.sql's pricing_settings inserts */
    private static function seedValues(): array
    {
        $seed = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/seed.sql');
        preg_match_all('/INSERT IGNORE INTO pricing_settings \(setting_key, setting_value\) VALUES(.*?);/s', $seed, $blocks);
        $out = [];
        foreach ($blocks[1] as $block) {
            preg_match_all("/\\('([a-z_]+)',\\s*'([^']*)'\\)/", $block, $rows, PREG_SET_ORDER);
            foreach ($rows as [, $k, $v]) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function testSui006EveryFallbackEqualsTheInstalledValue(): void
    {
        $seed = self::seedValues();
        self::assertSame('15.00', $seed['margin_pct'] ?? null, 'D-347 installed margin');
        self::assertSame('5.50', $seed['other_cost_pct'] ?? null, 'D-347 installed other costs');
        foreach (PricingService::DEFAULTS as $k => $v) {
            self::assertArrayHasKey($k, $seed, "{$k} is installed by seed.sql");
            self::assertSame($seed[$k], $v, "fallback for {$k} equals the installed value");
        }
    }

    public function testSui006AStoredSettingStillWinsSoExistingPricesDoNotChange(): void
    {
        // A live site that runs on 20 % / 2.5 % keeps exactly the prices it had.
        self::pinWorkedExamplePricing();
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'program_enabled'");
        $s = PricingService::settings();
        self::assertSame([20.0, 2.5], [$s['margin_pct'], $s['other_cost_pct']]);

        $admin = $this->createTestUser('Admin');
        $id = ProductRepository::create(['name' => 'SUI006 Black Galaxy ' . bin2hex(random_bytes(3)), 'specifications' => 'Granite block', 'hs_code' => '25161100', 'reference_unit' => 'CBM'], $admin);
        $offer = ProductSupplierRepository::create(['product_id' => $id, 'supplier_name' => 'SUI006 Quarry', 'location' => null, 'contact_person' => null, 'contact_phone' => null, 'contact_email' => null,
            'fob_source' => 'direct', 'fob_value' => 80000, 'factory_cost' => null, 'transportation_cost' => null, 'packing_cost' => null, 'loading_cost' => null, 'cha_cost' => null,
            'is_primary' => 1, 'notes' => null], $admin);
        PricingRepository::setSupplierDates($offer, date('Y-m-d'), null);
        self::configureProductForQuoting($id, $admin);
        self::assertSame([1326.13, 1287.5], OrderLinePricing::pickerPrices([['id' => $id]])[$id]['staff'], 'the D-245 worked example, unchanged');
    }

    public function testSui006AMissingRowFallsBackToTheInstalledValue(): void
    {
        $pdo = Database::connection();
        $pdo->exec("DELETE FROM pricing_settings WHERE setting_key IN ('margin_pct', 'other_cost_pct')");
        try {
            $s = PricingService::settings();
            self::assertSame([15.0, 5.5], [$s['margin_pct'], $s['other_cost_pct']]);
        } finally {
            $pdo->exec("INSERT INTO pricing_settings (setting_key, setting_value) VALUES ('margin_pct', '15.00'), ('other_cost_pct', '5.50')");
        }
    }
}
