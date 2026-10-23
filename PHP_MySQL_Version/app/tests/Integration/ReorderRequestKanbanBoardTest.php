<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ReorderRequestController;
use App\Repositories\OrderReorderRequestRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/** Task #41 — the Kanban UI template's Reorder Requests board: Pending / Approved / Rejected columns. */
final class ReorderRequestKanbanBoardTest extends DbTestCase
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
        (new ReorderRequestController())->index([]);
        return (string) ob_get_clean();
    }

    public function testAUserWithNoPreferenceSeesTheStandardListNotTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');

        $output = $this->render($userId);

        self::assertStringContainsString('<h1>Reorder Requests</h1>', $output);
        self::assertStringNotContainsString('Kanban Board', $output);
    }

    public function testAUserWhoPrefersKanbanGetsTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        self::assertStringContainsString('Reorder Requests — Kanban Board', $output);
    }

    public function testAPendingRequestAppearsInThePendingColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $clientId = $this->createTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);
        OrderReorderRequestRepository::create($clientId, $sourceOrderId, 'Please reorder the same items.');

        $output = $this->render($userId);
        $pendingPos = strpos($output, '>Pending<');
        $cardPos = strpos($output, 'Please reorder the same items.');

        self::assertNotFalse($pendingPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($pendingPos, $cardPos);
    }

    public function testAnApprovedRequestMovesToTheApprovedColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $clientId = $this->createTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);
        $requestId = OrderReorderRequestRepository::create($clientId, $sourceOrderId, 'Approve me please.');
        $newOrderId = $this->createTestOrder($this->createTestClient());
        OrderReorderRequestRepository::markApproved($requestId, $userId, $newOrderId);

        $output = $this->render($userId);
        $approvedPos = strpos($output, '>Approved<');
        $cardPos = strpos($output, 'Approve me please.');

        self::assertNotFalse($approvedPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($approvedPos, $cardPos);
    }

    public function testARejectedRequestMovesToTheRejectedColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $clientId = $this->createTestClient();
        $sourceOrderId = $this->createTestOrder($clientId);
        $requestId = OrderReorderRequestRepository::create($clientId, $sourceOrderId, 'Reject me please.');
        OrderReorderRequestRepository::markRejected($requestId, $userId, 'Pricing no longer valid.');

        $output = $this->render($userId);
        $rejectedPos = strpos($output, '>Rejected<');
        $cardPos = strpos($output, 'Reject me please.');

        self::assertNotFalse($rejectedPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($rejectedPos, $cardPos);
    }
}
