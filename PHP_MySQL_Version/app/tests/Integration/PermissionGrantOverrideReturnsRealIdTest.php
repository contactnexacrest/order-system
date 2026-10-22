<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\PermissionRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report R-08: re-granting a permission the user already has
 * (INSERT ... ON DUPLICATE KEY UPDATE taking the UPDATE branch) made
 * PDO::lastInsertId() return 0 — no new AUTO_INCREMENT value was ever
 * generated — so PermissionAdminController::grantOverride()'s audit log
 * entry pointed at user_permissions #0, losing which grant actually
 * changed.
 */
final class PermissionGrantOverrideReturnsRealIdTest extends DbTestCase
{
    public function testFirstGrantReturnsANonZeroId(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $adminId = $this->createTestUser('Admin');
        $permission = \App\Config\Database::connection()->query('SELECT id FROM permissions LIMIT 1')->fetch();

        $id = PermissionRepository::grantOverride($userId, (int) $permission['id'], $adminId, 'Initial grant for testing.');

        self::assertGreaterThan(0, $id);
    }

    public function testReGrantingAnAlreadyGrantedPermissionReturnsTheSameRealIdNotZero(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $adminId = $this->createTestUser('Admin');
        $permission = \App\Config\Database::connection()->query('SELECT id FROM permissions LIMIT 1')->fetch();
        $permissionId = (int) $permission['id'];

        $firstId = PermissionRepository::grantOverride($userId, $permissionId, $adminId, 'Initial grant for testing.');
        $secondId = PermissionRepository::grantOverride($userId, $permissionId, $adminId, 'Re-granted — reason updated.');

        self::assertGreaterThan(0, $secondId, 're-granting must never return 0 — that is the exact bug this fix closes');
        self::assertSame($firstId, $secondId, 're-granting the same (user, permission) pair must return the same real row id');
    }
}
