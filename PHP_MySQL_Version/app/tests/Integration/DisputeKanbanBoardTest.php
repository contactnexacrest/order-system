<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\DisputeController;
use App\Repositories\DisputeRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/**
 * Task #40 — the Kanban UI template's Disputes board: one column per
 * dispute_status dropdown option (admin-configurable, in their own
 * order), rather than the fixed stage columns Orders uses. Same
 * UiTemplateService::resolve() switch as Task #37's Orders board.
 */
final class DisputeKanbanBoardTest extends DbTestCase
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
        (new DisputeController())->index([]);
        return (string) ob_get_clean();
    }

    public function testAUserWithNoPreferenceSeesTheStandardListNotTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');

        $output = $this->render($userId);

        self::assertStringContainsString('Dispute Log (All Orders)', $output);
        self::assertStringNotContainsString('Kanban Board', $output);
    }

    public function testAUserWhoPrefersKanbanGetsTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        self::assertStringContainsString('Disputes — Kanban Board', $output);
    }

    public function testADisputeAppearsInItsOwnStatusColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $orderId = $this->createTestOrder($this->createTestClient());
        DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Carton arrived crushed', null, null);

        $output = $this->render($userId);
        $openPos = strpos($output, '>Open<');
        $cardPos = strpos($output, 'Carton arrived crushed');

        self::assertNotFalse($openPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($openPos, $cardPos, 'the card must render inside the Open column, after its header');
    }

    public function testMovingADisputesStatusMovesItToTheNewColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $orderId = $this->createTestOrder($this->createTestClient());
        $disputeId = DisputeRepository::create($orderId, date('Y-m-d'), 'Buyer', 'Escalated issue text', null, null);
        DisputeRepository::updateStatus($disputeId, 'Escalated');

        $output = $this->render($userId);
        $escalatedPos = strpos($output, '>Escalated<');
        $cardPos = strpos($output, 'Escalated issue text');

        self::assertNotFalse($escalatedPos);
        self::assertNotFalse($cardPos);
        // Escalated is the dropdown's last-configured status, so this
        // alone proves the card rendered inside that column, not an
        // earlier one (e.g. Resolved, where it started).
        self::assertGreaterThan($escalatedPos, $cardPos);
    }

    public function testEveryConfiguredStatusColumnExistsEvenWhenEmpty(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        foreach (['Open', 'Under Review', 'Resolved', 'Escalated'] as $label) {
            self::assertStringContainsString(">{$label}<", $output);
        }
    }

    public function testACompanyDefaultOfKanbanAppliesToAUserWithNoPersonalPreference(): void
    {
        $userId = $this->createTestUser('Admin');
        \App\Config\Database::connection()
            ->prepare("UPDATE company_settings SET setting_value = 'kanban' WHERE setting_key = 'default_ui_template'")
            ->execute();

        try {
            $output = $this->render($userId);
            self::assertStringContainsString('Disputes — Kanban Board', $output);
        } finally {
            \App\Config\Database::connection()
                ->prepare("UPDATE company_settings SET setting_value = 'standard' WHERE setting_key = 'default_ui_template'")
                ->execute();
        }
    }
}
