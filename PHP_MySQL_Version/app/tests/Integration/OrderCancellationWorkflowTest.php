<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderCancellationRepository;
use App\Repositories\OrderRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report M-09: "Formal order cancellation workflow (reason,
 * approval, money/refund handling, audit) distinct from 'mark lost'."
 * Request/approve-or-reject, modeled on AmendmentRepository's own
 * request/MD-approve/reject shape and MakerCheckerGuard's self-approval
 * separation — see docs/migrations/2026-10-23c-order-cancellation-workflow.sql.
 */
final class OrderCancellationWorkflowTest extends DbTestCase
{
    private function lastAuditLogAction(): ?array
    {
        $stmt = Database::connection()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
        return $stmt->fetch() ?: null;
    }

    public function testRequestCancellationCreatesAPendingRow(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'Buyer has gone unresponsive for over a month.'];

        ob_start();
        (new OrderController())->requestCancellation(['id' => (string) $orderId]);
        ob_end_clean();

        $pending = OrderCancellationRepository::findPendingForOrder($orderId);
        self::assertNotNull($pending);
        self::assertSame('pending', $pending['status']);
        self::assertSame($userId, (int) $pending['requested_by']);
        self::assertSame('active', OrderRepository::find($orderId)['status'], 'a request must never change the order status by itself');

