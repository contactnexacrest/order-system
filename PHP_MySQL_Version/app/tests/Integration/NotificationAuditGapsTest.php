<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientIntakeController;
use App\Controllers\ClientPortalController;
use App\Controllers\PiIntakeController;
use App\Repositories\NotificationRepository;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * Task #35 — full notification audit across the user's 0-17 export
 * process sequence. The sequence's own words are "SYSTEM MUST SEND
 * NOTIFICATION FOR ALL ACTIONS TO NOTIFICATION EMAIL AND CORROSPONDING
 * PERSON STAFF/ADMIN/CLIENT AS AND WHEN NEEDED. THIS IS NON NEGOTIABLE."
 *
 * The audit found four client-initiated actions that landed in a staff
 * review queue (visible only via a passive nav-badge count) with no
 * active notification at all: step 0 (Quotation-stage intake), step 4d
 * (PI-stage intake), step 14 (Order Feedback, introduced in the same
 * change as the feature itself), and the reorder-request flow (the same
 * gap pattern, adjacent to the numbered steps). Each now fires the same
 * dual-channel pattern already established by
 * OrderCommentService::postAsClient(): an in-app NotificationRepository
 * row per manage_orders holder, plus the catch-all/acting-user email via
 * AuditLogRepository::log() -> ActionNotificationService.
 */
