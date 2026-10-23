<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DashboardController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report U-18: "Viewer / Auditor has view_orders and
 * view_clients but no Orders or Clients link in the menu — the only way
 * to an order is through a report link." $opsGroupVisible in
 * views/layout/base.php never included view_clients/view_orders, even
 * though /clients and /orders each accept either of those as an
 * alternative to manage_orders (PermissionCheck::requiresAny() on both
 * routes) — so the whole Operations dropdown never rendered at all for a
 * role holding only those two, and even once the group renders, the
 * Clients/Orders links themselves need their own gate (same as every
 * other link in this dropdown) so a view_orders-only holder isn't shown a
 * Clients link they can't actually use, and vice versa.
 */
final class OpsNavGroupVisibilityTest extends DbTestCase
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

    public function testOpsGroupRendersWithAClientsLinkForAUserWhoseOnlyOpsPermissionIsViewClients(): void
    {
        // CA / Chartered Accountant is seeded with neither manage_orders
        // nor view_clients/view_orders (docs/seed.sql) — a clean base role
        // to add exactly one override onto.
        $userId = $this->createTestUser('CA / Chartered Accountant');
        $this->grantOnlyPermission($userId, 'view_clients');

        $output = $this->renderDashboardAs($userId);

        self::assertStringContainsString('href="/clients"', $output, 'the bug: the Operations dropdown never rendered at all for a user whose only Ops permission was this one');
        self::assertStringNotContainsString('href="/orders"', $output, 'view_clients alone must not also surface the Orders link');
    }

    public function testOpsGroupRendersWithAnOrdersLinkForAUserWhoseOnlyOpsPermissionIsViewOrders(): void
    {
        $userId = $this->createTestUser('CA / Chartered Accountant');
        $this->grantOnlyPermission($userId, 'view_orders');

        $output = $this->renderDashboardAs($userId);

        self::assertStringContainsString('href="/orders"', $output);
        self::assertStringNotContainsString('href="/clients"', $output, 'view_orders alone must not also surface the Clients link');
    }

    public function testTheSeededViewerAuditorRoleSeesBothLinks(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');

        $output = $this->renderDashboardAs($userId);

        self::assertStringContainsString('href="/clients"', $output);
        self::assertStringContainsString('href="/orders"', $output);
    }

    public function testOpsGroupStaysHiddenForAUserWithNoOpsPermissionsAtAll(): void
    {
        // CA / Chartered Accountant is seeded with only ca_module_view +
        // inr_actual_view (docs/seed.sql) — none of manage_orders,
        // view_archived_orders, manage_disputes, respond_to_disputes,
        // view_clients or view_orders, so this is a clean negative case
        // without mutating any shared role_permissions row.
        $userId = $this->createTestUser('CA / Chartered Accountant');

        $output = $this->renderDashboardAs($userId);

        self::assertStringNotContainsString('href="/clients"', $output);
        self::assertStringNotContainsString('href="/orders"', $output);
        self::assertStringNotContainsString('>Operations</summary>', $output);
    }
}
