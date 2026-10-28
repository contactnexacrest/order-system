<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\VendorBillController;
use App\Helpers\Flash;
use App\Repositories\LogisticsPartnerRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Repositories\SupplierRepository;
use App\Services\CaFinancialSnapshotService;
use App\Services\OrderLedgerService;
use App\Services\OrderProfitabilityService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2026-10-28, Option B, approved mockups m1–m6): a supplier
 * or logistics bill is a Proforma (asks for money now — not a cost) or a Tax
 * invoice (the cost). A proforma can say how much it asks for now; a tax
 * invoice can replace an earlier proforma from the same vendor, so what was
 * paid on it counts towards the invoice; a bill takes several payments; a
 * 7e tax invoice still owed is shown in 10a; Step 12, the ledger and CAFIN
 * count tax invoices plus what was paid on proformas not yet replaced.
 * The Node suite has the same test (vendorBillProforma.test.js).
 */
final class VendorBillProformaTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
        $_FILES = [];
        Flash::pull();
    }

    private function lastFlash(): array
    {
        $messages = Flash::pull();
        return end($messages) ?: ['type' => null, 'message' => ''];
    }

    private function orderWithSupplierPo(): array
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $stmt = Database::connection()->prepare('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)');
        foreach ([1, 2, 3, 4] as $n) {
            $stmt->execute(['s' => 'gate_passed', 'id' => $orderId, 'n' => $n]);
        }
        $stmt->execute(['s' => 'in_progress', 'id' => $orderId, 'n' => 5]);
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Proforma Quarry Co']);
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

    /** @param array<string,mixed> $extra */
    private function bill(int $orderId, string $vendorKind, array $extra): int
    {
        return OrderVendorBillRepository::create($orderId, $vendorKind, $extra + ['bill_date' => date('Y-m-d'), 'gst_inr' => 0], $this->userId);
    }

    private function pay(int $orderId, int $billId, float $amount, ?string $leg = null): array
    {
        $_POST = ['paid_at' => date('Y-m-d'), 'paid_amount_inr' => (string) $amount, 'payment_mode' => 'NEFT / RTGS', 'payment_ref' => 'UTR' . $billId . '-' . $amount] + ($leg !== null ? ['leg' => $leg] : []);
        (new VendorBillController())->payBill(['id' => (string) $orderId, 'billId' => (string) $billId]);
        return $this->lastFlash();
    }

    public function testAnAdvanceProformaThenItsTaxInvoicePaysTheSupplierExactlyOnce(): void
    {
        [$orderId, $po] = $this->orderWithSupplierPo();
        $proforma = $this->bill($orderId, 'supplier', ['supplier_po_id' => (int) $po['id'], 'bill_for' => 'advance', 'doc_kind' => 'proforma', 'requested_amount_inr' => 14175, 'bill_no' => 'SPI-1', 'taxable_inr' => 45000, 'gst_inr' => 2250]);
        self::assertSame(14175.0, OrderVendorBillRepository::find($proforma)['remaining'], 'a proforma asks only for the amount asked now');

        self::assertSame('success', $this->pay($orderId, $proforma, 14175, 'advance')['type']);
        $po = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame(14175.0, (float) $po['advance_paid_amount_inr'], '7e is paid');
        self::assertTrue(OrderVendorBillRepository::find($proforma)['settled']);

        $invoice = $this->bill($orderId, 'supplier', ['supplier_po_id' => (int) $po['id'], 'bill_for' => 'balance', 'doc_kind' => 'invoice', 'replaces_bill_id' => $proforma, 'bill_no' => 'TI-1', 'taxable_inr' => 45000, 'gst_inr' => 2250]);
        $bill = OrderVendorBillRepository::find($invoice);
        self::assertSame(14175.0, $bill['credited'], 'the advance paid on the proforma counts towards the invoice');
        self::assertSame(33075.0, $bill['remaining']);
        self::assertSame('TI-1', OrderVendorBillRepository::find($proforma)['replaced_by']);
        self::assertSame(0.0, OrderVendorBillRepository::find($proforma)['cost_inr'], 'a replaced proforma is not a cost');

        self::assertSame('That is more than is still due on bill TI-1 (₹33,075.00).', $this->pay($orderId, $invoice, 47250, 'balance')['message']);
        self::assertSame('Nothing is due on bill SPI-1 — it was replaced by tax invoice TI-1.', $this->pay($orderId, $proforma, 100, 'advance')['message']);

        self::assertSame('success', $this->pay($orderId, $invoice, 33075, 'balance')['type']);
        $po = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame(33075.0, (float) $po['balance_paid_amount_inr'], '10a is paid');
        self::assertTrue(OrderVendorBillRepository::find($invoice)['settled']);
        self::assertSame(date('Y-m-d'), (string) OrderVendorBillRepository::find($invoice)['paid_at']);

        $snapshot = CaFinancialSnapshotService::build($orderId);
        self::assertSame('47,250.00', $snapshot['supplier']['paid_total'], 'paid once in total — nothing counted twice');
        self::assertSame('= Total payable ✓', $snapshot['supplier']['paid_check']);
        self::assertStringContainsString('Proforma SPI-1', $snapshot['supplier']['bills'][0]['bill']);
        self::assertStringContainsString('asks ₹14,175.00 now — replaced by tax invoice TI-1, not a cost', $snapshot['supplier']['bills'][0]['bill']);
        self::assertStringContainsString('Tax invoice TI-1', $snapshot['supplier']['bills'][1]['bill']);
        self::assertStringContainsString('replaces proforma SPI-1', $snapshot['supplier']['bills'][1]['bill']);
        self::assertStringContainsString('₹14,175.00 on', $snapshot['supplier']['bills'][1]['paid']);
        self::assertStringContainsString('(on proforma SPI-1); ₹33,075.00 on', $snapshot['supplier']['bills'][1]['paid']);
    }

    public function testOneSupplierTaxInvoicePaidFrom7eAndThen10a(): void
    {
        [$orderId, $po] = $this->orderWithSupplierPo();
        $invoice = $this->bill($orderId, 'supplier', ['supplier_po_id' => (int) $po['id'], 'bill_for' => 'advance', 'bill_no' => 'TI-ALL', 'taxable_inr' => 45000, 'gst_inr' => 2250]);
        self::assertSame('success', $this->pay($orderId, $invoice, 14175, 'advance')['type']);
        self::assertFalse(OrderVendorBillRepository::find($invoice)['settled']);
        self::assertFalse(OrderVendorBillRepository::deleteUnpaid($invoice), 'a bill with a payment on it stays on record');

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $page = (string) ob_get_clean();
        self::assertStringContainsString('Added in Step 7e — shown here until fully paid', $page, 'the 7e invoice still owed is carried into 10a');
        self::assertStringContainsString('Record another payment', $page);

        self::assertSame('success', $this->pay($orderId, $invoice, 33075, 'balance')['type']);
        self::assertSame([14175.0, date('Y-m-d')], OrderVendorBillRepository::supplierLegPaid((int) $po['id'], 'advance'));
        self::assertSame([33075.0, date('Y-m-d')], OrderVendorBillRepository::supplierLegPaid((int) $po['id'], 'balance'), 'each payment counts towards the step it was recorded from');
        self::assertCount(2, OrderVendorBillRepository::find($invoice)['payments']);
    }

    public function testStep12AndTheLedgerCountTaxInvoicesAndUnreplacedProformaPayments(): void
    {
        [$orderId] = $this->orderWithSupplierPo();
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Proforma CHA', 'service_type' => 'cha'], $this->userId);
        $proforma = $this->bill($orderId, 'logistics', ['logistics_partner_id' => $partnerId, 'bill_for' => 'cha', 'payment_kind' => 'advance', 'doc_kind' => 'proforma', 'bill_no' => 'CHA-PI', 'taxable_inr' => 5000]);
        self::assertSame(0.0, OrderProfitabilityService::computeForOrder($orderId)['logistics_cost_inr'], 'an unpaid proforma is not a cost');

        self::assertSame('success', $this->pay($orderId, $proforma, 5000)['type']);
        self::assertSame(5000.0, OrderProfitabilityService::computeForOrder($orderId)['logistics_cost_inr'], 'what was paid on it counts until its tax invoice arrives');

        $invoice = $this->bill($orderId, 'logistics', ['logistics_partner_id' => $partnerId, 'bill_for' => 'cha', 'doc_kind' => 'invoice', 'replaces_bill_id' => $proforma, 'bill_no' => 'CHA-TI', 'taxable_inr' => 12000]);
        self::assertSame(12000.0, OrderProfitabilityService::computeForOrder($orderId)['logistics_cost_inr'], 'the invoice replaces the proforma — 12,000, never 17,000');
        self::assertSame(7000.0, OrderVendorBillRepository::find($invoice)['remaining']);

        self::assertSame('success', $this->pay($orderId, $invoice, 3000)['type']);
        self::assertSame('success', $this->pay($orderId, $invoice, 4000)['type']);
        self::assertTrue(OrderVendorBillRepository::find($invoice)['settled']);

        $ledger = OrderLedgerService::forOrder($orderId, 'USD', true);
        self::assertSame(12000.0, $ledger['totals']['logistics_inr'], 'one ledger line per payment: 5,000 + 3,000 + 4,000');
        self::assertCount(3, array_filter($ledger['rows'], static fn (array $r): bool => str_starts_with($r['event'], 'Logistics paid')));

        $snapshot = CaFinancialSnapshotService::build($orderId);
        self::assertSame('12,000.00', $snapshot['logistics_total']);
        self::assertSame('12,000.00', $snapshot['logistics_paid']);
        self::assertSame('0.00', $snapshot['logistics_due']);
    }

    public function testTheBillTypeFieldsAreChecked(): void
    {
        [$orderId] = $this->orderWithSupplierPo();
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Check CHA', 'service_type' => 'cha'], $this->userId);
        $otherId = LogisticsPartnerRepository::create(['partner_name' => 'Other CHA', 'service_type' => 'cha'], $this->userId);
        $otherProforma = $this->bill($orderId, 'logistics', ['logistics_partner_id' => $otherId, 'bill_for' => 'cha', 'doc_kind' => 'proforma', 'bill_no' => 'OTHER-PI', 'taxable_inr' => 1000]);

        $tmp = tempnam(sys_get_temp_dir(), 'bill-');
        file_put_contents($tmp, '%PDF-1.4 bill');
        $_FILES = ['bill_file' => ['name' => 'bill.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]];
        $base = ['logistics_partner_id' => (string) $partnerId, 'bill_for' => 'cha', 'payment_kind' => 'final', 'bill_no' => 'CHK-1', 'bill_date' => date('Y-m-d'), 'taxable_inr' => '1000', 'gst_rate_pct' => '18'];
        try {
            foreach ([
                'Choose whether the bill is a Proforma or a Tax invoice.' => ['doc_kind' => 'quote'],
                'The amount asked now cannot be more than the bill total (₹1,180.00).' => ['doc_kind' => 'proforma', 'requested_amount_inr' => '1500'],
                'That proforma cannot be linked — it is from another vendor, or already replaced by an invoice.' => ['doc_kind' => 'invoice', 'replaces_bill_id' => (string) $otherProforma],
            ] as $expected => $fields) {
                $_POST = $base + $fields;
                (new VendorBillController())->addLogisticsBill(['id' => (string) $orderId]);
                self::assertSame($expected, $this->lastFlash()['message']);
            }
        } finally {
            @unlink($tmp);
        }
        self::assertCount(1, OrderVendorBillRepository::forOrder($orderId), 'nothing was added');
    }
}
