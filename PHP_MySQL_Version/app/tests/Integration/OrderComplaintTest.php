<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Controllers\ComplaintController;
use App\Helpers\Flash;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderComplaintReplyRepository;
use App\Repositories\OrderComplaintRepository;
use App\Services\OrderComplaintService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BX — Complaint / Process Issue, split out of the
 * Disputes table (dispute_type, reverted). A Complaint means the client
 * does NOT want a refund/cancellation — the opposite of a Dispute — so it
 * is always available on any order (never gated by
 * dispute_button_visible_to_client) and is a genuine two-way conversation,
 * not a staff-only resolution log.
 */
final class OrderComplaintTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
        PermissionService::resetCache();
    }

    // ---------------------------------------------------------------
    // Permissions
    // ---------------------------------------------------------------

    public function testExportExecutiveCanRespondButCannotManageComplaints(): void
    {
        $userId = $this->createTestUser('Export Executive');
        self::assertTrue(PermissionService::can($userId, $this->roleIdOf($userId), 'respond_to_complaints'));
        self::assertFalse(PermissionService::can($userId, $this->roleIdOf($userId), 'manage_complaints'));
    }

    public function testAdminHasBothComplaintPermissionsViaTheAllPermissionsCrossJoin(): void
    {
        $userId = $this->createTestUser('Admin');
        self::assertTrue(PermissionService::can($userId, $this->roleIdOf($userId), 'manage_complaints'));
        self::assertTrue(PermissionService::can($userId, $this->roleIdOf($userId), 'respond_to_complaints'));
    }

    public function testViewerAuditorHasNeitherComplaintPermissionByDefault(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        self::assertFalse(PermissionService::can($userId, $this->roleIdOf($userId), 'manage_complaints'));
        self::assertFalse(PermissionService::can($userId, $this->roleIdOf($userId), 'respond_to_complaints'));
    }

    // ---------------------------------------------------------------
    // OrderComplaintRepository
    // ---------------------------------------------------------------

    public function testCreateAndFindRoundtrip(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $complaintId = OrderComplaintRepository::create($orderId, $clientId, 'Packaging was damp on arrival.');
        $row = OrderComplaintRepository::find($complaintId);

        self::assertNotNull($row);
        self::assertSame('open', $row['status']);
        self::assertSame('Packaging was damp on arrival.', $row['description']);
        self::assertNull($row['resolved_at']);
    }

    public function testUpdateStatusToResolvedSetsResolvedAt(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintRepository::create($orderId, $clientId, 'Slow email replies last time.');

        OrderComplaintRepository::updateStatus($complaintId, 'resolved');

        $row = OrderComplaintRepository::find($complaintId);
        self::assertSame('resolved', $row['status']);
        self::assertNotNull($row['resolved_at']);
    }

    public function testUpdateStatusBackToOpenClearsResolvedAt(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintRepository::create($orderId, $clientId, 'Something went wrong.');
        OrderComplaintRepository::updateStatus($complaintId, 'resolved');

        OrderComplaintRepository::updateStatus($complaintId, 'open');

        self::assertNull(OrderComplaintRepository::find($complaintId)['resolved_at']);
    }

    public function testAllFiltersByStatus(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $openId = OrderComplaintRepository::create($orderId, $clientId, 'Still open.');
        $resolvedId = OrderComplaintRepository::create($orderId, $clientId, 'Already fixed.');
        OrderComplaintRepository::updateStatus($resolvedId, 'resolved');

        $open = array_column(OrderComplaintRepository::all('open'), 'id');
        $resolved = array_column(OrderComplaintRepository::all('resolved'), 'id');

        self::assertContains($openId, $open);
        self::assertNotContains($resolvedId, $open);
        self::assertContains($resolvedId, $resolved);
    }

    // ---------------------------------------------------------------
    // OrderComplaintReplyRepository — genuine two-way conversation
    // ---------------------------------------------------------------

    public function testReplyThreadMixesStaffAndClientOldestFirst(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintRepository::create($orderId, $clientId, 'Initial complaint.');
        $userId = $this->createTestUser('Export Executive');

        OrderComplaintReplyRepository::createAsStaff($complaintId, $userId, 'We are looking into it.');
        OrderComplaintReplyRepository::createAsClient($complaintId, $clientId, 'Thanks, appreciated.');

        $replies = OrderComplaintReplyRepository::forComplaint($complaintId);
        self::assertCount(2, $replies);
        self::assertSame('staff', $replies[0]['author_type']);
        self::assertSame('We are looking into it.', $replies[0]['body']);
        self::assertSame('PHPUnit Test User', $replies[0]['staff_author_name']);
        self::assertSame('client', $replies[1]['author_type']);
        self::assertSame('Thanks, appreciated.', $replies[1]['body']);
    }

    // ---------------------------------------------------------------
    // OrderComplaintService — notifications + audit log
    // ---------------------------------------------------------------

    public function testRaiseAsClientNotifiesEveryComplaintPermissionHolder(): void
    {
        $manager = $this->createTestUser('Admin');
        $responder = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        OrderComplaintService::raiseAsClient($orderId, $clientId, 'Boxes arrived slightly dented.');

        self::assertGreaterThan(0, NotificationRepository::unreadCountForUser($manager));
        self::assertGreaterThan(0, NotificationRepository::unreadCountForUser($responder));
    }

    public function testRaiseAsStaffDoesNotDuplicateNotificationsToSelf(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $before = NotificationRepository::unreadCountForUser($userId);
        OrderComplaintService::raiseAsStaff($orderId, $clientId, $userId, 'Client called in with a concern.');

        // Staff-logged complaints are a record, not a self-notification.
        self::assertSame($before, NotificationRepository::unreadCountForUser($userId));
        self::assertCount(1, OrderComplaintRepository::forOrder($orderId));
    }

    public function testReplyAsClientNotifiesStaffAgain(): void
    {
        $manager = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintService::raiseAsClient($orderId, $clientId, 'First message.');
        NotificationRepository::markAllRead($manager);

        OrderComplaintService::replyAsClient($complaintId, $clientId, 'Following up on this.');

        self::assertGreaterThan(0, NotificationRepository::unreadCountForUser($manager));
    }

    public function testReplyAsStaffDoesNotThrowEvenWithoutAMailTransportConfigured(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintService::raiseAsClient($orderId, $clientId, 'Need a reply.');

        OrderComplaintService::replyAsStaff($complaintId, $userId, 'We will fix this next time.');

        $replies = OrderComplaintReplyRepository::forComplaint($complaintId);
        self::assertCount(1, $replies);
        self::assertSame('staff', $replies[0]['author_type']);
    }

    // ---------------------------------------------------------------
    // ComplaintController (staff side)
    // ---------------------------------------------------------------

    public function testStaffCreateLogsAComplaintOnTheClientsBehalf(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['description' => 'Client phoned in a packaging concern.'];

        ob_start();
        (new ComplaintController())->create(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertCount(1, OrderComplaintRepository::forOrder($orderId));
        self::assertSame('success', Flash::pull()[0]['type']);
    }

    public function testStaffCreateRejectsAnEmptyDescription(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['description' => '  '];

        ob_start();
        (new ComplaintController())->create(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertCount(0, OrderComplaintRepository::forOrder($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testPostReplyRejectsAnEmptyBody(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintRepository::create($orderId, $clientId, 'Something.');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['body' => ''];

        ob_start();
        (new ComplaintController())->postReply(['complaintId' => (string) $complaintId]);
        ob_end_clean();

        self::assertCount(0, OrderComplaintReplyRepository::forComplaint($complaintId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testUpdateStatusRejectsAnInvalidStatusValue(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintRepository::create($orderId, $clientId, 'Something.');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['status' => 'not_a_real_status'];

        ob_start();
        (new ComplaintController())->updateStatus(['complaintId' => (string) $complaintId]);
        ob_end_clean();

        self::assertSame('open', OrderComplaintRepository::find($complaintId)['status']);
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testUpdateStatusAcceptsAcknowledged(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintRepository::create($orderId, $clientId, 'Something.');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['status' => 'acknowledged'];

        ob_start();
        (new ComplaintController())->updateStatus(['complaintId' => (string) $complaintId]);
        ob_end_clean();

        self::assertSame('acknowledged', OrderComplaintRepository::find($complaintId)['status']);
    }

    public function testIndexFiltersByStatus(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        OrderComplaintRepository::create($orderId, $clientId, 'Open one.');
        $_SESSION['_auth_user_id'] = $userId;
        $_GET['status'] = 'open';

        ob_start();
        (new ComplaintController())->index([]);
        $output = ob_get_clean();
        unset($_GET['status']);

        self::assertStringContainsString('Open one.', $output);
    }

    // ---------------------------------------------------------------
    // ClientPortalController — always available, never gated by the
    // dispute toggle.
    // ---------------------------------------------------------------

    public function testRaiseComplaintWorksEvenWhenTheDisputeButtonIsOff(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        Database::connection()->prepare('UPDATE orders SET dispute_button_visible_to_client = 0 WHERE id = :id')->execute(['id' => $orderId]);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['description' => 'The delivery was a day late.'];

        ob_start();
        (new ClientPortalController())->raiseComplaint(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertCount(1, OrderComplaintRepository::forOrder($orderId));
        self::assertSame('success', Flash::pull()[0]['type']);
    }

    public function testRaiseComplaintRejectsAnEmptyDescription(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['description' => ''];

        ob_start();
        (new ClientPortalController())->raiseComplaint(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertCount(0, OrderComplaintRepository::forOrder($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testRaiseComplaintReturns404ForAnotherClientsOrder(): void
    {
        $ownerClientId = $this->createTestClient();
        $orderId = $this->createTestOrder($ownerClientId);
        $otherClientId = $this->createTestClient();
        $_SESSION['_client_portal_client_id'] = $otherClientId;
        $_POST = ['description' => 'Trying to complain on someone else\'s order.'];

        ob_start();
        (new ClientPortalController())->raiseComplaint(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertSame(404, http_response_code());
        self::assertCount(0, OrderComplaintRepository::forOrder($orderId));
    }

    public function testPostComplaintReplyAppendsToTheConversation(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintService::raiseAsClient($orderId, $clientId, 'First message.');
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['body' => 'Any update on this?'];

        ob_start();
        (new ClientPortalController())->postComplaintReply(['id' => (string) $orderId, 'complaintId' => (string) $complaintId]);
        ob_end_clean();

        $replies = OrderComplaintReplyRepository::forComplaint($complaintId);
        self::assertCount(1, $replies);
        self::assertSame('client', $replies[0]['author_type']);
        self::assertSame('success', Flash::pull()[0]['type']);
    }

    public function testPostComplaintReplyReturns404ForAnotherClientsComplaint(): void
    {
        $ownerClientId = $this->createTestClient();
        $orderId = $this->createTestOrder($ownerClientId);
        $complaintId = OrderComplaintService::raiseAsClient($orderId, $ownerClientId, 'Owner only.');
        $otherClientId = $this->createTestClient();
        $_SESSION['_client_portal_client_id'] = $otherClientId;
        $_POST = ['body' => 'Trying to reply to someone else\'s complaint.'];

        ob_start();
        (new ClientPortalController())->postComplaintReply(['id' => (string) $orderId, 'complaintId' => (string) $complaintId]);
        ob_end_clean();

        self::assertSame(404, http_response_code());
        self::assertCount(0, OrderComplaintReplyRepository::forComplaint($complaintId));
    }

    // ---------------------------------------------------------------
    // View rendering — the client portal section is always present,
    // regardless of dispute_button_visible_to_client.
    // ---------------------------------------------------------------

    public function testClientPortalOrderPageOffersComplaintFormRegardlessOfDisputeToggle(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        Database::connection()->prepare('UPDATE orders SET dispute_button_visible_to_client = 0 WHERE id = :id')->execute(['id' => $orderId]);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Report a Process Issue', $output);
        self::assertStringContainsString('action="/client/orders/' . $orderId . '/complaints"', $output);
        self::assertStringNotContainsString('Raise a Dispute', $output);
    }

    public function testClientPortalOrderPageShowsExistingComplaintConversation(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintService::raiseAsClient($orderId, $clientId, 'Delivery was late.');
        $userId = $this->createTestUser('Export Executive');
        OrderComplaintService::replyAsStaff($complaintId, $userId, 'Apologies — we are reviewing our courier.');
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Delivery was late.', $output);
        self::assertStringContainsString('Apologies — we are reviewing our courier.', $output);
    }

    private function roleIdOf(int $userId): ?int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT role_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $roleId = $stmt->fetchColumn();
        return $roleId !== null ? (int) $roleId : null;
    }
}
