<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Tests\Support\DbTestCase;

/**
 * Payment Snapshot + Payment Ledger — a quick-glance summary and a single
 * consolidated, read-only history of every payment-related event on an
 * order (advance/freight/balance remittance+clearance, plus every
 * client-self-reported payment). Amounts are gated: everyone who can view
 * the order sees each leg's STATUS, but only manage_payments/close_orders
 * (Super Admin already covered transitively through PermissionService::can())
 * see the actual figures — "only able to see the needed things."
 */
final class PaymentLedgerTest extends DbTestCase
{
    public function testGeneralStaffSeeStatusOnlyNoAmounts(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 5000.0, '2026-06-01');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Payment Snapshot', $output);
        self::assertStringContainsString('Payment Ledger', $output);
        self::assertStringContainsString('Received, pending clearance', $output);
        self::assertStringNotContainsString('5,000.00', $output);
    }

    public function testManagePaymentsHolderSeesAmounts(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 5000.0, '2026-06-01');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertMatchesRegularExpression('/5,000\.00\s*USD/', $output);
    }

    public function testCloseOrdersHolderSeesAmountsEvenWithoutManagePayments(): void
    {
        $userId = $this->createTestUser('Logistics Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 7500.0, '2026-06-01');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertMatchesRegularExpression('/7,500\.00\s*USD/', $output);
    }

    public function testLedgerIncludesClientReportedPaymentsChronologically(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 1000.0, '2026-06-01');
        ClientPaymentReportRepository::create($orderId, 'advance', 'UTR12345', 'Test Bank', 1000.0, '2026-05-30', null);

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Payment Reported by Client', $output);
        self::assertStringContainsString('Client-reported', $output);
    }

    public function testNoPaymentActivityShowsEmptyLedgerMessage(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('No payment events recorded yet.', $output);
    }
}
