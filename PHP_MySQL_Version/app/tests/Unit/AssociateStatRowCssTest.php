<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Found while recording the associate tutorial: the money boxes on the
 * associate home page and the Track record boxes on the Associate page
 * (.stat-row / .stat / .stat-label / .stat-value) had no styles, so they
 * showed as plain stacked text. They now look like the dashboard tiles.
 */
final class AssociateStatRowCssTest extends TestCase
{
    public function testStatRowBoxesAreStyledLikeTheDashboardTiles(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/css/app.css');

        self::assertMatchesRegularExpression('/\.stat-row\{display:grid;/', $css);
        self::assertMatchesRegularExpression('/\.stat-row \.stat\{[^}]*border-top:3px solid var\(--gold\)/', $css);
        self::assertMatchesRegularExpression('/\.stat-row \.stat-label\{[^}]*font-size:0\.78rem/', $css);
        self::assertMatchesRegularExpression('/\.stat-row \.stat-value\{[^}]*font-weight:800/', $css);
    }

    public function testBothViewsThatUseTheBoxesStillUseTheStyledClassNames(): void
    {
        $views = dirname(__DIR__, 2) . '/views';
        foreach (['/associate_portal/home.php', '/associates/_portal_access.php'] as $v) {
            $html = (string) file_get_contents($views . $v);
            self::assertStringContainsString('class="stat-row"', $html, $v);
            self::assertStringContainsString('class="stat-label"', $html, $v);
            self::assertStringContainsString('class="stat-value"', $html, $v);
        }
    }
}
