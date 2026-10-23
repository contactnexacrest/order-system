<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-41: "Any e-mail template is accepted for any
 * document: a Supplier PO went out under the subject 'Quotation
 * SC/SPO/…'; COO Prep went out as 'Quotation — —'." Nothing tied
 * templateKey to the document actually being sent — every buyer-facing
 * document type has exactly one seeded template, send_<lowercase code>
 * (docs/schema.sql Section AU), so EmailDispatchService::buildPreview()
 * (the one chokepoint requestSend()/sendNowOverride() both go through)
 * must refuse any other template for a document-bound send.
 */
final class EmailTemplateDocumentMismatchTest extends DbTestCase
{
    public function testBuildPreviewRefusesAMismatchedTemplateForADocument(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Wrong template for this document');

        EmailDispatchService::buildPreview($orderId, $documentId, 'send_ci', 1);
    }

    public function testBuildPreviewAllowsTheMatchingTemplateForTheDocumentType(): void
    {
        $clientId = $this->createTestClient($this->uniqueEmail());
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', null);

        $preview = EmailDispatchService::buildPreview($orderId, $documentId, 'send_qt', 1);

        self::assertIsArray($preview);
    }

    public function testGenericDocumentLessTemplatesAreUnaffectedByTheGuard(): void
    {
        $clientId = $this->createTestClient($this->uniqueEmail());
        $orderId = $this->createTestOrder($clientId);

        // No documentId at all — the four generic templates (L-42) must
        // stay reachable exactly as before this fix.
        $preview = EmailDispatchService::buildPreview($orderId, null, 'payment_followup', 1);

        self::assertIsArray($preview);
    }

    private function uniqueEmail(): string
    {
        return 'phpunit-buyer-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
    }
}
