<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Flash;
use App\Repositories\IncentiveRepository;
use App\Services\IncentiveReportService;
use App\Services\IncentiveService;
use App\Services\OrderProfitabilityService;
use App\Tests\Support\DbTestCase;

/**
 * D-259 (owner approval 6 Nov 2026, mockups mock12): team incentive eligibility and caps.
 * A person not eligible gets nothing and the company keeps their share; a per-order or
 * monthly cap per role limits a person's amount and the company keeps the rest; nobody
 * else's amount goes up; locked orders never change; only the MD changes eligibility
 * and caps. The Node suite has the same test (incentiveEligibility.test.js).
 */
final class IncentiveEligibilityTest extends DbTestCase
{
    private int $admin;
    private int $md;
    /** @var array<string,int> role key => person */
    private array $people = [];
    /** @var array<string, array<string,mixed>> */
    private array $rolesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
        $this->md = $this->createTestUser('Managing Director');
        $_SESSION['_auth_user_id'] = $this->admin;
        $this->rolesBefore = IncentiveRepository::allRoles();
        $map = [];
        foreach (['sales', 'sales_manager', 'export', 'export_compliance', 'marketing', 'logistics', 'accounts'] as $role) {
            $this->people[$role] = $this->createTestUser('Export Executive');
            $map[$this->people[$role]] = [$role];
        }
        IncentiveRepository::replaceUserRoles($map);
        IncentiveRepository::setSetting('program_enabled', '1', $this->admin);
    }

    protected function tearDown(): void
    {
        IncentiveRepository::saveCaps(array_map(static fn (): array => ['order' => null, 'month' => null], IncentiveRepository::allRoles()), $this->admin);
        IncentiveRepository::replaceUserRoles([]);
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        IncentiveRepository::saveRoles(array_values($this->rolesBefore), $this->admin);
        Database::connection()->exec('UPDATE users SET incentive_eligible = NULL WHERE incentive_eligible IS NOT NULL');
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    /** A fully paid USD order (₹4,00,000 received) whose team incentive the MD fixed at ₹60,000. */
    private function order(): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, array_values($this->people));
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = '2026-10-10 10:00:00', advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
            balance_amount = 2700, balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = 250000, balance_exchange_rate = 92.5926 WHERE order_id = :o")->execute(['o' => $orderId]);
        IncentiveRepository::saveOrder($orderId, ['incentive_mode' => 'fixed', 'incentive_value' => 60000, 'incentive_reason' => 'Worked example of the mockup'], $this->admin);
        return $orderId;
    }

    /** The mockup's worked example: Sales capped per order, Sales Manager not eligible, Logistics at the monthly cap. */
    private function workedExampleSetUp(): void
    {
        IncentiveRepository::saveCaps(['sales' => ['order' => 15000.0, 'month' => 40000.0], 'logistics' => ['order' => null, 'month' => 20000.0]], $this->admin);
        Database::connection()->prepare('UPDATE users SET incentive_eligible = 0 WHERE id = :u')->execute(['u' => $this->people['sales_manager']]);
        // The Logistics person already has ₹14,000 locked this month on another order.
        $other = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        IncentiveRepository::addPayout(['order_id' => $other, 'payee_kind' => 'staff', 'user_id' => $this->people['logistics'], 'role' => 'logistics', 'amount_inr' => 14000,
            'incentive_month' => IncentiveService::estimateMonth(), 'payout_run_on' => IncentiveService::estimateMonth() . '-05', 'locked_on' => date('Y-m-d')]);
    }

    public function testTheWorkedExampleKeepsTwelveThousandAndPaysFortyEight(): void
    {
        $orderId = $this->order();
        $this->workedExampleSetUp();
        $calc = IncentiveService::calculate($orderId);
        $byRole = [];
        foreach ($calc['lines'] as $l) {
            $byRole[$l['role']] = [$l['by_share'], $l['gets'], $l['kept'], $l['reason']];
        }
        self::assertSame([18000.0, 15000.0, 3000.0, 'cap_order'], $byRole['sales']);
        self::assertSame([6000.0, 0.0, 6000.0, 'not_eligible'], $byRole['sales_manager']);
        self::assertSame([9000.0, 6000.0, 3000.0, 'cap_month'], $byRole['logistics']);
        self::assertSame([9000.0, 9000.0, 0.0, null], $byRole['export'], 'nobody else\'s amount goes up');
        self::assertSame([60000.0, 48000.0, 12000.0, 6000.0, 6000.0], [$calc['team_by_share'], $calc['team_total'], $calc['kept_total'], $calc['kept_not_eligible'], $calc['kept_capped']]);
        self::assertStringContainsString('capped: ₹14,000 already this month, monthly cap ₹20,000', (string) $calc['lines'][array_search('logistics', array_column($calc['lines'], 'role'), true)]['note']);
        // The profit layer uses what is actually payable.
        self::assertSame(48000.0, OrderProfitabilityService::incentiveLayer($orderId, OrderProfitabilityService::computeForOrder($orderId, false))['incentive_inr']);

        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        $payouts = IncentiveRepository::payoutsForOrder($orderId);
        self::assertSame(48000.0, round(array_sum(array_map(static fn (array $p): float => (float) $p['amount_inr'], $payouts)), 2));
        self::assertNotContains($this->people['sales_manager'], array_map(static fn (array $p): int => (int) $p['user_id'], $payouts));
        $kept = IncentiveRepository::keptForOrder($orderId);
        self::assertSame(['cap_order' => '3000.00', 'not_eligible' => '6000.00', 'cap_month' => '3000.00'], array_column($kept, 'kept_inr', 'kept_reason'));
        $row = IncentiveRepository::forOrder($orderId);
        self::assertSame(['48000.00', '60000.00', '6000.00', '6000.00'], [$row['team_total_inr'], $row['team_by_share_inr'], $row['kept_not_eligible_inr'], $row['kept_capped_inr']]);
        self::assertSame('48000.00', IncentiveRepository::costEntries($orderId)['team']['amount_inr']);

        // The register: the kept-only line for the person not eligible, and totals that add up.
        $rows = array_values(array_filter(IncentiveReportService::rows(['with_kept' => true, 'order_ids' => [$orderId]]), static fn (array $r): bool => $r['payee_kind'] === 'staff'));
        $t = IncentiveReportService::totals($rows);
        self::assertSame([48000.0, 6000.0, 6000.0], [$t['amount_inr'], $t['kept_not_eligible_inr'], $t['kept_capped_inr']]);
        self::assertCount(0, array_filter(IncentiveReportService::rows(['order_ids' => [$orderId]]), static fn (array $r): bool => !empty($r['kept_only'])), 'kept-only rows never reach "to pay"');

        // Unlocking removes the kept lines with the payouts.
        self::assertNull(IncentiveService::unlock($orderId, $this->admin, 'A claim came in after locking'));
        self::assertSame([], IncentiveRepository::keptForOrder($orderId));
    }

    public function testWithNoCapsAndEveryoneEligibleNothingChanges(): void
    {
        $orderId = $this->order();
        $calc = IncentiveService::calculate($orderId);
        self::assertSame([60000.0, 60000.0, 0.0], [$calc['amount'], $calc['team_total'], $calc['kept_total']]);
        foreach ($calc['lines'] as $l) {
            self::assertSame([$l['by_share'], 0.0, null], [$l['gets'], $l['kept'], $l['reason']]);
        }
        self::assertSame(60000.0, OrderProfitabilityService::incentiveLayer($orderId, OrderProfitabilityService::computeForOrder($orderId, false))['incentive_inr']);
        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        self::assertSame([], IncentiveRepository::keptForOrder($orderId));
        self::assertSame('60000.00', IncentiveRepository::forOrder($orderId)['team_total_inr']);
    }

    public function testALockedOrderNeverChanges(): void
    {
        $orderId = $this->order();
        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        $before = array_map(static fn (array $p): string => $p['user_id'] . ':' . $p['amount_inr'], IncentiveRepository::payoutsForOrder($orderId));
        $this->workedExampleSetUp();
        self::assertSame($before, array_map(static fn (array $p): string => $p['user_id'] . ':' . $p['amount_inr'], IncentiveRepository::payoutsForOrder($orderId)));
        self::assertSame('60000.00', IncentiveRepository::costEntries($orderId)['team']['amount_inr']);
        self::assertSame(60000.0, OrderProfitabilityService::incentiveLayer($orderId, OrderProfitabilityService::computeForOrder($orderId, false))['incentive_inr']);
    }

    public function testThePagesShowTheSwitchTheCapsAndWhatIsKept(): void
    {
        $orderId = $this->order();
        $this->workedExampleSetUp();
        $render = static function (callable $call): string {
            ob_start();
            $call();
            return (string) ob_get_clean();
        };
        $_SESSION['_auth_user_id'] = $this->admin;
        $show = $render(fn () => (new \App\Controllers\OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('Kept by company', $show);
        self::assertStringContainsString('not eligible (switched off for this person), share stays with company', $show);
        self::assertStringContainsString('12,000.00 kept by company', $show);
        self::assertStringContainsString('id="incentive-caps"', $render(fn () => (new \App\Controllers\IncentiveController())->settings([])));
        self::assertStringContainsString('Incentive eligible', $render(fn () => (new \App\Controllers\DepartmentAdminController())->index([])));
        $edit = $render(fn () => (new \App\Controllers\UserController())->editForm(['id' => (string) $this->people['sales']]));
        self::assertStringContainsString('Eligible for team incentive', $edit);
        self::assertStringContainsString('Only the MD can change this.', $edit, 'greyed for anyone but the MD');
        $_SESSION['_auth_user_id'] = $this->md;
        self::assertStringNotContainsString('Only the MD can change this.', $render(fn () => (new \App\Controllers\UserController())->editForm(['id' => (string) $this->people['sales']])));
        $_SESSION['_auth_user_id'] = $this->admin;
        $_GET = [];
        $register = $render(fn () => (new \App\Controllers\IncentiveReportController())->register([]));
        self::assertStringContainsString('Kept — not eligible (INR)', $register);
    }

    public function testOnlyTheMdChangesEligibilityAndCaps(): void
    {
        $pdo = Database::connection();
        $actor = static fn (int $id): array => $pdo->query('SELECT * FROM users WHERE id = ' . $id)->fetch();
        $person = $this->people['sales'];
        self::assertStringContainsString('Only the MD', (string) IncentiveService::setEligible($actor($this->admin), $person, false));
        self::assertTrue(IncentiveService::isEligible($person));
        self::assertNull(IncentiveService::setEligible($actor($this->md), $person, false));
        self::assertFalse(IncentiveService::isEligible($person));
        self::assertNull(IncentiveService::setEligible($actor($this->md), $person, true));
        self::assertNull($pdo->query('SELECT incentive_eligible FROM users WHERE id = ' . $person)->fetchColumn(), 'matching the designation follows it again');

        // The settings form: the caps are the MD's (manage_pricing); anyone else is refused.
        $ed = $this->createTestUser('Executive Director');
        $save = function (int $as, array $caps): array {
            $_SESSION['_auth_user_id'] = $as;
            $_POST = ['lock_reminder_days' => '3', 'caps_present' => '1', 'caps' => $caps];
            ob_start();
            (new \App\Controllers\IncentiveController())->saveSettings([]);
            ob_end_clean();
            $_POST = [];
            return Flash::pull()[0] ?? ['type' => 'none', 'message' => ''];
        };
        self::assertStringContainsString('Only the MD can change the incentive caps', $save($ed, ['sales' => ['order' => '100', 'month' => '']])['message']);
        self::assertNull(IncentiveRepository::allRoles()['sales']['cap_per_order']);
        self::assertStringContainsString('Enter the Sales cap per order', $save($this->md, ['sales' => ['order' => '-5', 'month' => '']])['message']);
        self::assertSame('success', $save($this->md, ['sales' => ['order' => '15000', 'month' => '40000']])['type']);
        self::assertSame([15000.0, 40000.0], [IncentiveRepository::allRoles()['sales']['cap_per_order'], IncentiveRepository::allRoles()['sales']['cap_per_month']]);
        self::assertSame('success', $save($this->md, ['sales' => ['order' => '', 'month' => '']])['type']);
        self::assertNull(IncentiveRepository::allRoles()['sales']['cap_per_order'], 'empty = no cap');
    }
}
