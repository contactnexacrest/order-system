<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 0 item 2 of the approved remediation plan: the user's later,
 * more specific correction to the Freight Debit Note said it must ALWAYS
 * be a pure cost reimbursement with no GST/IGST branch at all — not a
 * document whose GST treatment depends on a staff-chosen setting. The
 * original flaw fix only replaced a vague "As advised by CA" placeholder
 * with an IGST_18%/NIL toggle, which still left a conditional branch the
 * correction explicitly ruled out.
 */
final class FdnAlwaysPureCostReimbursementTest extends TestCase
{
    public function testFdnTemplateHasNoGstIgstConditionalBranch(): void
    {
        $twig = (string) file_get_contents(__DIR__ . '/../../templates/FDN/freight_debit_note.html.twig');

        self::assertStringNotContainsString('gst_treatment', $twig);
        self::assertStringNotContainsString('IGST_18', $twig);
        self::assertStringContainsString('pure cost reimbursement', $twig);
    }

    public function testFdnDocxBuilderHasNoGstIgstConditionalBranch(): void
    {
        $php = (string) file_get_contents(__DIR__ . '/../../src/Services/Docx/DocxDocumentBuilder.php');

        self::assertStringNotContainsString('gst_treatment', $php);
        self::assertStringContainsString('pure cost reimbursement, not a taxable service supply', $php);
    }
}
