<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog item #7/#19 ("'?' symbol in some PDFs"): several
 * templates used decorative Unicode symbols (stopwatch, warning
 * triangle, check mark, heavy check mark, double arrow) that the
 * embedded Carlito font (registered as "Aptos" — see
 * DocumentGenerationService::registerDocumentFonts()'s docblock) has no
 * glyph for. DOMPDF has no automatic font-substitution fallback the way
 * a browser does, so every one of these rendered as a literal "?"
 * tofu box in the generated PDF. Fixed by either dropping the icon
 * (stopwatch — no close Carlito equivalent) or swapping to a
 * semantically-close codepoint Carlito does carry (warning -> '!!'
 * U+203C, check mark/heavy check mark -> square root U+221A, double
 * arrow -> single arrow U+2192). This test scans every PDF template for
 * the specific numeric entities confirmed missing from Carlito, so a
 * future template can't silently reintroduce the same bug.
 */
final class TemplateGlyphCoverageTest extends TestCase
{
    /** Confirmed missing from every Carlito-Regular/Bold/Italic/BoldItalic variant via fontTools' cmap. */
    private const KNOWN_MISSING_ENTITIES = ['&#9201;', '&#9888;', '&#10003;', '&#9989;', '&#8658;'];

    public function testNoTemplateUsesAKnownCarlitoMissingGlyphEntity(): void
    {
        $templatesDir = dirname(__DIR__, 2) . '/templates';
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templatesDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            foreach (self::KNOWN_MISSING_ENTITIES as $entity) {
                if (str_contains($contents, $entity)) {
                    $offenders[] = $file->getPathname() . ' contains ' . $entity;
                }
            }
        }

        self::assertSame([], $offenders, "Template(s) still use a glyph missing from the embedded Carlito/Aptos font:\n" . implode("\n", $offenders));
    }
}
