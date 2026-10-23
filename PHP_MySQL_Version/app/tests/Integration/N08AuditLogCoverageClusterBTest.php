<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientIntakeController;
use App\Controllers\ClientIntakeReviewController;
use App\Controllers\PiIntakeReviewController;
use App\Controllers\ReorderRequestController;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\OrderReorderRequestRepository;
use App\Repositories\PiIntakeRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report N-08, Cluster B: the public no-login intake
 * correction form and the three staff intake/reorder review queues'
 * accept/reject actions never called AuditLogRepository::log(), so none
 * of them ever notified anyone either.
 */
final class N08AuditLogCoverageClusterBTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
    }

    private function lastAuditLogAction(): ?array
    {
        $stmt = Database::connection()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
        return $stmt->fetch() ?: null;
    }

    private function createTestIntakeSubmission(): int
    {
        return ClientIntakeRepository::create([
            'company_legal_name'     => 'PHPUnit N08 Test Buyer Ltd',
            'billing_address'        => '1 Test Street',
            'vat_eori_tax_no'        => 'VAT123',
            'contact_person'         => 'Test Contact',
            'email'                  => 'n08-buyer@example.test',
            'phone'                  => '',
            'country_of_destination' => 'Testland',
            'port_of_discharge_text' => '',
            'coo_type'               => '',
            'incoterm_preference'    => '',
            'container_type_text'    => '',
            'buyer_own_reference'    => '',
            'notes'                  => '',
        ], '127.0.0.1');
    }

    public function testQuotationDetailsEditIsLoggedAgainstTheSubmission(): void
    {
        $id = $this->createTestIntakeSubmission();
        $token = bin2hex(random_bytes(32));
        Database::connection()->prepare(
            "UPDATE client_intake_submissions SET access_token_hash = :hash, access_token_expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE id = :id"
        )->execute(['hash' => hash('sha256', $token), 'id' => $id]);

        $_POST = [
            'company_legal_name' => 'PHPUnit N08 Test Buyer Ltd (corrected)',
            'billing_address_line1' => '2 Corrected Street',
            'billing_city' => 'Testville',
            'billing_country' => 'Testland',
            'vat_eori_tax_no' => 'VAT123',
            'contact_person' => 'Test Contact',
            'email' => 'n08-buyer@example.test',
            'phone' => '',
            'country_of_destination' => 'Testland',
            'incoterm_preference' => 'FOB',
        ];

        ob_start();
        (new ClientIntakeController())->updateSubmission(['token' => $token]);
        ob_end_clean();

        $audit = $this->lastAuditLogAction();
        self::assertSame('CLIENT_INTAKE_SUBMISSION_UPDATED_BY_CLIENT', $audit['action_type']);
        self::assertSame($id, (int) $audit['entity_id']);
    }

    public function testClientIntakeAcceptIsLogged(): void
    {
        $id = $this->createTestIntakeSubmission();
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new ClientIntakeReviewController())->accept(['id' => (string) $id]);
        ob_end_clean();

        $audit = $this->lastAuditLogAction();
        self::assertSame('CLIENT_INTAKE_ACCEPTED', $audit['action_type']);
    }

    public function testClientIntakeRejectIsLogged(): void
    {
        $id = $this->createTestIntakeSubmission();
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['reason' => 'Duplicate submission already handled by email.'];

        ob_start();
        (new ClientIntakeReviewController())->reject(['id' => (string) $id]);
        ob_end_clean();

        self::assertSame('CLIENT_INTAKE_REJECTED', $this->lastAuditLogAction()['action_type']);
    }

    public function testPiIntakeRejectIsLogged(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);
        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'             => $submission['company_legal_name'],
            'billing_address'                => $submission['billing_address'],
            'vat_eori_tax_no'                => $submission['vat_eori_tax_no'],
            'contact_person'                 => $submission['contact_person'],
            'email'                          => $submission['email'],
            'phone'                          => $submission['phone'],
            'port_of_discharge_text'         => null,
            'country_of_destination'         => $submission['country_of_destination'],
            'incoterm_confirmed'             => null,
            'container_type_text'            => null,
            'payment_terms_confirmation'     => null,
            'quotation_acceptance_reference' => null,
            'coo_type'                       => null,
            'buyer_po_ref'                   => null,
            'changes_from_quotation'         => null,
            'special_document_requirements'  => null,
        ], '127.0.0.1');

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['reason' => 'Buyer confirmed by phone they will resubmit.'];

        ob_start();
        (new PiIntakeReviewController())->reject(['id' => (string) $submission['id']]);
        ob_end_clean();

        self::assertSame('PI_INTAKE_REJECTED', $this->lastAuditLogAction()['action_type']);
    }

    public function testReorderRequestRejectIsLogged(): void
    {
        $clientId = $this->createTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);
        $id = OrderReorderRequestRepository::create($clientId, $sourceOrderId, 'Please reorder the same products.');
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $_POST = ['reason' => 'Client cancelled the reorder request by phone.'];

        ob_start();
        (new ReorderRequestController())->reject(['id' => (string) $id]);
        ob_end_clean();

        self::assertSame('REORDER_REQUEST_REJECTED', $this->lastAuditLogAction()['action_type']);
    }
}
