<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Services\AssociateCommission;
use App\Services\AssociatePayoutService;
use App\Services\PricingService;
use App\Tests\Support\DbTestCase;

/**
 * Owner, 14 Nov 2026: the associate commission estimate, the level fixed at the PI, the USD rate before the PI
 * and the USD 100 minimum for associates abroad use the pricing rate IN USE (Automatic = market less the cushion,
 * or Manual), not the Manual box while Automatic is on. Node twin: tests/integration/associateRateInUse.test.js.
 */
final class AssociateRateInUseTest extends DbTestCase
{
    private const FAR_DATE = '2099-01-01';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Database::connection();
        // Automatic on (market 94.25, cushion 2 % → 92.365); the Manual box holds a stale 82.
        foreach (['fx_mode' => 'auto', 'fx_cushion_pct' => '2', 'pricing_fx_rate' => '82.0000'] as $k => $v) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => $v]);
        }
        $pdo->prepare("INSERT INTO fx_rates (rate_date, market_rate, source, status, fetched_at) VALUES (:d, 94.25, 'TEST', 'in_use', :t)")
            ->execute(['d' => self::FAR_DATE, 't' => self::FAR_DATE . ' 07:00:00']);
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM fx_rates WHERE rate_date = :d')->execute(['d' => self::FAR_DATE]);
        foreach (['fx_mode', 'fx_cushion_pct', 'pricing_fx_rate'] as $k) {
            $pdo->prepare('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k')->execute(['k' => $k, 'v' => PricingService::DEFAULTS[$k]]);
        }
        parent::tearDown();
    }

    private function usdEquivalent(array $p): float
    {
        $m = new \ReflectionMethod(AssociatePayoutService::class, 'usdEquivalent');
        return (float) $m->invoke(null, $p);
    }

    public function testAllFourPlacesUseTheRateInUseNotTheManualBox(): void
    {
        $usd = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $order = Database::connection()->query('SELECT * FROM orders WHERE id = ' . $usd)->fetch(\PDO::FETCH_ASSOC);

        // 1. $10,000 in range → ₹9,23,650 (not ₹8,20,000) for the level fixed at the PI.
        self::assertEqualsWithDelta(923650.0, AssociateCommission::estimateInr($order, 10000.0), 0.01);
        // 2. Nothing received yet → estimated at the rate in use.
        self::assertSame(['rate' => 92.365, 'estimated' => true], AssociateCommission::realisedRate($order));
        // 3. A non-USD order before its PI → the rate in use; after the PI → the PI's frozen rate.
        $eur = ['currency_code' => 'EUR', 'pricing_fx_rate_at_pi' => null] + $order;
        self::assertSame(92.365, AssociateCommission::usdRate($eur));
        self::assertSame(91.5, AssociateCommission::usdRate(['pricing_fx_rate_at_pi' => '91.5'] + $eur));
        // 4. ₹8,700 owed abroad is about $94 (held), not $106.
        self::assertEqualsWithDelta(94.19, $this->usdEquivalent(['currency_code' => 'EUR', 'amount_inr' => '8700', 'amount_fc' => '80']), 0.01);
        self::assertSame(120.0, $this->usdEquivalent(['currency_code' => 'USD', 'amount_inr' => '8700', 'amount_fc' => '120']));

        // Manual mode → the Manual box is the rate in use.
        Database::connection()->exec("UPDATE pricing_settings SET setting_value = 'manual' WHERE setting_key = 'fx_mode'");
        self::assertEqualsWithDelta(820000.0, AssociateCommission::estimateInr($order, 10000.0), 0.01);
        self::assertSame(82.0, AssociateCommission::usdRate($eur));
    }
}
