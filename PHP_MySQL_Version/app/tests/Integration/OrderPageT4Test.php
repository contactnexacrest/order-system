<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Controllers\SupplierActionController;
use App\Helpers\Flash;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\WetSignatureGuardService;
use App\Tests\Support\DbTestCase;

/**
 * Owner requests (2026-10-26, t4): Step 12 can be skipped with a reason
 * (figures stay blank, never 0), and Step 7c gets a no-login Supplier
 * Action Link plus a reasoned "supplier did not acknowledge" skip.
 */
final class OrderPageT4Test extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        Flash::pull();
    }

    private function createSupplierPo(int $orderId): array
    {
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'T4 Quarry Supplier Co']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'T4-SPO-' . $orderId, [
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null,
            'dimensions' => null, 'dimensional_tolerance' => null, 'quantity' => null,
            'unit' => null, 'colour_reference' => null, 'special_requirements' => null,
            'unit_price_inr' => '1234', 'basic_value_inr' => null, 'gst_rate_pct' => null,
            'gst_amount_inr' => null, 'total_payable_inr' => null, 'advance_pct' => null,
            'advance_amount_inr' => null, 'balance_amount_inr' => null,
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], 'issued');
        return OrderSupplierPoRepository::findLatestForOrder($orderId);
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    public function testStep12SkipNeedsAReasonAndWritesNoFigures(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $_POST = ['skip_reason' => 'short'];
        (new OrderFinancialsController())->skipFinancials(['id' => (string) $orderId]);
        self::assertNull(OrderRepository::find($orderId)['financials_skipped_at'], 'a short reason is refused');

        $_POST = ['skip_reason' => 'No costs to record on this sample order'];
        (new OrderFinancialsController())->skipFinancials(['id' => (string) $orderId]);
        $order = OrderRepository::find($orderId);
        self::assertNotNull($order['financials_skipped_at']);
        self::assertSame('No costs to record on this sample order', $order['financials_skip_reason']);
        $costRows = Database::connection()->prepare('SELECT COUNT(*) FROM order_cost_entries WHERE order_id = :id');
        $costRows->execute(['id' => $orderId]);
        self::assertSame(0, (int) $costRows->fetchColumn(), 'nothing is written as 0');

        (new OrderFinancialsController())->undoSkipFinancials(['id' => (string) $orderId]);
        self::assertNull(OrderRepository::find($orderId)['financials_skipped_at']);
    }

    public function testASupplierLinkOpensOnlyItsOwnPoAndShowsNoPricesOrBuyer(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierPo = $this->createSupplierPo($orderId);
        $token = OrderSupplierPoRepository::generateActionToken((int) $supplierPo['id']);

        self::assertSame((int) $supplierPo['id'], (int) OrderSupplierPoRepository::findByValidActionToken($token)['id']);
        self::assertNull(OrderSupplierPoRepository::findByValidActionToken('not-a-real-token'));
        self::assertNull(OrderSupplierPoRepository::findByValidActionToken(OrderSupplierPoRepository::generateActionToken((int) $supplierPo['id'], -1)), 'an expired link is refused');

        $token = OrderSupplierPoRepository::generateActionToken((int) $supplierPo['id']);
        $page = $this->render(fn () => (new SupplierActionController())->show(['token' => $token]));
        self::assertStringContainsString($supplierPo['supplier_po_reference'], $page);
        self::assertStringContainsString('/acknowledgment', $page);
        // Random link/CSRF tokens can contain any digit run — drop them before
        // checking that the PO price never reaches the supplier page.
        self::assertStringNotContainsString('1234', (string) preg_replace('/[A-Za-z0-9_-]{20,}/', '', $page), 'no PO prices on the supplier page');
        self::assertStringNotContainsString((string) OrderRepository::find($orderId)['company_legal_name'], $page, 'the buyer is never named to the supplier');

        $expired = $this->render(fn () => (new SupplierActionController())->show(['token' => 'bogus']));
        self::assertStringContainsString('Link No Longer Valid', $expired);
    }

    public function testStaffCanRecordThatTheSupplierDidNotAcknowledge(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierPo = $this->createSupplierPo($orderId);
        $previous = CompanySettingsRepository::get('wet_signature_required_supplier_po');
        Database::connection()->prepare("UPDATE company_settings SET setting_value = '1' WHERE setting_key = 'wet_signature_required_supplier_po'")->execute();
        try {
            self::assertTrue(WetSignatureGuardService::supplierPoBlocked((int) $supplierPo['id']));

            $_POST = ['skip_reason' => 'nope'];
            (new OrderController())->skipSupplierPoAck(['id' => (string) $orderId]);
            self::assertTrue(WetSignatureGuardService::supplierPoBlocked((int) $supplierPo['id']), 'a short reason is refused');

            $_POST = ['skip_reason' => 'Supplier confirmed by phone, will not sign'];
            (new OrderController())->skipSupplierPoAck(['id' => (string) $orderId]);
            self::assertFalse(WetSignatureGuardService::supplierPoBlocked((int) $supplierPo['id']), 'Step 7d opens once the skip is recorded');
            self::assertSame('Supplier confirmed by phone, will not sign', OrderSupplierPoRepository::findLatestForOrder($orderId)['ack_skip_reason']);
        } finally {
            Database::connection()->prepare('UPDATE company_settings SET setting_value = :v WHERE setting_key = \'wet_signature_required_supplier_po\'')->execute(['v' => (string) $previous]);
        }
    }

    public function testGeneratingTheSupplierLinkShowsItOnTheOrderPage(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->createSupplierPo($orderId);

        (new OrderController())->generateSupplierActionLink(['id' => (string) $orderId]);
        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        self::assertStringContainsString('/supplier-action/', $flash[0]['message']);
        self::assertNotNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['supplier_action_token_hash']);
    }
}
