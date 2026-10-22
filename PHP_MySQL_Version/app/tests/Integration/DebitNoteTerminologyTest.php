<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\DbTestCase;

/**
 * External QA report L-07: "'FDN' still printed: CI 'Freight &
 * Insurance paid separately via FDN'; Debit Note 'Please quote FDN
 * No.'" document_types.name/ref_format were already renamed
 * "Freight Debit Note" -> "Debit Note" / "SC/FDN/" -> "SC/DN/" (per
 * NexaCrest_Change_Log.txt's authoritative "Tier1/06_DebitNote"
 * entry), but the PDF templates themselves still spelled "FDN" out
 * literally in several buyer-facing sentences. The document_type_code
 * itself ('FDN') is an internal identifier, never shown to the buyer,
 * and is deliberately left unchanged.
 */
final class DebitNoteTerminologyTest extends DbTestCase
{
    public function testTheDebitNoteTemplateNoLongerSpellsOutFdn(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../templates/FDN/freight_debit_note.html.twig');

        self::assertStringNotContainsString('FDN', $html, 'the Debit Note document must never print the internal code "FDN" to the buyer');
        self::assertStringContainsString('Please quote Debit Note No.', $html);
    }

    public function testTheCommercialInvoiceTemplateNoLongerSpellsOutFdn(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../templates/CI/commercial_invoice.html.twig');

        self::assertStringNotContainsString('FDN', $html, 'the Commercial Invoice must never print the internal code "FDN" to the buyer');
        self::assertStringContainsString('PAID VIA DEBIT NOTE', $html);
        self::assertStringContainsString('Against Debit Note No.', $html);
        self::assertStringContainsString('paid separately via Debit Note', $html);
    }

    public function testTheDocumentTypeRowItselfIsRenamed(): void
    {
        $row = \App\Config\Database::connection()
            ->query("SELECT name, ref_format FROM document_types WHERE code = 'FDN'")
            ->fetch();

        self::assertSame('Debit Note', $row['name']);
        self::assertSame('SC/DN/{YYYY}/{DDMM}{NNN}', $row['ref_format']);
    }
}
