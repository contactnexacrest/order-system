<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Services\OrderProfitabilityService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-413 (25 Nov 2026, confirmed twice): a cancelled or lost order counts only the money that
 * actually moved — what the buyer paid (less a completed refund), what was paid to the supplier, real bills
 * and expenses. No balance still to come, no full supplier order, no unbilled freight, no estimate.
 * Node twin: cancelledOrderActualMoneyD413.test.js.
 */
final class CancelledOrderActualMoneyD413Test extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = $this->createTestUser('Admin');
    }

    /** USD CIF order of 10,000: advance 3,000 received at 84 (₹252,000 credited), supplier PO ₹600,000 with ₹150,000 paid, freight 1,000 confirmed. */
    private function order(string $status): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF');
        OrderProductRepository::add($orderId, 1, 'Test granite', null, null, '10', false, 'PCS', '1000');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 3000.0, '2026-11-01');
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 84.0, $this->userId);
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 252000.0, $this->userId);
        OrderPaymentStatusRepository::setBalanceAmount($orderId, 7000.0, null);
        OrderFreightRepository::upsert($orderId, ['confirmed_freight_rate' => '1000', 'insurance_amount' => '50']);
        $pdo->exec("INSERT INTO suppliers (supplier_legal_name) VALUES ('TEST-Supplier D413')");
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference, total_payable_inr, advance_paid_amount_inr)
                    VALUES ({$orderId}, {$supplierId}, 'TEST-Supplier D413', 'TEST-SPO-D413', 600000, 150000)");
        $pdo->prepare('UPDATE orders SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $orderId]);
        return $orderId;
    }

    private function refund(int $orderId, float $amount, string $refundStatus): void
    {
        Database::connection()->prepare("INSERT INTO order_cancellations (order_id, reason, requested_by, status, refund_amount, refund_status)
            VALUES (:o, 'TEST buyer withdrew', :u, 'approved', :a, :r)")->execute(['o' => $orderId, 'u' => $this->userId, 'a' => $amount, 'r' => $refundStatus]);
    }

    public function testACancelledOrderCountsOnlyMoneyThatMoved(): void
    {
        $p = OrderProfitabilityService::computeForOrder($this->order('cancelled'));

        self::assertTrue($p['actual_money_only']);
        self::assertFalse($p['revenue_is_estimated'], 'nothing is estimated');
        self::assertSame(252000.0, $p['revenue_inr'], 'only the advance actually credited, no balance to come');
        self::assertSame(150000.0, $p['supplier_cost_inr'], 'only what was paid to the supplier, not the PO total');
        self::assertSame(0.0, $p['freight_cost_inr'], 'confirmed freight rate not billed is not money that moved');
        self::assertSame(0.0, $p['insurance_cost_inr']);
    }

    public function testACompletedRefundIsTakenOffAtTheAdvanceRate(): void
    {
        $orderId = $this->order('cancelled');
        $this->refund($orderId, 1000.0, 'completed');

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame(168000.0, $p['revenue_inr'], '₹252,000 − USD 1,000 × 84');
    }

    public function testARefundNotYetPaidIsNotTakenOff(): void
    {
        $orderId = $this->order('cancelled');
        $this->refund($orderId, 1000.0, 'pending');

        self::assertSame(252000.0, OrderProfitabilityService::computeForOrder($orderId)['revenue_inr']);
    }

    public function testAReceivedLegWithoutRupeesEnteredCountsAtItsRate(): void
    {
        $orderId = $this->order('lost');
        OrderPaymentStatusRepository::clearAdvanceInrActual($orderId);

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertTrue($p['actual_money_only']);
        self::assertSame(252000.0, $p['revenue_inr'], 'USD 3,000 received × 84');
    }

    public function testAnOpenOrderStillShowsTheEstimate(): void
    {
        $p = OrderProfitabilityService::computeForOrder($this->order('active'));

        self::assertFalse($p['actual_money_only']);
        self::assertSame(600000.0, $p['supplier_cost_inr'], 'open orders keep the full supplier order');
        self::assertSame(84000.0, $p['freight_cost_inr'], 'USD 1,000 × 84');
    }

    public function testTheProfitabilityReportTagsIt(): void
    {
        // D-422 (25 Nov 2026): the finance report says which rows are "actual money only".
        $cancelled = $this->order('cancelled');
        $lost = $this->order('lost');
        $open = $this->order('active');
        $rows = array_column(\App\Repositories\ReportRepository::orderProfitabilityReport(null, null)['rows'], null, 'order_id');
        self::assertTrue($rows[$cancelled]['actual_money_only']);
        self::assertSame('cancelled', $rows[$cancelled]['order_status']);
        self::assertSame('lost', $rows[$lost]['order_status']);
        self::assertFalse($rows[$open]['actual_money_only']);
        $view = (string) file_get_contents(dirname(__DIR__, 2) . '/views/reports/profitability.php');
        self::assertStringContainsString('data-actual-money-tag', $view);
        self::assertStringContainsString(' — actual money only', $view);
        self::assertStringContainsString('data-actual-money-legend', $view);
    }

    public function testTheOrderPageExplainsIt(): void
    {
        $orderId = $this->order('cancelled');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$this->userId}");
        $_SESSION = ['_auth_user_id' => $this->userId, '_session_version' => AuthService::sessionVersion() ?? 0];
        PermissionService::resetCache();
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-actual-money-only', $html);
        self::assertStringContainsString('only money that actually moved is counted', $html);
    }
}
