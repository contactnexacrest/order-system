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

        // Stale-test fix (QA 13 Nov 2026, D-360): only the selector part of a rule
        // (the text before '{') is checked — a comma inside a declaration such as
        // var(--navy,#1f3a68) is not a selector list.
        foreach (preg_split('/\R/', $css) as $line) {
            if (self::hasMixedFocusVisibleList($line)) {
                self::fail("A :focus-visible selector shares a rule with others (older Safari drops the whole rule): {$line}");
            }
        }
        // The check itself still catches a mixed list.
        self::assertTrue(self::hasMixedFocusVisibleList('.x:focus-visible, .y:hover{outline:0}'));
        self::assertFalse(self::hasMixedFocusVisibleList('.offer-switch:focus-visible{outline:2px solid var(--navy,#1f3a68)}'));
    }

    private static function hasMixedFocusVisibleList(string $line): bool
    {
        foreach (explode('}', $line) as $segment) {
            if (!str_contains($segment, '{')) {
                continue;
            }
            $selector = trim(substr($segment, 0, (int) strpos($segment, '{')));
            if (str_contains($selector, ':focus-visible') && str_contains($selector, ',') && !str_starts_with($selector, 'a:focus-visible, button:focus-visible')) {
                return true;
            }
        }
        return false;
    }
}
