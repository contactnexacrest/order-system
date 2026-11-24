<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\AssociateRules;
use App\Services\PricingEngine;
use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-410 (24 Nov 2026): what the client's price includes for an associate.
 *  - Quote = staff floor ÷ (1 − included %) × (1 + buffer); lowest = staff floor ÷ (1 − min(rate, included %)).
 *  - The staff price and the old associate formula do not change (owner: "the existing price calculation must not
 *    get disturbed for any other part apart from the associate changes").
 * Node twin: tests/unit/associatePriceIncludedD410.test.js.
 */
final class AssociatePriceIncludedD410Test extends TestCase
{
    private const RAW = ['staff_floor' => 1000.0, 'buffer_pct' => 3.0];

    public function testTheQuoteIsTheSameAtEveryLevelWithAFixedFivePercent(): void
    {
        $quotes = [];
        foreach ([2.0, 5.0, 7.0, 10.0] as $rate) {
            $quotes[] = PricingEngine::associateIncluded(self::RAW, $rate, 5.0)['quote'];
        }
        self::assertSame([1084.21, 1084.21, 1084.21, 1084.21], $quotes, '1000 ÷ 0.95 × 1.03');
    }

    public function testTheLowestPriceUsesTheSmallerOfTheRateAndTheIncludedPercent(): void
    {
        self::assertSame(1020.41, PricingEngine::associateIncluded(self::RAW, 2.0, 5.0)['floor'], 'below 5 %: room to negotiate (1000 ÷ 0.98)');
        self::assertSame(1052.63, PricingEngine::associateIncluded(self::RAW, 7.0, 5.0)['floor'], 'above 5 %: never above the quote basis (1000 ÷ 0.95)');
        self::assertSame(1052.63, PricingEngine::associateIncluded(self::RAW, 10.0, 5.0)['floor']);
    }

    public function testLadderRateIsTheOldFormula(): void
    {
        foreach ([2.0, 5.0, 7.0, 10.0] as $rate) {
            self::assertSame(PricingEngine::associate(self::RAW, 'pct', $rate), PricingEngine::associateIncluded(self::RAW, $rate, $rate), "{$rate} %");
        }
    }

    public function testTheOldAssociateFormulaIsUnchanged(): void
    {
        self::assertSame(['floor' => 1052.63, 'quote' => 1084.21], PricingEngine::associate(self::RAW, 'pct', 5.0));
        self::assertSame(['floor' => 1025.0, 'quote' => 1055.75], PricingEngine::associate(self::RAW, 'fixed_usd', 25.0));
    }

    public function testTheStaffPriceIsUnchanged(): void
    {
        // The same build as before D-410: nothing in it reads the associate settings.
        $b = PricingEngine::build(['supplier_inr' => 80000.0, 'fx_rate' => 82.0, 'other_mode' => 'pct', 'other_value' => 5.5, 'margin_pct' => 15.0,
            'incentive_mode' => 'pct', 'incentive_value' => 15.0, 'program_on' => true, 'buffer_pct' => 3.0, 'adjust' => null]);
        self::assertSame([1210.90, 1238.15, 1275.29], [$b['minimum'], $b['staff_floor'], $b['staff_quote']]);
    }

    public function testThePartFromOurMargin(): void
    {
        self::assertSame(0.0, AssociateRules::marginPct(2.0, 5.0));
        self::assertSame(0.0, AssociateRules::marginPct(5.0, 5.0));
        self::assertSame(2.0, AssociateRules::marginPct(7.0, 5.0));
        self::assertSame(5.0, AssociateRules::marginPct(10.0, 5.0));
        self::assertSame(0.0, AssociateRules::marginPct(null, 5.0), 'a USD-per-unit level');
    }

    public function testInvalidPercentagesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PricingEngine::associateIncluded(self::RAW, 5.0, 100.0);
    }
}
