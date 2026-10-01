<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\DashboardController;
use App\Controllers\OrderController;
use App\Controllers\ReportController;
use App\Repositories\OrderFreightRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Tests\Support\DbTestCase;

/**
 * "we should show currency like USD/GBP/INR etc whenever needed — this
 * avoids confusion and makes it clear" — a sitewide audit found several
 * places showing a raw monetary figure with no currency code next to it
 * (Payment Status on the order page, the dashboard's overdue-payments
 * widget, and two report tables). This pins the fix at the places most
 * likely to regress silently; the Order Financials panel's own currency
 * display is already covered elsewhere (OrderFinancialsTest, CA tests).
 */
final class CurrencyDisplayTest extends DbTestCase
{
    public function testOrderShowPagePaymentStatusDisplaysCurrencyCode(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 5000.0, '2026-06-01');
        OrderPaymentStatusRepository::setBalanceAmount($orderId, 3000.0, '2026-07-01');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertMatchesRegularExpression('/5,000\.00\s*USD/', $output, 'advance amount must show the order currency');
        self::assertMatchesRegularExpression('/3,000\.00\s*USD/', $output, 'balance amount must show the order currency');
    }

    public function testDashboardOverdueBalanceWidgetDisplaysCurrencyCode(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::setBalanceAmount($orderId, 4200.0, '2020-01-01');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new DashboardController())->index([]);
        $output = ob_get_clean();

        self::assertMatchesRegularExpression('/4,200\.00\s*USD/', $output, 'overdue balance must show the order currency');
    }

    public function testPerClientReportShowsCurrencyColumnsForPaymentsAndProducts(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.0, '2026-06-01');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new ReportController())->client(['clientId' => (string) $clientId]);
        $output = ob_get_clean();

        self::assertStringContainsString('<th>Currency</th>', $output);
    }

    public function testFreightCostReportShowsCurrencyColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderFreightRepository::upsert($orderId, [
            'confirmed_freight_rate' => '1500.00',
            'insurance_amount' => '0',
            'freight_forwarder_name' => 'PHPUnit Forwarder',
        ]);

        $_SESSION['_auth_user_id'] = $userId;
        $_GET = [];
        ob_start();
        (new ReportController())->freightCost([]);
        $output = ob_get_clean();

        self::assertStringContainsString('<th>Currency</th>', $output);
        self::assertStringContainsString('USD', $output);
    }
}
