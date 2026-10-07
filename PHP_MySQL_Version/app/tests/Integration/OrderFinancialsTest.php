<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderFinancialsController;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\ReportRepository;
use App\Services\DocumentDataAssembler;
use App\Services\OrderDuplicationService;
use App\Services\OrderProfitabilityService;
use App\Helpers\Flash;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section AP — reorder-to-supplier linking, the Order Cost
 * Entries table, the Order Profitability Sheet, the order-page-embedded
 * OrderFinancialsController actions, and the DocumentDataAssembler
 * supplier-cost hardening (client-facing documents must never see
 * order_supplier_po data).
 */
final class OrderFinancialsTest extends DbTestCase
{
    // ---------------------------------------------------------------
    // OrderCostEntryRepository
    // ---------------------------------------------------------------

    public function testCostEntryCreateForOrderTotalAndDelete(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $id1 = OrderCostEntryRepository::create($orderId, 'cha_charges', 'CHA fee', 1500.0, '2026-06-01', $userId);
        $id2 = OrderCostEntryRepository::create($orderId, 'bank_charges', null, 500.5, null, $userId);

        $rows = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(2, $rows);
        self::assertSame(2000.5, OrderCostEntryRepository::totalForOrder($orderId));

        $found = OrderCostEntryRepository::find($id1);
        self::assertNotNull($found);
        self::assertSame('cha_charges', $found['category']);

        OrderCostEntryRepository::delete($id1);
        self::assertNull(OrderCostEntryRepository::find($id1));
        self::assertSame(500.5, OrderCostEntryRepository::totalForOrder($orderId));
        self::assertNotNull(OrderCostEntryRepository::find($id2));
    }

    public function testCostEntryForOrderIsScopedToItsOwnOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderA = $this->createTestOrder($this->createTestClient());
        $orderB = $this->createTestOrder($this->createTestClient());

        OrderCostEntryRepository::create($orderA, 'other', null, 100.0, null, $userId);

