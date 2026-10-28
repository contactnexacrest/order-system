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

    /**
     * QA Round 5, R5-10: Safari 14-15.3 also ignore :has(), and drop a whole
     * rule when one selector in its list is unsupported. Every :has()
     * current/locked highlight needs an is-current/is-locked twin with the
     * same declarations (app.js sets those classes), and no rule may list a
     * :focus-visible selector beside others outside the @supports fallback.
     */
    public function testEveryHasHighlightRuleHasAClassTwinAndNoMixedFocusVisibleLists(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/css/app.css');
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/js/app.js');

        preg_match_all('/^(\.[\w-]+):has\(> summary > \.step-card-icon\.(current|locked)\)([^{]*)\{([^}]*)\}/m', $css, $rules, PREG_SET_ORDER);
        self::assertNotEmpty($rules);
        foreach ($rules as [, $box, $state, $rest, $body]) {
            self::assertStringContainsString("{$box}.is-{$state}{$rest}{{$body}}", $css, "missing older-Safari twin for {$box}:has(...{$state}){$rest}");
        }
        self::assertStringContainsString('function initStepStatusClasses()', $js);
        self::assertStringContainsString('initStepStatusClasses();', $js);

        foreach (preg_split('/\R/', $css) as $line) {
            if (str_contains($line, ':focus-visible') && str_contains($line, ',') && str_contains($line, '{') && !str_starts_with($line, 'a:focus-visible, button:focus-visible')) {
                self::fail("A :focus-visible selector shares a rule with others (older Safari drops the whole rule): {$line}");
            }
        }
    }
}
