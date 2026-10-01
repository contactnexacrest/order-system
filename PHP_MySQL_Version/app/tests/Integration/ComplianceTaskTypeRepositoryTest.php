<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ComplianceTaskTypeRepository;
use App\Tests\Support\DbTestCase;
use PDOException;

/**
 * Admin-editable compliance/pre-closure task-type list (docs/schema.sql
 * Section AR) — covers the CRUD surface, including that deleting a type
 * still referenced by order_compliance_tasks is blocked by the FK (the
 * controller catches this and tells the admin to deactivate instead).
 */
final class ComplianceTaskTypeRepositoryTest extends DbTestCase
{
    public function testCreateThenFindReturnsAllFields(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = ComplianceTaskTypeRepository::create('PHPUnit Test Task', $userId);

        $row = ComplianceTaskTypeRepository::find($id);

        self::assertNotNull($row);
        self::assertSame('PHPUnit Test Task', $row['name']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame($userId, (int) $row['created_by']);
    }

    public function testUpdateOverwritesName(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = ComplianceTaskTypeRepository::create('PHPUnit Original Name', $userId);

        ComplianceTaskTypeRepository::update($id, 'PHPUnit Renamed');

        self::assertSame('PHPUnit Renamed', ComplianceTaskTypeRepository::find($id)['name']);
    }

    public function testToggleActiveFlipsBothWays(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = ComplianceTaskTypeRepository::create('PHPUnit Toggle Task', $userId);
        self::assertSame(1, (int) ComplianceTaskTypeRepository::find($id)['is_active']);

        ComplianceTaskTypeRepository::toggleActive($id);
        self::assertSame(0, (int) ComplianceTaskTypeRepository::find($id)['is_active']);

        ComplianceTaskTypeRepository::toggleActive($id);
        self::assertSame(1, (int) ComplianceTaskTypeRepository::find($id)['is_active']);
    }

    public function testAllExcludesInactiveByDefaultButIncludesOnRequest(): void
    {
        $userId = $this->createTestUser('Admin');
        $activeId = ComplianceTaskTypeRepository::create('PHPUnit Active Task', $userId);
        $inactiveId = ComplianceTaskTypeRepository::create('PHPUnit Inactive Task', $userId);
        ComplianceTaskTypeRepository::toggleActive($inactiveId);

        $activeOnly = array_column(ComplianceTaskTypeRepository::all(false), 'id');
        self::assertContains($activeId, $activeOnly);
        self::assertNotContains($inactiveId, $activeOnly);

        $withInactive = array_column(ComplianceTaskTypeRepository::all(true), 'id');
        self::assertContains($activeId, $withInactive);
        self::assertContains($inactiveId, $withInactive);
    }

    public function testDeleteRemovesAnUnreferencedRow(): void
    {
        $userId = $this->createTestUser('Admin');
        $id = ComplianceTaskTypeRepository::create('PHPUnit Deletable Task', $userId);

        ComplianceTaskTypeRepository::delete($id);

        self::assertNull(ComplianceTaskTypeRepository::find($id));
    }

    public function testDeletingATypeStillReferencedByAnOrderThrows(): void
    {
        $userId = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $id = ComplianceTaskTypeRepository::create('PHPUnit Referenced Task', $userId);
        \App\Repositories\OrderComplianceTaskRepository::setStatus($orderId, $id, 'approved', null, $userId);

        $this->expectException(PDOException::class);
        ComplianceTaskTypeRepository::delete($id);
    }
}
