<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\VendorBillController;
use App\Helpers\Flash;
use App\Repositories\IncentiveRepository;
use App\Repositories\LogisticsPartnerRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Repositories\SupplierRepository;
use App\Services\CourierCharges;
use App\Services\PermissionService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner points of 3 Nov 2026 (part B):
 *  4 — logistics bill: Payment "Advance" goes with "Proforma / advance request", "Final" with "Tax invoice / final bill";
 *  5 — a read-only, system-calculated "To pay now" with its working on every payment form;
 *  6 — the bills table "Total cost" row counts the advance paid on a replaced proforma, once
 *      (screenshot: ₹196,664.70 + ₹458,884.30 = ₹655,549.00, Nothing due);
 *  7 — "Paid from" / "Paid by" default to Company account;
 *  8 — Step 13f courier charges booked as the order cost "Courier Charges" with the D-212 description;
 *  9 — Step 12d hidden while the team incentive program is off, unless an associate's commission is due.
 * Node twin: ownerPointsNov3PartB.test.js.
 */
final class OwnerPointsNov3PartBTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$this->userId}");
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        $_FILES = [];
        PermissionService::resetCache();
        Flash::pull();
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
        parent::tearDown();
    }

    private function lastFlash(): array
    {
        $messages = Flash::pull();
        return end($messages) ?: ['type' => null, 'message' => ''];
    }

    private function render(int $orderId): string
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    /** The screenshot's order: Supplier PO ₹655,549.00, advance ₹196,664.70. */
    private function orderWithSupplierPo(): array
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $stmt = Database::connection()->prepare('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)');
        foreach ([1, 2, 3, 4, 5, 6, 7] as $n) {
            $stmt->execute(['s' => 'gate_passed', 'id' => $orderId, 'n' => $n]);
        }
        $stmt->execute(['s' => 'in_progress', 'id' => $orderId, 'n' => 8]);
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Nov3 Quarry Co']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'N3-SPO-' . $orderId, [
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null, 'dimensions' => null,
            'dimensional_tolerance' => null, 'quantity' => '100', 'unit' => 'sqm', 'colour_reference' => null,
            'special_requirements' => null, 'unit_price_inr' => '6555.49', 'basic_value_inr' => '655549', 'gst_rate_pct' => '0',
            'gst_amount_inr' => '0', 'total_payable_inr' => '655549', 'advance_pct' => '30',
            'advance_amount_inr' => '196664.70', 'balance_amount_inr' => '458884.30',
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], 'issued');
        return [$orderId, OrderSupplierPoRepository::findLatestForOrder($orderId)];
    }

    private function pay(int $orderId, int $billId, float $amount, ?string $leg = null): array
    {
        $_POST = ['paid_at' => date('Y-m-d'), 'paid_amount_inr' => (string) $amount, 'payment_mode' => 'NEFT / RTGS', 'payment_ref' => 'UTR' . $billId . '-' . $amount] + ($leg !== null ? ['leg' => $leg] : []);
        (new VendorBillController())->payBill(['id' => (string) $orderId, 'billId' => (string) $billId]);
        return $this->lastFlash();
    }

    public function testPoint4AdvanceIsAProformaAndFinalIsTheTaxInvoice(): void
    {
        self::assertNull(VendorBillController::paymentKindMismatch('advance', 'proforma'));
        self::assertNull(VendorBillController::paymentKindMismatch('final', 'invoice'));
        self::assertSame('An Advance bill is a Proforma / advance request — pick that bill type, or choose Payment "Final" for a tax invoice.', VendorBillController::paymentKindMismatch('advance', 'invoice'));
        self::assertSame('A Final bill is the Tax invoice / final bill — pick that bill type, or choose Payment "Advance" for a proforma.', VendorBillController::paymentKindMismatch('final', 'proforma'));

        [$orderId] = $this->orderWithSupplierPo();
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Nov3 CHA', 'service_type' => 'cha'], $this->userId);
        $tmp = tempnam(sys_get_temp_dir(), 'bill-');
        file_put_contents($tmp, '%PDF-1.4 bill');
        $base = ['logistics_partner_id' => (string) $partnerId, 'bill_for' => 'cha', 'bill_date' => date('Y-m-d'), 'taxable_inr' => '1000', 'gst_rate_pct' => '18'];
        try {
            foreach ([
                'An Advance bill is a Proforma / advance request — pick that bill type, or choose Payment "Final" for a tax invoice.' => ['payment_kind' => 'advance', 'doc_kind' => 'invoice', 'bill_no' => 'N3-A'],
                'A Final bill is the Tax invoice / final bill — pick that bill type, or choose Payment "Advance" for a proforma.' => ['payment_kind' => 'final', 'doc_kind' => 'proforma', 'bill_no' => 'N3-B'],
            ] as $expected => $fields) {
                $_FILES = ['bill_file' => ['name' => 'bill.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
                $_POST = $base + $fields;
                (new VendorBillController())->addLogisticsBill(['id' => (string) $orderId]);
                self::assertSame($expected, $this->lastFlash()['message'], 'refused on the server, even when posted directly');
            }
            self::assertCount(0, OrderVendorBillRepository::forOrder($orderId, 'logistics'), 'nothing was added');
        } finally {
            @unlink($tmp);
        }
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/views/orders/show.php');
        self::assertStringContainsString("var want=s.value==='advance'?'proforma':'invoice'", $view, 'the browser picks the bill type live');
        self::assertStringContainsString('Advance = a Proforma / advance request; Final = the Tax invoice / final bill.', $view);
    }

    public function testPoints5And6ToPayNowAndTheTotalRowWithTheScreenshotFigures(): void
    {
        [$orderId, $po] = $this->orderWithSupplierPo();
        $proforma = OrderVendorBillRepository::create($orderId, 'supplier', ['supplier_po_id' => (int) $po['id'], 'bill_for' => 'advance', 'doc_kind' => 'proforma', 'requested_amount_inr' => 196664.70, 'bill_no' => 'PI-7', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 655549, 'gst_inr' => 0], $this->userId);
        $tpn = OrderVendorBillRepository::toPayNow(OrderVendorBillRepository::find($proforma));
        self::assertSame(196664.70, $tpn['amount']);
        self::assertSame('asked now on the proforma ₹196,664.70', $tpn['working']);

        $bills = OrderVendorBillRepository::forOrder($orderId);
        self::assertSame(['amount' => 196664.70, 'working' => 'planned advance ₹196,664.70 − already paid ₹0.00'], OrderVendorBillRepository::supplierLegToPayNow($bills, $po, 'advance'));

        self::assertSame('success', $this->pay($orderId, $proforma, 196664.70, 'advance')['type']);
        $invoice = OrderVendorBillRepository::create($orderId, 'supplier', ['supplier_po_id' => (int) $po['id'], 'bill_for' => 'balance', 'doc_kind' => 'invoice', 'replaces_bill_id' => $proforma, 'bill_no' => 'TI-7', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 655549, 'gst_inr' => 0], $this->userId);

        $tpn = OrderVendorBillRepository::toPayNow(OrderVendorBillRepository::find($invoice));
        self::assertSame(458884.30, $tpn['amount']);
        self::assertSame('bill total ₹655,549.00 − advance paid on proforma PI-7 ₹196,664.70', $tpn['working']);
        $bills = OrderVendorBillRepository::forOrder($orderId);
        self::assertSame(['amount' => 458884.30, 'working' => 'PO total ₹655,549.00 − advance paid ₹196,664.70 − balance already paid ₹0.00'], OrderVendorBillRepository::supplierLegToPayNow($bills, $po, 'balance'));
        self::assertSame(['amount' => 0.0, 'working' => 'planned advance ₹196,664.70 − already paid ₹196,664.70'], OrderVendorBillRepository::supplierLegToPayNow($bills, $po, 'advance'));

        $page = $this->render($orderId);
        self::assertStringContainsString('<strong>To pay now: &#8377;458,884.30</strong> <small class="muted">(bill total ₹655,549.00 − advance paid on proforma PI-7 ₹196,664.70)</small>', $page);
        self::assertStringContainsString('<strong>Balance to pay now: &#8377;458,884.30</strong>', $page);

        self::assertSame('success', $this->pay($orderId, $invoice, 458884.30, 'balance')['type']);
        $bills = OrderVendorBillRepository::forOrder($orderId);
        $invoiceOnly = array_values(array_filter($bills, static fn (array $b): bool => (int) $b['id'] === $invoice));
        self::assertSame(['cost' => 655549.0, 'paid' => 655549.0, 'due' => 0.0], OrderVendorBillRepository::totalsFor($invoiceOnly), '10a lists only the tax invoice: 196,664.70 + 458,884.30');
        self::assertSame(['cost' => 655549.0, 'paid' => 655549.0, 'due' => 0.0], OrderVendorBillRepository::totalsFor($bills), 'both listed: the advance is counted once');
        self::assertStringContainsString('<span class="vendor-bill-paid">Paid &#8377;655,549.00</span><br><span class="vendor-bill-paid">Nothing due</span>', $this->render($orderId));
    }

    public function testPoint6LogisticsProformaPaymentCountsOnceInTheTotalRow(): void
    {
        [$orderId] = $this->orderWithSupplierPo();
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Nov3 Forwarder', 'service_type' => 'cha'], $this->userId);
        $proforma = OrderVendorBillRepository::create($orderId, 'logistics', ['logistics_partner_id' => $partnerId, 'bill_for' => 'cha', 'payment_kind' => 'advance', 'doc_kind' => 'proforma', 'bill_no' => 'CHA-PI', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 196664.70, 'gst_inr' => 0], $this->userId);
        self::assertSame('success', $this->pay($orderId, $proforma, 196664.70)['type']);
        $invoice = OrderVendorBillRepository::create($orderId, 'logistics', ['logistics_partner_id' => $partnerId, 'bill_for' => 'cha', 'payment_kind' => 'final', 'doc_kind' => 'invoice', 'replaces_bill_id' => $proforma, 'bill_no' => 'CHA-TI', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 655549, 'gst_inr' => 0], $this->userId);
        self::assertSame('bill total ₹655,549.00 − advance paid on proforma CHA-PI ₹196,664.70', OrderVendorBillRepository::toPayNow(OrderVendorBillRepository::find($invoice))['working']);
        self::assertSame('success', $this->pay($orderId, $invoice, 458884.30)['type']);
        self::assertSame(['cost' => 655549.0, 'paid' => 655549.0, 'due' => 0.0], OrderVendorBillRepository::totalsFor(OrderVendorBillRepository::forOrder($orderId, 'logistics')));
    }

    public function testPoint5BuyerToPayNowLine(): void
    {
        $rd = ['label' => 'Balance', 'due' => 12500.0, 'so_far' => 4000.0, 'currency' => 'USD', 'max_pct' => 3.0];
        ob_start();
        require dirname(__DIR__, 2) . '/views/orders/_remittance_difference.php';
        $html = (string) ob_get_clean();
        self::assertStringContainsString('<p class="full to-pay-now"><strong>Buyer to pay now: USD 8,500.00</strong> <small class="muted">(balance due USD 12,500.00 − already received USD 4,000.00)</small></p>', $html);
    }

    public function testPoint7PaidFromDefaultsToCompanyAccount(): void
    {
        [$orderId] = $this->orderWithSupplierPo();
        $page = $this->render($orderId);
        foreach (['paid_from', 'paid_by'] as $name) {
            self::assertSame(1, preg_match('#<select name="' . $name . '" required>(.*?)</select>#s', $page, $m), $name . ' is on the page');
            self::assertStringNotContainsString('Choose…', $m[1], $name . ': no blank choice');
            self::assertStringContainsString('<option value="company_account" selected>Company account</option>', $m[1], $name . ': Company account by default');
        }
    }

    private function orderAtStage9(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');
        for ($stage = 1; $stage <= 8; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $this->createTestDocument($orderId, 'BLI', 'approved');
        $this->createTestDocument($orderId, 'COOPREP', 'sent');
        return $orderId;
    }

    private function close(int $orderId, array $post): void
    {
        $_POST = $post;
        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();
    }

    public function testPoint8CourierChargesAreCheckedAndBookedWithTheirDescription(): void
    {
        self::assertNull(CourierCharges::parse(['courier_charges_inr' => '']));
        self::assertNull(CourierCharges::parse(['courier_charges_inr' => '0']));
        self::assertSame('Courier charges must be a number of rupees (0 or more), or leave the box blank.', CourierCharges::parse(['courier_charges_inr' => '-5']));
        self::assertSame('Courier charges must be a number of rupees (0 or more), or leave the box blank.', CourierCharges::parse(['courier_charges_inr' => 'abc']));
        self::assertSame('Courier charges of ₹100,000.01 look too high for one document courier (more than ₹100,000.00). Check the courier bill.', CourierCharges::parse(['courier_charges_inr' => '100000.01']));
        self::assertSame('Choose what the courier charges were paid from.', CourierCharges::parse(['courier_charges_inr' => '10', 'courier_paid_from' => 'cash']));
        self::assertSame(['amount' => 100000.0, 'company' => '', 'paid_from' => 'company_account'], CourierCharges::parse(['courier_charges_inr' => '100000']));

        // Refused: nothing is closed or booked.
        $orderId = $this->orderAtStage9();
        $this->close($orderId, ['courier_tracking_number' => '1234567890', 'courier_charges_inr' => '250000']);
        self::assertSame('Courier charges of ₹250,000.00 look too high for one document courier (more than ₹100,000.00). Check the courier bill.', $this->lastFlash()['message']);
        self::assertSame('active', OrderRepository::find($orderId)['status']);

        $this->close($orderId, ['courier_tracking_number' => '1234567890', 'courier_company' => 'DHL Express', 'courier_charges_inr' => '3540', 'courier_paid_from' => 'company_card']);
        $order = OrderRepository::find($orderId);
        self::assertSame('shipped_docs_sent', $order['status']);
        $entries = array_values(array_filter(OrderCostEntryRepository::forOrder($orderId), static fn (array $e): bool => $e['category'] === 'courier'));
        self::assertCount(1, $entries);
        $expected = 'Courier charges for the original document set of order ' . $order['buyer_inquiry_ref'] . ' to ' . $order['company_legal_name'] . ' — DHL Express AWB 1234567890, ₹3,540.00 incl. GST';
        self::assertSame($expected, $entries[0]['description']);
        self::assertSame(3540.0, (float) $entries[0]['amount_inr']);
        self::assertSame('company_card', $entries[0]['paid_from']);
        self::assertSame('Courier Charges', OrderCostEntryRepository::CATEGORIES['courier']);
        self::assertContains('courier', OrderCostEntryRepository::ZOHO_CATEGORIES, 'goes to Zoho Books at the next sync');
        $audit = Database::connection()->query("SELECT new_value, reason FROM audit_log WHERE action_type = 'COURIER_CHARGES_RECORDED' AND entity_id = " . (int) $entries[0]['id'])->fetch();
        self::assertSame('3540.00', $audit['new_value']);

        // No charges typed in: closes as before, no cost.
        $other = $this->orderAtStage9();
        $this->close($other, ['courier_tracking_number' => 'AWB-2']);
        self::assertSame('shipped_docs_sent', OrderRepository::find($other)['status']);
        self::assertSame([], array_filter(OrderCostEntryRepository::forOrder($other), static fn (array $e): bool => $e['category'] === 'courier'));
        self::assertSame('Courier charges for the original document set of order NC/SC/2026/0411001 to Acme GmbH — AWB X1, ₹10.50 incl. GST',
            CourierCharges::description(['buyer_inquiry_ref' => 'NC/SC/2026/0411001', 'company_legal_name' => 'Acme GmbH'], 1, '', 'X1', 10.5));
    }

    public function testPoint9Step12dFollowsTheIncentiveProgram(): void
    {
        $plain = $this->createTestOrder($this->createTestClient());
        IncentiveRepository::setSetting('program_enabled', '0', $this->userId);
        $html = $this->render($plain);
        self::assertStringNotContainsString('id="financials-12d"', $html, 'program off, no associate: no Step 12d');

        IncentiveRepository::setSetting('program_enabled', '1', $this->userId);
        $html = $this->render($plain);
        self::assertStringContainsString('Step 12d &middot; Team Incentive', $html);
        self::assertStringContainsString('Who gets what', $html);
        self::assertStringContainsString("and the team's incentive", $html);

        $pdo = Database::connection();
        $pdo->exec("INSERT INTO associates (name) VALUES ('TEST-Associate Nov3')");
        $associateId = (int) $pdo->lastInsertId();
        $client = $this->createTestClient();
        $pdo->exec("UPDATE clients SET associate_id = {$associateId} WHERE id = {$client}");
        $withAssociate = $this->createTestOrder($client);
        IncentiveRepository::setSetting('program_enabled', '0', $this->userId);
        $html = $this->render($withAssociate);
        self::assertStringContainsString('Step 12d &middot; Associate Commission', $html, 'program off: only the associate part');
        self::assertStringContainsString("and the associate's commission", $html);
        self::assertStringContainsString('Associate commission on this order', $html);
        self::assertStringNotContainsString('Who gets what', $html);
        self::assertStringNotContainsString('Team reward', $html);
        self::assertStringNotContainsString('Exclude this order (no team reward)', $html);
        self::assertStringContainsString('<input type="hidden" name="reward_override_inr" value="0">', $html, 'saving keeps the team settings');
    }
}
