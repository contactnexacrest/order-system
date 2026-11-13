<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * QA round 13 Nov 2026 — UI, navigation and form fixes (UI-004 …). Parity:
 * qaUiBatchNov13.test.js (which also runs the Reorder script against a small fake DOM).
 */
final class QaUiBatchNov13Test extends TestCase
{
    private const VIEWS = __DIR__ . '/../../views';
    private const NODE_VIEWS = __DIR__ . '/../../../../NODE_MySQL_Version/app/views';

    /** UI-004: "+ Add product line" clones a row kept from page load when the buyer/staff removed every line. */
    public function testUi004AddProductLineDoesNotNeedARemainingRow(): void
    {
        foreach ([
            self::VIEWS . '/client_portal/reorder.php', self::VIEWS . '/orders/create.php',
            self::NODE_VIEWS . '/client_portal/reorder.njk', self::NODE_VIEWS . '/orders/create.njk',
        ] as $file) {
            $text = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/rows\.firstElementChild\.cloneNode/', $text, $file);
            self::assertMatchesRegularExpression('/var \w+Template = document\.getElementById\(\'product-rows\'\)\.firstElementChild\.cloneNode\(true\);/', $text, $file);
            self::assertMatchesRegularExpression('/\(rows\.firstElementChild \|\| \w+Template\)\.cloneNode/', $text, $file);
        }
    }

    /**
     * UI-008: every fixed internal link in a view leads to a page route (the TDS
     * register linked to /associates/report, which does not exist). A route
     * parameter named …id only takes digits, as the controllers expect.
     */
    public function testUi008EveryFixedLinkInAViewHasAPageRoute(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public_html/index.php');
        preg_match_all("#\\\$router->get\\('([^']+)'#", $source, $m);
        $routes = array_map(
            static fn (string $p): string => '#^' . preg_replace_callback(
                '#\\\\\{([^}]+)\\\\\}#',
                static fn (array $x): string => preg_match('/id$/i', $x[1]) ? '\d+' : '[^/]+',
                preg_quote($p, '#')
            ) . '/?$#',
            $m[1]
        );
        self::assertGreaterThan(200, count($routes));
        $dead = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('#href="(/[^"<{\'$]*)"#', (string) file_get_contents($file->getPathname()), $hrefs);
            foreach ($hrefs[1] as $href) {
                $path = (string) preg_split('/[?#]/', $href)[0];
                if (preg_match('#^/(?:assets|css|js|img|favicon)#', $path) || str_starts_with($path, '//')) {
                    continue;
                }
                $found = false;
                foreach ($routes as $r) {
                    if (preg_match($r, $path)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $dead[] = substr($file->getPathname(), strlen(self::VIEWS) + 1) . ': ' . $href;
                }
            }
        }
        self::assertSame([], $dead);
    }
}
