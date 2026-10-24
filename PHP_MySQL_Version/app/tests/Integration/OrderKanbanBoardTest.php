<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Task #37 — the Kanban UI template's real Orders list: every active
 * order as a card in its own Stage 1-9 column, plus "Docs Sent" and
 * "Complete" columns for the two closure phases. Lost/Cancelled are
 * rolled up into a compact summary rather than given full board columns
 * (they're dead ends, not pipeline stages). Which template a user sees
 * is driven entirely by UiTemplateService::resolve() (built in Task #29)
 * — this is the first thing that actually consumes it to render a
 * different view for the same route.
 */
final class OrderKanbanBoardTest extends DbTestCase
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

    private function setUserTemplate(int $userId, ?int $templateId): void
    {
        UserRepository::updateUiTemplatePreference($userId, $templateId);
    }

    private function render(int $userId): string
    {
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->index([]);
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------
    // Which template actually renders
    // ---------------------------------------------------------------

    public function testAUserWithNoPreferenceSeesTheStandardListNotTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');

        $output = $this->render($userId);

        self::assertStringContainsString('Orders</h1>', $output);
        self::assertStringNotContainsString('kanban-board', $output);
    }

    public function testAUserWhoPrefersKanbanSeesTheBoard(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->setUserTemplate($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        self::assertStringContainsString('Orders — Kanban Board</h1>', $output);
        self::assertStringContainsString('class="kanban-board"', $output);
        self::assertStringNotContainsString('class="card-grid"', $output, 'the flat list view must not also render');
    }

    public function testTheCompanyDefaultAppliesToAUserWithNoPersonalPreference(): void
    {
        $userId = $this->createTestUser('Admin');
        $pdo = Database::connection();
        $pdo->exec("UPDATE company_settings SET setting_value = 'kanban' WHERE setting_key = 'default_ui_template'");

        try {
            $output = $this->render($userId);
            self::assertStringContainsString('class="kanban-board"', $output);
        } finally {
            $pdo->exec("UPDATE company_settings SET setting_value = 'standard' WHERE setting_key = 'default_ui_template'");
        }
    }

    public function testAPersonalPreferenceOverridesTheCompanyDefault(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->setUserTemplate($userId, $this->kanbanTemplateId());
        $pdo = Database::connection();
        $pdo->exec("UPDATE company_settings SET setting_value = 'standard' WHERE setting_key = 'default_ui_template'");

        $output = $this->render($userId);

        self::assertStringContainsString('class="kanban-board"', $output);
    }

    // ---------------------------------------------------------------
    // Board contents — correct column per order
    // ---------------------------------------------------------------

    public function testAFreshOrderAppearsInTheStage1Column(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->setUserTemplate($userId, $this->kanbanTemplateId());
        $orderId = $this->createTestOrder($this->createTestClient());

        $output = $this->render($userId);

        $stage1ColPos = strpos($output, 'Stage 1 &middot;');
        $stage2ColPos = strpos($output, 'Stage 2 &middot;');
        self::assertNotFalse($stage1ColPos);
        self::assertNotFalse($stage2ColPos);
        $stage1Column = substr($output, $stage1ColPos, $stage2ColPos - $stage1ColPos);
        self::assertStringContainsString("href=\"/orders/{$orderId}?from=kanban\"", $stage1Column, 'a fresh order must have a card inside the Stage 1 column, linking back to the board');
    }

    public function testAnOrderUnlockedThroughStage3AppearsInTheStage4Column(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->setUserTemplate($userId, $this->kanbanTemplateId());
        $orderId = $this->createTestOrder($this->createTestClient());
        for ($stage = 1; $stage <= 3; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }

        $output = $this->render($userId);

        $stage4ColPos = strpos($output, 'Stage 4 &middot;');
        $stage5ColPos = strpos($output, 'Stage 5 &middot;');
        self::assertNotFalse($stage4ColPos);
        self::assertNotFalse($stage5ColPos);
        $stage4Column = substr($output, $stage4ColPos, $stage5ColPos - $stage4ColPos);
        $order = \App\Repositories\OrderRepository::find($orderId);
        self::assertStringContainsString(htmlspecialchars($order['order_reference']), $stage4Column);
    }

    public function testShippedDocsSentOrdersAppearInTheirOwnColumnNotAStageColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->setUserTemplate($userId, $this->kanbanTemplateId());
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare("UPDATE orders SET status = 'shipped_docs_sent' WHERE id = :id")
            ->execute(['id' => $orderId]);

        $output = $this->render($userId);

        $docsSentPos = strpos($output, 'Docs Sent — Awaiting Benefits');
        self::assertNotFalse($docsSentPos);
        $order = \App\Repositories\OrderRepository::find($orderId);
        $tailFromDocsSent = substr($output, $docsSentPos);
        self::assertStringContainsString(htmlspecialchars($order['order_reference']), $tailFromDocsSent);

        // And must not render as a card inside any numbered Stage column.
        $boardStart = strpos($output, 'class="kanban-board"');
        $beforeDocsSent = substr($output, $boardStart, $docsSentPos - $boardStart);
        self::assertStringNotContainsString(htmlspecialchars($order['order_reference']), $beforeDocsSent);
    }

    public function testCompleteOrdersAppearInTheCompleteColumn(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->setUserTemplate($userId, $this->kanbanTemplateId());
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare("UPDATE orders SET status = 'complete' WHERE id = :id")
            ->execute(['id' => $orderId]);

        $output = $this->render($userId);

        $completePos = strpos($output, '<span class="kanban-column-title">Complete</span>');
        self::assertNotFalse($completePos);
        $order = \App\Repositories\OrderRepository::find($orderId);
        self::assertStringContainsString(htmlspecialchars($order['order_reference']), substr($output, $completePos));
    }

    public function testLostAndCancelledOrdersAreSummarizedAsCountsNotFullColumns(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->setUserTemplate($userId, $this->kanbanTemplateId());
        $lostOrderId = $this->createTestOrder($this->createTestClient());
        $cancelledOrderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->prepare("UPDATE orders SET status = 'lost' WHERE id = :id")->execute(['id' => $lostOrderId]);
        $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :id")->execute(['id' => $cancelledOrderId]);

        $output = $this->render($userId);

        self::assertStringContainsString('Lost (', $output);
        self::assertStringContainsString('Cancelled (', $output);
        $lostOrder = \App\Repositories\OrderRepository::find($lostOrderId);
        $cancelledOrder = \App\Repositories\OrderRepository::find($cancelledOrderId);
        self::assertStringNotContainsString(htmlspecialchars($lostOrder['order_reference']), $output, 'a lost order must not render as a full card on the board');
        self::assertStringNotContainsString(htmlspecialchars($cancelledOrder['order_reference']), $output, 'a cancelled order must not render as a full card on the board');
    }

    public function testEveryStageColumnRendersEvenWhenEmpty(): void
    {
        $userId = $this->createTestUser('Admin');
        $this->setUserTemplate($userId, $this->kanbanTemplateId());

        $output = $this->render($userId);

        for ($stage = 1; $stage <= 9; $stage++) {
            self::assertStringContainsString("Stage {$stage} &middot;", $output, "Stage {$stage} column must always render, even with zero orders currently in it");
        }
    }

    // ---------------------------------------------------------------
    // Order detail page is reached identically from either list type —
    // only the "back" breadcrumb differs, driven by the ?from=kanban
    // query param the board's own card link now carries.
    // ---------------------------------------------------------------

    public function testOrderDetailBreadcrumbPointsBackToTheBoardWhenOpenedFromKanban(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        $_GET['from'] = 'kanban';
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('&larr; Back to Board</a>', $output);
        self::assertStringNotContainsString('&larr; Back to Orders</a>', $output);
    }

    public function testOrderDetailBreadcrumbPointsBackToTheStandardListWhenOpenedWithoutFromKanban(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());

        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('&larr; Back to Orders</a>', $output);
        self::assertStringNotContainsString('&larr; Back to Board</a>', $output);
    }
}
