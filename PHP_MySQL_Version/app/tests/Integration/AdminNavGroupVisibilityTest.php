<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DashboardController;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #34: the sidebar's "Admin" <details> dropdown only
 * renders when $adminGroupVisible is true — but that flag's OR-list in
 * views/layout/base.php never included $canManageHsCodes,
 * $canManageComplianceTaskTypes, $canManageDropdownOptions or
 * $canManageEmailTemplates, even though each of those gates its own link
 * inside the very same dropdown. A role/user whose ONLY Admin-section
 * permission was one of those four saw no "Admin" group at all — the
 * whole dropdown (summary + wrapper) never rendered, so there was nothing
 * to click even though the route itself worked fine from a direct URL.
 * This grants exactly one such permission via a user_permissions override
 * on a role ("Export Executive") that otherwise has none of the
 * Admin-group permissions, so a false result here can only come from this
 * specific gap, not from some other permission the role already has.
 */
final class AdminNavGroupVisibilityTest extends DbTestCase
{
    private function grantOnlyPermission(int $userId, string $permissionKey): void
    {
        $pdo = Database::connection();
        $permissionId = (int) $pdo->query(
            'SELECT id FROM permissions WHERE permission_key = ' . $pdo->quote($permissionKey)
        )->fetchColumn();
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:uid, :pid, 1)')
            ->execute(['uid' => $userId, 'pid' => $permissionId]);
    }

    private function renderDashboardAs(int $userId): string
    {
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new DashboardController())->index([]);
        return ob_get_clean();
    }

    public function testAdminGroupRendersForAUserWhoseOnlyAdminPermissionIsManageComplianceTaskTypes(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $this->grantOnlyPermission($userId, 'manage_compliance_task_types');

        $output = $this->renderDashboardAs($userId);

        self::assertStringContainsString(
            'href="/compliance-task-types"',
            $output,
            'the bug: the Admin dropdown never rendered at all for a user whose only Admin permission was this one'
        );
    }

    public function testAdminGroupRendersForAUserWhoseOnlyAdminPermissionIsManageHsCodes(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $this->grantOnlyPermission($userId, 'manage_hs_codes');

        $output = $this->renderDashboardAs($userId);

        self::assertStringContainsString('href="/hs-codes"', $output);
    }

    public function testAdminGroupRendersForAUserWhoseOnlyAdminPermissionIsManageDropdownOptions(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $this->grantOnlyPermission($userId, 'manage_dropdown_options');

        $output = $this->renderDashboardAs($userId);

        self::assertStringContainsString('href="/admin/dropdown-options"', $output);
    }

    public function testAdminGroupRendersForAUserWhoseOnlyAdminPermissionIsManageEmailTemplates(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $this->grantOnlyPermission($userId, 'manage_email_templates');

        $output = $this->renderDashboardAs($userId);

        self::assertStringContainsString('href="/email-templates"', $output);
    }

    public function testAdminGroupStaysHiddenForAUserWithNoAdminPermissionsAtAll(): void
    {
        $userId = $this->createTestUser('Export Executive');

        $output = $this->renderDashboardAs($userId);

        self::assertStringNotContainsString('href="/compliance-task-types"', $output);
        self::assertStringNotContainsString('>Admin</summary>', $output);
    }
}
