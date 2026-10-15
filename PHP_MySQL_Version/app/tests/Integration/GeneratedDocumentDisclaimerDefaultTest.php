<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * docs/seed.sql company_settings.show_generated_document_disclaimer
 * (90-item backlog #21) — the "system-generated document" disclaimer
 * under the signature block now defaults to OFF on a fresh install,
 * not ON. Pure seed-default/data change — DocumentDataAssembler's
 * companyBlock() logic itself is unchanged, it just now reads a
 * different stored value.
 */
final class GeneratedDocumentDisclaimerDefaultTest extends DbTestCase
{
    public function testFreshSeedDefaultsDisclaimerToOff(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $data = DocumentDataAssembler::assemble($orderId);

        self::assertFalse($data['company']['show_generated_document_disclaimer']);
    }
}
