<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CoordinationController;
use App\Helpers\Flash;
use App\Repositories\DepartmentRepository;
use App\Repositories\DepartmentTaskRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\PurchasingRepository;
use App\Services\ActiveDepartmentService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-214 (2026-10-31), phase 4: the system coordinates. Each
 * department's tasks come from the order's own state (one step done = the
 * next department's task appears), with due and escalation days from Admin;
 * one owner per department per order, chosen by that department's manager.
 * The Node suite has the same test (coordination.test.js).
 */
final class CoordinationTest extends DbTestCase
{
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        OrderPaymentStatusRepository::initializeForOrder($this->orderId);
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        Database::connection()->exec("UPDATE department_task_types SET standard_days = 1, escalate_after_days = 2 WHERE task_key = 'clear_buyer_payment'");
        parent::tearDown();
    }

    /** @return list<string> this order's open task keys */
    private function taskKeys(?array $departments = null): array
    {
        $keys = [];
        foreach (DepartmentTaskRepository::open($departments) as $t) {
            if ((int) $t['order_id'] === $this->orderId) {
                $keys[] = (string) $t['task_key'];
            }
        }
        return $keys;
    }

    private function member(string $designation): int
    {
        $stmt = Database::connection()->prepare('SELECT dd.id, r.name FROM department_designations dd JOIN roles r ON r.id = dd.role_id WHERE dd.title = :t');
        $stmt->execute(['t' => $designation]);
        $d = $stmt->fetch();
        $userId = $this->createTestUser($d['name']);
        $m = DepartmentRepository::addMembership($userId, (int) $d['id'], date('Y-m-d'), $userId);
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
        ActiveDepartmentService::resetCache();
        ActiveDepartmentService::activate($userId, $m, false);
        return $userId;
    }

    private function department(string $code): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM departments WHERE code = :c');
        $stmt->execute(['c' => $code]);
        return $stmt->fetch();
    }

    public function testEachStepDoneHandsTheNextTaskToTheNextDepartment(): void
    {
        self::assertSame([], $this->taskKeys());

        OrderPaymentStatusRepository::recordAdvanceReceived($this->orderId, 5000.0, date('Y-m-d'));
        self::assertSame(['clear_buyer_payment'], $this->taskKeys());
        self::assertSame(['clear_buyer_payment'], $this->taskKeys(['FIN']));
        self::assertSame([], $this->taskKeys(['SALES', 'LOG']));

        Database::connection()->exec("UPDATE order_payment_status SET advance_cleared_at = NOW() WHERE order_id = {$this->orderId}");
        self::assertSame(['raise_purchase_requirement'], $this->taskKeys(), 'advance cleared: Finance is done, Sales raises the purchase requirement');

        $userId = $this->createTestUser('Admin');
        $id = PurchasingRepository::create($this->orderId, null, 'Granite slabs', null, 50.0, 'sqm', date('Y-m-d', strtotime('+10 days')), $userId, null);
        self::assertSame(['quote_and_award'], $this->taskKeys(), 'the requirement is raised: Procurement gets quotes');

        PurchasingRepository::setStatus($id, 'cancelled');
        self::assertSame(['raise_purchase_requirement'], $this->taskKeys(), 'a cancelled requirement leaves Sales to raise it again');
    }

    public function testATaskIsOverdueAfterItsDaysAndEscalatedAfterTheEscalationDays(): void
    {
        OrderPaymentStatusRepository::recordAdvanceReceived($this->orderId, 5000.0, date('Y-m-d', strtotime('-2 days')));
        Database::connection()->exec("UPDATE order_payment_status SET advance_remittance_received_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE order_id = {$this->orderId}");
        $task = static fn (int $orderId): array => array_values(array_filter(DepartmentTaskRepository::open(['FIN']), static fn (array $t): bool => (int) $t['order_id'] === $orderId))[0];

        DepartmentTaskRepository::updateType('clear_buyer_payment', 5, 5);
        self::assertSame([0, 0], [(int) $task($this->orderId)['overdue'], (int) $task($this->orderId)['escalated']]);
        DepartmentTaskRepository::updateType('clear_buyer_payment', 1, 5);
        self::assertSame([1, 0], [(int) $task($this->orderId)['overdue'], (int) $task($this->orderId)['escalated']]);
        DepartmentTaskRepository::updateType('clear_buyer_payment', 0, 1);
        self::assertSame([1, 1], [(int) $task($this->orderId)['overdue'], (int) $task($this->orderId)['escalated']]);
        self::assertSame(date('Y-m-d', strtotime('-2 days')), (string) $task($this->orderId)['due_on']);
    }

    public function testAManagerSetsTheirOwnDepartmentsOwnerOnly(): void
    {
        $logisticsExec = $this->member('Logistics Executive');
        $this->member('Logistics Manager');
        $log = $this->department('LOG');
        $sales = $this->department('SALES');

        $_POST = ['department_id' => (string) $sales['id'], 'user_id' => (string) $logisticsExec];
        (new CoordinationController())->setOwner(['id' => (string) $this->orderId]);
        self::assertSame('You assign owners for your own department only — not Sales & Export.', Flash::pull()[0]['message']);

        $outsider = $this->createTestUser('Export Executive');
        $_POST = ['department_id' => (string) $log['id'], 'user_id' => (string) $outsider];
        (new CoordinationController())->setOwner(['id' => (string) $this->orderId]);
        self::assertSame('Choose a person who works in Logistics.', Flash::pull()[0]['message']);

        $_POST = ['department_id' => (string) $log['id'], 'user_id' => (string) $logisticsExec];
        (new CoordinationController())->setOwner(['id' => (string) $this->orderId]);
        self::assertSame('PHPUnit Test User now owns this order for Logistics.', Flash::pull()[0]['message']);
        $owners = array_column(DepartmentTaskRepository::ownersForOrder($this->orderId), 'user_id', 'code');
        self::assertSame($logisticsExec, (int) $owners['LOG']);
        self::assertNull($owners['SALES']);
        $pdo = Database::connection();
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM order_assignees WHERE order_id = {$this->orderId} AND user_id = {$logisticsExec}")->fetchColumn(), 'the owner can open the order');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$logisticsExec} AND type = 'order_department_owner'")->fetchColumn());
    }

    public function testTaskDaysAreSetInAdminWithChecks(): void
    {
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        $standard = $escalate = [];
        foreach (DepartmentTaskRepository::types() as $t) {
            $standard[$t['task_key']] = (string) $t['standard_days'];
            $escalate[$t['task_key']] = (string) $t['escalate_after_days'];
        }
        $_POST = ['standard_days' => ['clear_buyer_payment' => '-1'] + $standard, 'escalate_after_days' => $escalate];
        (new CoordinationController())->updateTaskTypes([]);
        self::assertStringStartsWith('Enter whole days from 0 to 90 for', Flash::pull()[0]['message']);

        $_POST = ['standard_days' => ['clear_buyer_payment' => '3'] + $standard, 'escalate_after_days' => $escalate];
        (new CoordinationController())->updateTaskTypes([]);
        self::assertSame('Task days saved (1 changed).', Flash::pull()[0]['message']);
        $row = array_values(array_filter(DepartmentTaskRepository::types(), static fn (array $t): bool => $t['task_key'] === 'clear_buyer_payment'))[0];
        self::assertSame(3, (int) $row['standard_days']);
    }

    public function testADepartmentWorksItsOwnQueueAndManagementSeesEveryDepartment(): void
    {
        $fin = $this->department('FIN');
        $finScope = DepartmentTaskRepository::scopeFor(['department_id' => $fin['id']]);
        self::assertSame(['FIN'], $finScope, 'Finance works its own queue, though it sees every amount');
        self::assertNull(DepartmentTaskRepository::scopeFor(['department_id' => $this->department('MGMT')['id']]), 'management sees every department');
        self::assertNull(DepartmentTaskRepository::scopeFor(null), 'no department: every department');
    }
}
