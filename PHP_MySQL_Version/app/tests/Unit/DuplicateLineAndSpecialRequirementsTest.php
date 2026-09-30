<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Point 6 — the New Order creation screen's product lines get the same
 * "Duplicate this line with its values" button the post-confirmation edit
 * screen already has, implemented client-side (JS clone, no blanking)
 * since these rows aren't saved yet.
 *
 * Point 7 — "Special Requirements" is renamed to "Special
 * Requirements/Instructions" everywhere it's shown to a user, and on
 * every PDF/DOCX that renders it, it gets its own amber-highlighted
 * section instead of blending into an ordinary navy section or a plain
 * table row.
 */
final class DuplicateLineAndSpecialRequirementsTest extends TestCase
{
    private const VIEWS_DIR = __DIR__ . '/../../views';
    private const TEMPLATES_DIR = __DIR__ . '/../../templates';
    private const SRC_DIR = __DIR__ . '/../../src';

    public function testNewOrderCreateScreenHasADuplicateLineButtonAndHandler(): void
    {
        $html = (string) file_get_contents(self::VIEWS_DIR . '/orders/create.php');
        self::assertStringContainsString('duplicate-row', $html, 'the product row must carry a duplicate-row button');
        self::assertStringContainsString("classList.contains('duplicate-row')", $html, 'a click handler must clone the row when a duplicate-row button is clicked');
        // The clone must NOT blank inputs (that's the existing "+ Add
        // product line" behavior) — the duplicate handler's own code block
        // must not call el.value = ''.
        $handlerStart = strpos($html, "classList.contains('duplicate-row')");
        self::assertNotFalse($handlerStart);
        $handlerBlock = substr($html, $handlerStart, 300);
        self::assertStringNotContainsString("el.value = ''", $handlerBlock, 'duplicating a line must keep its values, unlike "+ Add product line"');
    }

    public function testSpecialRequirementsIsRenamedEverywhereInViews(): void
    {
        $files = [
            self::VIEWS_DIR . '/orders/create.php',
            self::VIEWS_DIR . '/orders/edit_details.php',
            self::VIEWS_DIR . '/orders/show.php',
        ];
        foreach ($files as $file) {
            $html = (string) file_get_contents($file);
            self::assertStringContainsString('Special Requirements/Instructions', $html, "{$file} must show the renamed label");
        }
    }

    public function testSpecialRequirementsGetsItsOwnHighlightedSectionOnQtAndSuppoPdfs(): void
    {
        $qt = (string) file_get_contents(self::TEMPLATES_DIR . '/QT/quotation.html.twig');
        self::assertStringContainsString('section-title-highlight', $qt);
        self::assertStringContainsString('SPECIAL REQUIREMENTS / INSTRUCTIONS', $qt);

        $suppo = (string) file_get_contents(self::TEMPLATES_DIR . '/SUPPO/supplier_po.html.twig');
        self::assertStringContainsString('section-title-highlight', $suppo);
        self::assertStringContainsString('SPECIAL REQUIREMENTS / INSTRUCTIONS', $suppo);
        // Must no longer be just another row in the specifications table.
        self::assertStringNotContainsString('<td class="k">Special Requirements</td>', $suppo);
    }

    public function testSpecialRequirementsGetsItsOwnHighlightedSectionInDocxGeneration(): void
    {
        $docx = (string) file_get_contents(self::SRC_DIR . '/Services/Docx/DocxDocumentBuilder.php');
        self::assertStringContainsString("'SPECIAL REQUIREMENTS / INSTRUCTIONS'", $docx);
        self::assertStringContainsString('AMBER_BORDER', $docx);
        // The SUPPO kv table must no longer include Special Requirements as
        // an ordinary row.
        self::assertStringNotContainsString("'label' => 'Special Requirements'", $docx);
    }
}
