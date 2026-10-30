<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\CaController;
use App\Controllers\OrderController;
use App\Tests\Support\DbTestCase;

/**
 * External QA report U-01: "Roles are shown links/buttons they are not
 * allowed to use" → clicking through to a "403 Not permitted" page.
 * Owner rule: everyone sees only what they can use. Each test below
 * logs in as the exact role QA found broken, renders the real page, and
 * asserts the link is now either omitted (wrapped in the same
 * permission check as its own route) or replaced with plain text, never
 * a dead-end link.
 */
final class NavLinksMatchRoutePermissionsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testExportExecutiveSeesTheDisputesListSidebarLink(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');

        ob_start();
        (new OrderController())->index([]);
        $html = (string) ob_get_clean();

        // Owner decision 2026-10-30 (QA Round 6, Q-R6-3): respond_to_disputes
        // now opens the list too, scoped to the orders the user may see.
        self::assertStringContainsString('href="/disputes"', $html, 'Export Executive has respond_to_disputes, which now opens the Disputes list');
    }

    public function testAdminDoesSeeTheDisputesListSidebarLink(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');

        ob_start();
        (new OrderController())->index([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('href="/disputes"', $html);
    }

    public function testViewerDoesNotSeeTheAnnexureOrAmendmentsLinksOnAnOrderPage(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Viewer / Auditor');

        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString("href=\"/orders/{$orderId}/annexure\"", $html, 'Viewer / Auditor lacks manage_orders, which /orders/{id}/annexure requires');
        self::assertStringNotContainsString("href=\"/orders/{$orderId}/amendments\"", $html, 'Viewer / Auditor lacks manage_orders, which /orders/{id}/amendments requires');
        self::assertStringNotContainsString("href=\"/orders/{$orderId}/email/compose\"", $html, 'Viewer / Auditor lacks generate_documents, which /orders/{id}/email/compose requires');
        self::assertStringNotContainsString("href=\"/orders/{$orderId}/disputes\"", $html, 'Viewer / Auditor has neither manage_disputes nor respond_to_disputes');
    }

    public function testAccountsExecutiveDoesNotSeeTheEmailComposeLinkOrTheOrderDisputesLink(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Accounts Executive');

        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString("href=\"/orders/{$orderId}/email/compose\"", $html, 'Accounts Executive lacks generate_documents');
        self::assertStringNotContainsString("href=\"/orders/{$orderId}/disputes\"", $html, 'Accounts Executive has neither manage_disputes nor respond_to_disputes');
    }

    public function testExportExecutiveDoesSeeTheOrderDisputesLinkViaRespondToDisputes(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $userId);
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString("href=\"/orders/{$orderId}/disputes\"", $html, 'Export Executive holds respond_to_disputes, which the order-level route accepts');
    }

    public function testCaRoleSeesEveryOrderAsALinkOnTheCaPages(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
        \App\Repositories\OrderPaymentStatusRepository::markAdvanceCleared($orderId, date('Y-m-d H:i:s'), $this->createTestUser('Admin'));
        $caId = $this->createTestUser('CA / Chartered Accountant');
        $_SESSION['_auth_user_id'] = $caId;
        \App\Services\PermissionService::resetCache();

        ob_start();
        (new CaController())->index([]);
        $html = (string) ob_get_clean();
        // Owner decision 2026-10-30: the CA holds view_all_orders (read-only) —
        // every order is a real link, not only those assigned to the CA.
        self::assertStringContainsString("href=\"/orders/{$orderId}\"", $html, 'the CA opens every order, assigned or not');

        ob_start();
        (new CaController())->expenses([]);
        $expensesHtml = (string) ob_get_clean();
        self::assertStringNotContainsString('href="/ca/zoho-sync"', $expensesHtml, 'Zoho sync needs ca_module_manage, a step up from the CA role\'s plain ca_module_view');
    }

    public function testAdminSeesOrderReferencesAsRealLinksOnTheCaHub(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $adminId = $this->createTestUser('Admin');
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
        \App\Repositories\OrderPaymentStatusRepository::markAdvanceCleared($orderId, date('Y-m-d H:i:s'), $adminId);
        $_SESSION['_auth_user_id'] = $adminId;

        ob_start();
        (new CaController())->index([]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString("href=\"/orders/{$orderId}\"", $html);
    }

    public function testExportOrLogisticsSeesCompliancTaskTypesAsPlainTextOnAnOrderPage(): void
    {
        // The Compliance Checklist card (shown to close_orders holders) names
        // where its task list is managed — a link only for those who can
        // open /compliance-task-types (Admin-only), plain text for everyone else.
        $orderId = $this->createTestOrder($this->createTestClient());
        \App\Repositories\OrderComplianceTaskRepository::initializeForOrder($orderId);

        $logisticsUserId = $this->createTestUser('Logistics Executive');
        // Non-oversight roles only open orders assigned to them.
        \App\Repositories\OrderAssigneeRepository::add($orderId, $logisticsUserId, null);
        $_SESSION['_auth_user_id'] = $logisticsUserId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('id="compliance-checklist"', $html, 'Logistics Executive holds close_orders, so the checklist is shown');
        self::assertStringContainsString('managed from Compliance Task Types', $html);
        self::assertStringNotContainsString('href="/compliance-task-types"', $html, 'Logistics Executive has close_orders but not manage_compliance_task_types');

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('<a href="/compliance-task-types">Compliance Task Types</a>', $html, 'Admin can manage the task types, so it is a link');
    }
}
