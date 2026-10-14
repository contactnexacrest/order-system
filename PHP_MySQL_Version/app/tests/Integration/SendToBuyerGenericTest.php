<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #12: "Send to Buyer" must be generalized to every
 * document type, not re-gated per type by hand. The order page previously
 * drove this off a hardcoded exclusion array in views/orders/show.php
 * (`!in_array($d['document_type_code'], ['AMD', 'SUPPO', 'COOPREP', 'BLI'],
 * true)`), which happened to already cover more than "QT/Buyer PO only" —
 * but it silently omitted every OTHER internal/procurement document_types
 * row (CHECKLIST*, SOP_A_SALES, SOP_B_SALES, STAGEGATE, WALLREF, CAFIN):
 * none of those were in the exclusion list, so any of them would have
 * wrongly shown "Send to Buyer" if ever rendered on an order page. This
 * test proves the fix is driven by document_types.category instead, so a
 * document type is included/excluded correctly without this view ever
 * needing another hand-maintained list — including for a type that didn't
 * exist when the exclusion array was first written (CAFIN, added later).
 */
final class SendToBuyerGenericTest extends DbTestCase
{
    private function renderOrderShow(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testCafinInternalDocumentNeverOfferedSendToBuyer(): void
    {
        // CAFIN (CA Financial Annexure, category=internal) postdates the
        // original hand-written exclusion array and was never added to it —
        // exactly the gap this fix closes.
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'CAFIN', 'approved');

        $output = $this->renderOrderShow($orderId);

        self::assertStringNotContainsString(
            '<h3>Send to Buyer</h3>',
            $output,
            'CAFIN is an internal-only document type and must never offer a Send to Buyer action'
        );
    }

    public function testChecklistInternalDocumentNeverOfferedSendToBuyer(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'CHECKLIST', 'approved');

        $output = $this->renderOrderShow($orderId);

        self::assertStringNotContainsString('<h3>Send to Buyer</h3>', $output);
    }

    public function testBuyerFacingBleDocumentIsOfferedSendToBuyer(): void
    {
        // BLE (BL Endorsement, category=customer_facing) must get the
        // action generically, the same as QT/PI/OC always have, with no
        // per-type code change required.
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'BLE', 'approved');

        $output = $this->renderOrderShow($orderId);

        self::assertStringContainsString('<h3>Send to Buyer</h3>', $output);
    }

    public function testAlreadyExcludedProcurementTypesStillExcluded(): void
    {
        // Confirms no regression on the four types the old hardcoded list
        // already excluded (SUPPO here; AMD/COOPREP/BLI share the same
        // category-driven logic now).
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createTestDocument($orderId, 'SUPPO', 'approved');

        $output = $this->renderOrderShow($orderId);

        self::assertStringNotContainsString('<h3>Send to Buyer</h3>', $output);
    }
}
