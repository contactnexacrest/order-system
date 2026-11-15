<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ReportController;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\DepartmentRepository;
use App\Repositories\ReportRepository;
use App\Services\ActiveDepartmentService;
use App\Services\AuthService;
use App\Services\Navigation;
use App\Services\PermissionService;
use App\Services\ReportAccess;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-379 (15 Nov 2026): report access per role — spec docs/decisions/D-379-report-access-spec.md §6.
 *
 *  - the seeded default levels (spec §4) and the permission manage_report_access (MD, ED; not Admin);
 *  - Own / Department / All limit the rows of Aggregate, Sales performance, Ageing, Find an order and
 *    Disputes (the CSV of each is built from the same rows, and its URL is refused like the page);
 *  - Off answers 403 by direct URL (page and CSV); the base permission (money too) is still needed;
 *  - an extra only raises the level (a lower one is refused); the highest level over memberships counts;
 *  - Admin sees Management (and Find an order) only; the Investor cannot open the per-client report;
 *  - the Reports page shows only the allowed sections and the Own / Department banner;
 *  - the menu shows Sales targets to MD / ED and the Sales Director only; the team view has no money / Edit;
 *  - "See Reports as this role" draws the page as the role; Reset to defaults.
 *
 * Node twin: NODE_MySQL_Version/app/tests/integration/reportAccess.test.js
 */
final class ReportAccessTest extends DbTestCase
{
    private static ?Router $router = null;

