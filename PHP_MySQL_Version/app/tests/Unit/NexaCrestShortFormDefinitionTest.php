<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 0 item 1 of the approved remediation plan: every generated document
 * must tie the short form "NexaCrest", used throughout the body clause
 * text, back to the full legal entity name ("NexaCrest International
 * Private Limited") — a gap the user caught in their own legal document
 * review self-audit. _layout.html.twig's signature_block is shared by
 * every document template (QT, PI, OC, CI, AMD, etc.), so one addition
 * there covers all of them.
 */
final class NexaCrestShortFormDefinitionTest extends TestCase
{
    public function testSharedLayoutDefinesTheNexaCrestShortFormAgainstTheFullLegalName(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../../templates/_layout.html.twig');

        self::assertStringContainsString('footer-entity-note', $html);
        self::assertStringContainsString('"NexaCrest" in this document refers to {{ company.legal_name }}.', $html);
    }
}