        $audit = $this->lastAuditLogAction();
        self::assertSame('ORDER_CANCELLATION_REQUESTED', $audit['action_type']);
    }

    public function testRequestCancellationRejectsATooShortReason(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'short'];

        ob_start();
        (new OrderController())->requestCancellation(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderCancellationRepository::findPendingForOrder($orderId));
    }

    public function testRequestCancellationBlocksADuplicatePendingRequest(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'First cancellation request for this order.'];
        ob_start();
        (new OrderController())->requestCancellation(['id' => (string) $orderId]);
        ob_end_clean();

        $_POST = ['reason' => 'A second, different cancellation reason entirely.'];
        ob_start();
        (new OrderController())->requestCancellation(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertCount(1, OrderCancellationRepository::forOrder($orderId));
    }

    public function testRequestCancellationRefusedOnAnAlreadyTerminalOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare("UPDATE orders SET status = 'complete' WHERE id = :id")->execute(['id' => $orderId]);
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['reason' => 'Trying to cancel an already-complete order.'];

        ob_start();
        (new OrderController())->requestCancellation(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderCancellationRepository::findPendingForOrder($orderId));
    }

    public function testApproveCancellationCancelsTheOrderAndRecordsRefundFields(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $approver = $this->createTestUser('Admin');
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer requested cancellation before shipment.', $requester);

        $_SESSION['_auth_user_id'] = $approver;
        $_POST = ['refund_status' => 'pending', 'refund_amount' => '1500.50', 'decision_notes' => 'Advance will be refunded once reconciled.'];

        ob_start();
        (new OrderController())->approveCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        $order = OrderRepository::find($orderId);
        self::assertSame('cancelled', $order['status']);
        self::assertSame(1, (int) $order['is_locked']);

        $cancellation = OrderCancellationRepository::find($cancellationId);
        self::assertSame('approved', $cancellation['status']);
        self::assertSame($approver, (int) $cancellation['decided_by']);
        self::assertSame('pending', $cancellation['refund_status']);
        self::assertSame('1500.50', $cancellation['refund_amount']);

        $audit = $this->lastAuditLogAction();
        self::assertSame('ORDER_CANCELLATION_APPROVED', $audit['action_type']);
    }

    public function testApproveCancellationRefusedWhenNotPending(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $approver = $this->createTestUser('Admin');
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer requested cancellation before shipment.', $requester);
        OrderCancellationRepository::reject($cancellationId, $approver, 'Already resolved directly with the buyer.');

        $_SESSION['_auth_user_id'] = $approver;
        $_POST = [];

        ob_start();
        (new OrderController())->approveCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        self::assertSame('active', OrderRepository::find($orderId)['status'], 'approving an already-decided request must not re-cancel the order');
    }

    public function testApproveCancellationBlocksSelfApprovalForAnOrdinaryUser(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $cancellationId = OrderCancellationRepository::create($orderId, 'Requesting and trying to approve my own cancellation.', $requester);

        $_SESSION['_auth_user_id'] = $requester;
        $_POST = ['refund_status' => 'not_applicable'];

        ob_start();
        (new OrderController())->approveCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        self::assertSame('active', OrderRepository::find($orderId)['status'], 'self-approval must be blocked for an ordinary user');
        self::assertSame('pending', OrderCancellationRepository::find($cancellationId)['status']);
    }

    public function testApproveCancellationBlocksSelfApprovalEvenForAManagePermissionsHolder(): void
    {
        // External QA report N-04: Admin holds manage_permissions via
        // seed.sql's wildcard grant (and is one of the only roles that can
        // approve a cancellation at all), but must still be blocked from
        // approving their own request — only Super Admin is exempt here.
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Admin');
        $cancellationId = OrderCancellationRepository::create($orderId, 'Requesting and trying to approve my own cancellation as Admin.', $requester);

        $_SESSION['_auth_user_id'] = $requester;
        $_POST = ['refund_status' => 'not_applicable'];

        ob_start();
        (new OrderController())->approveCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        self::assertSame('active', OrderRepository::find($orderId)['status'], 'a manage_permissions holder must still be blocked from self-approving a cancellation');
        self::assertSame('pending', OrderCancellationRepository::find($cancellationId)['status']);
    }

    public function testApproveCancellationAllowsSelfApprovalForASuperAdmin(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $requester]);
        $cancellationId = OrderCancellationRepository::create($orderId, 'Requesting and approving my own cancellation as Super Admin.', $requester);

        $_SESSION['_auth_user_id'] = $requester;
        $_POST = ['refund_status' => 'not_applicable'];

        ob_start();
        (new OrderController())->approveCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        self::assertSame('cancelled', OrderRepository::find($orderId)['status']);
    }

    public function testRejectCancellationLeavesTheOrderUnaffectedAndRequiresNotes(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $approver = $this->createTestUser('Admin');
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer requested cancellation before shipment.', $requester);

        $_SESSION['_auth_user_id'] = $approver;
        $_POST = ['decision_notes' => 'short'];
        ob_start();
        (new OrderController())->rejectCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();
        self::assertSame('pending', OrderCancellationRepository::find($cancellationId)['status'], 'too-short notes must not reject the request');

        $_POST = ['decision_notes' => 'Buyer confirmed by phone they want to proceed after all.'];
        ob_start();
        (new OrderController())->rejectCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        self::assertSame('rejected', OrderCancellationRepository::find($cancellationId)['status']);
        self::assertSame('active', OrderRepository::find($orderId)['status'], 'a rejection must never change the order status');

        $audit = $this->lastAuditLogAction();
        self::assertSame('ORDER_CANCELLATION_REJECTED', $audit['action_type']);
    }

    public function testCompleteCancellationRefundOnlyWorksWhenApprovedAndRefundPending(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $approver = $this->createTestUser('Admin');
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer requested cancellation before shipment.', $requester);

        // Not yet approved — refusing to complete a refund that was never approved.
        $_SESSION['_auth_user_id'] = $approver;
        $_POST = ['refund_notes' => 'Trying too early.'];
        ob_start();
        (new OrderController())->completeCancellationRefund(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();
        self::assertSame('not_applicable', OrderCancellationRepository::find($cancellationId)['refund_status']);

        OrderCancellationRepository::approve($cancellationId, $approver, null, 2000.00, 'pending');

        $_POST = ['refund_notes' => 'Wire transfer TXN123 sent back to buyer.', 'refund_paid_at' => date('Y-m-d'), 'refund_reference' => 'TXN123'];
        ob_start();
        (new OrderController())->completeCancellationRefund(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        $cancellation = OrderCancellationRepository::find($cancellationId);
        self::assertSame('completed', $cancellation['refund_status']);
        self::assertSame($approver, (int) $cancellation['refund_recorded_by']);

        $audit = $this->lastAuditLogAction();
        self::assertSame('ORDER_CANCELLATION_REFUND_COMPLETED', $audit['action_type']);
    }

    public function testOrdersIndexCountsAndFiltersACancelledOrderCorrectly(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::cancel($orderId);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $_GET = ['status' => 'cancelled'];

        ob_start();
        (new OrderController())->index([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Cancelled', $html);
        self::assertStringNotContainsString('badge-active">Active', $html, 'a cancelled order must never show the Active badge');
    }

    public function testOrderShowPageRendersThePendingCancellationBanner(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        OrderCancellationRepository::create($orderId, 'Buyer requested cancellation before shipment.', $requester);
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('awaiting approval', $html);
        self::assertStringContainsString('Approve Cancellation', $html);
        self::assertStringContainsString('Reject Cancellation', $html);
    }

    public function testOrderShowPageOffersRequestCancellationOnAnActiveOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Request Cancellation', $html);
    }
}