    /** @var list<int> */
    private array $departments = [];
    /** @var list<int> */
    private array $designations = [];
    /** @var list<int> */
    private array $roles = [];

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__report-access-build__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler();
            self::$router = $router;
        }
        return self::$router;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::router();
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach ($this->designations as $id) {
            $pdo->prepare('DELETE FROM user_department_memberships WHERE designation_id = :d')->execute(['d' => $id]);
            $pdo->prepare('DELETE FROM department_designations WHERE id = :d')->execute(['d' => $id]);
        }
        foreach ($this->departments as $id) {
            $pdo->prepare('DELETE FROM order_department_owners WHERE department_id = :d')->execute(['d' => $id]);
            $pdo->prepare('DELETE FROM departments WHERE id = :d')->execute(['d' => $id]);
        }
        foreach ($this->roles as $id) {
            $pdo->prepare('DELETE FROM role_report_access WHERE role_id = :r')->execute(['r' => $id]);
            $pdo->prepare('UPDATE users SET role_id = NULL WHERE role_id = :r')->execute(['r' => $id]);
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :r')->execute(['r' => $id]);
            $pdo->prepare('DELETE FROM roles WHERE id = :r')->execute(['r' => $id]);
        }
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        PermissionService::resetCache();
        ActiveDepartmentService::resetCache();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
        ActiveDepartmentService::resetCache();
        PermissionService::resetCache();
        ActiveDepartmentService::afterLogin($userId); // a single membership is worked as at once
        PermissionService::resetCache();
    }

    /** @return array{code: int, body: string, flash: ?string, type: ?string} one request through the real Router */
    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_GET = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $flash = Flash::pull()[0] ?? null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null, 'type' => $flash['type'] ?? null];
    }

    private function roleId(string $name): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM roles WHERE name = :n');
        $stmt->execute(['n' => $name]);
        return (int) $stmt->fetchColumn();
    }

    /** A fresh department of its own (so its members are only this test's people), with designations for these roles. */
    private function department(array $roleNames): array
    {
        $code = 'RA' . strtoupper(bin2hex(random_bytes(3)));
        $dept = DepartmentRepository::createDepartment($code, "Report Access {$code}", '#2f6fb3');
        $this->departments[] = $dept;
        $out = ['id' => $dept];
        foreach ($roleNames as $name) {
            $out[$name] = DepartmentRepository::createDesignation($dept, "{$name} {$code}", 'executive', $this->roleId($name));
            $this->designations[] = $out[$name];
        }
        return $out;
    }

    private function member(int $userId, int $designationId): void
    {
        DepartmentRepository::addMembership($userId, $designationId, date('Y-m-d', strtotime('-1 day')), $userId);
    }

    private function customRole(): int
    {
        $name = 'PHPUnit D-379 Role ' . bin2hex(random_bytes(3));
        Database::connection()->prepare("INSERT INTO roles (name, description, is_system_role) VALUES (:n, 'Test only', 0)")->execute(['n' => $name]);
        $id = (int) Database::connection()->lastInsertId();
        $this->roles[] = $id;
        return $id;
    }

    private function grant(int $roleId, array $permissionKeys): void
    {
        $in = implode(',', array_map(static fn (string $k): string => Database::connection()->quote($k), $permissionKeys));
        Database::connection()->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled) SELECT {$roleId}, id, 1 FROM permissions WHERE permission_key IN ({$in})");
    }

    private function userWithRole(int $roleId): int
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
                       VALUES ('PHPUnit Test User', :e, NULL, 'x', :r, 1, 0, 0)")
            ->execute(['e' => 'phpunit-ra-' . bin2hex(random_bytes(4)) . '@nexacrest.test', 'r' => $roleId]);
        return (int) $pdo->lastInsertId();
    }

    private function outstandingAdvance(int $orderId): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = 100, advance_cleared_at = NULL WHERE order_id = :o')->execute(['o' => $orderId]);
    }

    private function dispute(int $orderId): void
    {
        Database::connection()->prepare("INSERT INTO disputes (order_id, notice_date, description, status) VALUES (:o, CURDATE(), 'PHPUnit D-379 dispute', 'Open')")
            ->execute(['o' => $orderId]);
    }

    private function reference(int $orderId): string
    {
        return (string) Database::connection()->query('SELECT order_reference FROM orders WHERE id = ' . $orderId)->fetchColumn();
    }

    /**
     * Orders around one Export Executive (E) of a fresh department, a Sales/Export Manager (M) of the same
     * department and a Logistics Executive (X) of another:
     *   mine   — E is an assignee;      credit — E is the salesperson credited (no assignee);
     *   owned  — E is the department owner;  other — only X is assigned.
     *
     * @return array<string,int>
     */
    private function scenario(): array
    {
        $sales = $this->department(['Export Executive', 'Sales/Export Manager']);
        $logistics = $this->department(['Logistics Executive']);
        $e = $this->createTestUser('Export Executive');
        $m = $this->createTestUser('Sales/Export Manager');
        $x = $this->createTestUser('Logistics Executive');
        $this->member($e, $sales['Export Executive']);
        $this->member($m, $sales['Sales/Export Manager']);
        $this->member($x, $logistics['Logistics Executive']);

        $client = $this->createTestClient();
        $mine = $this->createTestOrder($client, 'FOB', 'Standard — New Buyer', 'USD', null, null, $e);
        $credit = $this->createTestOrder($client);
        Database::connection()->prepare('UPDATE orders SET sales_credit_user_id = :u WHERE id = :o')->execute(['u' => $e, 'o' => $credit]);
        $owned = $this->createTestOrder($client);
        Database::connection()->prepare('INSERT INTO order_department_owners (order_id, department_id, user_id, assigned_by) VALUES (:o, :d, :u, :b)')
            ->execute(['o' => $owned, 'd' => $sales['id'], 'u' => $e, 'b' => $e]);
        $other = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $x);
        foreach ([$mine, $credit, $owned, $other] as $o) {
            $this->outstandingAdvance($o);
            $this->dispute($o);
        }
        return compact('e', 'm', 'x', 'mine', 'credit', 'owned', 'other') + ['client' => $client];
    }

    // ---------------------------------------------------------------- defaults

    public function testTheDefaultLevelsAreSeededForEveryRoleOfTheTable(): void
    {
        $pdo = Database::connection();
        $spot = [
            ['Export Executive', 'aggregate', 'own'], ['Sales/Export Manager', 'ageing', 'department'], ['Sales Director', 'sales_targets', 'department'],
            ['Admin', 'audit_log', 'all'], ['Investor (view only)', 'cockpit', 'all'], ['Managing Director', 'cafin', 'all'],
            ['Executive Director', 'incentives', 'all'], ['Logistics Manager', 'freight_cost', 'department'], ['Viewer / Auditor', 'audit_log', 'all'],
            ['Procurement Executive', 'supplier_performance', 'own'], ['Export Compliance Executive', 'missing_ebrc', 'all'],
        ];
        foreach ($spot as [$role, $key, $level]) {
            self::assertSame($level, ReportAccess::roleLevel($this->roleId($role), $key), "{$role} / {$key}");
        }
        self::assertSame('off', ReportAccess::roleLevel($this->roleId('Admin'), 'aggregate'), 'no row = off');
        self::assertSame('off', ReportAccess::roleLevel($this->roleId('Investor (view only)'), 'per_client'));

        // Every role of the table holds exactly its defaults (spec §4).
        foreach (array_keys(ReportAccess::DEFAULTS) as $group) {
            $names = $group === 'MD' ? ReportAccess::MD_ROLE_NAMES : [$group];
            foreach ($names as $name) {
                $roleId = $this->roleId($name);
                if ($roleId === 0) {
                    continue; // "Founder & …" designations are not roles on a fresh install
                }
                self::assertSame(ReportAccess::defaultsForRoleName($name), ReportAccess::roleLevels($roleId), $name);
            }
        }
        self::assertCount(24, ReportAccess::REPORTS);
        self::assertSame(['sales', 'payments', 'disputes', 'freight', 'people', 'finance', 'management'], array_keys(ReportAccess::SECTIONS));

        $holders = $pdo->query("SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                                 WHERE p.permission_key = 'manage_report_access' ORDER BY r.name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('Managing Director', $holders);
        self::assertContains('Executive Director', $holders);
        self::assertNotContains('Admin', $holders, 'Admin does not get it by default');
    }

    // ---------------------------------------------------------------- scope

    public function testOwnDepartmentAndAllLimitTheRowsOfEveryScopedReport(): void
    {
        $s = $this->scenario();
        $ownSet = [$s['mine'], $s['credit'], $s['owned']];
        $ids = static fn (array $rows, string $key): array => array_values(array_unique(array_map('intval', array_column($rows, $key))));
        $today = date('Y-m-d');

        // Own (Export Executive): assignee, department owner or salesperson credited — nothing else.
        $this->signIn($s['e']);
        self::assertSame('own', ReportAccess::level('aggregate'));
        $aggregate = $ids(ReportRepository::aggregate(null, null, null, null, null), 'id');
        sort($aggregate);
        $expected = $ownSet;
        sort($expected);
        self::assertSame($expected, $aggregate, 'Aggregate (and its CSV, built from these rows): own orders only');
        self::assertSame(3, ReportRepository::salesPerformanceReport($today, $today)['current']['orders_created'], 'Sales performance counts own orders only');
        $ageing = $ids(ReportRepository::ageingReport()['rows'], 'order_id');
        self::assertEqualsCanonicalizing($ownSet, $ageing, 'Ageing');
        $disputes = $ids(ReportRepository::disputesReport(null, null)['rows'], 'order_id');
        self::assertEqualsCanonicalizing($ownSet, $disputes, 'Disputes');
        self::assertNotContains($s['other'], $ids(ReportRepository::searchOrders($this->reference($s['other'])), 'id'), 'Find an order: another person\'s order is not found');
        self::assertContains($s['mine'], $ids(ReportRepository::searchOrders($this->reference($s['mine'])), 'id'));

        // Department (Sales/Export Manager): orders whose assignee or department owner is in their department, plus own.
        $this->signIn($s['m']);
        self::assertSame('department', ReportAccess::level('aggregate'));
        foreach ([
            'aggregate' => $ids(ReportRepository::aggregate(null, null, null, null, null), 'id'),
            'ageing' => $ids(ReportRepository::ageingReport()['rows'], 'order_id'),
            'disputes' => $ids(ReportRepository::disputesReport(null, null)['rows'], 'order_id'),
        ] as $report => $rows) {
            self::assertContains($s['mine'], $rows, "{$report}: the colleague's assigned order");
            self::assertContains($s['owned'], $rows, "{$report}: the colleague's department-owned order");
            self::assertNotContains($s['other'], $rows, "{$report}: another department's order");
        }
        self::assertGreaterThanOrEqual(2, ReportRepository::salesPerformanceReport($today, $today)['current']['orders_created']);
        self::assertNotContains($s['other'], $ids(ReportRepository::searchOrders($this->reference($s['other'])), 'id'));

        // All (Viewer / Auditor): every order.
        $this->signIn($this->createTestUser('Viewer / Auditor'));
        self::assertSame('all', ReportAccess::level('aggregate'));
        self::assertContains($s['other'], $ids(ReportRepository::aggregate(null, null, null, null, null), 'id'));
        self::assertContains($s['other'], $ids(ReportRepository::ageingReport()['rows'], 'order_id'));
        self::assertContains($s['other'], $ids(ReportRepository::disputesReport(null, null)['rows'], 'order_id'));
        self::assertContains($s['other'], $ids(ReportRepository::searchOrders($this->reference($s['other'])), 'id'));

        // Through the routes: the pages show the scoped rows; the CSV is the same route.
        $this->signIn($s['e']);
        $page = $this->call('GET', '/reports/aggregate');
        self::assertSame(200, $page['code']);
        self::assertStringContainsString($this->reference($s['mine']), $page['body']);
        self::assertStringNotContainsString($this->reference($s['other']), $page['body']);
        $search = $this->call('GET', '/reports?q=' . urlencode($this->reference($s['other'])));
        self::assertStringContainsString('No orders match', $search['body']);
    }

    public function testOffAnswers403ByDirectUrlForThePageAndItsCsv(): void
    {
        $this->signIn($this->createTestUser('Export Executive'));
        foreach (['/reports/freight-cost', '/reports/freight-cost?format=csv', '/cockpit', '/audit-log', '/reports/staff', '/ca', '/ca/cafin-export',
                  '/reports/order-profitability?format=csv', '/reports/incentives?format=csv', '/sales-targets'] as $path) {
            self::assertSame(403, $this->call('GET', $path)['code'], $path);
        }
        // Admin holds every permission of these reports, but its level is Off.
        $this->signIn($this->createTestUser('Admin'));
        foreach (['/reports/aggregate', '/reports/aggregate?format=csv', '/reports/payments', '/reports/disputes?format=csv', '/ca'] as $path) {
            $r = $this->call('GET', $path);
            self::assertSame(403, $r['code'], $path);
            self::assertStringContainsString('403 — Not permitted', $r['body']);
        }
        self::assertSame(200, $this->call('GET', '/reports/queues')['code'], 'Admin: Operations queues is All');
    }

    public function testTheBasePermissionIsStillNeeded(): void
    {
        // A role with All for Freight cost but without view_logistics_costs, and for Aggregate without the selling prices.
        $role = $this->customRole();
        $this->grant($role, ['view_reports']);
        ReportAccess::saveRoleLevels($role, ['freight_cost' => 'all', 'aggregate' => 'all', 'queues' => 'all'], 1, 'PHPUnit D-379 base permission');
        $user = $this->userWithRole($role);
        $this->signIn($user);
        self::assertSame('off', ReportAccess::level('freight_cost'));
        self::assertSame('off', ReportAccess::level('aggregate'), 'money permissions are a ceiling');
        self::assertSame('all', ReportAccess::level('queues'));
        self::assertSame(403, $this->call('GET', '/reports/freight-cost')['code']);
        self::assertSame(403, $this->call('GET', '/reports/aggregate')['code']);
        self::assertSame(200, $this->call('GET', '/reports/queues')['code']);

        // An extra cannot get around it either.
        $exec = $this->createTestUser('Export Executive');
        self::assertNull(ReportAccess::addUserExtra($exec, $this->roleId('Export Executive'), 'supplier_performance', 'all', 'PHPUnit D-379 extra without the permission', 1));
        $this->signIn($exec);
        self::assertSame('off', ReportAccess::level('supplier_performance'), 'no view_purchase_prices');

        // The Reports tab says what is missing.
        $this->signIn($this->createTestUser('Managing Director'));
        $tab = $this->call('GET', '/admin/roles/' . $role . '/reports');
        self::assertSame(200, $tab['code']);
        self::assertStringContainsString('missing for this role: view_logistics_costs', $tab['body']);
        self::assertStringContainsString('needs: view_reports, selling prices', $tab['body']);
    }

    public function testInvestorCannotOpenThePerClientReport(): void
    {
        $client = $this->createTestClient();
        $this->signIn($this->createTestUser('Investor (view only)'));
        self::assertSame('off', ReportAccess::level('per_client'));
        self::assertSame(403, $this->call('GET', '/reports/client/' . $client)['code']);
        self::assertSame(403, $this->call('GET', '/reports/client/' . $client . '?format=csv')['code']);
        self::assertSame(403, $this->call('GET', '/reports/performance')['code'], 'performance is All for the Investor, but it needs selling prices');
        self::assertSame(200, $this->call('GET', '/reports/conversion')['code'], 'a report without amounts opens');
    }

    // ---------------------------------------------------------------- extras and memberships

    public function testAnExtraOnlyRaisesTheLevel(): void
    {
        $exec = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $this->signIn($md);
        $lower = $this->call('POST', '/users/' . $exec . '/report-extras', ['report_key' => 'aggregate', 'level' => 'own', 'reason' => 'PHPUnit D-379 not higher']);
        self::assertSame('error', $lower['type']);
        self::assertStringContainsString('An extra can only raise the level', (string) $lower['flash']);
        self::assertSame('error', $this->call('POST', '/users/' . $exec . '/report-extras', ['report_key' => 'aggregate', 'level' => 'department', 'reason' => 'short'])['type'], 'a reason of 10 characters');
        self::assertSame([], ReportAccess::userExtras($exec));

        $ok = $this->call('POST', '/users/' . $exec . '/report-extras', ['report_key' => 'aggregate', 'level' => 'department', 'reason' => 'PHPUnit D-379 covers the manager']);
        self::assertSame('success', $ok['type']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'USER_REPORT_EXTRA_ADDED' AND entity_id = {$exec}")->fetchColumn());
        $edit = $this->call('GET', '/users/' . $exec . '/edit');
        self::assertStringContainsString('id="report-extras"', $edit['body']);
        self::assertStringContainsString('PHPUnit D-379 covers the manager', $edit['body']);

        $this->signIn($exec);
        self::assertSame('department', ReportAccess::level('aggregate'));
        self::assertSame('own', ReportAccess::level('ageing'), 'only the report of the extra');

        $this->signIn($md);
        self::assertSame('success', $this->call('POST', '/users/' . $exec . '/report-extras/remove', ['report_key' => 'aggregate'])['type']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'USER_REPORT_EXTRA_REMOVED' AND entity_id = {$exec}")->fetchColumn());
        $this->signIn($exec);
        self::assertSame('own', ReportAccess::level('aggregate'));

        // Only manage_report_access holders manage extras (Admin does not hold it by default).
        $this->signIn($this->createTestUser('Admin'));
        self::assertSame(403, $this->call('POST', '/users/' . $exec . '/report-extras', ['report_key' => 'aggregate', 'level' => 'all', 'reason' => 'PHPUnit D-379 admin try'])['code']);
        self::assertStringNotContainsString('id="report-extras"', $this->call('GET', '/users/' . $exec . '/edit')['body']);
    }

    public function testTheHighestLevelOverEveryMembershipCounts(): void
    {
        $dept = $this->department(['Sales/Export Manager']);
        $user = $this->createTestUser('Export Executive'); // aggregate: own
        $this->member($user, $dept['Sales/Export Manager']); // aggregate: department
        $this->signIn($user);
        self::assertSame('department', ReportAccess::level('aggregate'));
        self::assertSame('department', ReportAccess::level('queues'), 'Export Executive is Off for queues; the manager role gives Department');
    }

    // ---------------------------------------------------------------- Reports page and menu

    public function testTheReportsPageShowsOnlyTheAllowedSectionsAndTheBanner(): void
    {
        $sectionsOf = static function (string $html): array {
            preg_match_all('#data-report-section="([a-z]+)"#', $html, $m);
            return $m[1];
        };
        $reportsOf = static function (string $html): array {
            preg_match_all('#data-report="([a-z_]+)"#', $html, $m);
            return $m[1];
        };

        $this->signIn($this->createTestUser('Export Executive'));
        $page = $this->call('GET', '/reports');
        self::assertSame(200, $page['code']);
        self::assertSame(['sales', 'payments', 'disputes'], $sectionsOf($page['body']));
        self::assertStringContainsString(ReportAccess::BANNER_OWN, $page['body']);
        self::assertNotContains('freight_cost', $reportsOf($page['body']));

        $this->signIn($this->createTestUser('Sales/Export Manager'));
        $page = $this->call('GET', '/reports');
        self::assertStringContainsString(htmlspecialchars(ReportAccess::BANNER_DEPARTMENT), $page['body']);

        $this->signIn($this->createTestUser('Managing Director'));
        $page = $this->call('GET', '/reports');
        self::assertSame(array_keys(ReportAccess::SECTIONS), $sectionsOf($page['body']));
        self::assertStringNotContainsString('id="reports-scope-banner"', $page['body']);

        // A role with no report at all: /reports itself is refused (the gate is "at least one report").
        $role = $this->customRole();
        $this->grant($role, ['view_reports']);
        $this->signIn($this->userWithRole($role));
        self::assertSame(403, $this->call('GET', '/reports')['code']);
    }

    /** Spec §6 "Admin sees Management only": Management, plus the order lookup the defaults give Admin (Find an order = All). */
    public function testAdminSeesManagementOnly(): void
    {
        $this->signIn($this->createTestUser('Admin'));
        $page = $this->call('GET', '/reports');
        preg_match_all('#data-report="([a-z_]+)"#', $page['body'], $m);
        self::assertEqualsCanonicalizing(['order_search', 'cockpit', 'queues', 'audit_log'], $m[1]);
        foreach (['aggregate', 'payments', 'ageing', 'profitability', 'cafin', 'ca_reports', 'staff_productivity', 'freight_cost'] as $key) {
            self::assertSame('off', ReportAccess::level($key), $key);
        }
    }

    public function testTheMenuShowsSalesTargetsToMdEdAndTheSalesDirectorOnly(): void
    {
        $expect = ['Managing Director' => true, 'Executive Director' => true, 'Sales Director' => true,
            'Sales/Export Manager' => false, 'Export Executive' => false, 'Admin' => false, 'Finance Director' => false];
        foreach ($expect as $role => $shown) {
            $user = $this->createTestUser($role);
            $this->signIn($user);
            $roleId = $this->roleId($role);
            $built = Navigation::build(static fn (string $p): bool => PermissionService::can($user, $roleId, $p), ReportAccess::navFlags(), [], '/', []);
            self::assertSame($shown, in_array('sales_targets', array_column($built['sidebar'], 'key'), true), $role);
        }
        // The menu's Reports entries follow report access too: Freight cost for Logistics, not for Sales.
        $logistics = $this->createTestUser('Logistics Manager');
        $this->signIn($logistics);
        self::assertTrue(\App\Helpers\PermittedLinks::currentUserMayOpen('/reports/freight-cost'));
        $this->signIn($this->createTestUser('Export Executive'));
        self::assertFalse(\App\Helpers\PermittedLinks::currentUserMayOpen('/reports/freight-cost'));
        self::assertTrue(\App\Helpers\PermittedLinks::currentUserMayOpen('/reports'));
    }

    public function testTheTeamViewHasNoMoneyAndNoEdit(): void
    {
        $dept = $this->department(['Sales Director', 'Export Executive']);
        $director = $this->createTestUser('Sales Director');
        $this->member($director, $dept['Sales Director']);
        $this->signIn($director);
        self::assertSame('department', ReportAccess::level('sales_targets'));
        $r = $this->call('GET', '/sales-targets');
        self::assertSame(200, $r['code']);
        $start = strpos($r['body'], 'id="sales-targets-team"');
        self::assertNotFalse($start, 'the read-only team view');
        $end = strpos($r['body'], '</table>', $start);
        $view = substr($r['body'], $start, ($end !== false ? $end : strlen($r['body'])) - $start);
        self::assertStringContainsString('id="target-team-view"', $view);
        foreach (['>Edit<', '₹', 'target-calculator', '/sales-targets/settings', 'Associates', 'Multiple'] as $absent) {
            self::assertStringNotContainsString($absent, $view, $absent);
        }
        self::assertSame(403, $this->call('GET', '/sales-targets/settings')['code']);
        self::assertSame(403, $this->call('POST', '/sales-targets/person/' . $director, ['containers' => '5', 'reason' => 'PHPUnit D-379 not allowed'])['code']);

        // MD: the full page (All).
        $this->signIn($this->createTestUser('Managing Director'));
        self::assertStringContainsString('id="target-team"', $this->call('GET', '/sales-targets')['body']);
    }

    // ---------------------------------------------------------------- Reports tab, preview, reset

    public function testPreviewRendersTheReportsPageAsTheRole(): void
    {
        $this->signIn($this->createTestUser('Managing Director'));
        $investor = $this->roleId('Investor (view only)');
        $r = $this->call('GET', '/admin/roles/' . $investor . '/reports/preview');
        self::assertSame(200, $r['code']);
        self::assertStringContainsString('Preview as Investor (view only)', $r['body']);
        $start = (int) strpos($r['body'], 'id="reports-page"');
        $end = strpos($r['body'], '</main>', $start);
        $page = substr($r['body'], $start, ($end !== false ? $end : strlen($r['body'])) - $start);
        preg_match_all('#data-report="([a-z_]+)"#', $page, $m);
        self::assertEqualsCanonicalizing(['conversion', 'cockpit', 'queues'], $m[1], 'Investor: performance and trends need selling prices it does not have');
        self::assertStringNotContainsString('href="/reports/queues"', $page, 'read-only: no links');

        $this->signIn($this->createTestUser('Admin'));
        self::assertSame(403, $this->call('GET', '/admin/roles/' . $investor . '/reports/preview')['code'], 'manage_report_access only');
        self::assertSame(403, $this->call('GET', '/admin/roles/' . $investor . '/reports')['code']);
    }

    public function testTheReportsTabSavesWithAReasonAndResetsToDefaults(): void
    {
        $role = $this->customRole();
        $this->signIn($this->createTestUser('Managing Director'));
        $tab = $this->call('GET', '/admin/roles/' . $role . '/reports');
        self::assertSame(200, $tab['code']);
        self::assertStringContainsString('name="levels[aggregate]" value="off" checked', $tab['body']);
        self::assertStringContainsString('See Reports as this role', $tab['body']);
        self::assertStringContainsString('Reset to defaults', $tab['body']);

        $noReason = $this->call('POST', '/admin/roles/' . $role . '/reports', ['levels' => ['aggregate' => 'all']]);
        self::assertSame('error', $noReason['type']);
        self::assertSame('off', ReportAccess::roleLevel($role, 'aggregate'));

        $saved = $this->call('POST', '/admin/roles/' . $role . '/reports', ['levels' => ['aggregate' => 'all', 'payments' => 'own', 'queues' => 'off', 'bogus' => 'all'], 'reason' => 'PHPUnit D-379 tab save']);
        self::assertSame('success', $saved['type']);
        PermissionService::resetCache();
        self::assertSame('all', ReportAccess::roleLevel($role, 'aggregate'));
        self::assertSame('own', ReportAccess::roleLevel($role, 'payments'));
        $audit = Database::connection()->query("SELECT field_name, old_value, new_value, reason FROM audit_log WHERE action_type = 'REPORT_ACCESS_CHANGED' AND entity_id = {$role} ORDER BY field_name")->fetchAll();
        self::assertSame([['aggregate', 'off', 'all', 'PHPUnit D-379 tab save'], ['payments', 'off', 'own', 'PHPUnit D-379 tab save']],
            array_map(static fn (array $a): array => [$a['field_name'], $a['old_value'], $a['new_value'], $a['reason']], $audit), 'one row per changed report, old → new');

        self::assertSame('error', $this->call('POST', '/admin/roles/' . $role . '/reports/reset', [])['type'], 'reset needs a reason');
        self::assertSame('success', $this->call('POST', '/admin/roles/' . $role . '/reports/reset', ['reason' => 'PHPUnit D-379 reset'])['type']);
        PermissionService::resetCache();
        self::assertSame(array_fill_keys(array_keys(ReportAccess::REPORTS), 'off'), ReportAccess::roleLevels($role), 'a role not in the defaults goes back to Off');

        // A role of the defaults resets to its seeded levels (checked without changing the seeded role).
        self::assertSame('own', ReportAccess::defaultsForRoleName('Export Executive')['aggregate']);
        self::assertSame(ReportAccess::defaultsForRoleName('Managing Director'), ReportAccess::defaultsForRoleName('Founder & Executive Director'));
    }

    /** The Reports page controller data also works without a request (preview): nothing is queried for a previewed role. */
    public function testIndexDataForAPreviewQueriesNothing(): void
    {
        $this->signIn($this->createTestUser('Managing Director'));
        ReportAccess::previewAsRole($this->roleId('Export Executive'));
        try {
            $data = ReportController::indexData('anything', ['id' => $this->roleId('Export Executive'), 'name' => 'Export Executive']);
        } finally {
            ReportAccess::previewAsRole(null);
        }
        self::assertSame([], $data['searchResults']);
        self::assertSame([], $data['clients']);
        self::assertSame(ReportAccess::BANNER_OWN, $data['banner']);
    }
}
