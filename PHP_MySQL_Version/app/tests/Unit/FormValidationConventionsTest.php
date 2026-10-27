<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Owner rule (2026-10-27): every input form, existing or future, is checked
 * in the browser before it is sent. That check lives once, sitewide, in
 * public_html/assets/js/app.js, so every layout must load it; and a
 * "Re-enter ..." field is matched to the field it confirms by name
 * ("<name>_confirm" -> "<name>", "confirm_password" -> "new_password"), so
 * the field it confirms must be on the same form.
 */
final class FormValidationConventionsTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../views';

    public function testEveryLayoutLoadsTheSitewideValidationScript(): void
    {
        foreach (glob(self::VIEWS . '/layout/*.php') as $layout) {
            self::assertStringContainsString('/assets/js/app.js', (string) file_get_contents($layout), basename($layout) . ' must load app.js');
        }
    }

    public function testEveryReEnterFieldHasTheFieldItConfirms(): void
    {
        $missing = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::VIEWS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $html = (string) file_get_contents($file->getPathname());
            preg_match_all('/<input[^>]*\bname="([a-z_]+_confirm|confirm_password)"[^>]*>/', $html, $m, PREG_SET_ORDER);
            foreach ($m as [$tag, $name]) {
                $targets = preg_match('/data-match="([a-z_]+)"/', $tag, $dm) ? [$dm[1]]
                    : ($name === 'confirm_password' ? ['new_password', 'password'] : [substr($name, 0, -strlen('_confirm'))]);
                $found = array_filter($targets, static fn (string $t): bool => str_contains($html, 'name="' . $t . '"'));
                if ($found === []) {
                    $missing[] = basename($file->getPathname()) . ': ' . $name;
                }
            }
        }
        self::assertSame([], $missing, 'a Re-enter field needs the field it confirms on the same form, or data-match naming it');
    }
}
