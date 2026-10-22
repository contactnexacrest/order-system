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

    public function testExportExecutiveDoesNotSeeTheDisputesListSidebarLink(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');

        ob_start();
        (new OrderController())->index([]);
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('href="/disputes"', $html, 'Export Executive has respond_to_disputes but not manage_disputes, and the list route needs manage_disputes');
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
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Export Executive');

        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString("href=\"/orders/{$orderId}/disputes\"", $html, 'Export Executive holds respond_to_disputes, which the order-level route accepts');
    }

    public function testCaRoleSeesOrderReferencesAsPlainTextNotLinksOnEveryCaPage(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($orderId);
        \App\Repositories\OrderPaymentStatusRepository::markAdvanceCleared($orderId, date('Y-m-d H:i:s'), $this->createTestUser('Admin'));
        $_SESSION['_auth_user_id'] = $this->createTestUser('CA / Chartered Accountant');

        ob_start();
        (new CaController())->index([]);
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString("href=\"/orders/{$orderId}\"", $html, 'the CA role has no order-management access at all');

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
        $orderId = $this->createTestOrder($this->createTestClient());
        $_SESSION['_auth_user_id'] = $this->createTestUser('Logistics Executive');

        ob_start();
        (new OrderController())->show(['id' => $orderId]);
        $html = (string) ob_get_clean();

        if (str_contains($html, 'Pre-closure compliance tasks')) {
            self::assertStringNotContainsString('href="/compliance-task-types"', $html, 'Logistics Executive has close_orders but not manage_compliance_task_types');
        } else {
            self::markTestSkipped('Compliance tasks section not rendered for this order/stage state.');
        }
    }
}
