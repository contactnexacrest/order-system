<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\PermissionAdminController;
use App\Helpers\Flash;
use App\Repositories\PermissionRepository;
use App\Tests\Support\DbTestCase;

/**
 * External QA report L-37: saving a role's permission page with nothing
 * ticked silently removed every permission from that role — including
 * the Admin role — with no confirmation, no reason, and no safeguard;
 * all users on that role lose access at once. The audit entry also only
 * ever logged the new permission COUNT, never which permissions were
 * actually removed. Found when the fuzzer emptied the Admin role on the
 * UI copy; reproduced here directly against the controller.
 */
final class RolePermissionWipeGuardTest extends DbTestCase
{
    public function testSavingWithNoPermissionsSelectedIsRefusedAndChangesNothing(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $roleId = (int) Database::connection()
            ->query("SELECT id FROM roles WHERE name = 'Export Executive'")
            ->fetchColumn();
        $before = PermissionRepository::enabledForRole($roleId);
        self::assertNotEmpty($before, 'Export Executive must start with real permissions for this test to mean anything');

        $_POST = ['permission_ids' => []];
        (new PermissionAdminController())->rolePermissionsUpdate(['id' => (string) $roleId]);

        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
        self::assertStringContainsString('remove every permission', $flash[0]['message']);

        $after = PermissionRepository::enabledForRole($roleId);
        self::assertSame($before, $after, 'the role must keep every permission it had — nothing may be silently wiped');
    }

    public function testSavingWithAtLeastOnePermissionStillWorksAndLogsBeforeAndAfter(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        $roleId = (int) Database::connection()
            ->query("SELECT id FROM roles WHERE name = 'Export Executive'")
            ->fetchColumn();
        $viewOrdersId = (int) Database::connection()
            ->query("SELECT id FROM permissions WHERE permission_key = 'manage_orders'")
            ->fetchColumn();

        $_POST = ['permission_ids' => [(string) $viewOrdersId]];
        (new PermissionAdminController())->rolePermissionsUpdate(['id' => (string) $roleId]);

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);

        $after = PermissionRepository::enabledForRole($roleId);
        self::assertSame([$viewOrdersId], $after);

        $logRow = Database::connection()
            ->query("SELECT old_value, new_value FROM audit_log WHERE action_type = 'ROLE_PERMISSIONS_UPDATED' ORDER BY id DESC LIMIT 1")
            ->fetch();
        self::assertNotEmpty($logRow['old_value'], 'the audit entry must record which permissions the role had BEFORE, not just a count');
        self::assertSame('manage_orders', $logRow['new_value']);
    }
}
