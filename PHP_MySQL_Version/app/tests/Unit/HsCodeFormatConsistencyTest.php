<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Point 9 — HS codes must be 6-8 plain digits, no dots (see
 * HsCodeController's validation regex). SampleDataService used to seed
 * demo orders with the app's own former dotted default ('6802.93',
 * '2516.11') even after that format was declared wrong everywhere else —
 * a fresher browsing sample data would see the "wrong" format held up as
 * an example. This guards against that regressing: no dotted digit-group
 * HS-code-shaped literal may appear in SampleDataService's source.
 */
final class HsCodeFormatConsistencyTest extends TestCase
{
    public function testSampleDataServiceContainsNoDottedHsCodeLiterals(): void
    {
        $lines = file(__DIR__ . '/../../src/Services/SampleDataService.php');
        foreach ($lines as $lineNumber => $line) {
            // Money amounts elsewhere in this file are also N.NN literals
            // (e.g. '95000.00'), so only lines that actually mention
            // hs_code are in scope here.
            if (stripos($line, 'hs_code') === false) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                '/[\'"]\d{4}\.\d{2}[\'"]/',
                $line,
                'Line ' . ($lineNumber + 1) . ' seeds a dotted-format HS code literal (e.g. "6802.93") — use a plain 6-8 digit code.'
            );
        }
    }

    public function testOrderProductRepositoryDefaultHsCodeIsSixOrEightDigitsNoDots(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Repositories/OrderProductRepository.php');
        preg_match_all('/hs_code.*?[\'"](\d+(?:\.\d+)?)[\'"]/', $source, $matches);
        self::assertNotEmpty($matches[1], 'expected to find at least one hs_code default literal to check');
        foreach ($matches[1] as $code) {
            self::assertMatchesRegularExpression('/^\d{6}$|^\d{8}$/', $code, "hs_code default '{$code}' must be 6 or 8 plain digits, no dots");
        }
    }
}
