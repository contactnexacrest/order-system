<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Point 5 — "TBC" was unclear to anyone not already familiar with trade
 * jargon; every place it was shown to a user (screens and generated PDFs)
 * now spells out "To Be Confirmed" instead. This guards against a future
 * edit reintroducing the bare abbreviation as display text. The
 * `quantity_is_tbc`/`quantity_tbc`/`product_quantity_tbc` identifiers are
 * the underlying field/column names, not display text, and are exempt.
 */
final class NoAbbreviatedTbcTest extends TestCase
{
    private const SCAN_DIRS = ['../../views', '../../templates'];

    public function testNoViewOrTemplateFileDisplaysTheBareTbcAbbreviation(): void
    {
        $base = __DIR__;
        foreach (self::SCAN_DIRS as $dir) {
            $fullDir = $base . '/' . $dir;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fullDir));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
                if (!in_array($ext, ['php', 'twig'], true)) {
                    continue;
                }
                $lines = file($file->getPathname());
                foreach ($lines as $lineNumber => $line) {
                    if (preg_match('/quantity_is_tbc|quantity_tbc/', $line)) {
                        continue;
                    }
                    self::assertDoesNotMatchRegularExpression(
                        '/\bTBC\b/',
                        $line,
                        "{$file->getPathname()}:" . ($lineNumber + 1) . ' displays the bare "TBC" abbreviation — spell out "To Be Confirmed" instead.'
                    );
                }
            }
        }
    }
}
