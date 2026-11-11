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
 * Owner decision 2026-10-30, reworked by D-245 P4 (4 Nov 2026): the team
 * incentive is a % of the order's profit after the associate's commission
 * (never below 0) or a fixed amount — global rule, product rule or the MD's
 * amount for one order with a reason; editable roles whose shares add up to
 * 100%; the MD ticks who gets it or sets the shares; nothing locks by itself
 * (the MD locks a ready order, one or all; unlock with a reason until paid);
 * every change in the order's incentive history. The Node suite has the same
 * test (teamIncentive.test.js).
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
        IncentiveRepository::setSetting('program_enabled', '1', $this->admin);
        $this->rolesBefore = IncentiveRepository::allRoles();
    }

    /** @var array<string, array<string,mixed>> */
    private array $rolesBefore = [];

    protected function tearDown(): void
    {
        IncentiveRepository::replaceUserRoles([]);
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        // The roles are shared by every test: put them back as they were.
        foreach (array_keys(IncentiveRepository::allRoles()) as $key) {
            if (!isset($this->rolesBefore[$key])) {
                IncentiveRepository::deleteRole($key);
            }
        }
        IncentiveRepository::saveRoles(array_values($this->rolesBefore), $this->admin);
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
            $key = $p['payee_kind'] === 'staff' ? array_search((int) $p['user_id'], $this->people, true) : 'associate';
            $out[$key] = round(($out[$key] ?? 0) + (float) $p['amount_inr'], 2);
        }
        return $out;
    }

    private function call(string $method, int $orderId, array $post, ?int $as = null): array
    {
        $_SESSION['_auth_user_id'] = $as ?? $this->admin;
        $_POST = $post;
        ob_start();
        (new \App\Controllers\IncentiveController())->{$method}(['id' => (string) $orderId]);
        ob_end_clean();
        $_SESSION['_auth_user_id'] = $this->admin;
        $_POST = [];
        return Flash::pull()[0] ?? ['type' => 'none', 'message' => ''];
    }

    /** People on the usual roles (sales, export, marketing, logistics ×2, accounts); Sales Manager and Export Compliance have nobody. */
    private function history(int $orderId): array
    {
        return array_map(static fn (array $h): string => $h['action'], IncentiveRepository::history($orderId));
    }

    public function testTheGlobalRuleIsAPercentOfProfitSharedOverTheRolesWithPeopleAndNothingLocksByItself(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        $calc = IncentiveService::calculate($orderId);
        $expected = round($calc['basis'] * 0.15, 2);
        self::assertSame(['global', 'ready', $expected, $expected, 0.0], [$calc['source'], $calc['state'], $calc['amount'], $calc['team_total'], $calc['unallocated']]);
        // Sales Manager and Export Compliance have nobody on this order: their shares go to the others in proportion (30:15:15:15:10).
        self::assertFalse($calc['roles']['sales_manager']['gets_it']);
        self::assertSame(round(30 / 85 * 100, 2), $calc['roles']['sales']['pct']);
        self::assertNull(IncentiveRepository::forOrder($orderId), 'nothing locks by itself, even fully paid');
        self::assertContains($orderId, IncentiveService::readyToLock());

        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        $row = IncentiveRepository::forOrder($orderId);
        self::assertSame([number_format($expected, 2, '.', ''), substr(\App\Services\AssociateCommission::payoutRunOn(date('Y-m-d')), 0, 7), (string) $this->admin],
            [$row['team_total_inr'], $row['incentive_month'], (string) $row['locked_by']]);
        self::assertEqualsWithDelta($expected, array_sum($this->payouts($orderId)), 0.001, 'the payouts add up to the incentive exactly');
        self::assertSame(number_format($expected, 2, '.', ''), IncentiveRepository::costEntries($orderId)['team']['amount_inr']);
        self::assertSame(['lock'], $this->history($orderId));
        self::assertSame('locked', IncentiveService::state($orderId));
    }

    public function testTheMdSetsTheOrdersAmountWithAReasonAndChoosesWhoGetsIt(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        self::assertStringContainsString('Say why', $this->call('saveOrder', $orderId, ['incentive_mode' => 'fixed', 'incentive_value' => '100000'])['message']);
        self::assertSame('success', $this->call('saveOrder', $orderId, ['incentive_mode' => 'fixed', 'incentive_value' => '100000', 'incentive_reason' => 'Large first order, weekend work'])['type']);
        $calc = IncentiveService::calculate($orderId);
        self::assertSame(['order_fixed', 100000.0, 100000.0], [$calc['source'], $calc['amount'], $calc['team_total']]);
        self::assertSame([35294.12, 17647.06, 17647.06, 17647.06, 8823.53, 11764.70],
            [$calc['roles']['sales']['amount'], $calc['roles']['export']['amount'], $calc['roles']['marketing']['amount'], $calc['roles']['logistics']['amount'], $calc['roles']['logistics']['each'], $calc['roles']['accounts']['amount']]);

        // One person does Sales and Export; Marketing and Accounts are unticked: 30:15:15 over Sales, Export, Logistics.
        $flash = $this->call('saveOrderPeople', $orderId, ['gets_it' => ['sales' => '1', 'export' => '1', 'logistics' => '1'],
            'people' => ['sales' => [$this->people['sales']], 'export' => [$this->people['sales']], 'logistics' => [$this->people['logistics1'], $this->people['logistics2']]],
            'people_note' => 'The Export Executive did sales and documents']);
        self::assertSame('success', $flash['type']);
        $calc = IncentiveService::calculate($orderId);
        self::assertSame([50000.0, 25000.0, 25000.0, 12500.0, false], [$calc['roles']['sales']['amount'], $calc['roles']['export']['amount'], $calc['roles']['logistics']['amount'], $calc['roles']['logistics']['each'], $calc['roles']['marketing']['gets_it']]);
        $byPerson = array_column($calc['per_person'], 'amount', 'id');
        self::assertSame(75000.0, $byPerson[$this->people['sales']], 'one person, two roles');

        // Custom shares must add up to 100%; one person may get it all.
        $post = ['gets_it' => ['sales' => '1', 'export' => '1', 'logistics' => '1'], 'shares_customised' => '1',
            'people' => ['sales' => [$this->people['sales']], 'export' => [$this->people['sales']], 'logistics' => [$this->people['logistics1']]]];
        self::assertStringContainsString('add up to 100%', $this->call('saveOrderPeople', $orderId, $post + ['share_pct' => ['sales' => '50', 'export' => '20', 'logistics' => '20']])['message']);
        self::assertSame('success', $this->call('saveOrderPeople', $orderId, $post + ['share_pct' => ['sales' => '100', 'export' => '0', 'logistics' => '0']])['type']);
        $calc = IncentiveService::calculate($orderId);
        self::assertSame([100000.0, 100000.0], [$calc['roles']['sales']['amount'], array_column($calc['per_person'], 'amount', 'id')[$this->people['sales']]]);

        // A % of this order's profit; more than the profit is refused.
        self::assertSame('success', $this->call('saveOrder', $orderId, ['incentive_mode' => 'pct', 'incentive_value' => '25', 'incentive_reason' => 'Quarter of the profit this time'])['type']);
        self::assertSame(round($calc['basis'] * 0.25, 2), IncentiveService::calculate($orderId)['amount']);
        self::assertStringContainsString('cannot be more than', $this->call('saveOrder', $orderId, ['incentive_mode' => 'fixed', 'incentive_value' => '99999999', 'incentive_reason' => 'Far too much on purpose'])['message']);
        self::assertSame(['amount', 'people', 'people', 'amount'], $this->history($orderId), 'every change is kept (newest first); refused ones are not');
    }

    public function testTheProfitUsedNeverGoesBelowZeroAndAnUnpaidOrderIsOnlyEstimated(): void
    {
        $orderId = $this->paidOrder($this->createTestClient(), null);
        Database::connection()->prepare("INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by) VALUES (:o, 'other', 'PHPUnit big cost', 9000000, '2026-10-01', :u)")
            ->execute(['o' => $orderId, 'u' => $this->admin]);
        $calc = IncentiveService::calculate($orderId);
        self::assertLessThan(0, $calc['basis_raw']);
        self::assertSame([0.0, 0.0, true, 'open'], [$calc['basis'], $calc['amount'], $calc['estimated'], $calc['state']]);
        self::assertSame('The buyer has not paid in full yet.', IncentiveService::lockIfReady($orderId, $this->admin));
        self::assertNull(IncentiveRepository::forOrder($orderId));
    }

    public function testWithTheProgramOffTheMdMayStillSetAnIncentiveByHand(): void
    {
        IncentiveRepository::setSetting('program_enabled', '0', $this->admin);
        $orderId = $this->paidOrder($this->createTestClient());
        self::assertSame(['off', 0.0], [IncentiveService::calculate($orderId)['source'], IncentiveService::calculate($orderId)['amount']]);
        self::assertSame('success', $this->call('saveOrder', $orderId, ['incentive_mode' => 'fixed', 'incentive_value' => '20000', 'incentive_reason' => 'Special effort on this order'])['type']);
        $calc = IncentiveService::calculate($orderId);
        self::assertSame(['order_fixed', 20000.0, 20000.0], [$calc['source'], $calc['amount'], $calc['team_total']]);
        self::assertEqualsWithDelta($calc['basis'] - 20000, $calc['left_for_company'], 0.001);
    }

    public function testBelowTheMinimumMarginWarnsAndMoreThanTheProfitCannotBeLocked(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        $basis = IncentiveService::calculate($orderId)['basis'];
        IncentiveRepository::saveOrder($orderId, ['incentive_mode' => 'fixed', 'incentive_value' => $basis - 1000], $this->admin);
        $calc = IncentiveService::calculate($orderId);
        self::assertTrue($calc['margin_warning']);
        self::assertFalse($calc['over_profit']);
        IncentiveRepository::saveOrder($orderId, ['incentive_mode' => 'fixed', 'incentive_value' => $basis + 100], $this->admin);
        self::assertTrue(IncentiveService::calculate($orderId)['over_profit']);
        self::assertStringContainsString('more than the order', (string) IncentiveService::lockIfReady($orderId, $this->admin));
        self::assertNull(IncentiveRepository::forOrder($orderId)['locked_at']);
    }

    public function testLockAllUnlockWithAReasonAndAPaidOrderNeverChanges(): void
    {
        $a = $this->paidOrder($this->createTestClient());
        $b = $this->paidOrder($this->createTestClient());
        $result = IncentiveService::lockAllReady($this->admin);
        self::assertGreaterThanOrEqual(2, $result['locked']);
        self::assertSame(['locked', 'locked'], [IncentiveService::state($a), IncentiveService::state($b)]);
        self::assertNotContains($a, IncentiveService::readyToLock());

        self::assertStringContainsString('Say why', (string) IncentiveService::unlock($a, $this->admin, 'short'));
        self::assertNull(IncentiveService::unlock($a, $this->admin, 'A claim came in after locking'));
        self::assertSame([[], 'ready'], [IncentiveRepository::payoutsForOrder($a), IncentiveService::state($a)]);
        self::assertArrayNotHasKey('team', IncentiveRepository::costEntries($a));
        self::assertSame(['unlock', 'lock'], $this->history($a));
        self::assertSame('A claim came in after locking', IncentiveRepository::history($a)[0]['reason']);

        $rows = IncentiveRepository::payoutsForOrder($b);
        IncentiveService::markPaid((int) $rows[0]['id'], date('Y-m-d'), (float) $rows[0]['amount_inr'], null, $this->admin);
        self::assertSame('paid', IncentiveService::state($b));
        self::assertStringContainsString('already paid', (string) IncentiveService::unlock($b, $this->admin, 'Trying to change a paid order'));
        self::assertStringContainsString('locked', $this->call('saveOrder', $b, ['incentive_mode' => 'fixed', 'incentive_value' => '1', 'incentive_reason' => 'Trying to change it'])['message']);
    }

    public function testOnlyTheMdChangesOrLocksAnOrdersIncentive(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        $ed = $this->createTestUser('Executive Director');
        self::assertStringContainsString('Only the MD', $this->call('saveOrder', $orderId, ['incentive_mode' => 'fixed', 'incentive_value' => '5000', 'incentive_reason' => 'ED trying to change'], $ed)['message']);
        self::assertStringContainsString('Only the MD', $this->call('lock', $orderId, [], $ed)['message']);
        self::assertStringContainsString('Only the MD', $this->call('saveOrderPeople', $orderId, ['gets_it' => ['sales' => '1']], $ed)['message']);
        self::assertNull(IncentiveRepository::forOrder($orderId));
        self::assertSame('success', $this->call('lock', $orderId, [])['type']);
    }

    public function testTheRolesAreAnEditableListWhoseSharesAddUpTo100(): void
    {
        $roles = static function (array $changes): array {
            $post = [];
            foreach (IncentiveRepository::allRoles() as $k => $r) {
                $post[$k] = ['label' => $r['label'], 'share_pct' => (string) $r['share_pct'], 'is_active' => $r['is_active'] ? '1' : null];
            }
            return array_replace_recursive($post, $changes);
        };
        $save = function (array $post): array {
            $_POST = $post + ['lock_reminder_days' => '3'];
            ob_start();
            (new \App\Controllers\IncentiveController())->saveSettings([]);
            ob_end_clean();
            $_POST = [];
            IncentiveRepository::forgetRoles();
            return Flash::pull()[0];
        };
        self::assertStringContainsString('add up to 100%', $save(['roles' => $roles(['sales' => ['share_pct' => '35']])])['message']);
        self::assertSame('success', $save(['roles' => $roles(['sales' => ['share_pct' => '25']]), 'new_role_label' => 'Quality Check', 'new_role_share' => '5'])['type']);
        self::assertSame(['Quality Check', 5.0, 25.0], [IncentiveRepository::roles()['quality_check'], IncentiveRepository::allRoles()['quality_check']['share_pct'], IncentiveRepository::allRoles()['sales']['share_pct']]);

        $delete = function (string $key): array {
            ob_start();
            (new \App\Controllers\IncentiveController())->deleteRole(['key' => $key]);
            ob_end_clean();
            IncentiveRepository::forgetRoles();
            return Flash::pull()[0];
        };
        self::assertStringContainsString('in use', $delete('sales')['message'], 'used by people: only deactivate');
        self::assertStringContainsString('Set the role', $delete('quality_check')['message']);
        self::assertSame('success', $save(['roles' => $roles(['sales' => ['share_pct' => '30'], 'quality_check' => ['share_pct' => '0', 'is_active' => null]])])['type']);
        self::assertSame('success', $delete('quality_check')['type']);
        self::assertArrayNotHasKey('quality_check', IncentiveRepository::allRoles());
    }

    public function testTheAssociatesCommissionIsDeductedFirstAndLocksWithTheOrder(): void
    {
        // D-245 P3: the associate on the ladder — step 1 (Level 1, 2%) of the invoiced FOB of the goods.
        $associate = AssociateRepository::create(['name' => 'PHPUnit Associate', 'based_in' => 'abroad', 'country' => 'USA', 'currency_code' => 'USD',
            'email' => null, 'phone' => null, 'tax_id' => null, 'agreement_signed_on' => '2026-09-01', 'status' => 'active', 'notes' => null], $this->admin);
        $client = $this->createTestClient();
        AssociateRepository::linkClient($client, $associate, '2026-09-05');

        $orderId = $this->paidOrder($client);
        $profitBefore = OrderProfitabilityService::computeForOrder($orderId)['profit_inr'];
        $calc = IncentiveService::calculate($orderId);
        self::assertSame(['Level 1 — 2%', 2.0, 90.0, 'USD'], [$calc['associate']['level_label'], $calc['associate']['rate'], $calc['associate']['amount_fc'], $calc['associate']['currency']]);
        self::assertEqualsWithDelta(8000.0, $calc['associate']['amount_inr'], 0.01, 'USD 90.00 at the ₹88.89 actually received per dollar');
        self::assertEqualsWithDelta($profitBefore - 8000, $calc['basis'], 0.01, 'the team incentive is worked out after the commission');
        self::assertEqualsWithDelta(round(($profitBefore - 8000) * 0.15, 2), $calc['amount'], 0.01);

        IncentiveService::lockIfReady($orderId, $this->admin);
        self::assertEqualsWithDelta(8000.0, $this->payouts($orderId)['associate'], 0.01, 'the commission locks together with the order');
        self::assertSame('8000.00', IncentiveRepository::costEntries($orderId)['associate']['amount_inr']);

        $later = $this->paidOrder($client);
        Database::connection()->prepare('UPDATE orders SET created_at = DATE_ADD(created_at, INTERVAL 15 DAY) WHERE id = :id')->execute(['id' => $later]);
        self::assertNull(IncentiveService::calculate($later)['associate'], 'after day 10 it is a repeat order: no commission');
        IncentiveRepository::saveOrder($later, ['associate_mode' => 'yes'], $this->admin);
        self::assertSame(90.0, IncentiveService::calculate($later)['associate']['amount_fc'], 'forced on: commission again');
    }

    public function testThePayoutMonthIsThePayoutRunAfterTheDate(): void
    {
        IncentiveRepository::setSetting('associate_payout_run', 'monthly', $this->admin);
        IncentiveRepository::setSetting('associate_payout_day', '5', $this->admin);
        self::assertSame('2026-10', IncentiveService::incentiveMonth('2026-10-05'));
        self::assertSame('2026-11', IncentiveService::incentiveMonth('2026-10-06'));
        self::assertSame('2027-01', IncentiveService::incentiveMonth('2026-12-25'));
    }

    public function testTheMdIsRemindedBeforeThePayoutRunOfOrdersReadyToLock(): void
    {
        IncentiveRepository::setSetting('associate_payout_run', 'monthly', $this->admin);
        IncentiveRepository::setSetting('associate_payout_day', '5', $this->admin);
        IncentiveRepository::setSetting('lock_reminder_days', '3', $this->admin);
        IncentiveRepository::setSetting('lock_reminder_sent_for', '', $this->admin);
        $this->paidOrder($this->createTestClient());
        self::assertSame(0, IncentiveService::lockReminders('2026-11-01'), 'not 3 days before the run');
        self::assertGreaterThanOrEqual(1, IncentiveService::lockReminders('2026-11-02'));
        self::assertSame(0, IncentiveService::lockReminders('2026-11-02'), 'once per run');
        $count = (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$this->admin} AND type = 'incentive_lock_reminder'")->fetchColumn();
        self::assertSame(1, $count);
        IncentiveRepository::setSetting('lock_reminder_sent_for', '', $this->admin);
    }

    public function testNothingIsReadyUntilTheBuyerHasFullyPaid(): void
    {
        $orderId = $this->paidOrder($this->createTestClient(), null);
        self::assertNull(IncentiveService::fullyPaidOn($orderId));
        self::assertNotNull(IncentiveService::lockIfReady($orderId, $this->admin));
        self::assertNull(IncentiveRepository::forOrder($orderId));
        self::assertSame([], IncentiveRepository::payoutsForOrder($orderId));
    }

    public function testPayingEveryoneSendsTheTeamIncentiveToZohoBooksAsPaid(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        Database::connection()->prepare("UPDATE orders SET company_legal_name = 'TEST-Incentive Sync Buyer' WHERE id = :o")->execute(['o' => $orderId]);
        $toSync = static fn (): array => array_map('intval', array_column(\App\Repositories\ZohoPurchaseRepository::costEntriesToSync(null), 'id'));
        IncentiveService::lockIfReady($orderId, $this->admin);
        $total = IncentiveRepository::forOrder($orderId)['team_total_inr'];
        self::assertNotContains((int) IncentiveRepository::costEntries($orderId)['team']['id'], $toSync(), 'locked but not paid: not picked up by the Zoho sync');
        $rows = IncentiveRepository::payoutsForOrder($orderId);
        IncentiveService::markPaid((int) $rows[0]['id'], date('Y-m-d'), (float) $rows[0]['amount_inr'], null, $this->admin);
        self::assertNull(IncentiveRepository::costEntries($orderId)['team']['paid_from'], 'not all paid yet: not sent');
        foreach (array_slice($rows, 1) as $r) {
            IncentiveService::markPaid((int) $r['id'], date('Y-m-d'), (float) $r['amount_inr'], null, $this->admin);
        }
        $entry = IncentiveRepository::costEntries($orderId)['team'];
        self::assertSame(['company_account', date('Y-m-d'), $total], [$entry['paid_from'], $entry['incurred_at'], $entry['amount_inr']]);
        self::assertStringStartsWith('Team incentive for order', (string) $entry['description'], 'D-212: says what it is and for which order');
        self::assertContains((int) $entry['id'], $toSync(), 'all paid: the sync sends it as a Books expense');
    }

    public function testTheIncentiveCostLineCannotBeRemovedByHand(): void
    {
        $orderId = $this->paidOrder($this->createTestClient());
        IncentiveService::lockIfReady($orderId, $this->admin);
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
        IncentiveService::lockIfReady($orderId, $this->admin);
        $month = (string) IncentiveRepository::forOrder($orderId)['incentive_month'];
        $controller = new \App\Controllers\IncentiveController();
        $render = static function (callable $call): string {
            ob_start();
            $call();
            return (string) ob_get_clean();
        };
        self::assertStringContainsString('Incentive roles and their usual shares', $render(fn () => $controller->settings([])));
        self::assertStringContainsString('Ready to lock', $render(fn () => $controller->ready([])));
        self::assertStringContainsString('Add an associate', $render(fn () => (new \App\Controllers\AssociateAdminController())->create([])));
        $_GET = ['month' => $month];
        $payouts = $render(fn () => $controller->payouts([]));
        $_GET = [];
        self::assertStringContainsString(IncentiveService::monthLabel($month) . ' payout run', $payouts);
        self::assertStringNotContainsString('profit', strtolower(strip_tags($payouts)), 'Accounts never sees profit');

        $_SESSION['_auth_user_id'] = $this->people['logistics1'];
        $mine = $render(fn () => $controller->mine([]));
        $own = $this->payouts($orderId)['logistics1'];
        self::assertStringContainsString(number_format($own, 0), $mine);
        self::assertStringNotContainsString(number_format($this->payouts($orderId)['sales'], 0), $mine, 'only their own amount');
        self::assertStringNotContainsString('profit', strtolower(strip_tags($mine)));
        $_SESSION['_auth_user_id'] = $this->admin;
    }

    public function testSavingTheSettingsFormStoresEachPersonsIncentiveRoles(): void
    {
        $_POST = ['program_enabled' => '1', 'lock_reminder_days' => '3', 'user_roles_present' => '1',
            'user_roles' => ['u' . $this->people['sales'] => ['sales', 'marketing'], 'u' . $this->people['accounts'] => ['accounts']]];
        ob_start();
        (new \App\Controllers\IncentiveController())->saveSettings([]);
        ob_end_clean();
        self::assertSame('success', Flash::pull()[0]['type']);
        self::assertSame([$this->people['sales'] => ['marketing', 'sales'], $this->people['accounts'] => ['accounts']],
            array_map(static function (array $r): array { sort($r); return $r; }, IncentiveRepository::userRoles()));
        $_POST = ['lock_reminder_days' => '40'];
        ob_start();
        (new \App\Controllers\IncentiveController())->saveSettings([]);
        ob_end_clean();
        self::assertSame('error', Flash::pull()[0]['type'], 'a reminder of more than 28 days is refused');
        $_POST = [];
    }

    public function testOnlyYouAndTheMdManageIncentivesAccountsPaysAndStaffSeeTheirOwn(): void
    {
        $pdo = Database::connection();
        $role = static fn (string $name): int => (int) $pdo->query('SELECT id FROM roles WHERE name = ' . $pdo->quote($name))->fetchColumn();
        $can = fn (string $roleName, string $perm): bool => PermissionService::can($this->createTestUser($roleName), $role($roleName), $perm);
        self::assertTrue($can('Managing Director', 'manage_incentives'));
        self::assertTrue($can('Managing Director', 'manage_pricing'));
        self::assertFalse($can('Executive Director', 'manage_incentives'));
        self::assertFalse($can('Executive Director', 'manage_pricing'));
        self::assertFalse($can('Accounts Executive', 'manage_incentives'));
        self::assertTrue($can('Accounts Executive', 'pay_incentives'));
        self::assertFalse($can('Export Executive', 'pay_incentives'));
        self::assertTrue($can('Logistics Executive', 'view_own_incentives'));
        self::assertFalse($can('CA / Chartered Accountant', 'view_own_incentives'));
    }
}
