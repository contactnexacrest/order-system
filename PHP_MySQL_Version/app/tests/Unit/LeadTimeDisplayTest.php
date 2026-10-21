<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\LeadTimeDisplay;
use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog item 16: lead-time days-vs-weeks display setting.
 * LeadTimeDisplay::formatRange() is the pure function the Order Detail
 * page's Production/Sailing Lead Time rows call — this is the only
 * display conversion; the stored figures and every input field always
 * stay in days.
 */
final class LeadTimeDisplayTest extends TestCase
{
    public function testDaysUnitRendersPlainIntegers(): void
    {
        self::assertSame('45–60', LeadTimeDisplay::formatRange(45, 60, 'days'));
    }

    public function testWeeksUnitConvertsAndRoundsToOneDecimal(): void
    {
        self::assertSame('6.4–8.6', LeadTimeDisplay::formatRange(45, 60, 'weeks'));
    }

    public function testWeeksUnitDropsATrailingPointZero(): void
    {
        self::assertSame('2–3', LeadTimeDisplay::formatRange(14, 21, 'weeks'), 'a whole number of weeks must render without a trailing .0');
    }

    public function testBothNullRendersEmDash(): void
    {
        self::assertSame('—', LeadTimeDisplay::formatRange(null, null, 'days'));
        self::assertSame('—', LeadTimeDisplay::formatRange(null, null, 'weeks'));
    }

    public function testOneSideNullFallsBackToQuestionMarkOnThatSideOnly(): void
    {
        self::assertSame('45–?', LeadTimeDisplay::formatRange(45, null, 'days'));
        self::assertSame('?–8.6', LeadTimeDisplay::formatRange(null, 60, 'weeks'));
    }

    public function testAnUnrecognizedUnitFallsBackToDaysBehavior(): void
    {
        self::assertSame('45–60', LeadTimeDisplay::formatRange(45, 60, 'anything-else'));
    }
}
