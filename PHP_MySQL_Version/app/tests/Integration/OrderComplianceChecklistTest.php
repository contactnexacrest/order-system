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
