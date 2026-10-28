<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\OrderExpenseController;
use App\Controllers\VendorBillController;
use App\Helpers\Flash;
use App\Repositories\CaFyLockRepository;
use App\Repositories\LogisticsPartnerRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Repositories\SupplierRepository;
use App\Services\FileUploadService;
use App\Services\OrderLedgerService;
use App\Services\OrderProfitabilityService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions (2026-10-27): bill first, then pay — the supplier's bills
 * (7e advance, 10a balance) and the logistics partners' bills (CHA,
 * transport, forwarder; on CFR/CIF payable only after 9d), the order's actual
 * expenses with receipts (travel replaces the provisional %), a warning (not
 * a block) on 13h for unpaid bills, and all of it in Step 12 and the ledger.
 */
final class VendorBillsAndExpensesTest extends DbTestCase
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

    private function setStages(int $orderId, array $numbers, string $status): void
    {
        $stmt = Database::connection()->prepare('UPDATE order_stages SET status = :s WHERE order_id = :id AND stage_id = (SELECT id FROM stages_master WHERE stage_number = :n)');
        foreach ($numbers as $n) {
            $stmt->execute(['s' => $status, 'id' => $orderId, 'n' => $n]);
        }
    }

    private function orderWithSupplierPo(string $incoterm = 'FOB'): array
    {
        $orderId = $this->createTestOrder($this->createTestClient(), $incoterm);
        $this->setStages($orderId, [1, 2, 3, 4], 'gate_passed');
        $this->setStages($orderId, [5], 'in_progress');
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Bills Quarry Co']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'BILL-SPO-' . $orderId, [
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null, 'dimensions' => null,
            'dimensional_tolerance' => null, 'quantity' => '100', 'unit' => 'sqm', 'colour_reference' => null,
            'special_requirements' => null, 'unit_price_inr' => '450', 'basic_value_inr' => '45000', 'gst_rate_pct' => '5',
            'gst_amount_inr' => '2250', 'total_payable_inr' => '47250', 'advance_pct' => '30',
            'advance_amount_inr' => '14175', 'balance_amount_inr' => '33075',
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], 'issued');
        return [$orderId, OrderSupplierPoRepository::findLatestForOrder($orderId)];
    }

    private function supplierBill(int $orderId, int $poId, string $leg, float $taxable, float $gst): int
    {
        return OrderVendorBillRepository::create($orderId, 'supplier', ['supplier_po_id' => $poId, 'bill_for' => $leg, 'bill_no' => strtoupper($leg) . '-1', 'bill_date' => date('Y-m-d'), 'taxable_inr' => $taxable, 'gst_inr' => $gst], $this->userId);
    }

    private function logisticsBill(int $orderId, int $partnerId, string $for, float $total): int
    {
        return OrderVendorBillRepository::create($orderId, 'logistics', ['logistics_partner_id' => $partnerId, 'bill_for' => $for, 'bill_no' => 'LB-' . $for, 'bill_date' => date('Y-m-d'), 'taxable_inr' => $total, 'gst_inr' => 0], $this->userId);
    }

    private function pay(int $orderId, int $billId, float $amount, string $date = ''): array
    {
        $_POST = ['paid_at' => $date ?: date('Y-m-d'), 'paid_amount_inr' => (string) $amount, 'payment_mode' => 'NEFT / RTGS', 'payment_ref' => 'UTR' . $billId];
        (new VendorBillController())->payBill(['id' => (string) $orderId, 'billId' => (string) $billId]);
        return $this->lastFlash();
    }

    public function testTheNewUploadContextsExist(): void
    {
        foreach (['vendor_bill', 'expense_receipt'] as $context) {
            $tmp = tempnam(sys_get_temp_dir(), 'bill-');
            file_put_contents($tmp, '%PDF-1.4 bill');
            $_FILES['f'] = ['name' => 'bill.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
            try {
                FileUploadService::handleUpload('f', $context, 'test/bills', null, null, null);
                self::fail('move_uploaded_file() cannot succeed in a CLI test');
            } catch (\RuntimeException $e) {
                self::assertStringNotContainsString('Unknown upload context', $e->getMessage(), $context);
            } finally {
                @unlink($tmp);
            }
        }
    }

    public function testASupplierBillNeedsItsDetailsAndTheBillAttached(): void
    {
        [$orderId] = $this->orderWithSupplierPo();
        $_POST = ['bill_for' => 'advance', 'bill_no' => 'SPI-1', 'bill_date' => date('Y-m-d'), 'taxable_inr' => '13500', 'gst_rate_pct' => '5'];
        (new VendorBillController())->addSupplierBill(['id' => (string) $orderId]);
        self::assertSame('Attach the bill / invoice.', $this->lastFlash()['message']);
        $_POST['bill_date'] = date('Y-m-d', strtotime('+2 days'));
        (new VendorBillController())->addSupplierBill(['id' => (string) $orderId]);
        self::assertStringContainsString('not in the future', $this->lastFlash()['message']);
        $_POST['bill_date'] = date('Y-m-d');
        $_POST['gst_rate_pct'] = '7';
        (new VendorBillController())->addSupplierBill(['id' => (string) $orderId]);
        self::assertSame('Choose the GST rate (%) on the bill.', $this->lastFlash()['message'], 'GST is a rate from the GST Rate list, never a typed amount');
        self::assertSame([], OrderVendorBillRepository::forOrder($orderId));
    }

    public function testPayingTheSupplierBillsRecordsTheAdvanceAndBalance(): void
    {
        [$orderId, $po] = $this->orderWithSupplierPo();
        $balance = $this->supplierBill($orderId, (int) $po['id'], 'balance', 31500, 1575);
        self::assertStringContainsString('Pay the supplier advance (Step 7e) first', $this->pay($orderId, $balance, 33075)['message']);

        $advance = $this->supplierBill($orderId, (int) $po['id'], 'advance', 13500, 675);
        self::assertSame('success', $this->pay($orderId, $advance, 14175)['type']);
        $po = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame(date('Y-m-d'), substr((string) $po['advance_paid_at'], 0, 10), '7e is done once the advance bill is paid');
        self::assertSame(14175.0, (float) $po['advance_paid_amount_inr']);
        self::assertSame('error', $this->pay($orderId, $advance, 14175)['type'], 'a bill is paid once');

        self::assertSame('success', $this->pay($orderId, $balance, 33075)['type']);
        self::assertSame(33075.0, (float) OrderSupplierPoRepository::findLatestForOrder($orderId)['balance_paid_amount_inr']);

        self::assertFalse(OrderVendorBillRepository::deleteUnpaid($advance), 'a paid bill stays on record');
    }

    public function testLogisticsBillsOnCifWaitFor9dAndOnFobDoNot(): void
    {
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Bills CHA & Transport', 'service_type' => 'both'], $this->userId);
        [$cifOrder] = $this->orderWithSupplierPo('CIF');
        $bill = $this->logisticsBill($cifOrder, $partnerId, 'cha', 18500);
        self::assertStringContainsString("Debit Note payment has cleared (Step 9d)", $this->pay($cifOrder, $bill, 18500)['message']);
        Database::connection()->prepare('INSERT INTO order_payment_status (order_id, freight_cleared_at) VALUES (:id, NOW()) ON DUPLICATE KEY UPDATE freight_cleared_at = NOW()')->execute(['id' => $cifOrder]);
        self::assertSame('success', $this->pay($cifOrder, $bill, 18500)['type']);

        [$fobOrder] = $this->orderWithSupplierPo('FOB');
        $fobBill = $this->logisticsBill($fobOrder, $partnerId, 'transport', 22000);
        self::assertSame('success', $this->pay($fobOrder, $fobBill, 22000)['type'], 'FOB orders have no Debit Note to wait for');
    }

    /** Owner decision (2026-10-27): a logistics Advance bill is paid any time; only the Final bill waits for 9d. */
    public function testALogisticsAdvanceBillIsPayableBefore9dOnCif(): void
    {
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Advance Forwarder', 'service_type' => 'both'], $this->userId);
        [$cifOrder] = $this->orderWithSupplierPo('CIF');
        $advance = OrderVendorBillRepository::create($cifOrder, 'logistics', ['logistics_partner_id' => $partnerId, 'bill_for' => 'ocean_freight', 'payment_kind' => 'advance', 'bill_no' => 'OF-ADV', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 50000, 'gst_inr' => 0], $this->userId);
        $final = $this->logisticsBill($cifOrder, $partnerId, 'cha', 18500);

        self::assertSame('advance', OrderVendorBillRepository::find($advance)['payment_kind']);
        self::assertSame('final', OrderVendorBillRepository::find($final)['payment_kind'], 'a bill is Final unless marked Advance');
        self::assertSame('success', $this->pay($cifOrder, $advance, 50000)['type'], 'an Advance is never held back');
        self::assertStringContainsString('Step 9d', $this->pay($cifOrder, $final, 18500)['message'], 'the Final bill still waits for the Debit Note payment');
    }

    public function testALogisticsBillMustSayAdvanceOrFinal(): void
    {
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Kind Check CHA', 'service_type' => 'both'], $this->userId);
        [$orderId] = $this->orderWithSupplierPo('CIF');
        $_POST = ['logistics_partner_id' => (string) $partnerId, 'bill_for' => 'cha', 'payment_kind' => '', 'bill_no' => 'K-1', 'bill_date' => date('Y-m-d'), 'taxable_inr' => '1000', 'gst_rate_pct' => '18'];
        (new VendorBillController())->addLogisticsBill(['id' => (string) $orderId]);
        self::assertSame('Choose whether the bill is an Advance or the Final bill.', $this->lastFlash()['message']);
    }

    public function testAnExpenseNeedsAReceiptOrAReasonAndRespectsTheFyLock(): void
    {
        [$orderId] = $this->orderWithSupplierPo();
        $base = ['expense_date' => date('Y-m-d'), 'category' => 'hotel', 'details' => 'Hotel Kota, 1 night', 'amount_inr' => '3540', 'paid_by' => 'company_card'];

        $_POST = $base;
        (new OrderExpenseController())->add(['id' => (string) $orderId]);
        self::assertStringContainsString('Attach the receipt', $this->lastFlash()['message']);

        $_POST = $base + ['paid_by' => 'staff'];
        $_POST['paid_by'] = 'staff';
        $_POST['no_receipt_reason'] = 'Roadside stop — no bill given';
        (new OrderExpenseController())->add(['id' => (string) $orderId]);
        self::assertStringContainsString('staff member', $this->lastFlash()['message']);

        $_POST['staff_name'] = 'Ramesh';
        (new OrderExpenseController())->add(['id' => (string) $orderId]);
        self::assertSame('success', $this->lastFlash()['type']);

        // A late voucher keeps its real date — unless that year is locked.
        $lateDate = date('Y-m-d', strtotime('-400 days'));
        $_POST = $base + ['no_receipt_reason' => 'Voucher written up at audit time'];
        $_POST['expense_date'] = $lateDate;
        (new OrderExpenseController())->add(['id' => (string) $orderId]);
        self::assertSame('success', $this->lastFlash()['type']);
        $late = array_values(array_filter(OrderExpenseRepository::forOrder($orderId), static fn ($e) => $e['expense_date'] === $lateDate));
        self::assertSame(1, (int) $late[0]['added_later']);

        $fy = \App\Helpers\FinancialYear::label($lateDate);
        CaFyLockRepository::lock($fy, $this->userId);
        try {
            $this->setUserRole('Export Executive');
            (new OrderExpenseController())->add(['id' => (string) $orderId]);
            self::assertStringContainsString('locked', $this->lastFlash()['message']);
        } finally {
            CaFyLockRepository::unlock($fy, $this->userId, 'test cleanup');
        }
        self::assertCount(2, OrderExpenseRepository::forOrder($orderId));
    }

    private function setUserRole(string $roleName): void
    {
        $this->userId = $this->createTestUser($roleName);
        $_SESSION = ['_auth_user_id' => $this->userId];
    }

    public function testStep12AndTheLedgerCountBillsAndExpensesOnceEach(): void
    {
        [$orderId] = $this->orderWithSupplierPo('CIF');
        Database::connection()->prepare("INSERT INTO order_freight (order_id, confirmed_freight_rate, insurance_amount) VALUES (:id, 90000, 1000) ON DUPLICATE KEY UPDATE confirmed_freight_rate = 90000, insurance_amount = 1000")->execute(['id' => $orderId]);
        $travelEntry = OrderCostEntryRepository::create($orderId, 'travel_expenses', 'Travel 7% of FOB', 50000, date('Y-m-d'), $this->userId);
        $before = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(90000.0, $before['freight_cost_inr']);
        self::assertTrue($before['travel_is_provisional']);

        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Bills Forwarder', 'service_type' => 'freight_forwarder'], $this->userId);
        $this->logisticsBill($orderId, $partnerId, 'ocean_freight', 85000);
        $cha = $this->logisticsBill($orderId, $partnerId, 'cha', 18500);
        OrderExpenseRepository::create($orderId, ['expense_date' => date('Y-m-d'), 'category' => 'travel_fuel', 'details' => 'Factory visit', 'amount_inr' => 6200, 'paid_by' => 'company_account', 'no_receipt_reason' => null], $this->userId);

        $after = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(85000.0, $after['freight_cost_inr'], "the forwarder's real bill replaces the 9a rate");
        self::assertTrue($after['freight_from_bills']);
        self::assertSame(18500.0, $after['logistics_cost_inr']);
        self::assertSame(6200.0, $after['expenses_inr']);
        self::assertFalse($after['travel_is_provisional']);
        self::assertSame(round($before['total_cost_inr'] - 90000 + 85000 + 18500 + 6200 - 50000, 2), $after['total_cost_inr'], 'the provisional travel line is replaced, not added to');


        Database::connection()->prepare('INSERT INTO order_payment_status (order_id, freight_cleared_at) VALUES (:id, NOW()) ON DUPLICATE KEY UPDATE freight_cleared_at = NOW()')->execute(['id' => $orderId]);
        $this->pay($orderId, $cha, 18500);
        $ledger = OrderLedgerService::forOrder($orderId, 'USD', true);
        self::assertSame(18500.0, $ledger['totals']['logistics_inr']);
        self::assertSame(6200.0, $ledger['totals']['expenses_inr'], 'actual travel replaces the provisional travel line in the ledger too');
        self::assertNotNull(OrderCostEntryRepository::find($travelEntry), 'the travel % itself is kept as the budget');
    }

    public function testTheOrderPageShowsBillsAndWarnsOn13h(): void
    {
        [$orderId, $po] = $this->orderWithSupplierPo('CIF');
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Page CHA Co', 'service_type' => 'cha'], $this->userId);
        $this->supplierBill($orderId, (int) $po['id'], 'advance', 13500, 675);
        $this->logisticsBill($orderId, $partnerId, 'cha', 18500);
        Database::connection()->prepare("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id")->execute(['id' => $orderId]);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $page = (string) ob_get_clean();

        self::assertStringContainsString('id="logistics-bills"', $page);
        self::assertStringContainsString('Page CHA Co', $page);
        self::assertStringContainsString('ADVANCE-1', $page);
        self::assertStringContainsString("Debit Note payment has cleared (Step 9d)", $page, 'on CIF the Mark Paid waits for 9d');
        self::assertStringContainsString('the Supplier PO terms plan an advance of ₹14,175.00.', $page, '10a shows the planned advance as money, not 14175.00');
        self::assertStringContainsString('id="unpaid-vendor-bills-warning"', $page, '13h warns about unpaid bills');
        self::assertStringContainsString('you can still mark the order fully settled', $page);
        self::assertStringContainsString('id="order-expenses"', $page);
    }

    /** Batch 5 parity: a Supplier PO with no advance entered means nothing to pay in 7e, on both stacks. */
    public function testABlankSupplierAdvanceMeansNothingToPayIn7e(): void
    {
        [$orderId] = $this->orderWithSupplierPo('CIF');
        Database::connection()->prepare('UPDATE order_supplier_po SET advance_pct = NULL, advance_amount_inr = NULL WHERE order_id = :id')->execute(['id' => $orderId]);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $page = (string) ob_get_clean();

        self::assertStringContainsString('No advance on this order', $page);
    }
}
