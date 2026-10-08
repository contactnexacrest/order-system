<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\DropdownOptionController;
use App\Helpers\Flash;
use App\Repositories\DropdownOptionRepository;
use App\Tests\Support\DbTestCase;

/**
 * Item 7 — admin CRUD for dropdown_options (docs/schema.sql Section BD),
 * the generic small-option-list table (container_type, coo_type, etc.)
 * that previously had no admin screen. Options are never hard-deleted —
 * only deactivated — since an existing order/submission may still carry
 * the exact text in a plain VARCHAR column with no FK to this table.
 */
final class DropdownOptionsTest extends DbTestCase
{
    private DropdownOptionController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new DropdownOptionController();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_POST = [];
        $_SESSION = [];
    }

    private function uniqueListKey(): string
    {
        return 'php_test_list_' . bin2hex(random_bytes(4));
    }

    public function testCreateInsertsNewOptionActiveNotDefault(): void
    {
        $listKey = $this->uniqueListKey();
        $id = DropdownOptionRepository::create($listKey, 'First Option', 1);

        $grouped = DropdownOptionRepository::allGrouped(true);
        $row = current(array_filter($grouped[$listKey], static fn ($r) => (int) $r['id'] === $id));
        self::assertSame('First Option', $row['option_value']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame(0, (int) $row['is_default']);
    }

    public function testAllGroupedExcludesInactiveUnlessRequested(): void
    {
        $listKey = $this->uniqueListKey();
        $activeId = DropdownOptionRepository::create($listKey, 'Active One', 1);
        $inactiveId = DropdownOptionRepository::create($listKey, 'Inactive One', 2);
        DropdownOptionRepository::toggleActive($inactiveId);

        $activeOnly = DropdownOptionRepository::allGrouped(false);
        $activeIds = array_column($activeOnly[$listKey], 'id');
        self::assertContains($activeId, $activeIds);
        self::assertNotContains($inactiveId, $activeIds);

        $everything = DropdownOptionRepository::allGrouped(true);
        $allIds = array_column($everything[$listKey], 'id');
        self::assertContains($activeId, $allIds);
        self::assertContains($inactiveId, $allIds);
    }

    public function testUpdateChangesValueAndSortOrder(): void
    {
        $listKey = $this->uniqueListKey();
        $id = DropdownOptionRepository::create($listKey, 'Original', 5);

        DropdownOptionRepository::update($id, 'Renamed', 9);

        $grouped = DropdownOptionRepository::allGrouped(true);
        $row = current(array_filter($grouped[$listKey], static fn ($r) => (int) $r['id'] === $id));
        self::assertSame('Renamed', $row['option_value']);
        self::assertSame(9, (int) $row['sort_order']);
    }

    public function testSetDefaultUnsetsPreviousDefault(): void
    {
        $listKey = $this->uniqueListKey();
        $firstId = DropdownOptionRepository::create($listKey, 'First', 1);
        $secondId = DropdownOptionRepository::create($listKey, 'Second', 2);
        DropdownOptionRepository::setDefault($firstId, $listKey);

        $grouped = DropdownOptionRepository::allGrouped(true);
        $byId = static fn (array $rows, int $id) => current(array_filter($rows, static fn ($r) => (int) $r['id'] === $id));
        self::assertSame(1, (int) $byId($grouped[$listKey], $firstId)['is_default']);
        self::assertSame(0, (int) $byId($grouped[$listKey], $secondId)['is_default']);

        DropdownOptionRepository::setDefault($secondId, $listKey);

        $grouped = DropdownOptionRepository::allGrouped(true);
        self::assertSame(0, (int) $byId($grouped[$listKey], $firstId)['is_default']);
        self::assertSame(1, (int) $byId($grouped[$listKey], $secondId)['is_default']);
    }

    public function testToggleActiveFlipsEachCall(): void
    {
        $listKey = $this->uniqueListKey();
        $id = DropdownOptionRepository::create($listKey, 'Toggle Me', 1);

        DropdownOptionRepository::toggleActive($id);
        $grouped = DropdownOptionRepository::allGrouped(true);
        $row = current(array_filter($grouped[$listKey], static fn ($r) => (int) $r['id'] === $id));
        self::assertSame(0, (int) $row['is_active']);

        DropdownOptionRepository::toggleActive($id);
        $grouped = DropdownOptionRepository::allGrouped(true);
        $row = current(array_filter($grouped[$listKey], static fn ($r) => (int) $r['id'] === $id));
        self::assertSame(1, (int) $row['is_active']);
    }

    public function testControllerCreateRejectsEmptyValue(): void
    {
        $listKey = $this->uniqueListKey();
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['option_value'] = '   ';

        ob_start();
        $this->controller->create(['listKey' => $listKey]);
        ob_end_clean();

        $grouped = DropdownOptionRepository::allGrouped(true);
        self::assertArrayNotHasKey($listKey, $grouped);
    }

    public function testControllerCreateAssignsNextSortOrder(): void
    {
        $listKey = $this->uniqueListKey();
        DropdownOptionRepository::create($listKey, 'Existing 1', 1);
        DropdownOptionRepository::create($listKey, 'Existing 5', 5);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['option_value'] = 'New One';

        ob_start();
        $this->controller->create(['listKey' => $listKey]);
        ob_end_clean();

        $grouped = DropdownOptionRepository::allGrouped(true);
        $row = current(array_filter($grouped[$listKey], static fn ($r) => $r['option_value'] === 'New One'));
        self::assertSame(6, (int) $row['sort_order']);
    }

    public function testControllerUpdateBulkAppliesChangesAndSetsDefault(): void
    {
        $listKey = $this->uniqueListKey();
        $id1 = DropdownOptionRepository::create($listKey, 'Row One', 1);
        $id2 = DropdownOptionRepository::create($listKey, 'Row Two', 2);
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;
        $_POST['option_value'] = [$id1 => 'Row One Renamed', $id2 => 'Row Two'];
        $_POST['sort_order'] = [$id1 => '1', $id2 => '2'];
        $_POST['is_active'] = [$id1 => '1']; // id2 omitted -> deactivated
        $_POST['default_id'] = (string) $id2;

        ob_start();
        $this->controller->update(['listKey' => $listKey]);
        ob_end_clean();

        $grouped = DropdownOptionRepository::allGrouped(true);
        $byId = static fn (array $rows, int $id) => current(array_filter($rows, static fn ($r) => (int) $r['id'] === $id));
        $row1 = $byId($grouped[$listKey], $id1);
        $row2 = $byId($grouped[$listKey], $id2);
        self::assertSame('Row One Renamed', $row1['option_value']);
        self::assertSame(1, (int) $row1['is_active']);
        self::assertSame(0, (int) $row2['is_active']);
        self::assertSame(1, (int) $row2['is_default']);
    }

    public function testControllerUpdateRedirectsWithErrorForUnknownListKey(): void
    {
        $staffId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $staffId;

        ob_start();
        $this->controller->update(['listKey' => 'totally_unknown_list_key_xyz']);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }
}
