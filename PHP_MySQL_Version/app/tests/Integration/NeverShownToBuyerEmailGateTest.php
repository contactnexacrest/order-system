<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-40: document_types.never_shown_to_buyer (Business
 * Rule #20) was a real, seeded column — set on SUPPO (Supplier PO, which
 * carries internal INR unit prices), BLI, COOPREP, the internal
 * checklists, and AMD — but was never actually checked anywhere in the
 * codebase. The order page only offers "Send to Buyer" for
 * category='customer_facing' documents, which happens to exclude all of
 * these by coincidence, but that's a UI convenience, not a security
 * boundary: the send route itself (EmailDispatchService::buildPreview(),
 * the one chokepoint requestSend()/sendNowOverride() both go through) did
 * no check at all, so a crafted request hitting it directly for a SUPPO
 * document's id would queue — and, once approved, actually send — a
 * buyer's internal cost data to the buyer themselves.
 */
final class NeverShownToBuyerEmailGateTest extends DbTestCase
{
    public function testBuildPreviewRefusesADocumentTypeFlaggedNeverShownToBuyer(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        // SUPPO is seeded with never_shown_to_buyer=1 (it carries internal
        // INR unit prices) — approved status so the earlier "must clear
        // internal review first" check doesn't mask this one.
        $documentId = $this->createTestDocument($orderId, 'SUPPO', 'approved', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('internal document and can never be sent to the buyer');

        EmailDispatchService::buildPreview($orderId, $documentId, 'send_qt', 1);
    }

    public function testBuildPreviewStillAllowsAnOrdinaryCustomerFacingDocument(): void
    {
        $clientId = $this->createTestClient($this->uniqueEmail());
        $orderId = $this->createTestOrder($clientId);
        // QT is customer_facing and never_shown_to_buyer=0 — must not be
        // refused by the same gate.
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', null);

        $preview = EmailDispatchService::buildPreview($orderId, $documentId, 'send_qt', 1);

        self::assertSame($documentId, $preview['document']['id']);
    }

    private function uniqueEmail(): string
    {
        return 'phpunit-buyer-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
    }
}