        self::assertCount(1, OrderCostEntryRepository::forOrder($orderA));
        self::assertSame([], OrderCostEntryRepository::forOrder($orderB));
        self::assertSame(0.0, OrderCostEntryRepository::totalForOrder($orderB));
    }

    // ---------------------------------------------------------------
    // OrderProfitabilityService
    // ---------------------------------------------------------------

    public function testProfitabilityWithNothingRecordedIsAllZeroAndEstimated(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // No OrderPaymentStatusRepository::initializeForOrder() call — this
        // order has no payment row at all yet, exactly like a brand-new
        // order that hasn't reached the PI stage.
        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame(0.0, $p['revenue_inr']);
        self::assertTrue($p['revenue_is_estimated']);
        self::assertSame(0.0, $p['supplier_cost_inr']);
        self::assertSame(0.0, $p['freight_cost_inr']);
        self::assertSame(0.0, $p['insurance_cost_inr']);
        self::assertSame(0.0, $p['other_costs_inr']);
        self::assertSame(0.0, $p['total_cost_inr']);
        self::assertSame(0.0, $p['profit_inr']);
        self::assertNull($p['margin_pct']);
    }

    public function testProfitabilityUsesAssumedRateAsEstimatedRevenueWhenNotSettled(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
        // FOB value = 10 * 100 = 1000 (foreign currency units)
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 85.0, $userId);

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame(85000.0, $p['revenue_inr']);
        self::assertTrue($p['revenue_is_estimated']);
        self::assertSame(85000.0, $p['profit_inr']); // no costs recorded yet
    }

    public function testProfitabilityUsesRealInrActualsOnceBothLegsClearAndIsNotEstimated(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 85.0, $userId);
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 40000.0, $userId);
        OrderPaymentStatusRepository::setBalanceInrActual($orderId, 46000.0, $userId);

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame(86000.0, $p['revenue_inr']);
        self::assertFalse($p['revenue_is_estimated']);
    }

    public function testProfitabilityRollsUpSupplierFreightAndOtherCostsIntoTotalAndMargin(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 85.0, $userId); // revenue 85000

        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-TEST-1', $this->fullSupplierPoData(['total_payable_inr' => '30000']), 'issued');

        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => 5000, 'insurance_amount' => 1000]);

        OrderCostEntryRepository::create($orderId, 'cha_charges', null, 2000.0, null, $userId);
        OrderCostEntryRepository::create($orderId, 'bank_charges', null, 500.0, null, $userId);

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame(85000.0, $p['revenue_inr']);
        self::assertSame(30000.0, $p['supplier_cost_inr']);
        self::assertSame(5000.0, $p['freight_cost_inr']);
        self::assertSame(1000.0, $p['insurance_cost_inr']);
        self::assertSame(2500.0, $p['other_costs_inr']);
        self::assertSame(38500.0, $p['total_cost_inr']);
        self::assertSame(46500.0, $p['profit_inr']);
        self::assertSame(round(46500 / 85000 * 100, 2), $p['margin_pct']);
    }

    // ---------------------------------------------------------------
    // OrderFinancialsController — order-page-embedded actions
    // ---------------------------------------------------------------

    public function testAddCostEntryRejectsAnInvalidCategory(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['category' => 'not-a-real-category', 'amount_inr' => '100'];
        $controller = new OrderFinancialsController();
        ob_start();
        $controller->addCostEntry(['id' => (string) $orderId]);
        ob_get_clean();

        self::assertSame([], OrderCostEntryRepository::forOrder($orderId));
        $flash = Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
    }

    public function testAddCostEntryRecordsAValidEntryAndAudits(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['category' => 'cha_charges', 'amount_inr' => '1234.56', 'incurred_at' => '2026-06-01', 'description' => 'Test CHA'];
        $controller = new OrderFinancialsController();
        ob_start();
        $controller->addCostEntry(['id' => (string) $orderId]);
        ob_get_clean();

        $rows = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $rows);
        self::assertSame('1234.56', $rows[0]['amount_inr']);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ORDER_COST_ENTRY_RECORDED' AND entity_id = {$rows[0]['id']}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testDeleteCostEntryRemovesItAndRejectsAMismatchedOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderA = $this->createTestOrder($this->createTestClient());
        $orderB = $this->createTestOrder($this->createTestClient());
        $entryId = OrderCostEntryRepository::create($orderA, 'other', null, 250.0, null, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new OrderFinancialsController();

        // Wrong order in the URL for this entry — must 404, not delete.
        ob_start();
        $controller->deleteCostEntry(['id' => (string) $orderB, 'entryId' => (string) $entryId]);
        $output = ob_get_clean();
        self::assertNotNull(OrderCostEntryRepository::find($entryId));
        self::assertStringContainsString('not found', strtolower($output));

        ob_start();
        $controller->deleteCostEntry(['id' => (string) $orderA, 'entryId' => (string) $entryId]);
        ob_get_clean();
        self::assertNull(OrderCostEntryRepository::find($entryId));
    }

    public function testAddExportBenefitRecordsAgainstTheOrderAndMarkReceivedUpdatesIt(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        // Government export benefits only apply once the order is complete
        // and the CI (balance) remittance has been received.
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordBalanceReceived($orderId, 9000.0, '2026-06-01');
        OrderRepository::markComplete($orderId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['scheme_name' => 'RODTEP', 'claimed_amount' => '9000', 'claimed_at' => '2026-06-01'];
        $controller = new OrderFinancialsController();
        ob_start();
        $controller->addExportBenefit(['id' => (string) $orderId]);
        ob_get_clean();

        $benefits = CaExportBenefitRepository::forOrder($orderId);
        self::assertCount(1, $benefits);
        $benefitId = (int) $benefits[0]['id'];
        self::assertNull($benefits[0]['received_amount']);

        $_POST = ['received_amount' => '9000', 'received_at' => '2026-07-01'];
        ob_start();
        $controller->markExportBenefitReceived(['id' => (string) $orderId, 'benefitId' => (string) $benefitId]);
        ob_get_clean();

        $updated = CaExportBenefitRepository::find($benefitId);
        self::assertSame('9000.00', $updated['received_amount']);
    }

    /**
     * "As the order is incomplete, then beneficiaries scheme won't be
     * applicable, so showing is of no use" — addExportBenefit() must refuse
     * to record a claim until the order is complete AND the CI (balance)
     * remittance has been received, not just hide the option in the UI.
     */
    public function testAddExportBenefitIsRefusedBeforeOrderIsCompleteAndRemittanceReceived(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $controller = new OrderFinancialsController();

        // Neither condition met yet — a brand-new order.
        $_POST = ['scheme_name' => 'RODTEP', 'claimed_amount' => '9000', 'claimed_at' => '2026-06-01'];
        ob_start();
        $controller->addExportBenefit(['id' => (string) $orderId]);
        ob_get_clean();
        self::assertSame([], CaExportBenefitRepository::forOrder($orderId));

        // Remittance received, but the order itself is not yet 'complete'.
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordBalanceReceived($orderId, 9000.0, '2026-06-01');
        ob_start();
        $controller->addExportBenefit(['id' => (string) $orderId]);
        ob_get_clean();
        self::assertSame([], CaExportBenefitRepository::forOrder($orderId), 'order must be complete, not just remittance-received');

        // Now both conditions hold — the claim must go through.
        OrderRepository::markComplete($orderId);
        ob_start();
        $controller->addExportBenefit(['id' => (string) $orderId]);
        ob_get_clean();
        self::assertCount(1, CaExportBenefitRepository::forOrder($orderId));
    }

    // ---------------------------------------------------------------
    // Reorder-to-supplier linking (OrderDuplicationService)
    // ---------------------------------------------------------------

    public function testDuplicateOrderCarriesForwardSupplierPoAsDraftAndLinksBothOrders(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);

        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        OrderSupplierPoRepository::create($sourceOrderId, $supplierId, 'SUPPO-SRC-1', $this->fullSupplierPoData([
            'material_stone_type' => 'Granite',
            'total_payable_inr'   => '55000',
        ]), 'issued');

        $newOrderId = OrderDuplicationService::duplicate($sourceOrderId, $userId);

        $newOrder = OrderRepository::find($newOrderId);
        self::assertSame($sourceOrderId, (int) $newOrder['duplicated_from_order_id']);

        $duplicatedInto = OrderRepository::findOrdersDuplicatedFrom($sourceOrderId);
        self::assertCount(1, $duplicatedInto);
        self::assertSame($newOrderId, (int) $duplicatedInto[0]['id']);

        $newSupplierPo = OrderSupplierPoRepository::findLatestForOrder($newOrderId);
        self::assertNotNull($newSupplierPo);
        self::assertSame('draft', $newSupplierPo['status']);
        self::assertSame('Granite', $newSupplierPo['material_stone_type']);
        self::assertSame($supplierId, (int) $newSupplierPo['supplier_id']);
        self::assertNotSame('SUPPO-SRC-1', $newSupplierPo['supplier_po_reference'], 'the carried-forward PO must get its own fresh reference, never reuse the source one');

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'SUPPLIER_PO_CARRIED_FORWARD' AND entity_id = {$newSupplierPo['id']}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testDuplicateOrderWithNoSourceSupplierPoCreatesNoDraft(): void
    {
        $userId = $this->createTestUser('Admin');
        $sourceOrderId = $this->createTestOrder($this->createTestClient());

        $newOrderId = OrderDuplicationService::duplicate($sourceOrderId, $userId);

        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($newOrderId));
    }

    // ---------------------------------------------------------------
    // DocumentDataAssembler — client-facing isolation hardening
    // ---------------------------------------------------------------

    public function testAssembleIncludesSupplierPoOnlyForSupplierFacingDocumentTypes(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-ASM-1', $this->fullSupplierPoData(['total_payable_inr' => '10000']), 'issued');

        $forSuppo = DocumentDataAssembler::assemble($orderId, 'SUPPO');
        self::assertNotNull($forSuppo['supplier_po']);

        foreach (['QT', 'PI', 'OC', 'BUYERPO', 'CI', 'PL', 'BLI', 'AMD'] as $clientFacingType) {
            $data = DocumentDataAssembler::assemble($orderId, $clientFacingType);
            self::assertNull($data['supplier_po'], "supplier_po must be null when assembling a {$clientFacingType} document");
        }
    }

    // ---------------------------------------------------------------
    // ReportRepository::orderProfitabilityReport() rollup
    // ---------------------------------------------------------------

    public function testOrderProfitabilityReportRollsUpMultipleOrdersWithATotalsRow(): void
    {
        $userId = $this->createTestUser('Admin');

        $orderA = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderA, 1, 'Stone A', null, null, '10', false, 'SQM', '100');
        OrderPaymentStatusRepository::initializeForOrder($orderA);
        OrderPaymentStatusRepository::setLegExchangeRate($orderA, 'advance', 80.0, $userId); // revenue 80000, no costs

        $orderB = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderB, 1, 'Stone B', null, null, '5', false, 'SQM', '200');
        OrderPaymentStatusRepository::initializeForOrder($orderB);
        OrderPaymentStatusRepository::setLegExchangeRate($orderB, 'advance', 80.0, $userId); // revenue 80000
        OrderCostEntryRepository::create($orderB, 'other', null, 10000.0, null, $userId);

        $data = ReportRepository::orderProfitabilityReport(null, null);
        $rowsById = [];
        foreach ($data['rows'] as $r) {
            $rowsById[$r['order_id']] = $r;
        }

        self::assertArrayHasKey($orderA, $rowsById);
        self::assertArrayHasKey($orderB, $rowsById);
        self::assertSame(80000.0, $rowsById[$orderA]['revenue_inr']);
        self::assertSame(80000.0, $rowsById[$orderA]['profit_inr']);
        self::assertSame(70000.0, $rowsById[$orderB]['profit_inr']);

        // The report's own totals row must equal the sum of every row it
        // lists (asserted directly, rather than against a fixed constant —
        // other tests in this process share the same disposable database
        // and may have their own orders in today's date range).
        $sumRevenue = array_sum(array_column($data['rows'], 'revenue_inr'));
        $sumProfit = array_sum(array_column($data['rows'], 'profit_inr'));
        self::assertSame(round($sumRevenue, 2), round($data['totals']['revenue_inr'], 2));
        self::assertSame(round($sumProfit, 2), round($data['totals']['profit_inr'], 2));
        self::assertSame(round($sumProfit / $sumRevenue * 100, 2), $data['totals']['margin_pct']);
    }

    public function testOrderProfitabilityReportRespectsDateRangeFilter(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $farFuture = date('Y-m-d', strtotime('+5 years'));
        $data = ReportRepository::orderProfitabilityReport($farFuture, null);

        $ids = array_column($data['rows'], 'order_id');
        self::assertNotContains($orderId, $ids, 'an order created today must not appear in a report filtered to 5 years in the future');
    }

    /**
     * OrderSupplierPoRepository::create()'s every real caller (the Stage 5
     * form, OrderDuplicationService::carrySupplierPoForward()) always
     * supplies the full key set — a partial array like a test might use
     * for brevity trips PHP's "undefined array key" warning on every
     * missing key, since the repository reads with `?:`, not `??`.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function fullSupplierPoData(array $overrides = []): array
    {
        return array_merge([
            'material_stone_type' => null,
            'grade' => null,
            'surface_finish' => null,
            'dimensions' => null,
            'dimensional_tolerance' => null,
            'quantity' => null,
            'unit' => null,
            'colour_reference' => null,
            'special_requirements' => null,
            'unit_price_inr' => null,
            'basic_value_inr' => null,
            'gst_rate_pct' => null,
            'gst_amount_inr' => null,
            'total_payable_inr' => null,
            'advance_pct' => null,
            'advance_amount_inr' => null,
            'balance_amount_inr' => null,
            'delivery_location' => null,
            'required_delivery_date' => null,
            'packing_requirement' => null,
        ], $overrides);
    }
}
