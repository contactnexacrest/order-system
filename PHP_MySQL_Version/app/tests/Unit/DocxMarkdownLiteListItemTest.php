<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\Docx\DocxComponents;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog Phase 5: DocxComponents::addMarkdownLiteListItem() is
 * the DOCX side of the same tokenize() call the markdown_lite Twig
 * filter uses for the PDF side — this drives it directly against a real
 * PhpWord Section and inspects the resulting runs' font styles, so the
 * two renderers can never disagree about which part of a clause is
 * bold/italic/underlined.
 */
final class DocxMarkdownLiteListItemTest extends TestCase
{
    public function testPlainTextProducesOneUnstyledRun(): void
    {
        $section = (new PhpWord())->addSection();

        DocxComponents::addMarkdownLiteListItem($section, 'Plain clause text.');

        $run = $section->getElements()[0];
        $textEl = $run->getElements()[0];
        self::assertSame('Plain clause text.', $textEl->getText());
        self::assertFalse((bool) $textEl->getFontStyle()->isBold());
    }

    public function testBoldMarkerProducesABoldRunAlongsidePlainRuns(): void
    {
        $section = (new PhpWord())->addSection();

        DocxComponents::addMarkdownLiteListItem($section, 'Before **bold** after.');

        $run = $section->getElements()[0];
        $texts = array_map(static fn($el) => $el->getText(), $run->getElements());
        $boldFlags = array_map(static fn($el) => (bool) $el->getFontStyle()->isBold(), $run->getElements());

        self::assertSame(['Before ', 'bold', ' after.'], $texts);
        self::assertSame([false, true, false], $boldFlags);
    }

    public function testUnderlineMarkerSetsTheUnderlineStyle(): void
    {
        $section = (new PhpWord())->addSection();

        DocxComponents::addMarkdownLiteListItem($section, 'An _underlined_ word.');

        $run = $section->getElements()[0];
        $underlineIndex = null;
        foreach ($run->getElements() as $i => $el) {
            if ($el->getText() === 'underlined') {
                $underlineIndex = $i;
            }
        }
        self::assertNotNull($underlineIndex);
        self::assertSame('single', $run->getElements()[$underlineIndex]->getFontStyle()->getUnderline());
    }

    public function testBaseStyleIsMergedIntoEveryRun(): void
    {
        $section = (new PhpWord())->addSection();

        DocxComponents::addMarkdownLiteListItem($section, 'Some **bold** text.', ['size' => 9.5]);

        $run = $section->getElements()[0];
        foreach ($run->getElements() as $el) {
            self::assertSame(9.5, $el->getFontStyle()->getSize());
        }
    }
}
