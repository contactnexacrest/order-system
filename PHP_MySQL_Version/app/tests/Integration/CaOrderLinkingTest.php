<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\CaController;
use App\Controllers\OrderController;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Config\Database;
use App\Tests\Support\DbTestCase;

/**
 * Point 2 follow-up: a RODTEP/export-benefit claim or an expense (ECGC
 * insurance, third-party inspection, CHA, transport, ...) can be linked
 * to the specific order it relates to, and the order's own detail page
 * shows every linked benefit/expense at a glance — not just the separate
 * CA module list screens.
 */
final class CaOrderLinkingTest extends DbTestCase
{
    public function testExpenseForOrderReturnsOnlyThatOrdersExpenses(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderIdA = $this->createTestOrder($this->createTestClient());
        $orderIdB = $this->createTestOrder($this->createTestClient());

        $expenseId = CaExpenseRepository::insert('ZOHO-LINK-1', 'ECGC insurance', 'Shipment cover', 'ECGC', 5000.0, 'INR', '2026-06-01');
        CaExpenseRepository::insert('ZOHO-LINK-2', 'CHA', 'Unrelated expense', 'Some CHA', 1000.0, 'INR', '2026-06-01');

        self::assertSame([], CaExpenseRepository::forOrder($orderIdA));

        CaExpenseRepository::linkToOrder($expenseId, $orderIdA);

        $forA = CaExpenseRepository::forOrder($orderIdA);
        self::assertCount(1, $forA);
        self::assertSame($expenseId, (int) $forA[0]['id']);
        self::assertSame([], CaExpenseRepository::forOrder($orderIdB));
    }

    public function testLinkToOrderCanBeUndoneByPassingNull(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $expenseId = CaExpenseRepository::insert('ZOHO-LINK-3', 'Transport', null, 'Some Transporter', 2000.0, 'INR', '2026-06-01');

        CaExpenseRepository::linkToOrder($expenseId, $orderId);
        self::assertCount(1, CaExpenseRepository::forOrder($orderId));

        CaExpenseRepository::linkToOrder($expenseId, null);
        self::assertSame([], CaExpenseRepository::forOrder($orderId));
    }

    public function testAllJoinsInTheLinkedOrderReference(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $order = \App\Repositories\OrderRepository::find($orderId);
        $expenseId = CaExpenseRepository::insert('ZOHO-LINK-4', 'Inspection', null, 'SGS', 3000.0, 'INR', '2026-06-01');
        CaExpenseRepository::linkToOrder($expenseId, $orderId);

        $found = array_values(array_filter(CaExpenseRepository::all(), static fn(array $r): bool => (int) $r['id'] === $expenseId))[0];
        self::assertSame($order['order_reference'], $found['order_reference']);
    }

    public function testExportBenefitForOrderReturnsOnlyThatOrdersClaims(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderIdA = $this->createTestOrder($this->createTestClient());
        $orderIdB = $this->createTestOrder($this->createTestClient());

        CaExportBenefitRepository::record($orderIdA, 'RODTEP', 'SB-LINK-1', 8000, '2026-06-01', 'INR', null, $userId);
        CaExportBenefitRepository::record($orderIdB, 'RODTEP', 'SB-LINK-2', 4000, '2026-06-01', 'INR', null, $userId);
        CaExportBenefitRepository::record(null, 'RODTEP', 'SB-LINK-3', 1000, '2026-06-01', 'INR', null, $userId);

        $forA = CaExportBenefitRepository::forOrder($orderIdA);
        self::assertCount(1, $forA);
        self::assertSame('SB-LINK-1', $forA[0]['reference_number']);
    }

    public function testLinkExpenseToOrderControllerLinksByValidReference(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $order = \App\Repositories\OrderRepository::find($orderId);
        $expenseId = CaExpenseRepository::insert('ZOHO-LINK-5', 'ECGC insurance', null, 'ECGC', 6000.0, 'INR', '2026-06-01');

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['order_reference' => $order['order_reference']];
        $controller = new CaController();
        ob_start();
        $controller->linkExpenseToOrder(['id' => (string) $expenseId]);
        ob_get_clean();

        $expense = CaExpenseRepository::find($expenseId);
        self::assertSame($orderId, (int) $expense['order_id']);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'CA_EXPENSE_LINKED_TO_ORDER' AND entity_id = {$expenseId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testLinkExpenseToOrderControllerRejectsAnUnknownReference(): void
    {
        $userId = $this->createTestUser('Admin');
        $expenseId = CaExpenseRepository::insert('ZOHO-LINK-6', 'CHA', null, 'Some CHA', 1500.0, 'INR', '2026-06-01');

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['order_reference' => 'NO-SUCH-ORDER-REF-999'];
        $controller = new CaController();
        ob_start();
        $controller->linkExpenseToOrder(['id' => (string) $expenseId]);
        ob_get_clean();

        $expense = CaExpenseRepository::find($expenseId);
        self::assertNull($expense['order_id']);
    }

    public function testLinkExpenseToOrderControllerUnlinksOnBlankReference(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $expenseId = CaExpenseRepository::insert('ZOHO-LINK-7', 'Transport', null, 'Some Transporter', 2500.0, 'INR', '2026-06-01');
        CaExpenseRepository::linkToOrder($expenseId, $orderId);

        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['order_reference' => ''];
        $controller = new CaController();
        ob_start();
        $controller->linkExpenseToOrder(['id' => (string) $expenseId]);
        ob_get_clean();

        $expense = CaExpenseRepository::find($expenseId);
        self::assertNull($expense['order_id']);

        $count = (int) Database::connection()
            ->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'CA_EXPENSE_UNLINKED_FROM_ORDER' AND entity_id = {$expenseId}")
            ->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testOrderShowPageDisplaysLinkedBenefitsAndExpenses(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $order = \App\Repositories\OrderRepository::find($orderId);

        CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB-SHOW-1', 9000, '2026-06-01', 'INR', null, $userId);
        $expenseId = CaExpenseRepository::insert('ZOHO-SHOW-1', 'ECGC insurance', null, 'ECGC', 4500.0, 'INR', '2026-06-01');
        CaExpenseRepository::linkToOrder($expenseId, $orderId);

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new OrderController();
        ob_start();
        $controller->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Order Financials', $output);
        self::assertStringContainsString('RODTEP', $output);
        self::assertStringContainsString('ECGC insurance', $output);
    }

    public function testOrderShowPageShowsEmptyStateWhenNothingLinked(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $controller = new OrderController();
        ob_start();
        $controller->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Nothing linked to this order yet', $output);
    }
}
