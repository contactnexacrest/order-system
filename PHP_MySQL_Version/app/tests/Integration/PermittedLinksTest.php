<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DashboardController;
use App\Controllers\GettingStartedController;
use App\Controllers\SopController;
use App\Helpers\PermittedLinks;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, R5-04 / R5-19 / R5-22: links to pages the user can't open
 * (each a 403) — in the SOP and Getting Started text, the sidebar's
 * intake/reorder links, the Dashboard's audit-log button — are no longer
 * shown as links; a user holding only manage_ports now gets the Admin menu.
 */
final class PermittedLinksTest extends DbTestCase
{
    protected function tearDown(): void
    {
        \App\Helpers\Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_GET = [];
    }

    private function actAs(int $userId): void
    {
        $_SESSION['_auth_user_id'] = $userId;
        PermissionService::resetCache();
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    public function testEveryRuleIsThePermissionItsRouteRequires(): void
    {
        $routes = OrderPageActionsMatchPermissionsTest::routes();
        $samples = [
            '/orders/1/dossier', '/orders/1/additional-documents/removed', '/clients/1/additional-documents/removed',
            '/documents/1/download', '/settings', '/audit-log', '/ca', '/disputes', '/complaints', '/reports', '/clients',
            '/orders', '/client-intake', '/pi-intake-review', '/reorder-requests', '/admin/field-protection',
            '/company-assets', '/signatories', '/ports', '/port-routes', '/users',
        ];
        foreach ($samples as $path) {
            $route = null;
            foreach ($routes as $r) {
                if ($r['method'] === 'GET' && preg_match($r['regex'], $path)) {
                    $route = $r;
                    break;
                }
            }
            self::assertNotNull($route, $path);
            self::assertEqualsCanonicalizing($route['permissions'], PermittedLinks::requiredFor($path), $path);
        }
    }

    public function testAViewerSeesNoLinkToAPageItCannotOpen(): void
    {
        $this->actAs($this->createTestUser('Viewer / Auditor'));

        $gettingStarted = $this->render(fn () => (new GettingStartedController())->show([]));
        foreach (['/settings', '/ca', '/disputes', '/complaints'] as $path) {
            self::assertStringNotContainsString('href="' . $path . '"', $gettingStarted, $path);
        }
        self::assertStringContainsString('href="/reports"', $gettingStarted, 'a page the Viewer can open stays a link');
        self::assertStringContainsString('href="/sop/14-reports"', $gettingStarted);
        self::assertStringNotContainsString('href="/sop/ca-01-overview"', $gettingStarted);

        $sop = $this->render(fn () => (new SopController())->index([]));
        self::assertStringNotContainsString('href="/sop/ca-', $sop);

        $dashboard = $this->render(fn () => (new DashboardController())->index([]));
        foreach (['/client-intake', '/pi-intake-review', '/reorder-requests'] as $path) {
            self::assertStringNotContainsString('href="' . $path . '"', $dashboard, $path);
        }
    }

    public function testAnAdminStillSeesEveryLink(): void
    {
        $this->actAs($this->createTestUser('Admin'));
        $gettingStarted = $this->render(fn () => (new GettingStartedController())->show([]));
        self::assertStringContainsString('href="/settings"', $gettingStarted);
        // D-320 (point 26): PI-stage details is in the Orders menu bar, shown on every Orders page.
        $_SERVER['REQUEST_URI'] = '/client-intake';
        try {
            $ordersPage = $this->render(fn () => (new \App\Controllers\ClientIntakeReviewController())->index([]));
        } finally {
            unset($_SERVER['REQUEST_URI']);
        }
        self::assertStringContainsString('href="/pi-intake-review"', $ordersPage);
    }

    public function testAUserHoldingOnlyManagePortsGetsTheAdminMenuWithPorts(): void
    {
        $userId = $this->createTestUser('Viewer / Auditor');
        $pdo = Database::connection();
        $permissionId = (int) $pdo->query("SELECT id FROM permissions WHERE permission_key = 'manage_ports'")->fetchColumn();
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:u, :p, 1)')->execute(['u' => $userId, 'p' => $permissionId]);
        $this->actAs($userId);

        $dashboard = $this->render(fn () => (new DashboardController())->index([]));
        self::assertStringContainsString('href="/ports"', $dashboard);
    }

    /**
     * QA Round 6, R6-N02: every page route under a rule needs exactly what
     * the rule says, so a link hidden by the rule is one the route refuses.
     */
    public function testEveryPageRouteUnderARuleNeedsWhatTheRuleSays(): void
    {
        $checked = 0;
        foreach (OrderPageActionsMatchPermissionsTest::routes() as $route) {
            if ($route['method'] !== 'GET' || $route['permissions'] === null) {
                continue;
            }
            $path = preg_replace('#\{[^}]+\}#', '1', $route['pattern']);
            $rule = PermittedLinks::requiredFor($path);
            if ($rule === null) {
                continue;
            }
            self::assertEqualsCanonicalizing($route['permissions'], $rule, $route['pattern']);
            $checked++;
        }
        self::assertGreaterThan(70, $checked);
    }

