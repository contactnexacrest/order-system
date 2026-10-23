<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Controllers\DisputeController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\DisputeRepository;
use App\Repositories\OrderFeedbackRepository;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section BV — export process step 14 (client Order
 * Feedback: a 1-5 star rating + comments, one row per order) and steps
 * 15-16 (the existing Disputes workflow now tags a dispute_type of
 * 'dispute' or 'complaint_damage' instead of duplicating a whole second
 * table/workflow for complaints and damage reports).
 */
final class OrderFeedbackAndDisputeTypeTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
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
        OrderFeedbackRepository::upsert($orderId, $clientId, 4, 'Already submitted once.');
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('value="4" selected', $output);
        self::assertStringContainsString('Already submitted once.', $output);
        self::assertStringContainsString('Update Feedback', $output);
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

    public function testStaffOrderPageShowsTheSubmittedRatingAndCommentsToEveryRole(): void
    {
        // Rating/comments aren't financial data (per the standing
        // sensitive-data rule) — a plain "Export Executive" with no
        // manage_order_financials grant must still see it.
        $userId = $this->createTestUser('Export Executive');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        OrderFeedbackRepository::upsert($orderId, $clientId, 3, 'Average — packaging could improve.');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('★★★☆☆', $output);
        self::assertStringContainsString('Average — packaging could improve.', $output);
    }

    // ---------------------------------------------------------------
    // DisputeRepository::create() — dispute_type
    // ---------------------------------------------------------------

    public function testCreateDefaultsToDisputeTypeWhenOmitted(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $disputeId = DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Legacy call site, no type arg', null, null);

        $row = Database::connection()->prepare('SELECT dispute_type FROM disputes WHERE id = :id');
        $row->execute(['id' => $disputeId]);
        self::assertSame('dispute', $row->fetch()['dispute_type']);
    }

    public function testCreateStoresAnExplicitComplaintDamageType(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $disputeId = DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Carton arrived crushed', null, null, 'complaint_damage');

        $row = Database::connection()->prepare('SELECT dispute_type FROM disputes WHERE id = :id');
        $row->execute(['id' => $disputeId]);
        self::assertSame('complaint_damage', $row->fetch()['dispute_type']);
    }

    // ---------------------------------------------------------------
    // DisputeController::create() (staff) — reads dispute_type from POST
    // ---------------------------------------------------------------

    public function testStaffCreateStoresTheSubmittedComplaintDamageType(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['notice_date' => date('Y-m-d'), 'description' => 'Goods damaged in transit', 'dispute_type' => 'complaint_damage'];

        ob_start();
        (new DisputeController())->create(['id' => (string) $orderId]);
        ob_end_clean();

        $disputes = DisputeRepository::forOrder($orderId);
        self::assertCount(1, $disputes);
        self::assertSame('complaint_damage', $disputes[0]['dispute_type']);
    }

    public function testStaffCreateDefaultsToDisputeForAnUnrecognisedType(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;
        $_POST = ['notice_date' => date('Y-m-d'), 'description' => 'Something went wrong', 'dispute_type' => 'not_a_real_type'];

        ob_start();
        (new DisputeController())->create(['id' => (string) $orderId]);
        ob_end_clean();

        $disputes = DisputeRepository::forOrder($orderId);
        self::assertSame('dispute', $disputes[0]['dispute_type']);
    }

    // ---------------------------------------------------------------
    // ClientPortalController::raiseDispute() — dispute_type from the portal
    // ---------------------------------------------------------------

    public function testClientRaiseDisputeStoresTheSubmittedComplaintDamageType(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        Database::connection()->prepare('UPDATE orders SET dispute_button_visible_to_client = 1 WHERE id = :id')->execute(['id' => $orderId]);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['description' => 'Box arrived damaged', 'dispute_type' => 'complaint_damage'];

        ob_start();
        (new ClientPortalController())->raiseDispute(['id' => (string) $orderId]);
        ob_end_clean();

        $disputes = DisputeRepository::forOrder($orderId);
        self::assertCount(1, $disputes);
        self::assertSame('complaint_damage', $disputes[0]['dispute_type']);
    }

    public function testClientRaiseDisputeDefaultsToDisputeTypeWhenNotSubmitted(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        Database::connection()->prepare('UPDATE orders SET dispute_button_visible_to_client = 1 WHERE id = :id')->execute(['id' => $orderId]);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['description' => 'Payment terms disagreement'];

        ob_start();
        (new ClientPortalController())->raiseDispute(['id' => (string) $orderId]);
        ob_end_clean();

        $disputes = DisputeRepository::forOrder($orderId);
        self::assertSame('dispute', $disputes[0]['dispute_type']);
    }

    // ---------------------------------------------------------------
    // View rendering — type selector + badges
    // ---------------------------------------------------------------

    public function testClientPortalRaiseDisputeFormOffersBothTypes(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        Database::connection()->prepare('UPDATE orders SET dispute_button_visible_to_client = 1 WHERE id = :id')->execute(['id' => $orderId]);
        $_SESSION['_client_portal_client_id'] = $clientId;

        ob_start();
        (new ClientPortalController())->showOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="dispute_type"', $output);
        self::assertStringContainsString('Complaint / Damage Report', $output);
    }

    public function testStaffOrderDisputeFormOffersBothTypes(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new DisputeController())->forOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('name="dispute_type"', $output);
        self::assertStringContainsString('Complaint / Damage Report', $output);
    }

    public function testStaffOrderDisputeLogShowsTheTypeOfALoggedComplaint(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Crate was wet on arrival', null, null, 'complaint_damage');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new DisputeController())->forOrder(['id' => (string) $orderId]);
        $output = ob_get_clean();

        self::assertStringContainsString('Complaint / Damage', $output);
    }

    public function testGlobalDisputeLogShowsTheTypeTagPerCard(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Carton crushed', null, null, 'complaint_damage');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new DisputeController())->index([]);
        $output = ob_get_clean();

        self::assertStringContainsString('Complaint / Damage', $output);
    }
}
