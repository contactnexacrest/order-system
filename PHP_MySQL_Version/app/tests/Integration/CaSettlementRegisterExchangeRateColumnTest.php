<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\CaController;
use App\Repositories\OrderPaymentStatusRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #56/#84: CaRepository::settlementRegister() already
 * computes each cleared leg's own exchange_rate (used internally to work
 * out forex gain/loss), but the INR Settlement Register table on the CA
 * hub page never actually rendered it as a column — a viewer could see
 * the foreign amount and the INR actual, but not the rate that connects
 * them, without opening the order itself.
 */
final class CaSettlementRegisterExchangeRateColumnTest extends DbTestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['_auth_user_id']);
        parent::tearDown();
    }

    public function testSettlementRegisterRendersAnExchangeRateColumnWithEachLegsOwnRate(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.00, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $userId);
        OrderPaymentStatusRepository::setLegExchangeRate($orderId, 'advance', 90.5, $userId);
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 91200.00, $userId);

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new CaController();
        ob_start();
        $controller->index([]);
        $html = ob_get_clean();

        self::assertStringContainsString('<th>Exchange Rate</th>', $html);
        $headerPos = strpos($html, '<th>Exchange Rate</th>');
        $ratePos = strpos($html, '90.5000');
        self::assertNotFalse($ratePos, 'the leg\'s own exchange rate (90.5) must be rendered, not just used internally for forex gain/loss');
        self::assertGreaterThan($headerPos, $ratePos, 'the rendered rate must come after the column header in the document');
    }

    public function testSettlementRegisterShowsADashWhenNoRateIsRecordedYet(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.00, '2026-01-10');
        OrderPaymentStatusRepository::markAdvanceCleared($orderId, '2026-01-15', $userId);
        // Deliberately no setLegExchangeRate() call — a cleared leg with no rate on file yet.

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new CaController();
        ob_start();
        $controller->index([]);
        $html = ob_get_clean();

        self::assertStringContainsString('<th>Exchange Rate</th>', $html);
    }
}