    /** QA Round 6, R6-N02: links to an order the user may not see, and to pages their permissions don't open, are plain text. */
    public function testLinksThatWouldAnswer403ArePlainText(): void
    {
        $exporter = $this->createTestUser('Export Executive');
        $client = $this->createTestClient();
        $mine = $this->createTestOrder($client, 'CIF', 'Standard — New Buyer', 'USD', null, null, $exporter);
        $other = $this->createTestOrder($this->createTestClient());
        $this->actAs($exporter);
        $html = PermittedLinks::filterHtml("<a href=\"/orders/{$mine}\">A</a> <a href=\"/orders/{$other}\">B</a> <a href=\"/reports/order/{$other}\">C</a> <a href=\"/orders/{$other}/disputes\">D</a>");
        self::assertStringContainsString("href=\"/orders/{$mine}\"", $html, 'an order assigned to the user stays a link');
        self::assertStringNotContainsString("href=\"/orders/{$other}", $html);
        self::assertStringNotContainsString("href=\"/reports/order/{$other}\"", $html);

        $userId = $this->createTestUser('Viewer / Auditor');
        $pdo = Database::connection();
        $permissionId = (int) $pdo->query("SELECT id FROM permissions WHERE permission_key = 'manage_incentives'")->fetchColumn();
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:u, :p, 1)')->execute(['u' => $userId, 'p' => $permissionId]);
        $this->actAs($userId);
        $settings = $this->render(fn () => (new \App\Controllers\IncentiveController())->settings([]));
        self::assertStringContainsString('Incentive payouts', $settings);
        self::assertStringNotContainsString('href="/incentives/payouts"', $settings, 'paying incentives needs pay_incentives');
    }

    /** UI-006: the Investor (view_reports, no selling prices / buyer payments) is not offered the 9 money reports that refuse it. */
    public function testUi006TheReportsPageOffersOnlyReportsThatShowMoneyThePersonMaySee(): void
    {
        $clientId = $this->createTestClient();
        $moneyReports = ['/reports/aggregate', '/reports/payments', '/reports/todays-payments', '/reports/ageing', '/reports/amendments',
            '/reports/trends', '/reports/performance', '/reports/products', '/reports/client/' . $clientId];

        $this->actAs($this->createTestUser('Investor (view only)'));
        $investor = $this->render(fn () => (new \App\Controllers\ReportController())->index([]));
        foreach ($moneyReports as $path) {
            self::assertStringNotContainsString('href="' . $path . '"', $investor, $path);
        }
        self::assertStringContainsString('href="/reports/queues"', $investor, 'a report without amounts stays');
        self::assertStringNotContainsString('Open Ageing Report', $investor, 'the section is left out, not shown as dead text');
        // Wherever such a link appears (any staff page), it is plain text for this person.
        $html = PermittedLinks::filterHtml('<a href="/reports/ageing?as_of=2026-11-01">A</a> <a href="/reports/client/' . $clientId . '">B</a> <a href="/reports/queues">C</a>');
        self::assertSame('<span class="link-not-permitted">A</span> <span class="link-not-permitted">B</span> <a href="/reports/queues">C</a>', $html);

        $this->actAs($this->createTestUser('Managing Director'));
        $md = $this->render(fn () => (new \App\Controllers\ReportController())->index([]));
        foreach ($moneyReports as $path) {
            self::assertStringContainsString('href="' . $path . '"', $md, $path);
        }
    }

    /** UI-007: the System Administrator's Setup checklist does not link to product / pricing pages it cannot open. */
    public function testUi007TheSetupChecklistLinksOnlyToPagesThePersonMayOpen(): void
    {
        $this->actAs($this->createTestUser('System Administrator'));
        $checklist = $this->render(fn () => (new \App\Controllers\SetupChecklistController())->index([]));
        self::assertStringContainsString('Commission levels', $checklist);
        self::assertStringNotContainsString('href="/products"', $checklist);
        self::assertStringNotContainsString('href="/pricing/commission-levels"', $checklist);
        self::assertSame('<span class="link-not-permitted">set it</span>', PermittedLinks::filterHtml('<a href="/products/categories/3?tab=options&amp;edit=4#spec-4">set it</a>'));

        $this->actAs($this->createTestUser('Managing Director'));
        $checklist = $this->render(fn () => (new \App\Controllers\SetupChecklistController())->index([]));
        self::assertStringContainsString('href="/products"', $checklist);
        self::assertStringContainsString('href="/pricing/commission-levels"', $checklist);
    }
}
