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

        // D-320: HS codes moved to the Products section; the role already opens Products, so the
        // left-menu entry goes to /products and the HS codes link is in that section's menu bar.
        self::assertStringContainsString('href="/products" class="" data-nav="products"', $this->renderDashboardAs($userId));
        // Stale-test fix (QA 13 Nov 2026, D-362): an empty Active tab now redirects to the
        // tab that has products, so the page is opened on the All tab (the Node twin follows
        // that redirect) — the HS codes link sits in the section menu bar either way.
        $_SERVER['REQUEST_URI'] = '/products?status=all';
        $_GET = ['status' => 'all'];
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new \App\Controllers\ProductController())->index([]);
        $output = (string) ob_get_clean();
        unset($_SERVER['REQUEST_URI']);
        $_GET = [];

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
        self::assertStringNotContainsString('data-nav="settings"', $output); // D-320: no Settings entry in the left menu
    }
}
