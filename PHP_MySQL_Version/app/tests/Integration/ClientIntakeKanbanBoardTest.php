<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientIntakeReviewController;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/** Task #42 — the Kanban UI template's Client Intake Review board: Pending / Converted / Rejected columns. */
final class ClientIntakeKanbanBoardTest extends DbTestCase
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
        (new ClientIntakeReviewController())->index([]);
        return (string) ob_get_clean();
    }

    private function createSubmission(string $companyName): int
    {
        return ClientIntakeRepository::create([
            'company_legal_name'     => $companyName,
            'billing_address'        => '1 Intake Street',
            'billing_address_line1'  => null,
            'billing_address_line2'  => null,
            'billing_city'           => null,
            'billing_postcode'       => null,
            'billing_country'        => null,
            'vat_eori_tax_no'        => null,
            'contact_person'         => 'Jane Buyer',
            'email'                  => 'jane-' . bin2hex(random_bytes(4)) . '@intake-kanban.example',
            'phone'                  => null,
            'country_of_destination' => 'Netherlands',
            'port_of_discharge_text' => null,
            'coo_type'               => null,
            'incoterm_preference'    => null,
            'container_type_text'    => null,
            'buyer_own_reference'    => null,
            'notes'                  => null,
        ], '127.0.0.1');
    }

    public function testAUserWithNoPreferenceSeesTheStandardListNotTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');

        $output = $this->render($userId);

        self::assertStringContainsString('Quotation Intake Review</h1>', $output);
        self::assertStringNotContainsString('Kanban Board', $output);
    }

    public function testAUserWhoPrefersKanbanGetsTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        self::assertStringContainsString('Quotation Intake Review — Kanban Board', $output);
    }

    public function testAPendingSubmissionAppearsInThePendingColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $this->createSubmission('Pending Buyer Kanban Ltd');

        $output = $this->render($userId);
        $pendingPos = strpos($output, '>Pending<');
        $cardPos = strpos($output, 'Pending Buyer Kanban Ltd');

        self::assertNotFalse($pendingPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($pendingPos, $cardPos);
    }

    public function testAConvertedSubmissionMovesToTheConvertedColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $submissionId = $this->createSubmission('Converted Buyer Kanban Ltd');
        $clientId = $this->createTestClient();
        ClientIntakeRepository::markConverted($submissionId, $clientId, $userId);

        $output = $this->render($userId);
        $convertedPos = strpos($output, '>Converted<');
        $cardPos = strpos($output, 'Converted Buyer Kanban Ltd');

        self::assertNotFalse($convertedPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($convertedPos, $cardPos);
    }

    public function testARejectedSubmissionMovesToTheRejectedColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $submissionId = $this->createSubmission('Rejected Buyer Kanban Ltd');
        ClientIntakeRepository::markRejected($submissionId, $userId, 'Not a genuine inquiry.');

        $output = $this->render($userId);
        $rejectedPos = strpos($output, '>Rejected<');
        $cardPos = strpos($output, 'Rejected Buyer Kanban Ltd');

        self::assertNotFalse($rejectedPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($rejectedPos, $cardPos);
    }
}
