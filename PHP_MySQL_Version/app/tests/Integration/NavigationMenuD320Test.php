<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\AssociateAdminController;
use App\Controllers\DashboardController;
use App\Controllers\OrderController;
use App\Services\Navigation;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-320 (11 Nov 2026, point 26): the left menu goes from about
 * 60 entries to 13; every page of a section shows that section's menu bar;
 * the order page gets a sticky bar of action buttons. One config,
 * src/Config/navigation.json (the same file in the Node stack), drives both
 * menus. Node twin: tests/integration/navigationMenuD320.test.js.
 */
final class NavigationMenuD320Test extends DbTestCase
{
    /** Every page the old left menu linked to, plus the pages it only reached through links inside other pages. */
    private const OLD_MENU_PAGES = [
        '/approvals', '/getting-started', '/reference-docs', '/sop', '/gallery', '/reviews', '/my-tasks', '/email-approvals',
        '/ca', '/ca/zoho-sync', '/products', '/clients', '/associates', '/agreements', '/orders', '/orders/archived',
        '/client-intake', '/pi-intake-review', '/reorder-requests', '/disputes', '/complaints', '/procurement', '/import', '/merchant',
        '/reports', '/reports/freight-cost', '/reports/suppliers', '/incentives/payouts', '/my-incentives', '/cockpit', '/audit-log',
        '/admin/setup-checklist', '/settings', '/admin/email-settings', '/holidays', '/hs-codes', '/logistics-partners', '/suppliers',
        '/ports', '/payment-presets', '/compliance-task-types', '/admin/dropdown-options', '/email-templates', '/ui-templates',
        '/watermarks', '/company-assets', '/signatories', '/admin/divisions', '/admin/gst-rates', '/admin/import-duty-rates',
        '/admin/departments', '/admin/task-types', '/admin/permissions', '/users', '/admin/field-protection', '/incentives/settings',
        '/admin/overrides', '/sample-data', '/admin/data-export', '/admin/backups', '/super-admin',
        '/admin/self-approval', '/test-mode',
        // Reached before only from links inside pages (the TDS register had no link at all).
        '/associates/tds', '/associates/registrations', '/associates/price-requests', '/associates/ladder', '/associates/access-levels',
        '/associates/new', '/reports/associates', '/products/categories', '/pricing/commission-levels',
        '/pricing/specifications', '/products/fix-hs-codes', '/products/link-suppliers', '/procurement/suppliers', '/admin/sop-access',
        '/settings/document-cleanup', '/port-routes', '/settings/remittance-reasons', '/settings/associate-tds', '/clients/create',
        '/products/create',
    ];

    /**
     * Decided bar (D-320 register): Procurement & suppliers has no "+ Add" entry; + Add supplier stays a button on the
     * Suppliers page, which shows the section's bar.
     */
    private const PAGE_BUTTON_ONLY = ['/suppliers/create'];

    /** D-362 (13 Nov 2026): reached only from the Products screen's link bar, not from a menu. */
    private const PRODUCTS_LINK_BAR_ONLY = ['/pricing/settings', '/products/price-rules'];

