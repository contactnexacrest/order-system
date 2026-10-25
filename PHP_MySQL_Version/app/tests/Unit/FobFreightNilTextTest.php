<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Owner request (2026-10-25): for FOB, the Quotation/PI freight and
 * insurance rows print just "NIL" — "freight arranged by buyer." and
 * "buyer's responsibility." were internal notes, not text for the buyer.
 * Covers both the PDF (Twig) templates and the DOCX builder.
 */
final class FobFreightNilTextTest extends TestCase
{
    public function testNoDocumentPrintsTheInternalFobNotes(): void
    {
        $app = __DIR__ . '/../..';
        foreach ([
            $app . '/templates/QT/quotation.html.twig',
            $app . '/templates/PI/proforma_invoice.html.twig',
            $app . '/src/Services/Docx/DocxDocumentBuilder.php',
        ] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('freight arranged by buyer', $source, basename($file));
            self::assertStringNotContainsString("buyer's responsibility", $source, basename($file));
        }
    }
}
