<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Helpers\MarkdownLite;
use PHPUnit\Framework\TestCase;

/**
 * 90-item backlog Phase 5: editor-level bold/emphasis control for the
 * T&C Clause library. tokenize() is the single source of truth both the
 * markdown_lite Twig filter (PDF) and the DOCX builder's addText() runs
 * read from — this drives it directly, same reasoning as every other
 * *CsvRows()-style pure-function test in this suite.
 */
final class MarkdownLiteTest extends TestCase
{
    public function testTokenizePlainTextIsOneUnstyledSegment(): void
    {
        $segments = MarkdownLite::tokenize('Plain clause text, nothing special.');

        self::assertSame([['text' => 'Plain clause text, nothing special.', 'bold' => false, 'italic' => false, 'underline' => false]], $segments);
    }

    public function testTokenizeBoldItalicAndUnderlineMarkers(): void
    {
        $segments = MarkdownLite::tokenize('Normal **bold** then *italic* then _underline_ done.');

        self::assertSame('Normal ', $segments[0]['text']);
        self::assertFalse($segments[0]['bold']);

        self::assertSame('bold', $segments[1]['text']);
        self::assertTrue($segments[1]['bold']);
        self::assertFalse($segments[1]['italic']);

        $boldIndex = array_search('bold', array_column($segments, 'text'), true);
        $italicIndex = array_search('italic', array_column($segments, 'text'), true);
        $underlineIndex = array_search('underline', array_column($segments, 'text'), true);

        self::assertTrue($segments[$italicIndex]['italic']);
        self::assertFalse($segments[$italicIndex]['bold']);
        self::assertTrue($segments[$underlineIndex]['underline']);
        self::assertNotFalse($boldIndex);
    }

    public function testToHtmlEscapesThenWraps(): void
    {
        self::assertSame('Buyer &amp; Seller agree that <strong>payment</strong> is due.', MarkdownLite::toHtml('Buyer & Seller agree that **payment** is due.'));
    }

    public function testToHtmlHandlesAllThreeMarkersTogether(): void
    {
        $html = MarkdownLite::toHtml('A **bold** word, an *italic* word, and an _underlined_ word.');

        self::assertSame('A <strong>bold</strong> word, an <em>italic</em> word, and an <u>underlined</u> word.', $html);
    }

    public function testUnpairedDelimiterIsLeftLiteral(): void
    {
        self::assertSame('Price is 5 * 3 = 15.', MarkdownLite::toHtml('Price is 5 * 3 = 15.'));
    }

    public function testDelimiterAdjacentToWhitespaceDoesNotOpenAMarker(): void
    {
        // A standalone "*" surrounded by spaces (e.g. a footnote marker)
        // must never be mistaken for the start of an italic run.
        self::assertSame('See note * below for details.', MarkdownLite::toHtml('See note * below for details.'));
    }

    public function testDoesNotHtmlEscapeTwiceWhenInputAlreadyHasALiteralAmpersand(): void
    {
        self::assertSame('Terms &amp; Conditions', MarkdownLite::toHtml('Terms & Conditions'));
    }

    public function testEmptyStringProducesNoSegmentsOfVisibleText(): void
    {
        self::assertSame('', MarkdownLite::toHtml(''));
    }
}
