<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\LogisticsPartnerRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Repositories\SupplierRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner requests (2 Nov 2026):
 * - "I am not sure what to put as taxable value — can you prefill taxable
 *   value / amount asked now": the supplier bill forms (Step 7e advance,
 *   Step 10a balance) start from the Supplier PO — its value before GST, its
 *   GST rate, and on the advance the amount still to ask for — with a hint
 *   naming the PO; the logistics bill form says what to type instead.
 * - Step 9a: the Freight Forwarder drop-down starts on the logistics partner
 *   already used on this order.
 * The Node suite has the same test (billPrefillAndForwarder.test.js).
 */
final class BillPrefillAndForwarderTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        http_response_code(200);
        parent::tearDown();
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function orderWithSupplierPo(string $incoterm = 'FOB', int $passedUpTo = 4): array
    {
        $orderId = $this->createTestOrder($this->createTestClient(), $incoterm);
        $stmt = Database::connection()->prepare('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)');
        for ($n = 1; $n <= $passedUpTo; $n++) {
            $stmt->execute(['s' => 'gate_passed', 'id' => $orderId, 'n' => $n]);
        }
        $stmt->execute(['s' => 'in_progress', 'id' => $orderId, 'n' => $passedUpTo + 1]);
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Prefill Quarry Co']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'PF-SPO-' . $orderId, [
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null, 'dimensions' => null,
            'dimensional_tolerance' => null, 'quantity' => '100', 'unit' => 'sqm', 'colour_reference' => null,
            'special_requirements' => null, 'unit_price_inr' => '450', 'basic_value_inr' => '45000', 'gst_rate_pct' => '5',
            'gst_amount_inr' => '2250', 'total_payable_inr' => '47250', 'advance_pct' => '30',
            'advance_amount_inr' => '14175', 'balance_amount_inr' => '33075',
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], 'issued');
        return [$orderId, OrderSupplierPoRepository::findLatestForOrder($orderId)];
    }

    private function page(int $orderId): string
    {
        \App\Services\PermissionService::resetCache();
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    /** The bill form posted for one supplier leg. */
    private function supplierForm(string $page, string $leg): string
    {
        $marker = '<input type="hidden" name="bill_for" value="' . $leg . '">';
        $start = strpos($page, $marker);
        self::assertNotFalse($start, "the {$leg} bill form is on the page");
        return substr($page, $start, strpos($page, '</form>', $start) - $start);
    }

    public function testTheAdvanceBillFormStartsFromTheSupplierPo(): void
    {
        [$orderId, $po] = $this->orderWithSupplierPo();
        $form = $this->supplierForm($this->page($orderId), 'advance');
        $ref = (string) $po['supplier_po_reference'];

        self::assertMatchesRegularExpression('/name="taxable_inr"[^>]*value="45000\.00"/', $form, 'taxable = the PO value before GST');
        self::assertStringContainsString('Pre-filled from Supplier PO ' . $ref . ' — change it if the bill shows a different amount.', $form);
        self::assertMatchesRegularExpression('/<option value="5(?:\.00)?" selected>/', $form, 'GST rate = the PO rate');
        self::assertStringContainsString('data-gst-amount value="2250.00"', $form);
        self::assertStringContainsString('data-gst-total value="47250.00"', $form);
        self::assertMatchesRegularExpression('/name="requested_amount_inr"[^>]*value="14175\.00"/', $form, 'amount asked now = the planned advance');
        self::assertStringContainsString('Pre-filled with the advance still to ask for under Supplier PO ' . $ref, $form);
    }

    public function testWhatWasAlreadyPaidOnAnAdvanceProformaIsNotAskedForAgain(): void
    {
        [$orderId, $po] = $this->orderWithSupplierPo();
        $proforma = OrderVendorBillRepository::create($orderId, 'supplier', [
            'supplier_po_id' => (int) $po['id'], 'bill_for' => 'advance', 'doc_kind' => 'proforma', 'requested_amount_inr' => 10000,
            'bill_no' => 'SPI-PART', 'taxable_inr' => 45000, 'gst_inr' => 2250, 'bill_date' => date('Y-m-d'),
        ], $this->userId);
        OrderVendorBillRepository::addPayment($proforma, 'advance', date('Y-m-d'), 10000.0, 'NEFT / RTGS', 'UTR-PART', $this->userId);

        $form = $this->supplierForm($this->page($orderId), 'advance');
        self::assertMatchesRegularExpression('/name="requested_amount_inr"[^>]*value="4175\.00"/', $form, '14,175 planned less 10,000 already paid');
    }

    public function testTheBalanceBillFormStartsFromThePoTooWithNoAskedAmount(): void
    {
        [$orderId, $po] = $this->orderWithSupplierPo('FOB', 6);
        $form = $this->supplierForm($this->page($orderId), 'balance');
        self::assertMatchesRegularExpression('/name="taxable_inr"[^>]*value="45000\.00"/', $form);
        self::assertDoesNotMatchRegularExpression('/name="requested_amount_inr"[^>]*value=/', $form);
    }

    public function testTheLogisticsBillFormSaysWhatToTypeWithoutGuessing(): void
    {
        [$orderId] = $this->orderWithSupplierPo('CIF', 6);
        $page = $this->page($orderId);
        $start = strpos($page, 'action="/orders/' . $orderId . '/vendor-bills/logistics"');
        self::assertNotFalse($start, 'the logistics bill form is on the page');
        $form = substr($page, $start, strpos($page, '</form>', $start) - $start);
        self::assertStringContainsString('The amount before GST printed on the partner&#039;s bill', $form);
        self::assertDoesNotMatchRegularExpression('/name="taxable_inr"[^>]*value=/', $form, 'no figure the app does not know');
    }

    public function testTheSuggestedForwarder(): void
    {
        $partners = [
            ['id' => 1, 'service_type' => 'both'],
            ['id' => 2, 'service_type' => 'freight_forwarder'],
            ['id' => 3, 'service_type' => 'cha'],
        ];
        $bill = static fn (int $id): array => ['vendor_kind' => 'logistics', 'logistics_partner_id' => $id];
        self::assertSame(3, LogisticsPartnerRepository::suggestedForwarderId(['freight_forwarder_partner_id' => 3], [$bill(1)], $partners), 'the one already saved');
        self::assertSame(1, LogisticsPartnerRepository::suggestedForwarderId(null, [$bill(1), $bill(1)], $partners), 'the one partner billing this order');
        self::assertSame(2, LogisticsPartnerRepository::suggestedForwarderId(null, [$bill(1), $bill(2)], $partners), 'of several, the only freight forwarder');
        self::assertNull(LogisticsPartnerRepository::suggestedForwarderId(null, [$bill(1), $bill(3)], $partners), 'several, none a forwarder');
        self::assertNull(LogisticsPartnerRepository::suggestedForwarderId(null, [], $partners), 'nothing used yet');
        self::assertNull(LogisticsPartnerRepository::suggestedForwarderId(null, [['vendor_kind' => 'supplier', 'logistics_partner_id' => 1]], $partners));
    }

    public function testStep9aPreselectsThePartnerAlreadyBillingThisOrder(): void
    {
        [$orderId] = $this->orderWithSupplierPo('CIF', 5);
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Prefill CHA & Transport', 'service_type' => 'both'], $this->userId);
        OrderVendorBillRepository::create($orderId, 'logistics', [
            'logistics_partner_id' => $partnerId, 'bill_for' => 'cha_transport', 'doc_kind' => 'proforma', 'bill_no' => 'LP-1',
            'taxable_inr' => 1000, 'gst_inr' => 180, 'bill_date' => date('Y-m-d'),
        ], $this->userId);

        $page = $this->page($orderId);
        $start = strpos($page, 'name="freight_forwarder_partner_id"');
        self::assertNotFalse($start, 'Step 9a form is on the page');
        $select = substr($page, $start, strpos($page, '</select>', $start) - $start);
        self::assertStringContainsString('<option value="' . $partnerId . '" selected>Prefill CHA &amp; Transport', $select);
        self::assertSame(1, substr_count($select, ' selected>'), 'only that one');
        self::assertStringContainsString('Pre-selected: the logistics partner already used on this order', $page);
    }
}
