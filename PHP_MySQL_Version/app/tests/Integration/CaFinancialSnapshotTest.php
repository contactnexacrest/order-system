<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\CaExportBenefitRepository;
use App\Repositories\LogisticsPartnerRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Repositories\SupplierRepository;
use App\Services\CaFinancialSnapshotService;
use App\Services\OrderProfitabilityService;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (2026-10-27, approved mockup, confirmed twice): the CAFIN
 * internal annexure is the complete order snapshot — A order details,
 * B supplier cost, C every expense, D government benefits, E profit, F open
 * items — with readable cost names and the same profit as the order page.
 */
final class CaFinancialSnapshotTest extends DbTestCase
{
    public function testTheSnapshotCoversTheWholeOrderInTheApprovedOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Snapshot Quarry Co']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SNAP-SPO-' . $orderId, [
            'material_stone_type' => 'Black granite', 'grade' => null, 'surface_finish' => null, 'dimensions' => null,
            'dimensional_tolerance' => null, 'quantity' => '100.000', 'unit' => 'sqm', 'colour_reference' => null,
            'special_requirements' => null, 'unit_price_inr' => '450', 'basic_value_inr' => '45000', 'gst_rate_pct' => null,
            'gst_amount_inr' => '0', 'total_payable_inr' => '45000', 'advance_pct' => null,
            'advance_amount_inr' => null, 'balance_amount_inr' => null,
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], 'signed');
        $po = OrderSupplierPoRepository::findLatestForOrder($orderId);
        $bill = OrderVendorBillRepository::create($orderId, 'supplier', ['supplier_po_id' => (int) $po['id'], 'bill_for' => 'balance', 'bill_no' => 'SNAP-TI-1', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 45000, 'gst_inr' => 0], $userId);
        OrderVendorBillRepository::addPayment($bill, 'balance', date('Y-m-d'), 45000, 'NEFT / RTGS', 'UTR-SNAP', $userId);
        $partnerId = LogisticsPartnerRepository::create(['partner_name' => 'Snapshot CHA', 'service_type' => 'cha'], $userId);
        OrderVendorBillRepository::create($orderId, 'logistics', ['logistics_partner_id' => $partnerId, 'bill_for' => 'cha', 'bill_no' => 'SNAP-CHA-1', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 18500, 'gst_inr' => 0], $userId);
        OrderCostEntryRepository::create($orderId, 'ecgc_insurance', 'ECGC cover', 5000, date('Y-m-d'), $userId);
        OrderExpenseRepository::create($orderId, ['expense_date' => date('Y-m-d'), 'category' => 'hotel', 'details' => 'Hotel Kota', 'amount_inr' => 3540, 'paid_by' => 'staff', 'staff_name' => 'Ramesh', 'no_receipt_reason' => 'No bill given at the hotel'], $userId);
        CaExportBenefitRepository::record($orderId, 'RODTEP', 'R-1', 5000.0, date('Y-m-d'), 'INR', null, $userId);

        $s = CaFinancialSnapshotService::build($orderId);

        self::assertSame('Order Reference', $s['details'][0][0]);
        self::assertStringContainsString('CIF', $s['details'][3][1]);
        self::assertSame('100 sqm × ₹450.00', $s['supplier']['rows'][1][3]);
        self::assertSame('45,000.00', $s['supplier']['paid_total']);
        self::assertSame('= Total payable ✓', $s['supplier']['paid_check']);
        self::assertSame('Snapshot CHA', $s['logistics'][0]['partner']);
        self::assertTrue($s['logistics'][0]['unpaid']);
        self::assertSame('ECGC Insurance', $s['costs'][0]['category'], 'readable names, never raw codes like ecgc_insurance');
        self::assertSame('Staff (to reimburse) — Ramesh', $s['expenses'][0]['paid_by']);
        self::assertSame('Claimed / Pending', $s['benefits'][0]['status']);

        $profit = OrderProfitabilityService::computeForOrder($orderId);
        $grand = array_values(array_filter($s['pl'], static fn (array $r): bool => $r[1] === 'ORDER PROFIT'));
        self::assertSame(number_format($profit['profit_inr'], 2), $grand[0][3], 'CAFIN profit = order page profit');
        self::assertSame('18,500.00', $s['summary'][1][1]);
        self::assertSame('3,540.00', $s['summary'][3][1]);

        $openText = implode(' | ', array_column($s['open'], 'text'));
        self::assertStringContainsString('Unpaid bill — Snapshot CHA SNAP-CHA-1, ₹18,500.00', $openText);
        self::assertStringContainsString('Expenses without a receipt: 1 · Staff to reimburse: ₹3,540.00', $openText);
        self::assertStringContainsString('1 government benefit claim(s) not yet received', $openText);
    }
}
