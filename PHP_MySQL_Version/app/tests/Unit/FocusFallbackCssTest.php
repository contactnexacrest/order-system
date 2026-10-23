<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * External QA report U-22: "Keyboard focus ring uses :focus-visible only
 * — not supported by Safari 14-15.3 (iOS 14-15.3), so keyboard users
 * there see no focus outline." Fix direction: add a :focus fallback.
 */
final class FocusFallbackCssTest extends TestCase
{
    public function testAFocusVisibleFallbackExistsForBrowsersWithoutFocusVisibleSupport(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/css/app.css');

        self::assertStringContainsString('@supports not selector(:focus-visible)', $css);
        self::assertMatchesRegularExpression(
            '/@supports not selector\(:focus-visible\)\{\s*a:focus, button:focus, input:focus, select:focus, textarea:focus, summary:focus, \[tabindex\]:focus\{/',
            $css
        );
    }
}
