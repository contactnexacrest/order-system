<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\PricingEngine;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-245 (4 Nov 2026): the price build-up to the paisa, with the
 * plan's worked example (₹80,000 at ₹82.00, 2.5% other costs, 20% margin, 15%
 * incentive reserve, 3% buffer). Node twin: tests/unit/pricingEngine.test.js.
 */
final class PricingEngineTest extends TestCase
{
    private static function example(bool $programOn = true): array
    {
        return PricingEngine::build([
            'supplier_inr' => 80000.0, 'fx_rate' => 82.0,
            'other_mode' => 'pct', 'other_value' => 2.5,
            'margin_pct' => 20.0,
            'incentive_mode' => 'pct', 'incentive_value' => 15.0, 'program_on' => $programOn,
            'buffer_pct' => 3.0,
        ]);
    }

    public function testThePlansWorkedExample(): void
    {
        $b = self::example();
        self::assertSame(
            [975.61, 24.39, 1000.0, 1250.0, 37.5, 1287.5, 1326.13],
            [$b['supplier_usd'], $b['other_costs'], $b['cost'], $b['minimum'], $b['reserve'], $b['staff_floor'], $b['staff_quote']]
        );
    }

    public function testAssociateLevels(): void
    {
        $raw = self::example()['raw'];
        self::assertSame(['floor' => 1313.78, 'quote' => 1353.19], PricingEngine::associate($raw, 'pct', 2.0));
        self::assertSame(['floor' => 1355.26, 'quote' => 1395.92], PricingEngine::associate($raw, 'pct', 5.0));
        self::assertSame(['floor' => 1384.41, 'quote' => 1425.94], PricingEngine::associate($raw, 'pct', 7.0));
        self::assertSame(['floor' => 1430.56, 'quote' => 1473.47], PricingEngine::associate($raw, 'pct', 10.0));
        self::assertSame(['floor' => 1317.5, 'quote' => 1357.03], PricingEngine::associate($raw, 'fixed_usd', 30.0));
    }

    public function testProgramOffAddsNoReserve(): void
    {
        $b = self::example(false);
        self::assertSame([0.0, 1250.0, 1287.5], [$b['reserve'], $b['staff_floor'], $b['staff_quote']]);
    }

    public function testFixedAmountsAndMarginNotMarkup(): void
    {
        $b = PricingEngine::build([
            'supplier_inr' => 82000.0, 'fx_rate' => 82.0, 'other_mode' => 'usd', 'other_value' => 0.0,
            'margin_pct' => 20.0, 'incentive_mode' => 'usd', 'incentive_value' => 50.0, 'program_on' => true, 'buffer_pct' => 3.0,
        ]);
        // Margin 20% on USD 1,000 is 1,250 (markup would be 1,200); + USD 50 reserve = 1,300; × 1.03 = 1,339.
        self::assertSame([1000.0, 1250.0, 50.0, 1300.0, 1339.0], [$b['cost'], $b['minimum'], $b['reserve'], $b['staff_floor'], $b['staff_quote']]);
    }

    public function testRoundingIsHalfUpIgnoringBinaryNoise(): void
    {
        self::assertSame(1326.13, PricingEngine::round2(1326.125));
        self::assertSame(1357.03, PricingEngine::round2(1317.5 * 1.03));
        self::assertSame(0.01, PricingEngine::round2(0.005));
        self::assertSame(-2.35, PricingEngine::round2(-2.345));
        self::assertSame(1355.26, PricingEngine::round2(1287.5 / 0.95));
    }

    public function testBadInputsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PricingEngine::build(['supplier_inr' => 1.0, 'fx_rate' => 0.0, 'other_mode' => 'pct', 'other_value' => 0.0, 'margin_pct' => 20.0,
            'incentive_mode' => 'pct', 'incentive_value' => 0.0, 'program_on' => false, 'buffer_pct' => 0.0]);
    }
}
