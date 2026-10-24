<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\AuditLogRepository;
use App\Repositories\ComplianceTaskTypeRepository;
use App\Repositories\OrderComplianceTaskRepository;
use App\Tests\Support\DbTestCase;

/**
 * Compliance/pre-closure task checklist (docs/schema.sql Section AR) —
 * "the person who has permission to close the order must able to see this
 * otherwise no meaning for this." Gated entirely on the existing
 * close_orders permission (Export Executive, Logistics Executive, Admin/MD/
 * ED, Super Admin by default) — no new permission for the checklist itself.
 */
final class OrderComplianceChecklistTest extends DbTestCase
{
    private int $orderId;
    private int $taskTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->orderId = $this->createTestOrder($this->createTestClient());
        $this->taskTypeId = ComplianceTaskTypeRepository::create('PHPUnit ECGC Cover', $this->createTestUser('Admin'));
    }

    public function testInitializeForOrderWritesARealRowForEveryActiveTaskTypeAtCreation(): void
    {
        // Unlike this file's own $this->taskTypeId (created AFTER
        // $this->orderId in setUp, deliberately, to exercise the LEFT JOIN
        // fallback below for a task type that postdates the order), this
        // test creates the task type FIRST, then a fresh order, to confirm
        // OrderRepository::insertRow() really did write a row up front —
        // read the raw table directly, bypassing forOrder()'s COALESCE
        // entirely, since a report against the raw table must never see
        // "no row" standing in for "not applicable."
        $preexistingTypeId = ComplianceTaskTypeRepository::create('PHPUnit Pre-Existing Task Type', $this->createTestUser('Admin'));
        $freshOrderId = $this->createTestOrder($this->createTestClient());

        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare('SELECT status FROM order_compliance_tasks WHERE order_id = :order_id AND task_type_id = :task_type_id');
        $stmt->execute(['order_id' => $freshOrderId, 'task_type_id' => $preexistingTypeId]);
        $row = $stmt->fetch();

        self::assertNotFalse($row, 'a real row must exist from order creation, not just the LEFT JOIN default');
        self::assertSame('not_started', $row['status']);
    }

    public function testForOrderDefaultsMissingRowsToNotStarted(): void
    {
        $rows = OrderComplianceTaskRepository::forOrder($this->orderId);

        $row = $this->findRow($rows, $this->taskTypeId);
        self::assertSame('not_started', $row['status']);
        self::assertNull($row['resolved_at']);
    }

    public function testSetStatusThenForOrderReflectsTheNewStatus(): void
    {
        $userId = $this->createTestUser('Export Executive');
        OrderComplianceTaskRepository::setStatus($this->orderId, $this->taskTypeId, 'approved', null, $userId);

        $row = $this->findRow(OrderComplianceTaskRepository::forOrder($this->orderId), $this->taskTypeId);
        self::assertSame('approved', $row['status']);
        self::assertNotNull($row['resolved_at']);
        self::assertSame('PHPUnit Test User', $row['resolved_by_name']);
    }

    public function testSetStatusTwiceUpsertsRatherThanDuplicating(): void
    {
        $userId = $this->createTestUser('Export Executive');
        OrderComplianceTaskRepository::setStatus($this->orderId, $this->taskTypeId, 'pending_approval', null, $userId);
        OrderComplianceTaskRepository::setStatus($this->orderId, $this->taskTypeId, 'approved', null, $userId);

        $matches = array_filter(
            OrderComplianceTaskRepository::forOrder($this->orderId),
            fn (array $r): bool => (int) $r['task_type_id'] === $this->taskTypeId
        );
        self::assertCount(1, $matches, 'one row per order+task_type pair — upsert, not insert');
    }

    public function testSummaryCountsSkippedAsResolvedAlongsideApproved(): void
    {
        $otherTypeId = ComplianceTaskTypeRepository::create('PHPUnit Fumigation Certificate', $this->createTestUser('Admin'));
        $userId = $this->createTestUser('Export Executive');
        $totalActiveTypes = count(ComplianceTaskTypeRepository::all(false));

        OrderComplianceTaskRepository::setStatus($this->orderId, $this->taskTypeId, 'approved', null, $userId);
        OrderComplianceTaskRepository::setStatus($this->orderId, $otherTypeId, 'skipped', 'Not applicable to this buyer', $userId);

        $summary = OrderComplianceTaskRepository::summaryForOrder($this->orderId);
        self::assertSame($totalActiveTypes, $summary['total'], 'total must cover every active task type, not just the ones touched on this order');
        self::assertSame(2, $summary['approved'], 'only the two touched task types (one approved, one skipped) count as resolved');
        self::assertSame($totalActiveTypes - 2, $summary['outstanding']);
    }

    public function testControllerRejectsAUserWithoutCloseOrdersPermission(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postUpdate(['task_type_id' => (string) $this->taskTypeId, 'status' => 'approved']);

        $row = $this->findRow(OrderComplianceTaskRepository::forOrder($this->orderId), $this->taskTypeId);
        self::assertSame('not_started', $row['status'], 'Accounts Executive has no close_orders permission by default — the write must be refused');
    }

    public function testControllerAllowsAUserWithCloseOrdersPermission(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postUpdate(['task_type_id' => (string) $this->taskTypeId, 'status' => 'approved']);

        $row = $this->findRow(OrderComplianceTaskRepository::forOrder($this->orderId), $this->taskTypeId);
        self::assertSame('approved', $row['status']);
    }

    public function testSkippedStatusWithoutAReasonIsRejected(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postUpdate(['task_type_id' => (string) $this->taskTypeId, 'status' => 'skipped']);

        $row = $this->findRow(OrderComplianceTaskRepository::forOrder($this->orderId), $this->taskTypeId);
        self::assertSame('not_started', $row['status'], 'skipped requires a reason — must not silently succeed without one');
    }

    public function testSkippedStatusWithAReasonSucceedsAndIsAuditLogged(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postUpdate([
            'task_type_id' => (string) $this->taskTypeId,
            'status' => 'skipped',
            'skip_reason' => 'Buyer exempt under scheme X',
        ]);

        $row = $this->findRow(OrderComplianceTaskRepository::forOrder($this->orderId), $this->taskTypeId);
        self::assertSame('skipped', $row['status']);
        self::assertSame('Buyer exempt under scheme X', $row['skip_reason']);

        $logRows = array_values(array_filter(
            AuditLogRepository::forOrder($this->orderId),
            static fn (array $r): bool => $r['action_type'] === 'COMPLIANCE_TASK_STATUS_UPDATED'
        ));
        self::assertCount(1, $logRows);
        self::assertSame($userId, (int) $logRows[0]['user_id']);
    }

    public function testOrderPageShowsChecklistOnlyToUsersWithCloseOrdersPermission(): void
    {
        $withPermission = $this->createTestUser('Export Executive');
        \App\Repositories\OrderAssigneeRepository::add($this->orderId, $withPermission, $withPermission);
        $_SESSION['_auth_user_id'] = $withPermission;
        ob_start();
        (new OrderController())->show(['id' => (string) $this->orderId]);
        $output = ob_get_clean();
        self::assertStringContainsString('Compliance Checklist', $output);
        self::assertStringContainsString('PHPUnit ECGC Cover', $output);

        $withoutPermission = $this->createTestUser('Accounts Executive');
        $_SESSION['_auth_user_id'] = $withoutPermission;
        ob_start();
        (new OrderController())->show(['id' => (string) $this->orderId]);
        $output2 = ob_get_clean();
        self::assertStringNotContainsString('Compliance Checklist', $output2);
    }

    public function testMarkAllNotApplicableRejectsAUserWithoutCloseOrdersPermission(): void
    {
        $userId = $this->createTestUser('Accounts Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postMarkAllNa(['skip_reason' => 'Repeat order']);

        $row = $this->findRow(OrderComplianceTaskRepository::forOrder($this->orderId), $this->taskTypeId);
        self::assertSame('not_started', $row['status'], 'Accounts Executive has no close_orders permission by default — the write must be refused');
    }

    public function testMarkAllNotApplicableWithoutAReasonIsRejected(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $this->postMarkAllNa([]);

        $row = $this->findRow(OrderComplianceTaskRepository::forOrder($this->orderId), $this->taskTypeId);
        self::assertSame('not_started', $row['status'], 'the bulk action requires a reason too — must not silently succeed without one');
    }

    public function testMarkAllNotApplicableResolvesEveryOutstandingTaskWithOneSharedReason(): void
    {
        $otherTypeId = ComplianceTaskTypeRepository::create('PHPUnit Fumigation Certificate', $this->createTestUser('Admin'));
        $alreadyApprovedTypeId = ComplianceTaskTypeRepository::create('PHPUnit Due Diligence', $this->createTestUser('Admin'));
        $userId = $this->createTestUser('Export Executive');
        OrderComplianceTaskRepository::setStatus($this->orderId, $alreadyApprovedTypeId, 'approved', null, $userId);

        // This is a shared, un-rolled-back dev DB (no per-test transaction),
        // so by this point in a full suite run other tests may already have
        // created their own active task types — the outstanding count below
        // is read fresh rather than hardcoded, so this test holds regardless
        // of what else is active at the time it runs.
        $expectedOutstanding = OrderComplianceTaskRepository::summaryForOrder($this->orderId)['outstanding'];

        $_SESSION['_auth_user_id'] = $userId;
        $this->postMarkAllNa(['skip_reason' => 'Repeat order — buyer previously cleared']);

        $rows = OrderComplianceTaskRepository::forOrder($this->orderId);
        self::assertSame('skipped', $this->findRow($rows, $this->taskTypeId)['status']);
        self::assertSame('Repeat order — buyer previously cleared', $this->findRow($rows, $this->taskTypeId)['skip_reason']);
        self::assertSame('skipped', $this->findRow($rows, $otherTypeId)['status']);
        self::assertSame(
            'approved',
            $this->findRow($rows, $alreadyApprovedTypeId)['status'],
            'an already-resolved task must not be touched by the bulk skip'
        );
        self::assertSame(0, OrderComplianceTaskRepository::summaryForOrder($this->orderId)['outstanding'], 'every outstanding task must now be resolved');

        $logRows = array_values(array_filter(
            AuditLogRepository::forOrder($this->orderId),
            static fn (array $r): bool => $r['action_type'] === 'COMPLIANCE_TASK_STATUS_UPDATED'
        ));
        self::assertCount($expectedOutstanding, $logRows, 'one audit log row per outstanding task actually touched, not per task type overall');
    }

    /** @param array<string, string> $fields */
    private function postMarkAllNa(array $fields): void
    {
        $_POST = $fields;
        ob_start();
        (new OrderController())->markAllComplianceTasksNotApplicable(['id' => (string) $this->orderId]);
        ob_end_clean();
    }

    /** @param array<string, string> $fields */
    private function postUpdate(array $fields): void
    {
        $_POST = $fields;
        ob_start();
        (new OrderController())->updateComplianceTask(['id' => (string) $this->orderId]);
        ob_end_clean();
    }

    /** @param array<int, array<string,mixed>> $rows @return array<string,mixed> */
    private function findRow(array $rows, int $taskTypeId): array
    {
        foreach ($rows as $r) {
            if ((int) $r['task_type_id'] === $taskTypeId) {
                return $r;
            }
        }
        self::fail("No row found for task_type_id {$taskTypeId}");
    }
}