final class NotificationAuditGapsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $_FILES = [];
    }

    private function quotationIntakePayload(string $ip): array
    {
        $_SERVER['REMOTE_ADDR'] = $ip;
        return [
            'company_legal_name'     => 'Notification Audit Test Co',
            'billing_address_line1'  => '1 Test Street',
            'billing_city'           => 'Testville',
            'billing_country'        => 'United Kingdom',
            'vat_eori_tax_no'        => 'GB123456789',
            'contact_person'         => 'Jane Test',
            'email'                  => 'jane-' . bin2hex(random_bytes(4)) . '@example.test',
            'country_of_destination' => 'Not sure',
            'incoterm_preference'    => 'FOB',
        ];
    }

    private function piIntakePayload(): array
    {
        return [
            'company_legal_name'             => 'PI Notification Test Ltd',
            'billing_address_line1'          => '10 Billing Street',
            'billing_city'                   => 'Rotterdam',
            'billing_country'                => 'Netherlands',
            'vat_eori_tax_no'                => 'NL123456789B01',
            'contact_person'                 => 'Jamie Buyer',
            'email'                          => 'jamie@buyer.example',
            'phone'                          => '+31 10 123 4567',
            'port_of_discharge_text'         => 'Rotterdam',
            'country_of_destination'         => 'Netherlands',
            'incoterm_confirmed'             => 'FOB',
            'payment_terms_confirmation'      => 'CONFIRMED',
            'quotation_acceptance_reference'  => 'We accept Quotation Z',
            'coo_type'                        => 'Non-preferential',
            'confirm_lock'                    => '1',
        ];
    }

    // ---------------------------------------------------------------
    // Step 0 — Quotation-stage public intake form
    // ---------------------------------------------------------------

    public function testQuotationIntakeSubmissionNotifiesManageOrdersHolders(): void
    {
        $userId = $this->createTestUser('Admin');
        $_POST = $this->quotationIntakePayload('203.0.113.50');

        ob_start();
        (new ClientIntakeController())->submit([]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($userId);
        $match = array_filter($notifications, static fn(array $n): bool => $n['type'] === 'client_intake_submitted');
        self::assertNotEmpty($match, 'an Admin (manage_orders) must be notified of a new Quotation-stage intake');

        $log = Database::connection()->query(
            "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'CLIENT_INTAKE_SUBMITTED'"
        )->fetch();
        self::assertGreaterThan(0, (int) $log['c']);
    }

    // ---------------------------------------------------------------
    // Step 4d — PI-stage public intake form
    // ---------------------------------------------------------------

    public function testPiIntakeSubmissionNotifiesManageOrdersHolders(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);
        $_POST = $this->piIntakePayload();

        ob_start();
        (new PiIntakeController())->submit(['token' => $token]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($userId);
        $match = array_filter($notifications, static fn(array $n): bool => $n['type'] === 'pi_intake_submitted' && (int) $n['related_order_id'] === $orderId);
        self::assertNotEmpty($match, 'an Admin (manage_orders) must be notified of a submitted PI-stage intake');

        $log = Database::connection()->prepare(
            "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'PI_INTAKE_SUBMITTED' AND entity_type = 'orders' AND entity_id = :id"
        );
        $log->execute(['id' => $orderId]);
        self::assertSame(1, (int) $log->fetch()['c']);
    }

    // ---------------------------------------------------------------
    // Step 14 — Order Feedback
    // ---------------------------------------------------------------

    public function testOrderFeedbackSubmissionNotifiesManageOrdersHolders(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        // External QA report N-12: feedback is only accepted once an order
        // has reached this status.
        Database::connection()->prepare("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id")->execute(['id' => $orderId]);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['rating' => '4', 'comments' => 'Great communication throughout.'];

        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($userId);
        $match = array_filter($notifications, static fn(array $n): bool => $n['type'] === 'order_feedback_submitted' && (int) $n['related_order_id'] === $orderId);
        self::assertNotEmpty($match, 'an Admin (manage_orders) must be notified of submitted Order Feedback');
        self::assertStringContainsString('4/5', array_values($match)[0]['message']);

        $log = Database::connection()->prepare(
            "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'ORDER_FEEDBACK_SUBMITTED' AND entity_type = 'orders' AND entity_id = :id"
        );
        $log->execute(['id' => $orderId]);
        self::assertSame(1, (int) $log->fetch()['c']);
    }

    // ---------------------------------------------------------------
    // Reorder request (same gap pattern, adjacent to the numbered steps)
    // ---------------------------------------------------------------

    public function testReorderRequestSubmissionNotifiesManageOrdersHolders(): void
    {
        $userId = $this->createTestUser('Admin');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['product_description' => ['A reordered widget']];

        ob_start();
        (new ClientPortalController())->submitReorder(['id' => (string) $orderId]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($userId);
        $match = array_filter($notifications, static fn(array $n): bool => $n['type'] === 'reorder_request_submitted' && (int) $n['related_order_id'] === $orderId);
        self::assertNotEmpty($match, 'an Admin (manage_orders) must be notified of a submitted reorder request');

        $log = Database::connection()->prepare(
            "SELECT COUNT(*) AS c FROM audit_log WHERE action_type = 'REORDER_REQUEST_SUBMITTED' AND entity_type = 'orders' AND entity_id = :id"
        );
        $log->execute(['id' => $orderId]);
        self::assertSame(1, (int) $log->fetch()['c']);
    }

    // ---------------------------------------------------------------
    // A role with no manage_orders permission must receive none of these
    // ---------------------------------------------------------------

    public function testAUserWithoutManageOrdersReceivesNoFeedbackNotification(): void
    {
        $viewerId = $this->createTestUser('Viewer / Auditor');
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        // External QA report N-12: feedback is only accepted once an order
        // has reached this status — set here so this test's "no
        // notification" assertion is about the permission gate, not a
        // vacuous pass from the submission being refused outright.
        Database::connection()->prepare("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id")->execute(['id' => $orderId]);
        $_SESSION['_client_portal_client_id'] = $clientId;
        $_POST = ['rating' => '5'];

        ob_start();
        (new ClientPortalController())->submitFeedback(['id' => (string) $orderId]);
        ob_end_clean();

        $notifications = NotificationRepository::forUser($viewerId);
        $match = array_filter($notifications, static fn(array $n): bool => $n['type'] === 'order_feedback_submitted');
        self::assertEmpty($match, 'a role without manage_orders must not be notified');
    }
}
