<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Point 6 — the data-export/migration tool is deliberately gated on its
 * own permission (data_export_run) rather than folded into
 * manage_company_settings, since the data file it unlocks can expose
 * every record in the system, including staff password hashes. This
 * confirms the seeded grant actually behaves as designed: Admin has it by
 * default, an ungranted role does not, and a Super Admin always does
 * regardless of role — the same bypass every other permission gets.
 */
final class DataExportPermissionTest extends DbTestCase
{
    public function testAdminRoleHasDataExportPermissionBySeed(): void
    {
        $userId = $this->createTestUser('Admin');
        $roleId = $this->roleIdFor($userId);

        self::assertTrue(PermissionService::can($userId, $roleId, 'data_export_run'));
    }

    public function testUngrantedRoleDoesNotHaveDataExportPermission(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $roleId = $this->roleIdFor($userId);

        self::assertFalse(PermissionService::can($userId, $roleId, 'data_export_run'));
    }

    public function testSuperAdminHasItRegardlessOfRole(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $roleId = $this->roleIdFor($userId);
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $userId]);

        self::assertTrue(PermissionService::can($userId, $roleId, 'data_export_run'));
    }

    private function roleIdFor(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT role_id FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }
}
