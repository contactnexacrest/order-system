<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\PiIntakeReviewController;
use App\Repositories\PiIntakeRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/** Task #43 — the Kanban UI template's PI Intake Review board: Pending Review / Applied / Rejected columns. */
final class PiIntakeKanbanBoardTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_SESSION = [];
    }

    private function kanbanTemplateId(): int
    {
        return (int) UiTemplateRepository::findByKey('kanban')['id'];
    }

    private function render(int $userId): string
    {
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new PiIntakeReviewController())->index([]);
        return (string) ob_get_clean();
    }

    /** Creates a pending_review PI intake submission for a fresh test order, returns [submissionId, orderId]. */
    private function createPendingSubmission(): array
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $token = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($token);

        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name'             => $submission['company_legal_name'],
            'billing_address'                => $submission['billing_address'],
            'billing_address_line1'          => $submission['billing_address_line1'],
            'billing_address_line2'          => $submission['billing_address_line2'],
            'billing_city'                   => $submission['billing_city'],
            'billing_postcode'               => $submission['billing_postcode'],
            'billing_country'                => $submission['billing_country'],
            'vat_eori_tax_no'                => $submission['vat_eori_tax_no'],
            'contact_person'                 => $submission['contact_person'],
            'email'                          => $submission['email'],
            'phone'                          => $submission['phone'],
            'port_of_discharge_text'         => $submission['port_of_discharge_text'],
            'country_of_destination'         => $submission['country_of_destination'],
            'incoterm_confirmed'             => $submission['incoterm_confirmed'],
            'container_type_text'            => $submission['container_type_text'],
            'payment_terms_confirmation'      => 'CONFIRMED — Kanban board test.',
            'quotation_acceptance_reference'  => 'We accept Quotation X',
            'coo_type'                        => $submission['coo_type'],
            'buyer_po_ref'                    => null,
            'changes_from_quotation'          => null,
            'special_document_requirements'   => null,
            'consignee_same_as_buyer'        => 1,
            'consignee_name' => null, 'consignee_address_line1' => null, 'consignee_address_line2' => null,
            'consignee_city' => null, 'consignee_postcode' => null, 'consignee_country' => null,
            'consignee_vat_eori_tax_no' => null, 'consignee_contact_person' => null,
            'consignee_phone' => null, 'consignee_email' => null,
            'notify_party_same_as_consignee' => 1,
            'notify_party' => null, 'notify_party_address_line1' => null, 'notify_party_address_line2' => null,
            'notify_party_city' => null, 'notify_party_postcode' => null, 'notify_party_country' => null,
            'notify_party_contact_person' => null, 'notify_party_phone' => null, 'notify_party_email' => null,
        ], '127.0.0.1');

        return [(int) $submission['id'], $orderId];
    }

    public function testAUserWithNoPreferenceSeesTheStandardListNotTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');

        $output = $this->render($userId);

        self::assertStringContainsString('PI Intake Review</h1>', $output);
        self::assertStringNotContainsString('Kanban Board', $output);
    }

    public function testAUserWhoPrefersKanbanGetsTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        self::assertStringContainsString('PI Intake Review — Kanban Board', $output);
    }

    public function testAPendingSubmissionAppearsInThePendingReviewColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        [, $orderId] = $this->createPendingSubmission();
        $order = \App\Repositories\OrderRepository::find($orderId);

        $output = $this->render($userId);
        $pendingPos = strpos($output, '>Pending Review<');
        $cardPos = strpos($output, $order['order_reference']);

        self::assertNotFalse($pendingPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($pendingPos, $cardPos);
    }

    public function testAnAppliedSubmissionMovesToTheAppliedColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        [$submissionId, $orderId] = $this->createPendingSubmission();
        $order = \App\Repositories\OrderRepository::find($orderId);
        PiIntakeRepository::markApplied($submissionId, $userId);

        $output = $this->render($userId);
        $appliedPos = strpos($output, '>Applied<');
        $cardPos = strpos($output, $order['order_reference']);

        self::assertNotFalse($appliedPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($appliedPos, $cardPos);
    }

    public function testARejectedSubmissionMovesToTheRejectedColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        [$submissionId, $orderId] = $this->createPendingSubmission();
        $order = \App\Repositories\OrderRepository::find($orderId);
        PiIntakeRepository::markRejected($submissionId, $userId, 'Details did not match official documents.');

        $output = $this->render($userId);
        $rejectedPos = strpos($output, '>Rejected<');
        $cardPos = strpos($output, $order['order_reference']);

        self::assertNotFalse($rejectedPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($rejectedPos, $cardPos);
    }
}
