<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Owner decision D-414 (25 Nov 2026): every screen shows rupees in Indian grouping (₹1,85,477.76) —
 * MoneyFormat::indian, never number_format, which groups the Western way (₹185,477.76). It extends D-410 from
 * six screens to all of them. Exempt: exchange rates ("₹84.00 per USD", a rate_inr), and plain numbers in input
 * values and limits (value="…", number_format(…, 2, '.', '')), which must stay without commas. CSV files and PDFs are not screens and are unchanged.
 * Node twin: tests/unit/rupeeIndianGroupingD414.test.js.
 */
final class RupeeIndianGroupingD414Test extends TestCase
{
    public function testNoScreenGroupsRupeesTheWesternWay(): void
    {
        $root = dirname(__DIR__, 2) . '/views';
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (str_contains($line, 'per USD')) {
                    continue;
                }
                $line = preg_replace('/value="[^"]*"/', 'value=""', $line);
                if (preg_match('/(₹|&#8377;)(<\?= ?)?number_format\((?![^?]*rate)|\'(₹|&#8377;)\' \. number_format\(|number_format\(\(float\) \(?\$[\w\[\]\'"]*(?<!rate)_inr\'\]\)?, \d\)/u', $line)) {
                    $bad[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1);
                }
            }
        }
        self::assertSame([], $bad, 'rupees on screen must use MoneyFormat::indian (D-414)');
    }

    public function testIndianGrouping(): void
    {
        self::assertSame('1,85,477.76', \App\Helpers\MoneyFormat::indian(185477.76));
        self::assertSame('5,00,000', \App\Helpers\MoneyFormat::indian(500000, 0));
        self::assertSame('-12,34,567.00', \App\Helpers\MoneyFormat::indian(-1234567));
        self::assertSame('999.50', \App\Helpers\MoneyFormat::indian(999.5));
    }
}
