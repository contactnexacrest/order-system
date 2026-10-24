<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientPortalController;
use App\Controllers\OrderController;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog "2-column layouts for Report-a-Payment and Supplier
 * PO": both forms were a single long column of full-width <label>s —
 * every other long form in this app (New Order, Products admin, Admin
 * Overrides) already uses the existing `.field-grid` CSS class (2
 * columns, collapsing to 1 on mobile) for exactly this reason. Pure
 * markup — no field, name, or value changes, so this is a presence/
 * position check, not a behavior test.
 */
final class TwoColumnFormLayoutsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
    }

    private function unlockThroughStage4(int $orderId): void
    {
        for ($stage = 1; $stage <= 4; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    public function testSupplierPoFormIsWrappedInATwoColumnFieldGrid(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThroughStage4($orderId);
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        $gridStart = strpos($output, '<div class="field-grid">');
        $formIdPos = strpos($output, 'id="supplier-po-form"');
        $supplierSelectPos = strpos($output, 'name="supplier_id"');
        $calcPos = strpos($output, 'data-spo-calc="quantity"');
        $submitPos = strpos($output, 'Save Supplier PO Terms</button>');

        self::assertNotFalse($formIdPos);
        self::assertNotFalse($supplierSelectPos);
        self::assertNotFalse($calcPos);
        self::assertNotFalse($submitPos);
        $gridStartAfterForm = strpos($output, '<div class="field-grid">', $formIdPos);
        self::assertNotFalse($gridStartAfterForm, 'the Supplier PO form must open a field-grid wrapper');
        self::assertGreaterThan($formIdPos, $gridStartAfterForm, 'the field-grid wrapper must be inside the Supplier PO form');
        self::assertLessThan($supplierSelectPos, $gridStartAfterForm, 'the field-grid wrapper must open before the first field');
        self::assertLessThan($submitPos, $calcPos, 'auto-calc hooks must still be present, inside the form, before the submit button');
    }

    public function testClientReportAPaymentFormIsWrappedInATwoColumnFieldGrid(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        $formPos = strpos($output, 'action="/client/orders/' . $orderId . '/report-payment"');
        $paymentTypePos = strpos($output, 'name="payment_type"');
        $screenshotPos = strpos($output, 'name="screenshot"');
        $submitPos = strpos($output, 'Submit Payment Details');

        self::assertNotFalse($formPos);
        self::assertNotFalse($paymentTypePos);
        $gridStart = strpos($output, '<div class="field-grid">', $formPos);
        self::assertNotFalse($gridStart, 'the Report a Payment form must open a field-grid wrapper');
        self::assertLessThan($paymentTypePos, $gridStart, 'the field-grid wrapper must open before the first field');
        self::assertGreaterThan($screenshotPos, $submitPos, 'the submit button must come after every field, outside the grid');
    }
}
