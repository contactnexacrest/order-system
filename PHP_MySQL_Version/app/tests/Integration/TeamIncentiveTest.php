<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Services\IncentiveService;
use App\Services\OrderProfitabilityService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2026-10-30 (mockups approved): the team incentive program
 * and associate commission. The Node suite has the same test
 * (teamIncentive.test.js).
 */
final class TeamIncentiveTest extends DbTestCase
{
    private int $admin;
    /** @var array<string,int> */
    private array $people;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->admin;
        $this->people = [
            'sales' => $this->createTestUser('Export Executive'),
            'export' => $this->createTestUser('Export Executive'),
            'marketing' => $this->createTestUser('Export Executive'),
            'logistics1' => $this->createTestUser('Logistics Executive'),
            'logistics2' => $this->createTestUser('Logistics Executive'),
            'accounts' => $this->createTestUser('Accounts Executive'),
        ];
        IncentiveRepository::replaceUserRoles([
            $this->people['sales'] => ['sales'], $this->people['export'] => ['export'], $this->people['marketing'] => ['marketing'],
            $this->people['logistics1'] => ['logistics'], $this->people['logistics2'] => ['logistics'], $this->people['accounts'] => ['accounts'],
        ]);
        foreach (['program_enabled' => '1', 'program_start_date' => '2026-01-01', 'min_profit_inr' => '300000',
            'reward_table' => '[{"from":300000,"reward":40000},{"from":500000,"reward":60000}]', 'new_client_bonus_inr' => '10000',
            'role_split' => '{"sales":40,"export":20,"marketing":15,"logistics":15,"accounts":10}', 'cutoff_day' => '20'] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->admin);
        }
    }

    protected function tearDown(): void
    {
        IncentiveRepository::replaceUserRoles([]);
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    /** A USD order: products USD 4,500; advance USD 1,800 (₹1,50,000) and balance USD 2,700 (₹2,50,000), balance cleared on $balanceCleared. */
    private function paidOrder(int $clientId, ?string $balanceCleared = '2026-10-25'): int
    {
        $pdo = Database::connection();
        // The helper makes one order per client: a further order for the same client is made under a spare client and moved.
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, array_values($this->people));
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM orders WHERE client_id = ' . $clientId)->fetchColumn();
        $pdo->prepare('UPDATE orders SET client_id = :c, sequence_no = :n WHERE id = :o')->execute(['c' => $clientId, 'n' => $next, 'o' => $orderId]);
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = :a, advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
            balance_amount = 2700, balance_cleared_at = :b, balance_inr_actual = :binr, balance_exchange_rate = 92.5926 WHERE order_id = :o')
            ->execute(['a' => '2026-10-10 10:00:00', 'b' => $balanceCleared === null ? null : $balanceCleared . ' 11:00:00', 'binr' => $balanceCleared === null ? null : 250000, 'o' => $orderId]);
        return $orderId;
    }

    private function payouts(int $orderId): array
    {
        $out = [];
        foreach (IncentiveRepository::payoutsForOrder($orderId) as $p) {
            $out[$p['payee_kind'] === 'staff' ? array_search((int) $p['user_id'], $this->people, true) : 'associate'] = (float) $p['amount_inr'];
        }
        return $out;
    }

    public function testRewardIsSplitByRoleWithTheNewClientBonusAndBecomesTheOrdersCost(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        $profitBefore = OrderProfitabilityService::computeForOrder($orderId)['profit_inr'];
        self::assertGreaterThanOrEqual(300000, $profitBefore);
        self::assertLessThan(500000, $profitBefore);

        IncentiveService::lockIfDue($orderId, $this->admin);

        self::assertSame([
            'sales' => 23272.73, 'export' => 8000.0, 'marketing' => 8727.27, 'logistics1' => 3000.0, 'logistics2' => 3000.0, 'accounts' => 4000.0,
        ], array_intersect_key($this->payouts($orderId), array_flip(['sales', 'export', 'marketing', 'logistics1', 'logistics2', 'accounts'])) + [], 'Sales 40% + 40/55 of the ₹10,000 new client bonus; Logistics shared');
        $row = IncentiveRepository::forOrder($orderId);
        self::assertSame(['2026-10-25', '2026-11', '50000.00', '10000.00'], [$row['fully_paid_on'], $row['incentive_month'], $row['team_total_inr'], $row['new_client_bonus_inr']]);

        $entry = IncentiveRepository::costEntries($orderId)['team'];
        self::assertSame(['commission', '50000.00', null], [$entry['category'], $entry['amount_inr'], $entry['paid_from']]);
        $after = OrderProfitabilityService::computeForOrder($orderId);
        self::assertEqualsWithDelta($profitBefore - 50000, $after['profit_inr'], 0.01, 'the team incentive is the order\'s cost');
        self::assertSame(50000.0, $after['team_incentive_inr']);
    }

    public function testTheAmountSetOnTheOrderTheExclusionTheMinimumAndTheSwitch(): void
    {
        $client = $this->createTestClient();
        $first = $this->paidOrder($client);
        IncentiveService::lockIfDue($first, $this->admin);

        $repeat = $this->paidOrder($client);
        IncentiveRepository::saveOrder($repeat, ['reward_override_inr' => 70000], $this->admin);
        $calc = IncentiveService::calculate($repeat, '2026-10-25');
        self::assertSame(['override', 70000.0, 0.0, 70000.0], [$calc['source'], $calc['reward'], $calc['new_client_bonus'], $calc['team_total']], 'a repeat order gets no new client bonus');

        IncentiveRepository::saveOrder($repeat, ['reward_override_inr' => 0, 'is_excluded' => 1], $this->admin);
        self::assertSame(['excluded', 0.0], [IncentiveService::calculate($repeat)['source'], IncentiveService::calculate($repeat)['team_total']]);

        IncentiveRepository::saveOrder($repeat, ['is_excluded' => 0], $this->admin);
        IncentiveRepository::setSetting('min_profit_inr', '900000', $this->admin);
        self::assertSame('below_min', IncentiveService::calculate($repeat)['source']);
        IncentiveRepository::setSetting('min_profit_inr', '300000', $this->admin);
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        self::assertSame(['off', 0.0], [IncentiveService::calculate($repeat)['source'], IncentiveService::calculate($repeat)['team_total']]);
        IncentiveRepository::setSetting('program_enabled', '1', $this->admin);
        self::assertSame('before_start', IncentiveService::calculate($repeat, '2025-12-31')['source']);
    }

    public function testTheAssociatesCommissionIsDeductedFirstOnTheClientsFirstTenDaysOnly(): void
    {
        $associate = AssociateRepository::create(['name' => 'PHPUnit Associate', 'based_in' => 'abroad', 'country' => 'USA', 'currency_code' => 'USD',
            'email' => null, 'phone' => null, 'tax_id' => null, 'agreement_signed_on' => '2026-09-01', 'rate_1_5' => 0.70, 'rate_6_10' => 1.00,
            'rate_11_20' => 1.50, 'rate_21_40' => 1.75, 'rate_41_plus' => 2.00, 'status' => 'active', 'notes' => null], $this->admin);
        $client = $this->createTestClient();
        AssociateRepository::linkClient($client, $associate, '2026-09-05');

        $orderId = $this->paidOrder($client);
        $profitBefore = OrderProfitabilityService::computeForOrder($orderId)['profit_inr'];
        $calc = IncentiveService::calculate($orderId);
        self::assertSame([1, 0.70, 31.5, 'USD'], [$calc['associate']['order_number'], $calc['associate']['rate'], $calc['associate']['amount_fc'], $calc['associate']['currency']]);
        self::assertEqualsWithDelta(2800.0, $calc['associate']['amount_inr'], 0.01, 'USD 31.50 at the ₹88.89 actually received per dollar');
        self::assertEqualsWithDelta($profitBefore - 2800, $calc['basis'], 0.01);

        IncentiveService::lockIfDue($orderId, $this->admin);
        self::assertEqualsWithDelta(2800.0, $this->payouts($orderId)['associate'], 0.01);
        self::assertSame('2800.00', IncentiveRepository::costEntries($orderId)['associate']['amount_inr']);

        $later = $this->paidOrder($client);
        Database::connection()->prepare('UPDATE orders SET created_at = DATE_ADD(created_at, INTERVAL 15 DAY) WHERE id = :id')->execute(['id' => $later]);
        self::assertNull(IncentiveService::calculate($later)['associate'], 'after day 10 it is a repeat order: no commission');
        IncentiveRepository::saveOrder($later, ['associate_mode' => 'yes'], $this->admin);
        self::assertSame(1, IncentiveService::calculate($later)['associate']['order_number'], 'still the same client: the same commission order');

        $second = $this->paidOrder($otherClient = $this->createTestClient());
        AssociateRepository::linkClient($otherClient, $associate, '2026-09-20');
        self::assertSame(2, IncentiveService::calculate($second)['associate']['order_number']);
    }

    public function testTheIncentiveMonthFollowsTheCutOffDay(): void
    {
        self::assertSame('2026-10', IncentiveService::incentiveMonth('2026-10-20', '20'));
        self::assertSame('2026-11', IncentiveService::incentiveMonth('2026-10-21', '20'));
        self::assertSame('2027-01', IncentiveService::incentiveMonth('2026-12-25', '20'));
        self::assertSame('2026-10', IncentiveService::incentiveMonth('2026-10-31', 'last'));
        self::assertSame('21 Oct to 20 Nov', IncentiveService::cycleLabel('2026-11', '20'));
        self::assertSame('1 Nov to 30 Nov', IncentiveService::cycleLabel('2026-11', 'last'));
    }

    public function testNothingIsFixedUntilTheBuyerHasFullyPaid(): void
    {
        $orderId = $this->paidOrder($this->createTestClient(), null);
        self::assertNull(IncentiveService::fullyPaidOn($orderId));
        IncentiveService::lockIfDue($orderId, $this->admin);
        self::assertNull(IncentiveRepository::forOrder($orderId));
        self::assertSame([], IncentiveRepository::payoutsForOrder($orderId));
    }

    public function testPayingEveryoneSendsTheTeamIncentiveToZohoBooksAsPaid(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        IncentiveService::lockIfDue($orderId, $this->admin);
        $rows = IncentiveRepository::payoutsForOrder($orderId);
        IncentiveService::markPaid((int) $rows[0]['id'], '2026-11-30', (float) $rows[0]['amount_inr'], null, $this->admin);
        self::assertNull(IncentiveRepository::costEntries($orderId)['team']['paid_from'], 'not all paid yet: not sent');
        foreach (array_slice($rows, 1) as $r) {
            IncentiveService::markPaid((int) $r['id'], '2026-11-30', (float) $r['amount_inr'], null, $this->admin);
        }
        $entry = IncentiveRepository::costEntries($orderId)['team'];
        self::assertSame(['company_account', '2026-11-30', '50000.00'], [$entry['paid_from'], $entry['incurred_at'], $entry['amount_inr']]);
        self::assertSame('Part of this order\'s incentive is already paid, so its figures can no longer be worked out again.', IncentiveService::unlock($orderId, $this->admin));
    }

    public function testTheIncentiveCostLineCannotBeRemovedByHand(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        IncentiveService::lockIfDue($orderId, $this->admin);
        $entryId = (int) IncentiveRepository::costEntries($orderId)['team']['id'];
        ob_start();
        (new OrderFinancialsController())->deleteCostEntry(['id' => (string) $orderId, 'entryId' => (string) $entryId]);
        ob_end_clean();
        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertArrayHasKey('team', IncentiveRepository::costEntries($orderId));
    }

    public function testEveryPageOpensAndStaffSeeOnlyTheirOwnAmounts(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        IncentiveService::lockIfDue($orderId, $this->admin);
        $controller = new \App\Controllers\IncentiveController();
        $render = static function (callable $call): string {
            ob_start();
            $call();
            return (string) ob_get_clean();
        };
        self::assertStringContainsString('Incentive cut-off day', $render(fn () => $controller->settings([])));
        self::assertStringContainsString('Add an associate', $render(fn () => $controller->associates([])));
        $_GET = ['month' => '2026-11'];
        $payouts = $render(fn () => $controller->payouts([]));
        $_GET = [];
        self::assertStringContainsString('November 2026 incentive', $payouts);
        self::assertStringContainsString('23,272.73', $payouts);
        self::assertStringNotContainsString('profit', strtolower(strip_tags($payouts)), 'Accounts never sees profit');

        $_SESSION['_auth_user_id'] = $this->people['logistics1'];
        $mine = $render(fn () => $controller->mine([]));
        self::assertStringContainsString('3,000', $mine);
        self::assertStringNotContainsString('23,273', $mine, 'only their own amount');
        self::assertStringNotContainsString('profit', strtolower(strip_tags($mine)));
        $_SESSION['_auth_user_id'] = $this->admin;
    }

    public function testSavingTheSettingsFormStoresEachPersonsIncentiveRoles(): void
    {
        $_POST = [
            'program_enabled' => '1', 'min_profit_inr' => '300000', 'cutoff_day' => '20', 'new_client_bonus_inr' => '10000',
            'table_from' => ['300000', '500000', '', '', ''], 'table_reward' => ['40000', '60000', '', '', ''],
            'split' => ['sales' => '40', 'export' => '20', 'marketing' => '15', 'logistics' => '15', 'accounts' => '10'],
            'user_roles' => ['u' . $this->people['sales'] => ['sales', 'marketing'], 'u' . $this->people['accounts'] => ['accounts']],
        ];
        ob_start();
        (new \App\Controllers\IncentiveController())->saveSettings([]);
        ob_end_clean();
        self::assertSame('success', Flash::pull()[0]['type']);
        self::assertSame([$this->people['sales'] => ['marketing', 'sales'], $this->people['accounts'] => ['accounts']],
            array_map(static function (array $r): array { sort($r); return $r; }, IncentiveRepository::userRoles()));

        $_POST['split']['sales'] = '45';
        ob_start();
        (new \App\Controllers\IncentiveController())->saveSettings([]);
        ob_end_clean();
        self::assertSame('error', Flash::pull()[0]['type'], 'role shares that do not add up to 100 are refused');
        $_POST = [];
    }

    public function testOnlyYouAndTheMdManageIncentivesAccountsPaysAndStaffSeeTheirOwn(): void
    {
        $pdo = Database::connection();
        $role = static fn (string $name): int => (int) $pdo->query('SELECT id FROM roles WHERE name = ' . $pdo->quote($name))->fetchColumn();
        $can = fn (string $roleName, string $perm): bool => PermissionService::can($this->createTestUser($roleName), $role($roleName), $perm);
        self::assertTrue($can('Managing Director', 'manage_incentives'));
        self::assertFalse($can('Executive Director', 'manage_incentives'));
        self::assertFalse($can('Accounts Executive', 'manage_incentives'));
        self::assertTrue($can('Accounts Executive', 'pay_incentives'));
        self::assertFalse($can('Export Executive', 'pay_incentives'));
        self::assertTrue($can('Logistics Executive', 'view_own_incentives'));
        self::assertFalse($can('CA / Chartered Accountant', 'view_own_incentives'));
    }
}
