<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientPortalController;
use App\Controllers\OrderController;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderCancellationRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-07: raising a dispute from the client portal and
 * requesting/approving/rejecting an order cancellation only ever wrote
 * the audit log (the catch-all notification email) — the specific
 * people actually responsible for acting on each (manage_disputes
 * holders, edit_locked_data holders) and the original requester on a
 * cancellation decision never got a targeted in-app notification,
 * unlike submitFeedback()'s own dual-channel pattern for manage_orders.
 */
final class DisputeAndCancellationNotificationTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
    }

    public function testRaisingADisputeNotifiesEveryManageDisputesHolder(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        \App\Config\Database::connection()->prepare('UPDATE orders SET dispute_button_visible_to_client = 1 WHERE id = :id')->execute(['id' => $orderId]);
        $disputeManager = $this->createTestUser('Admin');
        $noPermissionUser = $this->createTestUser('Export Executive');
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['description' => 'The goods arrived with visible damage to the packaging.'];

        ob_start();
        (new ClientPortalController())->raiseDispute(['id' => (string) $orderId]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($disputeManager);
        self::assertNotEmpty($notifications);
        self::assertSame('dispute_raised', $notifications[0]['type']);
        self::assertSame($orderId, (int) $notifications[0]['related_order_id']);

        self::assertSame([], NotificationRepository::forUser($noPermissionUser), 'a user without manage_disputes must not be notified');
    }

    public function testRequestingACancellationNotifiesEveryEditLockedDataHolder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $approver = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $requester;
        $_POST = ['reason' => 'Buyer has gone unresponsive for over a month.'];

        ob_start();
        (new OrderController())->requestCancellation(['id' => (string) $orderId]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($approver);
        self::assertNotEmpty($notifications);
        self::assertSame('order_cancellation_requested', $notifications[0]['type']);
        self::assertSame($orderId, (int) $notifications[0]['related_order_id']);
    }

    public function testApprovingACancellationNotifiesTheOriginalRequester(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $approver = $this->createTestUser('Admin');
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer requested cancellation before shipment.', $requester);
        $_SESSION['_auth_user_id'] = $approver;
        $_POST = ['refund_status' => 'not_applicable'];

        ob_start();
        (new OrderController())->approveCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($requester);
        self::assertNotEmpty($notifications);
        self::assertSame('order_cancellation_approved', $notifications[0]['type']);
    }

    public function testRejectingACancellationNotifiesTheOriginalRequester(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $approver = $this->createTestUser('Admin');
        $cancellationId = OrderCancellationRepository::create($orderId, 'Buyer requested cancellation before shipment.', $requester);
        $_SESSION['_auth_user_id'] = $approver;
        $_POST = ['decision_notes' => 'Buyer confirmed by phone they want to proceed after all.'];

        ob_start();
        (new OrderController())->rejectCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($requester);
        self::assertNotEmpty($notifications);
        self::assertSame('order_cancellation_rejected', $notifications[0]['type']);
    }
}
