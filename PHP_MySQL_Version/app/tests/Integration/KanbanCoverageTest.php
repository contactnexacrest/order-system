<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\ClientIntakeReviewController;
use App\Controllers\ComplaintController;
use App\Controllers\CoordinationController;
use App\Controllers\DisputeController;
use App\Controllers\OrderController;
use App\Controllers\PiIntakeReviewController;
use App\Controllers\PurchasingController;
use App\Controllers\ReorderRequestController;
use App\Repositories\OrderProductRepository;
use App\Repositories\PaymentMilestoneRepository;
use App\Repositories\PurchasingRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner request (1 Nov 2026): everything built for departments, procurement,
 * coordination and Phase 5a works under the Kanban template too, with
 * tests. The Procurement queue and My tasks get their own boards; every
 * board's "Switch to list view" really shows the list; the Orders board
 * hides the order value from a department that may not see selling
 * prices; and the order page shows the new parts (payment schedule,
 * Export Service Invoice, product category) the same in both templates.
 * The Node suite has the same test (kanbanCoverage.test.js).
 */
final class KanbanCoverageTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_SESSION = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function user(string $role, bool $kanban): int
    {
        $id = $this->createTestUser($role);
        UserRepository::updateUiTemplatePreference($id, $kanban ? (int) UiTemplateRepository::findByKey('kanban')['id'] : null);
        return $id;
    }

    private function render(int $userId, object $controller, string $action, array $get = [], array $params = []): string
    {
        $_SESSION = ['_auth_user_id' => $userId];
        $_GET = $get;
        \App\Services\PermissionService::resetCache();
        \App\Services\ActiveDepartmentService::resetCache();
        ob_start();
        $controller->{$action}($params);
        return (string) ob_get_clean();
    }

    public function testProcurementIsABoardUnderKanban(): void
    {
        $admin = $this->user('Admin', true);
        $orderId = $this->createTestOrder($this->createTestClient());
        $prId = PurchasingRepository::create($orderId, null, 'Granite blocks for headstones', null, 12.0, 'CBM', date('Y-m-d', strtotime('+10 days')), $admin, null);

        $board = $this->render($admin, new PurchasingController(), 'queue');
        self::assertStringContainsString('Procurement — Kanban Board</h1>', $board);
        foreach (['Waiting for quotes', 'Quotes in', 'Awarded — waiting for approval', 'Approved — with the supplier', 'Received'] as $column) {
            self::assertStringContainsString('<span class="kanban-column-title">' . htmlspecialchars($column) . '</span>', $board);
        }
        self::assertMatchesRegularExpression('#data-status="open">.*?href="/procurement/' . $prId . '"#s', $board, 'a new requirement sits in "Waiting for quotes"');

        $list = $this->render($admin, new PurchasingController(), 'queue', ['view' => 'list']);
        self::assertStringNotContainsString('kanban-board', $list);
        self::assertStringContainsString('PR-' . $prId, $list);

        $standard = $this->render($this->user('Admin', false), new PurchasingController(), 'queue');
        self::assertStringNotContainsString('kanban-board', $standard);
    }

    public function testMyTasksIsABoardUnderKanban(): void
    {
        $admin = $this->user('Admin', true);
        $this->createTestOrder($this->createTestClient());
        $board = $this->render($admin, new CoordinationController(), 'myTasks');
        self::assertStringContainsString('My tasks — Kanban Board</h1>', $board);
        foreach (['Escalated', 'Overdue', 'Due today', 'Coming up'] as $column) {
            self::assertStringContainsString('<span class="kanban-column-title">' . $column . '</span>', $board);
        }
        $list = $this->render($admin, new CoordinationController(), 'myTasks', ['view' => 'list']);
        self::assertStringNotContainsString('kanban-board', $list);
        self::assertSame(substr_count($list, '>Open</a>'), substr_count($board, 'class="entity-card kanban-card"'), 'the board shows every task the list shows');
    }

    public function testSwitchToListViewShowsTheListOnEveryBoard(): void
    {
        $admin = $this->user('Admin', true);
        $pages = [
            [new OrderController(), 'index', ['view' => 'list', 'status' => 'all']],
            [new DisputeController(), 'index', ['view' => 'list', 'status' => 'all']],
            [new ComplaintController(), 'index', ['view' => 'list']],
            [new ReorderRequestController(), 'index', ['view' => 'list']],
            [new ClientIntakeReviewController(), 'index', ['view' => 'list']],
            [new PiIntakeReviewController(), 'index', ['view' => 'list']],
        ];
        foreach ($pages as [$controller, $action, $get]) {
            $name = (new \ReflectionClass($controller))->getShortName();
            self::assertStringContainsString('kanban-board', $this->render($admin, $controller, $action), "{$name}: Kanban shows the board");
            self::assertStringNotContainsString('kanban-board', $this->render($admin, $controller, $action, $get), "{$name}: Switch to list view shows the list");
        }
    }

    public function testTheOrdersBoardHidesTheValueFromADepartmentWithoutSellingPrices(): void
    {
        $logistics = $this->user('Logistics Executive', true);
        $admin = $this->user('Admin', true);
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$logistics, $admin]);
        OrderProductRepository::add($orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');

        self::assertStringContainsString('USD 4,500.00', $this->render($admin, new OrderController(), 'index'));
        $board = $this->render($logistics, new OrderController(), 'index');
        self::assertStringContainsString('Orders — Kanban Board</h1>', $board);
        self::assertStringNotContainsString('4,500.00', $board, 'Logistics never sees the selling value on the board');
    }

    public function testTheOrderPageShowsTheNewPartsUnderKanbanToo(): void
    {
        $admin = $this->user('Admin', true);
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderProductRepository::add($orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
        $id = OrderProductRepository::add($orderId, 2, 'Installation supervision', null, null, '5', false, 'DAY', '180.00');
        OrderProductRepository::setLineType($id, 'service', '998719');
        PaymentMilestoneRepository::replaceSchedule($orderId, [['pct' => 20.0, 'due_text' => 'on shipment']], 40.0);

        $page = $this->render($admin, new OrderController(), 'show', ['from' => 'kanban'], ['id' => (string) $orderId]);
        self::assertStringContainsString('id="payment-schedule"', $page);
        self::assertStringContainsString('2 · Milestone', $page);
        self::assertStringContainsString('Generate Export Service Invoice', $page);
        self::assertStringContainsString('name="category_id"', $page);
    }
}