    private const ALL_FLAGS = [
        'superAdmin' => true, 'sopOpen' => true, 'approver' => true, 'mod_supplier_purchase' => true, 'mod_import_stock' => true,
        'mod_merchant_trade' => true, 'mod_associates' => true, 'mod_team_incentive' => true, 'div_gst' => true, 'div_import_duty' => true,
    ];

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI']);
        $_SESSION = [];
        $_GET = [];
        parent::tearDown();
    }

    /** @return list<array<string,mixed>> every link of every menu bar */
    private static function leaves(): array
    {
        $out = [];
        foreach (Navigation::config()['sections'] as $section) {
            foreach ($section['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $leaf) {
                    $out[] = $leaf + ['section' => $section['key']];
                }
            }
        }
        return $out;
    }

    private static function pathOf(string $href): string
    {
        return explode('#', explode('?', $href)[0])[0];
    }

    private function render(int $userId, string $path, callable $action): string
    {
        $_SESSION = ['_auth_user_id' => $userId];
        $_SERVER['REQUEST_URI'] = $path;
        \App\Services\PermissionService::resetCache();
        ob_start();
        try {
            $action();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    public function testTheConfigIsTheSameFileInBothStacks(): void
    {
        $node = dirname(__DIR__, 4) . '/NODE_MySQL_Version/app/src/config/navigation.json';
        if (!is_file($node)) {
            self::markTestSkipped('the Node stack is not next to this one');
        }
        self::assertSame(
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Config/navigation.json'),
            (string) file_get_contents($node),
            'parity: one menu config, the same in both stacks'
        );
    }

    public function testTheLeftMenuHasThirteenEntriesInTheDecidedOrder(): void
    {
        $sections = Navigation::config()['sections'];
        self::assertSame(
            ['dashboard', 'approvals', 'tasks', 'orders', 'clients', 'associates', 'products', 'procurement', 'finance', 'reports', 'help', 'users', 'settings'],
            array_column($sections, 'key')
        );
        self::assertSame(
            ['Dashboard', 'Approvals', 'My tasks', 'Orders', 'Clients', 'Associates', 'Products', 'Procurement & suppliers', 'Finance & accounts', 'Reports', 'Help & SOP', 'Users & roles', 'Settings'],
            array_column($sections, 'label')
        );
        $built = Navigation::build(static fn (): bool => true, self::ALL_FLAGS, [], '/', []);
        self::assertCount(13, $built['sidebar']);
        self::assertSame(['Work', 'Overview', 'Help', 'Admin'], array_values(array_filter(array_column($built['sidebar'], 'heading'))));
    }

    public function testEveryOldMenuPageIsStillReachableFromAMenuAndShowsItsSectionBar(): void
    {
        // A section without a menu bar of its own (Dashboard, My tasks) is reached from its left-menu entry.
        $sectionEntries = array_map(static fn (array $s): string => self::pathOf((string) $s['href']), array_filter(Navigation::config()['sections'], static fn (array $s): bool => !empty($s['href'])));
        $linked = array_unique(array_merge(array_map(static fn (array $l): string => self::pathOf((string) $l['href']), self::leaves()), $sectionEntries));
        foreach (self::OLD_MENU_PAGES as $page) {
            self::assertContains($page, $linked, "{$page} is in a section menu bar");
            self::assertNotNull(Navigation::sectionKeyFor($page), "{$page} belongs to a section, so its pages show the bar");
        }
        foreach (self::PAGE_BUTTON_ONLY as $page) {
            self::assertNotContains($page, $linked, "{$page} is a button on its section's page, not a menu link");
            self::assertSame('procurement', Navigation::sectionKeyFor($page), "{$page} shows the Procurement & suppliers bar");
        }
        foreach (self::PRODUCTS_LINK_BAR_ONLY as $page) {
            self::assertNotContains($page, $linked, "{$page} is a link on the Products screen, not a menu link");
            self::assertSame('products', Navigation::sectionKeyFor($page), "{$page} keeps Products highlighted");
        }
        self::assertSame('dashboard', Navigation::build(static fn (): bool => true, self::ALL_FLAGS, [], '/', [])['currentKey']);
    }

    /** A menu link is shown exactly to those its route lets in: the same permissions, or Super Admin. */
    public function testEveryMenuLinkNeedsExactlyWhatItsRouteNeeds(): void
    {
        $routes = OrderPageActionsMatchPermissionsTest::routes();
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/index.php');
        foreach (self::leaves() as $leaf) {
            $path = self::pathOf((string) $leaf['href']);
            $route = null;
            foreach ($routes as $r) {
                if ($r['method'] === 'GET' && preg_match($r['regex'], $path)) {
                    $route = $r;
                    break;
                }
            }
            self::assertNotNull($route, "{$path} has a page");
            $menu = $leaf['when']['any'] ?? null;
            if (str_starts_with((string) $leaf['href'], '/approvals?tab=')) {
                self::assertNull($route['permissions'], 'the Approvals page is open to everyone; a tab shows only to those who approve that kind');
                continue;
            }
            $sorted = static function (?array $a): ?array {
                if ($a !== null) {
                    sort($a);
                }
                return $a;
            };
            self::assertSame($sorted($route['permissions']), $sorted($menu), "{$leaf['href']}: menu condition = route permissions");
            if (in_array('superAdmin', $leaf['when']['flags'] ?? [], true)) {
                self::assertMatchesRegularExpression('#\$router->get\(\'' . preg_quote($path, '#') . '\',[^\n]*SuperAdminOnly#', $source, "{$path} is Super Admin only");
            }
        }
    }

    public function testAnEntryShowsOnlyWhenThePersonCanOpenAPageInItAndLinksToTheFirstOne(): void
    {
        $only = static fn (string ...$keys): \Closure => static fn (string $p): bool => in_array($p, $keys, true);

        $nothing = Navigation::build($only(), [], [], '/', []);
        self::assertSame(['dashboard', 'approvals', 'help'], array_column($nothing['sidebar'], 'key'), 'everyone: Dashboard, Approvals (their own document reviews) and Help');
        self::assertSame(['/', '/reviews', '/reference-docs'], array_column($nothing['sidebar'], 'href'), 'the first page that opens in the same tab');

        $ports = Navigation::build($only('manage_ports'), [], [], '/', []);
        self::assertSame(['dashboard', 'approvals', 'help', 'settings'], array_column($ports['sidebar'], 'key'));
        self::assertSame('/ports', $ports['sidebar'][3]['href']);

        $viewer = Navigation::build($only('view_orders', 'view_clients', 'view_reports', 'view_audit_log'), [], [], '/', []);
        self::assertSame(['dashboard', 'approvals', 'tasks', 'orders', 'clients', 'reports', 'help'], array_column($viewer['sidebar'], 'key'));

        // A module switched off (D-257) hides its pages; a Super Admin page needs the Super Admin.
        $buyer = Navigation::build($only('view_purchase_prices'), ['mod_import_stock' => false, 'mod_merchant_trade' => false], [], '/', []);
        self::assertNotContains('procurement', array_column($buyer['sidebar'], 'key'));
        $buyer = Navigation::build($only('view_purchase_prices'), ['mod_merchant_trade' => true], [], '/merchant', []);
        self::assertSame('/merchant', $buyer['sidebar'][array_search('procurement', array_column($buyer['sidebar'], 'key'), true)]['href']);
        $admin = Navigation::build(static fn (): bool => true, ['superAdmin' => false] + self::ALL_FLAGS, [], '/users', []);
        $labels = array_column($admin['bar']['items'], 'label');
        self::assertNotContains('Self-approval rules', $labels);
        self::assertContains('Roles and permissions', $labels);
    }

    public function testTheBarMarksThePageShownAndCarriesTheCounts(): void
    {
        $all = static fn (): bool => true;
        $active = static function (array $built): array {
            $out = [];
            foreach ($built['bar']['items'] as $item) {
                if ($item['active']) {
                    $out[] = $item['label'];
                    foreach ($item['children'] ?? [] as $child) {
                        if ($child['active']) {
                            $out[] = $child['label'];
                        }
                    }
                }
            }
            return $out;
        };
        self::assertSame(['Active'], $active(Navigation::build($all, self::ALL_FLAGS, [], '/orders/12', [])));
        self::assertSame(['Archived'], $active(Navigation::build($all, self::ALL_FLAGS, [], '/orders/archived', [])));
        self::assertSame(['Report'], $active(Navigation::build($all, self::ALL_FLAGS, [], '/reports/associates', [])), 'the longest prefix wins');
        // D-362: Global pricing settings is reached only from the Products link bar — no menu item is marked.
        self::assertSame([], $active(Navigation::build($all, self::ALL_FLAGS, [], '/pricing/settings', [])));
        self::assertSame(['Prices'], $active(Navigation::build($all, self::ALL_FLAGS, [], '/approvals', ['tab' => 'prices'])));
        self::assertSame(['All'], $active(Navigation::build($all, self::ALL_FLAGS, [], '/approvals', [])));
        self::assertNull(Navigation::sectionKeyFor('/cafin'), '"/ca" never covers "/cafin"');

        $built = Navigation::build($all, self::ALL_FLAGS, ['associateRegistrations' => 2, 'approvals' => 5, 'myTasks' => 3, 'settingsExpiry' => 1], '/associates', []);
        $items = array_column($built['bar']['items'], 'count', 'label');
        self::assertSame(2, $items['Registrations']);
        $side = array_column($built['sidebar'], 'count', 'key');
        self::assertSame(5, $side['approvals']);
        self::assertSame(3, $side['tasks']);
        self::assertSame(1, $side['settings']);
        self::assertSame(0, $side['orders']);
    }

    public function testThePagesDrawTheMenusFromTheConfig(): void
    {
        $admin = $this->createTestUser('Admin');

        $dashboard = $this->render($admin, '/', static fn () => (new DashboardController())->index([]));
        self::assertSame(13, substr_count($dashboard, ' data-nav="'), 'the short left menu');
        self::assertStringNotContainsString('class="section-nav"', $dashboard, 'the dashboard has no section bar');
        self::assertStringNotContainsString('class="nav-group"', $dashboard, 'the old drop-down groups are gone');

        $associates = $this->render($admin, '/associates', static fn () => (new AssociateAdminController())->index([]));
        self::assertStringContainsString('<nav class="section-nav" aria-label="Associates menu" data-section="associates">', $associates);
        self::assertStringContainsString('href="/associates/tds">TDS register', $associates, 'the TDS register now has a link');
        self::assertStringContainsString('href="/associates" class="active" aria-current="page">All associates', $associates);
        self::assertStringContainsString('href="/associates" class="active" data-nav="associates"', $associates, 'the left-menu entry is marked too');

        $viewer = $this->createTestUser('Viewer / Auditor');
        $clients = $this->render($viewer, '/clients', static fn () => (new \App\Controllers\ClientController())->index([]));
        self::assertStringContainsString('data-section="clients"', $clients);
        self::assertStringNotContainsString('href="/clients/create"', $clients, 'Viewer cannot add a client, so no "+ Add client"');
    }

    public function testTheOrderPageHasAStickyActionBarInsteadOfTheGreyLink(): void
    {
        $admin = $this->createTestUser('Admin');
        $orderId = $this->createTestOrder($this->createTestClient());
        $page = $this->render($admin, '/orders/' . $orderId, static fn () => (new OrderController())->show(['id' => (string) $orderId]));
        $start = strpos($page, 'data-testid="order-action-bar"');
        self::assertNotFalse($start);
        $bar = substr($page, $start, (int) strpos($page, '<div class="card page-wide">', $start) - $start);
        self::assertStringContainsString('href="/orders/' . $orderId . '/edit">Edit order</a>', $bar);
        self::assertStringContainsString('href="#document-review-approval">Documents</a>', $bar);
        self::assertStringContainsString('href="/reports/order/' . $orderId . '">Full report</a>', $bar);
        self::assertStringContainsString('action="/orders/' . $orderId . '/duplicate"', $bar);
        self::assertStringContainsString('Request cancellation</button>', $bar);
        self::assertStringContainsString('href="/orders/' . $orderId . '/audit-log">Audit trail</a>', $bar);
        self::assertStringNotContainsString('Edit Order Details', $page);
        self::assertSame(1, substr_count($page, 'action="/orders/' . $orderId . '/duplicate"'), 'Duplicate order lives in the bar only');
        self::assertStringContainsString('id="request-cancellation-btn"', $page, 'More › Request cancellation runs the existing request form');

        $viewer = $this->createTestUser('Viewer / Auditor');
        $page = $this->render($viewer, '/orders/' . $orderId, static fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('data-testid="order-action-bar"', $page);
        self::assertStringNotContainsString('/edit">Edit order</a>', $page);
        self::assertStringNotContainsString('/duplicate"', $page);
        self::assertStringNotContainsString('Request cancellation</button>', $page);
    }

    /** CSS: the bars stay at the top while scrolling (order page, client and associate pages). */
    public function testTheMainButtonsStickToTheTop(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/css/app.css');
        self::assertMatchesRegularExpression('/\.order-action-bar,\.sticky-actions\{position:sticky;top:0;/', $css);
        $views = dirname(__DIR__, 2) . '/views';
        self::assertStringContainsString('class="btn-row sticky-actions"', (string) file_get_contents($views . '/clients/show.php'));
        self::assertStringContainsString('class="page-header-row sticky-actions"', (string) file_get_contents($views . '/associates/show.php'));
    }
}
