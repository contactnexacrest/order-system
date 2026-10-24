<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * External QA report U-20: "About 340 text elements across the 30 tested
 * screens are smaller than 11px (badges, helper text) — hard to read on
 * phones." Fix direction: minimum 12px for helper text, 11px for badges.
 */
final class MinimumFontSizeCssTest extends TestCase
{
    public function testNavBadgeMeetsTheElevenPixelBadgeFloor(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/css/app.css');

        self::assertMatchesRegularExpression('/\.nav-badge\{[^}]*font-size:11px/', $css);
        self::assertStringNotContainsString('font-size:10px', $css, 'no badge/text rule should still be below the 11px floor');
    }

    public function testHelperTextLabelsMeetTheTwelvePixelFloor(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/css/app.css');

        self::assertMatchesRegularExpression('/\.product-row label\{[^}]*font-size:0\.75rem/', $css);
        self::assertMatchesRegularExpression('/\.process-step-label\{[^}]*font-size:0\.75rem/', $css);
    }
}
