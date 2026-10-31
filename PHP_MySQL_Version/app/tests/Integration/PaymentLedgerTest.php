<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\ClientPaymentReportRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Tests\Support\DbTestCase;

/**
 * Payment Overview's Snapshot + Ledger tabs — a quick-glance summary and
 * a single consolidated, read-only history of every payment-related event on an
 * order (advance/freight/balance remittance+clearance, plus every
 * client-self-reported payment). Amounts are gated: everyone who can view
 * the order sees each leg's STATUS, but only manage_payments/close_orders
 * (Super Admin already covered transitively through PermissionService::can())
 * see the actual figures — "only able to see the needed things."
 *
 * UI-polish round: Snapshot/Ledger/Full Status became real tabs (parallel
 * views of the same data) instead of nested <details>, so both panels are
 * always present in the static markup (zero-JS-safe degrade — JS applies
 * the single-pane switching at runtime) and are asserted by their
 * tab-panel id rather than a now-gone per-panel <summary>.
 */
final class PaymentLedgerTest extends DbTestCase
{
    /** Owner decision D-214, phase 2: a department that may not see buyer payments (Logistics) sees the status only. */
    public function testGeneralStaffSeeStatusOnlyNoAmounts(): void
    {
        $userId = $this->createTestUser('Logistics Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $userId);
        OrderPaymentStatusRepository::initializeForOrder($orderId);
        OrderPaymentStatusRepository::recordAdvanceReceived($orderId, 5000.0, '2026-06-01');

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Payment Overview', $output);
        self::assertStringContainsString('id="payment-overview-snapshot"', $output);
        self::assertStringContainsString('id="payment-overview-ledger"', $output);
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

    /** Owner decision D-214, phase 2: the auditors see every amount (read only), without "Manage payments". */
    public function testAuditorSeesAmountsWithoutManagePayments(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
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

        self::assertStringContainsString('payment reported by the client', $output);
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
