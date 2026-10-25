<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderFeedbackRepository;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BV — export process step 14: client Order
 * Feedback, a 1-5 star rating + comments, one row per order.
 *
 * (Steps 15-16, the Complaint/Process-Issue feature that originally
 * tagged this same migration's Disputes table, moved to its own
 * OrderComplaintTest — see docs/schema.sql Section BX.)
 */
final class OrderFeedbackTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
    }

    /** External QA report N-12: feedback is only offered once an order has reached this status. */
    private function markShippedDocsSent(int $orderId): void
    {
        Database::connection()->prepare("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id")->execute(['id' => $orderId]);
    }

    // ---------------------------------------------------------------
    // OrderFeedbackRepository
    // ---------------------------------------------------------------

    public function testFindReturnsNullWhenNoFeedbackYet(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertNull(OrderFeedbackRepository::find($orderId));
    }

    public function testUpsertCreatesThenUpdatesTheSameRow(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        OrderFeedbackRepository::upsert($orderId, $clientId, 4, 'Good service overall.');
        $first = OrderFeedbackRepository::find($orderId);
        self::assertSame(4, (int) $first['rating']);
        self::assertSame('Good service overall.', $first['comments']);

        OrderFeedbackRepository::upsert($orderId, $clientId, 5, 'Actually, excellent.');
        $second = OrderFeedbackRepository::find($orderId);
        self::assertSame(5, (int) $second['rating']);
        self::assertSame('Actually, excellent.', $second['comments']);
        self::assertSame($first['id'], $second['id'], 'resubmission must update the same row, not insert a second one');

        $countStmt = Database::connection()->prepare('SELECT COUNT(*) FROM order_feedback WHERE order_id = :id');
        $countStmt->execute(['id' => $orderId]);
        self::assertSame(1, (int) $countStmt->fetchColumn());
    }

    public function testUpsertAllowsNullComments(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        OrderFeedbackRepository::upsert($orderId, $clientId, 3, null);
        $row = OrderFeedbackRepository::find($orderId);
        self::assertSame(3, (int) $row['rating']);
        self::assertNull($row['comments']);
    }

    public function testRatingOutsideOneToFiveViolatesTheDbCheckConstraint(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);

        $this->expectException(\PDOException::class);
        OrderFeedbackRepository::upsert($orderId, $clientId, 6, 'Should never reach the DB — the controller validates first.');
    }

    // ---------------------------------------------------------------
    // ClientPortalController::submitFeedback()
    // ---------------------------------------------------------------

    public function testSubmitFeedbackSavesAValidRating(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['rating' => '5', 'comments' => 'Excellent handling of our shipment.'];

        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        $row = OrderFeedbackRepository::find($orderId);
        self::assertNotNull($row);
        self::assertSame(5, (int) $row['rating']);
        self::assertSame('Excellent handling of our shipment.', $row['comments']);
        self::assertSame('success', Flash::pull()[0]['type']);
    }

    public function testSubmitFeedbackRejectsARatingOfZero(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['rating' => '0'];

        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderFeedbackRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testSubmitFeedbackRejectsARatingOfSix(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['rating' => '6'];

        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderFeedbackRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testSubmitFeedbackRejectsANonNumericRating(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['rating' => 'five'];

        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderFeedbackRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testSubmitFeedbackCanBeResubmittedAndUpdatesTheSameRow(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        $_SESSION['_client_portal_client_id'] = $clientId;

        $_POST = ['rating' => '2', 'comments' => 'Initial impression — slow replies.'];
        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        $_POST = ['rating' => '5', 'comments' => 'Updating — issue was resolved quickly.'];
        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        $row = OrderFeedbackRepository::find($orderId);
        self::assertSame(5, (int) $row['rating']);
        self::assertSame('Updating — issue was resolved quickly.', $row['comments']);
    }

    /**
     * External QA report N-12: step 14 is a post-shipment step — a
     * client could previously rate an order still in production/
     * shipping. createTestOrder() defaults to status 'active', so this
     * exercises that default directly (no markShippedDocsSent() call).
     */
    public function testSubmitFeedbackRefusedOnAnActiveOrder(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['rating' => '5', 'comments' => 'Too early to say.'];

        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderFeedbackRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testSubmitFeedbackReturns404ForAnUnknownOrder(): void
    {
        $clientId = $this->createTestClient();
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['rating' => '5'];

        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => '999999999']);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    // ---------------------------------------------------------------
    // View rendering — client portal order_show.php
    // ---------------------------------------------------------------

    public function testClientOrderPageShowsTheFeedbackFormWithNoPriorSubmission(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('action="/client/orders/' . $orderId . '/feedback"', $output);
        self::assertStringContainsString('name="rating"', $output);
        self::assertStringContainsString('Submit Feedback', $output);
    }

    public function testClientOrderPagePrefillsAnExistingSubmissionAndOffersUpdate(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        OrderFeedbackRepository::upsert($orderId, $clientId, 4, 'Already submitted once.');
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('value="4" selected', $output);
        self::assertStringContainsString('Already submitted once.', $output);
        self::assertStringContainsString('Update Feedback', $output);
    }

    /** External QA report N-12: the form itself must not even be offered on an active order. */
    public function testClientOrderPageHidesTheFeedbackFormOnAnActiveOrder(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('action="/client/orders/' . $orderId . '/feedback"', $output);
        self::assertStringContainsString('Feedback will be available once', $output);
    }

    // ---------------------------------------------------------------
    // View rendering — staff order page (orders/show.php)
    // ---------------------------------------------------------------

    public function testStaffOrderPageShowsNoFeedbackMessageWhenNoneSubmitted(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Order Feedback', $output);
        self::assertStringContainsString("hasn't submitted feedback", $output);
    }

    // ---------------------------------------------------------------
    // OrderController::recordFeedback() — staff recording feedback the
    // client gave over the phone/in person, on their behalf
    // ---------------------------------------------------------------

    private function auditLogCount(string $actionType, int $entityId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action_type = :action AND entity_type = 'orders' AND entity_id = :entity_id"
        );
        $stmt->execute(['action' => $actionType, 'entity_id' => $entityId]);
        return (int) $stmt->fetchColumn();
    }

    public function testRecordFeedbackSavesAValidRatingAndLogsItUnderItsOwnActionType(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['rating' => '4', 'comments' => 'Client called — happy overall, minor delay in replies.'];

        ob_start();
        (new OrderController())->recordFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        $row = OrderFeedbackRepository::find($orderId);
        self::assertNotNull($row);
        self::assertSame(4, (int) $row['rating']);
        self::assertSame('Client called — happy overall, minor delay in replies.', $row['comments']);
        self::assertSame('success', Flash::pull()[0]['type']);
        self::assertSame(1, $this->auditLogCount('ORDER_FEEDBACK_RECORDED_BY_STAFF', $orderId));
        self::assertSame(0, $this->auditLogCount('ORDER_FEEDBACK_SUBMITTED', $orderId), 'staff-entered feedback must not be mistaken for a genuine client-portal submission in the audit trail');
    }

    public function testRecordFeedbackRejectsARatingOutsideOneToFive(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->markShippedDocsSent($orderId);
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['rating' => '0'];

        ob_start();
        (new OrderController())->recordFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderFeedbackRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testRecordFeedbackRefusedOnAnActiveOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient()); // defaults to 'active'
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['rating' => '5', 'comments' => 'Too early.'];

        ob_start();
        (new OrderController())->recordFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        self::assertNull(OrderFeedbackRepository::find($orderId));
        self::assertSame('error', Flash::pull()[0]['type']);
    }

    public function testStaffOrderPageOffersTheRecordFeedbackFormOnceShippedWithNoPriorSubmission(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->markShippedDocsSent($orderId);
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('action="/orders/' . $orderId . '/feedback"', $output);
        self::assertStringContainsString('Record Feedback', $output);
    }

    public function testStaffOrderPageHidesTheRecordFeedbackFormOnAnActiveOrder(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('action="/orders/' . $orderId . '/feedback"', $output);
    }

    public function testStaffOrderPageHidesTheRecordFeedbackFormOnceFeedbackAlreadyExists(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $this->markShippedDocsSent($orderId);
        OrderFeedbackRepository::upsert($orderId, $clientId, 4, 'Already on file.');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringNotContainsString('action="/orders/' . $orderId . '/feedback"', $output);
        self::assertStringContainsString('Already on file.', $output);
    }

    public function testStaffOrderPageShowsTheSubmittedRatingAndCommentsToEveryRole(): void
    {
        // Rating/comments aren't financial data (per the standing
        // sensitive-data rule) — a plain "Export Executive" with no
        // manage_order_financials grant must still see it.
        $userId = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD', null, null, $userId);
        OrderFeedbackRepository::upsert($orderId, $clientId, 3, 'Average — packaging could improve.');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('★★★☆☆', $output);
        self::assertStringContainsString('Average — packaging could improve.', $output);
    }
}
