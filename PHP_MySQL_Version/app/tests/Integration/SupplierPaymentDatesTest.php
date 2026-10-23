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

        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2026-10-20', $userId, 20000.0);
        OrderSupplierPoRepository::markBalancePaid($supplierPoId, '2026-10-21', $userId, 30000.0);

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame('2026-10-20', $supplierPo['advance_paid_at']);
        self::assertSame($userId, (int) $supplierPo['advance_paid_by']);
        self::assertSame('20000.00', $supplierPo['advance_paid_amount_inr']);
        self::assertSame('2026-10-21', $supplierPo['balance_paid_at']);
        self::assertSame($userId, (int) $supplierPo['balance_paid_by']);
        self::assertSame('30000.00', $supplierPo['balance_paid_amount_inr']);
    }

    /**
     * External QA report R-11 (SPO-PAY-01): through the real controller
     * action (not the repository call above, which has no validation of
     * its own) — an impossible date like 2026-02-30 must be refused with
     * a flash error, not crash with an HTTP 500.
     */
    public function testMarkSupplierAdvancePaidRefusesAnImpossibleDate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-6', $this->fullSupplierPoData([
            'advance_amount_inr' => '20000',
        ]), 'issued');
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['paid_date' => '2026-02-30', 'amount_paid_inr' => '20000'];
        \App\Helpers\Flash::pull(); // drain any leftover flash from an earlier test in this process

        ob_start();
        (new OrderController())->markSupplierAdvancePaid(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['advance_paid_at']);
        $flash = \App\Helpers\Flash::pull();
        self::assertNotEmpty($flash);
        self::assertSame('error', $flash[0]['type']);
    }

    /** External QA report R-11: a future-dated payment (2099) must be refused, not silently accepted. */
    public function testMarkSupplierAdvancePaidRefusesAFutureDate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-7', $this->fullSupplierPoData([
            'advance_amount_inr' => '20000',
        ]), 'issued');
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['paid_date' => '2099-01-01', 'amount_paid_inr' => '20000'];
        \App\Helpers\Flash::pull(); // drain any leftover flash from an earlier test in this process

        ob_start();
        (new OrderController())->markSupplierAdvancePaid(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['advance_paid_at']);
        $flash = \App\Helpers\Flash::pull();
        self::assertNotEmpty($flash);
        self::assertStringContainsString('future', $flash[0]['message']);
    }

    /** External QA report R-11: the amount actually paid must now be required — this used to capture nothing at all. */
    public function testMarkSupplierBalancePaidRefusesAMissingAmount(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-8', $this->fullSupplierPoData([
            'balance_amount_inr' => '30000',
        ]), 'issued');
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['paid_date' => '2026-10-20'];
        \App\Helpers\Flash::pull(); // drain any leftover flash from an earlier test in this process

        ob_start();
        (new OrderController())->markSupplierBalancePaid(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderSupplierPoRepository::findLatestForOrder($orderId)['balance_paid_at']);
        $flash = \App\Helpers\Flash::pull();
        self::assertNotEmpty($flash);
        self::assertStringContainsString('amount', $flash[0]['message']);
    }

    /** External QA report R-11: a valid date + amount through the real controller action persists both, including when the amount differs from the PO's own planned amount. */
    public function testMarkSupplierAdvancePaidPersistsADifferentActuallyPaidAmount(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Test Supplier']);
        OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-9', $this->fullSupplierPoData([
            'advance_amount_inr' => '20000',
        ]), 'issued');
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['paid_date' => '2026-10-20', 'amount_paid_inr' => '19500.50'];

        ob_start();
        (new OrderController())->markSupplierAdvancePaid(['id' => (string) $orderId]);
        ob_end_clean();

        $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
        self::assertSame('2026-10-20', $supplierPo['advance_paid_at']);
        self::assertSame('19500.50', $supplierPo['advance_paid_amount_inr']);
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
        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2031-03-11', $userId, 20000.0);
        OrderSupplierPoRepository::markBalancePaid($supplierPoId, '2031-03-12', $userId, 30000.0);

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

        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2026-10-20', $this->createTestUser('Admin'), 20000.0);

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
        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2026-10-21', $clearUserId, 20000.0);

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

    /**
     * External QA report Q-RPT-1 (owner review, 2026-10-23): "Viewer and
     * other view_reports roles can open Today's Payments, which also
     * lists supplier (outgoing INR) payments — cost information." The
     * Outgoing section (and its underlying data) is now only computed
     * for whoever holds manage_supplier_payments.
     */
    public function testTodaysPaymentsHidesTheSupplierSectionWithoutManageSupplierPayments(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $adminId = $this->createTestUser('Admin');
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'PHPUnit Restricted Supplier']);
        $supplierPoId = OrderSupplierPoRepository::create($orderId, $supplierId, 'SUPPO-PAY-5', $this->fullSupplierPoData([
            'advance_amount_inr' => '45000',
        ]), 'issued');
        OrderSupplierPoRepository::markAdvancePaid($supplierPoId, '2031-05-05', $adminId, 45000.0);

        $_GET['date'] = '2031-05-05';
        $_SESSION['_auth_user_id'] = $this->createTestUser('Viewer / Auditor');
        ob_start();
        (new ReportController())->todaysPayments([]);
        $asViewer = ob_get_clean();

        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');
        ob_start();
        (new ReportController())->todaysPayments([]);
        $asExportExecutive = ob_get_clean();

        $_SESSION['_auth_user_id'] = $this->createTestUser('Accounts Executive');
        ob_start();
        (new ReportController())->todaysPayments([]);
        $asAccounts = ob_get_clean();
        unset($_GET['date']);

        self::assertStringNotContainsString('PHPUnit Restricted Supplier', $asViewer);
        self::assertStringContainsString('internal cost data', $asViewer);
        self::assertStringNotContainsString('PHPUnit Restricted Supplier', $asExportExecutive);
        self::assertStringContainsString('PHPUnit Restricted Supplier', $asAccounts, 'Accounts must still see supplier payment data');
        self::assertStringContainsString(number_format(45000.0, 2), $asAccounts);
    }
}
