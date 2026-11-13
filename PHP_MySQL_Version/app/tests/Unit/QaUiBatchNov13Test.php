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

    /** UI-011: an order line's quantity of 0 is stopped in the browser (app.js data-min-exclusive; the Jest twin runs the rule). */
    public function testUi011QuantityZeroIsCaughtInTheBrowser(): void
    {
        foreach ([__DIR__ . '/../../../public_html/assets/js/app.js', __DIR__ . '/../../../../NODE_MySQL_Version/app/public/js/app.js'] as $file) {
            $js = (string) file_get_contents($file);
            self::assertStringContainsString("el.dataset.minExclusive !== undefined", $js, $file);
            self::assertStringContainsString("parseFloat(value) <= parseFloat(el.dataset.minExclusive)", $js, $file);
            self::assertStringContainsString("el.dataset.minExclusiveUnless", $js, $file);
        }
        foreach ([
            [self::VIEWS . '/orders/create.php', 'product_quantity[]', false], [self::NODE_VIEWS . '/orders/create.njk', 'product_quantity[]', false],
            [self::VIEWS . '/orders/_products_table.php', 'quantity', true], [self::NODE_VIEWS . '/orders/_products_table.njk', 'quantity', true],
        ] as [$file, $name, $hasTbc]) {
            self::assertSame(1, preg_match('#<input type="text" name="' . preg_quote($name, '#') . '"[^>]*>#', (string) file_get_contents($file), $m), $file);
            self::assertStringContainsString('data-min-exclusive="0" data-min-exclusive-message="The quantity must be more than 0."', $m[0], $file);
            self::assertSame($hasTbc, str_contains($m[0], 'data-min-exclusive-unless="quantity_is_tbc"'), $file);
        }
    }

    /** UI-012: every typed setting on Settings › Company shows a placeholder (the Jest twin compares both lists). */
    public function testUi012SettingsPlaceholders(): void
    {
        self::assertSame('e.g. Company Name Private Limited', \App\Helpers\SettingLabels::placeholder('legal_name', 'string'));
        self::assertSame('e.g. +91-0000000000', \App\Helpers\SettingLabels::placeholder('phone', 'string'));
        self::assertSame('e.g., 30', \App\Helpers\SettingLabels::placeholder('some_days', 'number'));
        self::assertSame('e.g. info@email.com', \App\Helpers\SettingLabels::placeholder('new_mail', 'email'));
        self::assertSame('e.g. md@email.com, ed@email.com', \App\Helpers\SettingLabels::placeholder('new_list', 'email_list'));
        self::assertSame('Type the lut expiry note', \App\Helpers\SettingLabels::placeholder('lut_expiry_note', 'string'));
        foreach (\App\Helpers\SettingLabels::PLACEHOLDERS as $key => $text) {
            preg_match_all('/[\w.+-]+@[\w.-]+/', $text, $emails);
            foreach ($emails[0] as $email) {
                self::assertMatchesRegularExpression('/^(?:info|md|ed|admin)@email\.com$/', $email, $key);
            }
        }
        // The view never makes the placeholder depend on the value type (FormPlaceholderTest checks every view).
        $view = (string) file_get_contents(self::VIEWS . '/settings/index.php');
        self::assertSame(2, substr_count($view, 'placeholder="<?= htmlspecialchars(\App\Helpers\SettingLabels::placeholder('));
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
