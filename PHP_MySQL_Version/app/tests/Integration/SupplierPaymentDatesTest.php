<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Controllers\ReportController;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\SupplierRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Phase 2 item 1 of the approved remediation plan: "customer-wise /
 * product-wise / country-wise reports; today's incoming/supplier
 * payments." The supplier half needed new tracking added first — only
 * the AMOUNT owed to a supplier was ever recorded, never whether or
 * when it was actually paid, unlike the buyer side.
 */
final class SupplierPaymentDatesTest extends DbTestCase
{
    private function fullSupplierPoData(array $overrides = []): array
    {
        return array_merge([
            'material_stone_type' => null, 'grade' => null, 'surface_finish' => null,
            'dimensions' => null, 'dimensional_tolerance' => null, 'quantity' => null,
            'unit' => null, 'colour_reference' => null, 'special_requirements' => null,
            'unit_price_inr' => null, 'basic_value_inr' => null, 'gst_rate_pct' => null,
            'gst_amount_inr' => null, 'total_payable_inr' => null, 'advance_pct' => null,
            'advance_amount_inr' => null, 'balance_amount_inr' => null,
            'delivery_location' => null, 'required_delivery_date' => null, 'packing_requirement' => null,
        ], $overrides);
    }

    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function render(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testMarkAdvancePaidAndMarkBalancePaidPersistDateAndUser(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        $supplierPoId = OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-1', $this->fullSupplierPoData([
            'advance_amount_inr' => '20000',
            'balance_amount_inr' => '30000',
        ]), 'issued');
        $userId = $this->createTestUser('Admin');

        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2026-10-20', $userId);
        OrderSupplierPoRepository::markBalancePaid($supplierPoId, '2026-10-21', $userId);

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame('2026-10-20', $supplierPo['advance_paid_at']);
        self::assertSame($userId, (int) $supplierPo['advance_paid_by']);
        self::assertSame('2026-10-21', $supplierPo['balance_paid_at']);
        self::assertSame($userId, (int) $supplierPo['balance_paid_by']);
    }

    public function testPaidOnDateFindsBothLegsOnTheirOwnDate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        $supplierPoId = OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-2', $this->fullSupplierPoData([
            'advance_amount_inr' => '20000',
            'balance_amount_inr' => '30000',
        ]), 'issued');
        $userId = $this->createTestUser('Admin');
        // Distinct, otherwise-unused dates — other tests in this class
        // also write real rows with real dates, and the schema/seed
        // rebuild happens once per PHPUnit process, not per test, so
        // this must not collide with another test's own date.
        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2031-03-11', $userId);
        OrderSupplierPoRepository::markBalancePaid($supplierPoId, '2031-03-12', $userId);

        $onThe11th = array_column(OrderSupplierPoRepository::paidOnDate('2031-03-11'), 'id', 'id');
        $onThe12th = array_column(OrderSupplierPoRepository::paidOnDate('2031-03-12'), 'id', 'id');
        $onAnotherDay = array_column(OrderSupplierPoRepository::paidOnDate('2031-01-01'), 'id', 'id');

        self::assertArrayHasKey($supplierPoId, $onThe11th);
        self::assertArrayHasKey($supplierPoId, $onThe12th);
        self::assertArrayNotHasKey($supplierPoId, $onAnotherDay);
    }

    public function testOrderPageOffersMarkPaidButtonsOnlyUntilPaid(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        $supplierPoId = OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-3', $this->fullSupplierPoData([
            'advance_amount_inr' => '20000',
            'balance_amount_inr' => '30000',
        ]), 'issued');
        $this->unlockThrough($orderId, 5);

        $before = $this->render($orderId);
        self::assertStringContainsString('/supplier-po/advance-paid', $before);
        self::assertStringContainsString('/supplier-po/balance-paid', $before);

        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2026-10-20', $this->createTestUser('Admin'));

        $after = $this->render($orderId);
        self::assertStringNotContainsString('/supplier-po/advance-paid', $after, 'once paid, the Mark Advance Paid form must not still offer itself');
        self::assertStringContainsString('/supplier-po/balance-paid', $after);
    }

    public function testTodaysPaymentsReportCombinesIncomingAndOutgoingLegsForTheGivenDate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.0, '2026-10-21');
        $clearUserId = $this->createTestUser('Admin');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-10-21 10:00:00', $clearUserId);

        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        $supplierPoId = OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-4', $this->fullSupplierPoData([
            'advance_amount_inr' => '20000',
        ]), 'issued');
        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2026-10-21', $clearUserId);

        $_GET['date'] = '2026-10-21';
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new ReportController())->todaysPayments([]);
        $output = ob_get_clean();
        unset($_GET['date']);

        self::assertStringContainsString('PHPUnit Test Supplier', $output);
        self::assertStringContainsString(number_format(1000.0, 2), $output);
        self::assertStringContainsString(number_format(20000.0, 2), $output);
    }
}
