<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ReportController;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\ReportRepository;
use App\Services\AuthService;
use App\Services\DivisionProfitService;
use App\Services\DivisionService;
use App\Services\OrderProfitabilityService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-257 Phase 5 (5 Nov 2026):
 *  - the division profit report shares the company's own costs out as
 *    overhead by revenue — only with two or more active divisions and only
 *    for "All divisions"; with one division the Order Profitability report
 *    is exactly what it was;
 *  - the Investor (view only) role works end to end: it holds only
 *    view_reports, view_cockpit and see_all_divisions, every other
 *    permission-gated route refuses it (403), the only write it can make is
 *    choosing a division in the header, and its pages open without error.
 * Node: divisionOverheadAndInvestor.test.js.
 */
final class DivisionOverheadAndInvestorTest extends DbTestCase
{
    private static ?Router $router = null;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__investor-walk__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler();
            self::$router = $router;
        }
        return self::$router;
    }

    /** @return array{0:int, 1:string} */
    private function dispatch(string $method, string $path): array
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        ob_start();
        try {
            self::router()->dispatch($method, $path);
        } finally {
            $body = (string) ob_get_clean();
        }
        return [(int) http_response_code(), $body];
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
        PermissionService::resetCache();
        DivisionService::resetCache();
    }

    protected function tearDown(): void
    {
        Database::connection()->exec("UPDATE divisions SET status = 'planned' WHERE id <> 1");
        DivisionService::resetCache();
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    public function testWithOneDivisionTheProfitReportIsExactlyAsBefore(): void
    {
        $md = $this->createTestUser('Managing Director');
        Database::connection()->prepare('UPDATE users SET division_id = NULL WHERE id = :id')->execute(['id' => $md]);
        $this->createTestOrder($this->createTestClient());
        $this->signIn($md);
        self::assertFalse(DivisionService::isMulti());
        $page = $this->render(fn () => (new ReportController())->profitability([]));
        self::assertStringNotContainsString('division-profit', $page);
        self::assertStringNotContainsString('Profit by division', $page);
        // The same page drawn from the same data without the new section is identical, byte for byte.
        $data = ReportRepository::orderProfitabilityReport(null, null, 'all');
        $before = $this->render(fn () => \App\Helpers\View::render('reports/profitability', [
            'seeIncentives' => OrderProfitabilityService::viewerSeesIncentives(),
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'filters' => ['dateFrom' => null, 'dateTo' => null, 'scope' => 'all'],
        ], 'layout/base'));
        self::assertSame($before, $page);
        self::assertNull(DivisionProfitService::summary($data['rows'], null, null, true));
    }

    public function testOverheadIsSharedByRevenueWithTwoDivisions(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'active' WHERE id = 2");
        $md = $this->createTestUser('Managing Director');
        $domStaff = $this->createTestUser('Procurement Manager');
        $pdo->prepare('UPDATE users SET division_id = NULL WHERE id = :id')->execute(['id' => $md]);
        $pdo->prepare('UPDATE users SET division_id = 2 WHERE id = :id')->execute(['id' => $domStaff]);
        $exp = $this->createTestOrder($this->createTestClient());
        $dom = $this->createTestOrder($this->createTestClient());
        $pdo->prepare('UPDATE orders SET division_id = 2 WHERE id = :id')->execute(['id' => $dom]);
        $tag = bin2hex(random_bytes(4));
        $ins = $pdo->prepare('INSERT INTO ca_expenses (zoho_expense_id, category, amount, currency_code, expense_date, division_id, order_id) VALUES (:z, :c, :a, :cur, :d, :div, :o)');
        $ins->execute(['z' => "T-{$tag}-1", 'c' => 'Rent', 'a' => '30000.00', 'cur' => 'INR', 'd' => '2031-01-15', 'div' => null, 'o' => null]);
        $ins->execute(['z' => "T-{$tag}-2", 'c' => 'Domestic office', 'a' => '5000.00', 'cur' => 'INR', 'd' => '2031-01-16', 'div' => 2, 'o' => null]);
        $ins->execute(['z' => "T-{$tag}-3", 'c' => 'Inspection', 'a' => '99999.00', 'cur' => 'INR', 'd' => '2031-01-17', 'div' => null, 'o' => $exp]);
        $ins->execute(['z' => "T-{$tag}-4", 'c' => 'Software', 'a' => '700.00', 'cur' => 'USD', 'd' => '2031-01-18', 'div' => null, 'o' => null]);
        $rows = [
            ['order_id' => $exp, 'revenue_inr' => 200000.0, 'profit_before_commission_inr' => 60000.0, 'profit_after_incentive_inr' => 50000.0],
            ['order_id' => $dom, 'revenue_inr' => 100000.0, 'profit_before_commission_inr' => 25000.0, 'profit_after_incentive_inr' => 20000.0],
        ];
        $this->signIn($md);
        $s = DivisionProfitService::summary($rows, '2031-01-01', '2031-01-31', true);
        self::assertNotNull($s);
        // ₹30,000 company overhead (the order-linked and the USD expense are left out) shared 2 : 1 by revenue.
        self::assertSame('30000.00', $s['overhead_inr']);
        $by = array_column($s['rows'], null, 'code');
        self::assertSame(['200000.00', '66.67', '50000.00', '0.00', '20000.00', '30000.00'],
            [$by['EXP']['revenue_inr'], $by['EXP']['revenue_share_pct'], $by['EXP']['profit_inr'], $by['EXP']['own_costs_inr'], $by['EXP']['overhead_share_inr'], $by['EXP']['profit_after_overhead_inr']]);
        self::assertSame(['100000.00', '33.33', '20000.00', '5000.00', '10000.00', '5000.00'],
            [$by['DOM']['revenue_inr'], $by['DOM']['revenue_share_pct'], $by['DOM']['profit_inr'], $by['DOM']['own_costs_inr'], $by['DOM']['overhead_share_inr'], $by['DOM']['profit_after_overhead_inr']]);
        self::assertSame('Profit before commission & incentive', DivisionProfitService::summary($rows, '2031-01-01', '2031-01-31', false)['profit_label']);
        self::assertSame('60000.00', array_column(DivisionProfitService::summary($rows, '2031-01-01', '2031-01-31', false)['rows'], null, 'code')['EXP']['profit_inr']);
        // No revenue: the overhead is not shared.
        self::assertTrue(DivisionProfitService::summary([], '2031-01-01', '2031-01-31', true)['overhead_unshared']);
        // A person who works in one division sees no overhead sharing (it needs every division's revenue).
        $this->signIn($domStaff);
        self::assertNull(DivisionProfitService::summary($rows, '2031-01-01', '2031-01-31', true));
        // The MD sees the section on the report.
        $this->signIn($md);
        $_GET = ['date_from' => '2031-01-01', 'date_to' => '2031-01-31'];
        $page = $this->render(fn () => (new ReportController())->profitability([]));
        self::assertStringContainsString('data-testid="division-profit"', $page);
        self::assertStringContainsString('₹30,000.00 in this period', $page);
    }

    public function testTheInvestorRoleIsViewOnlyEndToEnd(): void
    {
        $investor = $this->createTestUser('Investor (view only)');
        Database::connection()->prepare('UPDATE users SET division_id = NULL WHERE id = :id')->execute(['id' => $investor]);
        $role = (int) Database::connection()->query("SELECT id FROM roles WHERE name = 'Investor (view only)'")->fetchColumn();
        $held = Database::connection()->query("SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = {$role} AND rp.is_enabled = 1 ORDER BY p.permission_key")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['see_all_divisions', 'view_cockpit', 'view_reports'], $held, 'reports, the cockpit and every division — nothing else');

        self::router();
        $this->signIn($investor);
        $notRefused = [];
        $writesOpen = [];
        $failed = [];
        $checked = 0;
        foreach (OrderPageActionsMatchPermissionsTest::routes() as $route) {
            if ($route['permissions'] === null) {
                continue;
            }
            $path = (string) preg_replace('#\{[^}]+\}#', '999999', $route['pattern']);
            $mayPass = array_intersect($route['permissions'], $held) !== [];
            if (!$mayPass) {
                [$code] = $this->dispatch($route['method'], $path);
                if ($code !== 403) {
                    $notRefused[] = "{$route['method']} {$route['pattern']} → {$code}";
                }
                $checked++;
                continue;
            }
            if ($route['method'] === 'POST') {
                $writesOpen[] = $route['pattern'];
                continue;
            }
            if (!str_contains($route['pattern'], '{')) {
                $this->signIn($investor);
                [$code, $body] = $this->dispatch('GET', $path);
                if ($code >= 500 || str_contains($body, 'Fatal error')) {
                    $failed[] = "GET {$route['pattern']} → {$code}";
                }
            }
        }
        self::assertGreaterThan(300, $checked);
        self::assertSame([], $notRefused, 'every route outside the investor\'s permissions refuses it');
        self::assertSame(['/divisions/choose'], $writesOpen, 'the only write: choosing a division in the header');
        self::assertSame([], $failed, 'the investor\'s pages open without a server error');
        // The Internal Margin Impact Reference is MD-only and is not in the app at all: no route serves it.
        foreach (OrderPageActionsMatchPermissionsTest::routes() as $route) {
            self::assertStringNotContainsStringIgnoringCase('margin-impact', $route['pattern']);
        }
    }
}
