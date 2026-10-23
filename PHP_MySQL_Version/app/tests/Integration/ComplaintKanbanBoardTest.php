<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ComplaintController;
use App\Repositories\OrderComplaintRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\OrderComplaintService;
use App\Tests\Support\DbTestCase;

/** Task #44 — the Kanban UI template's Complaints board: Open / Acknowledged / Resolved columns. */
final class ComplaintKanbanBoardTest extends DbTestCase
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
        (new ComplaintController())->index([]);
        return (string) ob_get_clean();
    }

    public function testAUserWithNoPreferenceSeesTheStandardListNotTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');

        $output = $this->render($userId);

        self::assertStringContainsString('Complaints (All Orders)', $output);
        self::assertStringNotContainsString('Kanban Board', $output);
    }

    public function testAUserWhoPrefersKanbanGetsTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        self::assertStringContainsString('Complaints — Kanban Board', $output);
    }

    public function testAnOpenComplaintAppearsInTheOpenColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        OrderComplaintService::raiseAsClient($orderId, $clientId, 'Packaging was damp on arrival.');

        $output = $this->render($userId);
        $openPos = strpos($output, '>Open<');
        $cardPos = strpos($output, 'Packaging was damp on arrival.');

        self::assertNotFalse($openPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($openPos, $cardPos);
    }

    public function testAnAcknowledgedComplaintMovesToTheAcknowledgedColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintService::raiseAsClient($orderId, $clientId, 'Slow email replies last time.');
        OrderComplaintRepository::updateStatus($complaintId, 'acknowledged');

        $output = $this->render($userId);
        $ackPos = strpos($output, '>Acknowledged<');
        $cardPos = strpos($output, 'Slow email replies last time.');

        self::assertNotFalse($ackPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($ackPos, $cardPos);
    }

    public function testAResolvedComplaintMovesToTheResolvedColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $complaintId = OrderComplaintService::raiseAsClient($orderId, $clientId, 'Already fixed by staff.');
        OrderComplaintRepository::updateStatus($complaintId, 'resolved');

        $output = $this->render($userId);
        $resolvedPos = strpos($output, '>Resolved<');
        $cardPos = strpos($output, 'Already fixed by staff.');

        self::assertNotFalse($resolvedPos);
        self::assertNotFalse($cardPos);
        self::assertGreaterThan($resolvedPos, $cardPos);
    }

    public function testEveryStatusColumnExistsEvenWhenEmpty(): void
    {
        $userId = $this->createTestUser('Admin');
        UserRepository::updateUiTemplatePreference($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        foreach (['Open', 'Acknowledged', 'Resolved'] as $label) {
            self::assertStringContainsString(">{$label}<", $output);
        }
    }
}
