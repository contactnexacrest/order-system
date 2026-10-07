<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Services\OrderProfitabilityService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Batch 3 #3: order_payment_status.assumed_exchange_rate was a single rate
 * shared by all three settlement legs, set once — but advance, balance and
 * freight clear on different dates, often months apart, at genuinely
 * different market rates. Replaced with advance_exchange_rate/
 * balance_exchange_rate/freight_exchange_rate, each captured and used
 * independently. Covers the controller actions, the repository write, the
 * forex gain/loss calc now keyed per leg, and that the order-show page
 * renders correctly once a leg is cleared with its own rate on file.
 */
final class PerLegExchangeRateTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    public function testEachLegsRateIsRecordedIndependently(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $_POST = ['advance_exchange_rate' => '88.5'];
        ob_start();
        (new OrderController())->recordAdvanceExchangeRate(['id' => (string) $orderId]);
        ob_end_clean();

        $_POST = ['balance_exchange_rate' => '90.25'];
        ob_start();
        (new OrderController())->recordBalanceExchangeRate(['id' => (string) $orderId]);
        ob_end_clean();

        $_POST = ['freight_exchange_rate' => '91.0'];
        ob_start();
        (new OrderController())->recordFreightExchangeRate(['id' => (string) $orderId]);
        ob_end_clean();

        $payment = OrderPaymentStatusRepository::find($orderId);
        self::assertEqualsWithDelta(88.5, (float) $payment['advance_exchange_rate'], 0.001, 'advance rate must be recorded independently');
        self::assertEqualsWithDelta(90.25, (float) $payment['balance_exchange_rate'], 0.001, 'balance rate must be recorded independently');
        self::assertEqualsWithDelta(91.0, (float) $payment['freight_exchange_rate'], 0.001, 'freight rate must be recorded independently');
        self::assertSame($this->userId, (int) $payment['advance_exchange_rate_set_by']);
    }

    public function testUpdatingOneLegsRateNeverTouchesAnotherLegsRate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 85.0, $this->userId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'balance', 86.0, $this->userId);

        $_POST = ['advance_exchange_rate' => '95.0'];
        ob_start();
        (new OrderController())->recordAdvanceExchangeRate(['id' => (string) $orderId]);
        ob_end_clean();

        $payment = OrderPaymentStatusRepository::find($orderId);
        self::assertEqualsWithDelta(95.0, (float) $payment['advance_exchange_rate'], 0.001, 'advance rate must have been updated');
        self::assertEqualsWithDelta(86.0, (float) $payment['balance_exchange_rate'], 0.001, "balance rate must be untouched by an advance-leg update");
    }

    public function testZeroOrMissingRateIsRejected(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $_POST = ['advance_exchange_rate' => '0'];
        ob_start();
        (new OrderController())->recordAdvanceExchangeRate(['id' => (string) $orderId]);
        ob_end_clean();

        $payment = OrderPaymentStatusRepository::find($orderId);
        self::assertNull($payment['advance_exchange_rate'], 'a zero rate must be rejected, not recorded');
    }

    public function testForexGainLossUsesEachLegsOwnRateNotAnotherLegs(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        // Balance Payment's section only renders once Stage 8 unlocks (BL
        // issued) — walk the stage gates forward so both legs' widgets
        // are actually on the rendered page, not just advance's. This is
        // an FOB order (createTestOrder's default), so Stage 6 (Freight
        // Payment) is skipped, not passed — same as StageGateServiceTest.
        for ($stage = 1; $stage <= 5; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, $this->userId);
        }
        StageGateService::maybeAutoSkipFreightStage($orderId, $this->userId);
        StageGateService::passAndUnlockNext($orderId, 7, $this->userId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.00, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $this->userId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 90.0, $this->userId);
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 91000.0, $this->userId);

        OrderPaymentStatusRepository::recordBalanceReceived($orderId, 1000.00, '2026-04-10');
        OrderPaymentStatusRepository::markBalanceCleared($orderId, '2026-04-15', $this->userId);
        // $balanceCleared in the view is driven by Stage 8's gate having
        // been PASSED (StageGateService), not by this column alone — same
        // sequence OrderController::clearBalancePayment() performs.
        StageGateService::passAndUnlockNext($orderId, 8, $this->userId);
        // Deliberately a very different rate from advance's — months apart,
        // a genuinely different market rate, the whole point of this feature.
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'balance', 86.0, $this->userId);
        OrderPaymentStatusRepository::setBalanceInrActual($orderId, 86500.0, $this->userId);

        $_SESSION['_auth_user_id'] = $this->userId;
        $controller = new OrderController();
        ob_start();
        $controller->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        // Hand-computed: advance expected = 1000*90 = 90000, gain = 91000-90000 = 1000 (gain).
        self::assertStringContainsString('Forex gain: &#8377;1,000.00', $output);
        // Hand-computed: balance expected = 1000*86 = 86000, gain = 86500-86000 = 500 (gain).
        self::assertStringContainsString('Forex gain: &#8377;500.00', $output);
        self::assertStringContainsString('90.0000', $output, "advance leg's own rate must be shown");
        self::assertStringContainsString('86.0000', $output, "balance leg's own rate must be shown, distinct from advance's");
    }

    public function testOrderProfitabilityUsesPerLegRateForWhicheverLegHasNotClearedYet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
        // FOB value = 10 * 100 = 1000 (foreign currency units); advance_amount
        // and balance_amount are both still null (pre-PI order) — the
        // service falls back to the full FOB value at whichever leg's rate
        // is on file, same as the single shared-rate design used to.
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 87.0, $this->userId);

        $p = OrderProfitabilityService::computeForOrder($orderId);

        self::assertSame(87000.0, $p['revenue_inr']);
        self::assertTrue($p['revenue_is_estimated']);
    }

    public function testOrderProfitabilityMixesRealAdvanceWithEstimatedBalanceUsingBalancesOwnRate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'Test Stone', null, null, '10', false, 'SQM', '100');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 300.0, '2026-01-10');
        OrderPaymentStatusRepository::recordBalanceReceived($orderId, 700.0, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $this->userId);
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 27000.0, $this->userId); // advance leg realized exactly
        // Balance hasn't cleared yet — estimate it using ITS OWN rate, not
        // advance's, even though advance already has a real actual.
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'balance', 92.0, $this->userId);

        $p = OrderProfitabilityService::computeForOrder($orderId);

        // 27000 (real advance) + 700*92 (estimated balance) = 27000 + 64400 = 91400.
        self::assertSame(91400.0, $p['revenue_inr']);
        self::assertTrue($p['revenue_is_estimated'], 'still partly estimated since balance has not cleared');
    }

    public function testUnknownLegIsRejectedByTheRepository(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);

        $this->expectException(\InvalidArgumentException::class);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'not_a_real_leg', 85.0, $this->userId);
    }
}
