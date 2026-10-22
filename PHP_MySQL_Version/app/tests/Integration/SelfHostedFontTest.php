<?php

declare(strict_types=1);

namespace App\Tests\Integration;

/**
 * External QA report U-13: Poppins was loaded live from Google Fonts on
 * every page — if that request is blocked or slow, the whole UI
 * silently fell back to a different font with no warning. Self-hosted
 * instead, so a page that needs the font never depends on a third-party
 * request succeeding.
 */
final class SelfHostedFontTest extends \PHPUnit\Framework\TestCase
{
    private const LAYOUTS = ['layout/base.php', 'layout/bare.php', 'layout/client.php'];

    public function testNoLayoutLoadsFontsFromGoogle(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $html = (string) file_get_contents(__DIR__ . '/../../views/' . $layout);
            self::assertStringNotContainsString('fonts.googleapis.com', $html, "$layout must not load Poppins from Google Fonts");
            self::assertStringNotContainsString('fonts.gstatic.com', $html, "$layout must not load Poppins from Google Fonts");
        }
    }

    public function testAppCssDeclaresTheSelfHostedFontFaces(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public_html/assets/css/app.css');

        foreach (['400', '500', '600', '700'] as $weight) {
            self::assertStringContainsString("font-weight: {$weight}", $css);
            self::assertStringContainsString("url('/assets/fonts/poppins-{$weight}.woff2')", $css);
        }
    }

    public function testEveryDeclaredFontFileActuallyExistsOnDisk(): void
    {
        foreach (['400', '500', '600', '700'] as $weight) {
            $path = __DIR__ . "/../../../public_html/assets/fonts/poppins-{$weight}.woff2";
            self::assertFileExists($path);
            self::assertGreaterThan(0, filesize($path));
        }
    }
}
