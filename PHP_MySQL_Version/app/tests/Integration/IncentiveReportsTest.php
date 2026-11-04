<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ReportController;
use App\Repositories\IncentiveRepository;
use App\Repositories\ReportRepository;
use App\Services\CafinExportService;
use App\Services\IncentiveReportService;
use App\Services\IncentiveService;
use App\Services\OrderProfitabilityService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-245 P5: one profit calculation everywhere — profit before
 * commission and incentive, the associate commission and team incentive
 * (estimated until the MD locks the order, then the locked figure replaces the
 * estimate — never both), the profit after; the incentive & commission
 * register, "To pay", "Owed at month end" and person statement add up to
 * their rows; the CAFIN export carries the same figures with GST and TDS apart
 * (not costs). Node twin: incentiveReports.test.js.
 */
final class IncentiveReportsTest extends DbTestCase
{
    private int $admin;
    /** @var array<string,int> */
    private array $people;
    /** @var array<string, array<string,mixed>> */
    private array $rolesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->admin;
        $this->people = [
            'sales' => $this->createTestUser('Export Executive'),
            'logistics' => $this->createTestUser('Logistics Executive'),
        ];
        IncentiveRepository::replaceUserRoles([$this->people['sales'] => ['sales'], $this->people['logistics'] => ['logistics']]);
        IncentiveRepository::setSetting('program_enabled', '1', $this->admin);
        $this->rolesBefore = IncentiveRepository::allRoles();
    }

    protected function tearDown(): void
    {
        IncentiveRepository::replaceUserRoles([]);
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    /** USD 4,500 of goods; advance ₹1,50,000 + balance ₹2,50,000 credited, both cleared (fully paid) unless $paid is false. */
    private function order(bool $paid = true): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, array_values($this->people));
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = :a, advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
            balance_amount = 2700, balance_cleared_at = :b, balance_inr_actual = :binr, balance_exchange_rate = 92.5926 WHERE order_id = :o')
            ->execute(['a' => '2026-10-10 10:00:00', 'b' => $paid ? '2026-10-25 11:00:00' : null, 'binr' => $paid ? 250000 : null, 'o' => $orderId]);
        return $orderId;
    }

    public function testTheEstimateIsReplacedByTheLockedFigureNeverAddedTwice(): void
    {
        $orderId = $this->order();
        $before = OrderProfitabilityService::computeForOrder($orderId);
        $calc = IncentiveService::calculate($orderId);
        self::assertSame(['estimated', round($calc['team_total'], 2), false], [$before['incentive_state'], $before['incentive_inr'], $before['incentives_locked']]);
        self::assertGreaterThan(0, $before['incentive_inr']);
        self::assertSame(round($before['profit_before_commission_inr'] - $before['commission_inr'] - $before['incentive_inr'], 2), $before['profit_after_incentive_inr']);
        self::assertSame($before['profit_inr'], $before['profit_before_commission_inr'], 'nothing locked yet: the locked-only profit has no incentive in it');

        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        $after = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(['locked', $before['incentive_inr'], true], [$after['incentive_state'], $after['incentive_inr'], $after['incentives_locked']]);
        self::assertSame($before['profit_before_commission_inr'], $after['profit_before_commission_inr'], 'the locked cost entry is taken back out first');
        self::assertSame($before['profit_after_incentive_inr'], $after['profit_after_incentive_inr'], 'locking changes the label, not the profit');
        self::assertSame($after['profit_after_incentive_inr'], $after['profit_inr'], 'the locked-only profit now equals the profit after');
        self::assertSame(0.0, $after['other_costs_excl_incentives_inr']);

        Database::connection()->prepare("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-11-05', paid_inr = amount_inr WHERE order_id = :o")->execute(['o' => $orderId]);
        self::assertSame('paid', OrderProfitabilityService::computeForOrder($orderId)['incentive_state']);
    }

    public function testAnUnpaidOrderWithNoProfitNeverShowsANegativeIncentive(): void
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, array_values($this->people));
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        \App\Repositories\OrderCostEntryRepository::create($orderId, 'other', 'P5 test cost', 900000.0, null, $this->admin);
        $p = OrderProfitabilityService::computeForOrder($orderId);
        self::assertLessThan(0, $p['profit_before_commission_inr']);
        self::assertSame([0.0, 'estimated'], [$p['incentive_inr'], $p['incentive_state']]);
        self::assertSame(0.0, IncentiveService::calculate($orderId)['basis']);
    }

    public function testGstAndTdsOnCommissionAreShownApartAndAreNotCosts(): void
    {
        $orderId = $this->order();
        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        $cost = OrderProfitabilityService::computeForOrder($orderId)['total_cost_inr'];
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO associates (name, based_in, currency_code, status) VALUES ('P5 Test Associate', 'india', 'INR', 'active')");
        $aid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status, paid_on, paid_inr, gst_inr, tds_inr)
            VALUES (:o, 'associate', :a, 'associate', 1000, '2026-12', 'paid', '2026-12-05', 980, 180, 20)")->execute(['o' => $orderId, 'a' => $aid]);
        self::assertSame([180.0, 20.0], CafinExportService::commissionTaxes($orderId));
        self::assertSame($cost, OrderProfitabilityService::computeForOrder($orderId)['total_cost_inr'], 'GST and TDS never become a cost');
        $row = array_combine(CafinExportService::SUMMARY_HEADERS, CafinExportService::summaryRows([$orderId])[0]);
        self::assertSame(['180.00', '20.00', 'Locked'], [$row['GST on commission (INR, not a cost)'], $row['TDS on commission (INR, a liability)'], $row['Incentive state']]);
        $p = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(number_format($p['profit_after_incentive_inr'], 2, '.', ''), $row['Profit after commission & incentive (INR)']);
        self::assertSame([], CafinExportService::lockedOnly([$this->order()]), 'an open order is not in "locked only"');
        self::assertSame([$orderId], CafinExportService::lockedOnly([$orderId]));
    }

    public function testTheProfitabilityReportSwitchAndTotalsAreTheSumOfItsOrders(): void
    {
        $open = $this->order();
        $locked = $this->order();
        self::assertNull(IncentiveService::lockIfReady($locked, $this->admin));
        $all = ReportRepository::orderProfitabilityReport(null, null, 'all');
        $ids = array_column($all['rows'], 'order_id');
        self::assertContains($open, $ids);
        self::assertContains($locked, $ids);
        $onlyLocked = ReportRepository::orderProfitabilityReport(null, null, 'locked');
        self::assertNotContains($open, array_column($onlyLocked['rows'], 'order_id'));
        self::assertContains($locked, array_column($onlyLocked['rows'], 'order_id'));
        foreach ([$all, $onlyLocked] as $data) {
            foreach (['revenue_inr', 'cost_before_incentives_inr', 'profit_before_commission_inr', 'commission_inr', 'incentive_inr', 'profit_after_incentive_inr'] as $k) {
                self::assertSame(round(array_sum(array_column($data['rows'], $k)), 2), $data['totals'][$k], $k);
            }
        }
        $csv = ReportController::profitabilityCsvRows($all['rows']);
        self::assertSame(ReportController::PROFITABILITY_HEADERS, array_keys($csv[0]));
    }

    public function testTheRegisterToPayOwedAndStatementAddUpToTheirRows(): void
    {
        $open = $this->order();
        $locked = $this->order();
        self::assertNull(IncentiveService::lockIfReady($locked, $this->admin));

        $rows = IncentiveReportService::rows();
        $lockedRows = array_values(array_filter($rows, static fn (array $r): bool => $r['order_id'] === $locked));
        $openRows = array_values(array_filter($rows, static fn (array $r): bool => $r['order_id'] === $open));
        self::assertSame(['locked'], array_values(array_unique(array_column($lockedRows, 'state'))));
        self::assertSame(['ready'], array_values(array_unique(array_column($openRows, 'state'))), 'fully paid, not locked: ready (estimated)');
        self::assertEqualsWithDelta((float) IncentiveRepository::forOrder($locked)['team_total_inr'], array_sum(array_column($lockedRows, 'amount_inr')), 0.001);
        self::assertEqualsWithDelta(IncentiveService::calculate($open)['team_total'], array_sum(array_column($openRows, 'amount_inr')), 0.001);

        $t = IncentiveReportService::totals($rows);
        self::assertSame(round(array_sum(array_column($rows, 'amount_inr')), 2), $t['amount_inr']);
        self::assertSame(count($rows), $t['count']);
        self::assertSame(IncentiveReportService::HEADERS, array_keys(IncentiveReportService::csvRows($rows)[0]));

        $onlyLocked = IncentiveReportService::rows(['state' => 'locked']);
        self::assertSame(['locked'], array_values(array_unique(array_column($onlyLocked, 'state'))));
        $mine = IncentiveReportService::statement($this->people['sales'], null);
        self::assertNotEmpty($mine);
        self::assertSame([$this->people['sales']], array_values(array_unique(array_column($mine, 'user_id'))));

        $groups = IncentiveReportService::toPay();
        $grouped = array_merge($groups['this']['rows'], $groups['next']['rows']);
        self::assertNotEmpty(array_filter($grouped, static fn (array $r): bool => $r['order_id'] === $locked), 'a locked payout falls in this or next month\'s run');
        self::assertNotEmpty(array_filter($groups['open']['rows'], static fn (array $r): bool => $r['order_id'] === $open));
        foreach ($groups as $g) {
            self::assertSame(round(array_sum(array_column($g['rows'], 'amount_inr')), 2), $g['totals']['amount_inr']);
        }

        $owedToday = IncentiveReportService::owedAt(date('Y-m-d'));
        self::assertNotEmpty(array_filter($owedToday, static fn (array $r): bool => $r['order_id'] === $locked));
        $owedLongAgo = IncentiveReportService::owedAt('2020-01-31');
        self::assertEmpty(array_filter($owedLongAgo, static fn (array $r): bool => in_array($r['order_id'], [$locked, $open], true)), 'nothing was locked or ready back then');
    }

    public function testOnlyManagersSeeTheReportsStaffAndClientsNever(): void
    {
        $gate = ['manage_incentives', 'pay_incentives', 'view_price_buildup'];
        $can = static function (int $userId) use ($gate): bool {
            $u = \App\Repositories\UserRepository::findById($userId);
            foreach ($gate as $perm) {
                if (PermissionService::can($userId, $u['role_id'] !== null ? (int) $u['role_id'] : null, $perm)) {
                    return true;
                }
            }
            return false;
        };
        self::assertTrue($can($this->admin));
        self::assertFalse($can($this->people['sales']), 'an Export Executive never sees others\' amounts');
        self::assertFalse($can($this->people['logistics']));

        // Whoever sees profit but not incentives (manage_order_financials only) sees profit BEFORE them.
        $_SESSION['_auth_user_id'] = $this->people['sales'];
        self::assertFalse(OrderProfitabilityService::viewerSeesIncentives());
        $_SESSION['_auth_user_id'] = $this->admin;
        self::assertTrue(OrderProfitabilityService::viewerSeesIncentives());
        self::assertSame([], array_intersect(ReportController::INCENTIVE_COLUMNS, array_values(array_diff(ReportController::PROFITABILITY_HEADERS, ReportController::INCENTIVE_COLUMNS))));
    }
}
