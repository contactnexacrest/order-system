<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociateAdminController;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Services\AssociateCommission;
use App\Services\AssociatePayoutService;
use App\Services\IncentiveService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-264 (7 Nov 2026): the associate's commission is fixed by itself when the
 * buyer has paid in full — in rupees at the rate of ALL the buyer's payments — and never
 * changes; the order currency is the figure associates see (rupees only for India, once
 * fixed); India is paid in INR, abroad in USD/EUR/GBP; OUR charges by default; small amounts
 * abroad carry to the next run; paying records what it really cost (a company cost, never the
 * order's). Worked example of the mockups: advance USD 5,000 credited ₹4,15,500.00, balance
 * USD 12,857.14 credited ₹10,74,678.33 → ₹83.45; 7% → USD 1,250.00 = ₹104,312.50.
 * Node twin: tests/integration/associatePaymentsD264.test.js (same figures, same wording).
 */
final class AssociatePaymentsD264Test extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        PermissionService::resetCache();
        foreach (['associate_commission_due_days' => '30', 'associate_claim_hold' => '1', 'associate_payout_run' => 'monthly', 'associate_payout_day' => '5',
            'associate_counting_threshold_inr' => '500000', 'associate_abroad_charges' => 'OUR', 'associate_abroad_min_payout_usd' => '100'] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->admin);
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    /** @return array{0:int,1:int} associate id, client id */
    private function associate(string $basedIn = 'india', string $currency = 'INR'): array
    {
        $pdo = Database::connection();
        $level = (int) $pdo->query("SELECT id FROM commission_levels WHERE name = 'Level 3'")->fetchColumn();
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id, based_in, currency_code) VALUES (:n, 'active', 'fixed', :l, :b, :c)")
            ->execute(['n' => 'Demo Associate ' . bin2hex(random_bytes(3)), 'l' => $level, 'b' => $basedIn, 'c' => $currency]);
        $a = (int) $pdo->lastInsertId();
        $client = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01' WHERE id = :c")->execute(['a' => $a, 'c' => $client]);
        return [$a, $client];
    }

    /** The mockup order: one goods line USD 17,857.14; advance and balance receipts; balance cleared on $cleared (null = not yet). */
    private function order(int $client, ?string $cleared = '2026-11-12', bool $balanceRupees = true): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($client, 'FOB', 'Standard — New Buyer', 'USD');
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type) VALUES (:o, 1, 'Granite', 100, 'CBM', 178.5714, 17857.14, 'goods')")
            ->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = 5000.00, advance_cleared_at = :ac, balance_amount = 12857.14, balance_cleared_at = :bc WHERE order_id = :o')
            ->execute(['ac' => '2026-10-15 10:00:00', 'bc' => $cleared === null ? null : $cleared . ' 11:00:00', 'o' => $orderId]);
        $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 5000.00, '2026-10-15', 83.10, 415500.00, 'FIRC2610150003')")
            ->execute(['o' => $orderId]);
        $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'balance', 12857.14, '2026-11-12', 83.59, :i, 'FIRC2611120007')")
            ->execute(['o' => $orderId, 'i' => $balanceRupees ? 1074678.33 : null]);
        return $orderId;
    }

    private function payout(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare("SELECT * FROM incentive_payouts WHERE order_id = :o AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL");
        $stmt->execute(['o' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    private function lastNotice(int $associateId): string
    {
        $stmt = Database::connection()->prepare('SELECT message FROM associate_events WHERE associate_id = :a ORDER BY id DESC LIMIT 1');
        $stmt->execute(['a' => $associateId]);
        return (string) $stmt->fetchColumn();
    }

    public function testFixedAutomaticallyAtFullPaymentAtTheRateOfAllBuyerPayments(): void
    {
        [$a, $client] = $this->associate();
        $orderId = $this->order($client);
        self::assertTrue(AssociatePayoutService::autoFix($orderId));
        $p = $this->payout($orderId);
        self::assertSame(['1250.00', '104312.50', '83.450000', '2026-12-12', '2027-01-05', 'due'], [$p['amount_fc'], $p['amount_inr'], $p['fixed_rate'], $p['due_on'], $p['payout_run_on'], $p['status']]);
        $row = IncentiveRepository::forOrder($orderId);
        self::assertNotEmpty($row['associate_fixed_at']);
        self::assertNull($row['locked_at'], 'the team incentive keeps the MD lock (D-245)');
        $entry = IncentiveRepository::costEntries($orderId)['associate'];
        self::assertSame(104312.50, round((float) $entry['amount_inr'], 2));
        self::assertStringEndsWith('; fixed on ' . \App\Helpers\Dates::short(date('Y-m-d')) . ' at ₹83.45 per USD', (string) $entry['description']);
        $order = \App\Repositories\OrderRepository::find($orderId);
        self::assertSame('Your client ' . $order['company_legal_name'] . ' has paid order ' . $order['order_reference'] . ' in full. Your commission is now fixed at ₹104,312.50 (USD 1,250.00, 7% of the invoiced goods value, at ₹83.45 per USD — the rate at which the buyer\'s payments were converted by the bank). This amount will not change, whatever the exchange rate does from now on. It is due on 12 Dec 2026 and will be paid in the 05 Jan 2027 payout run to your verified bank account, after TDS as the law requires. Please upload your invoice for this commission under My commission. NexaCrest pays all bank charges.',
            $this->lastNotice($a));

        // Once only; and the MD's later team lock adds the team's part without a second associate payout.
        self::assertFalse(AssociatePayoutService::autoFix($orderId));
        self::assertNull(IncentiveService::lockIfReady($orderId, $this->admin));
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM incentive_payouts WHERE order_id = {$orderId} AND payee_kind = 'associate'")->fetchColumn());
        self::assertSame('104312.50', $this->payout($orderId)['amount_inr']);
        // Unlocking the team incentive keeps the associate's fixed commission.
        self::assertNull(IncentiveService::unlock($orderId, $this->admin, 'Team shares were wrong, fixing them'));
        self::assertNotNull($this->payout($orderId));
        self::assertNotEmpty(IncentiveRepository::forOrder($orderId)['associate_fixed_at']);
    }

    public function testNotFixedWhileTheRupeesOfAReceiptAreMissingThenTheDailyJobFixesIt(): void
    {
        [, $client] = $this->associate();
        $orderId = $this->order($client, '2026-11-12', false);
        self::assertFalse(AssociatePayoutService::autoFix($orderId), 'the rate is not known until every receipt\'s rupees are recorded');
        Database::connection()->prepare("UPDATE order_payment_receipts SET inr_credited = 1074678.33 WHERE order_id = :o AND leg = 'balance'")->execute(['o' => $orderId]);
        self::assertGreaterThanOrEqual(1, AssociatePayoutService::daily('2026-11-13')['fixed']);
        self::assertSame('104312.50', $this->payout($orderId)['amount_inr']);
        self::assertFalse(AssociatePayoutService::autoFix($this->order($client, null)), 'not before the buyer has paid in full');
    }

    public function testDisplayWordingIndiaAndAbroad(): void
    {
        self::assertSame('You will be paid ₹104,312.50 (fixed at ₹83.45 per USD on 12 Nov 2026; will not change)', AssociatePayoutService::fixedRupeeLine(104312.50, 83.45, 'USD', '2026-11-12 10:00:00'));
        self::assertSame('How your commission is fixed — it never changes afterwards', AssociatePayoutService::howFixedBox(['based_in' => 'india'])['title']);
        self::assertSame('Your commission is a percentage of the goods value (FOB) in the order\'s currency. On the day the buyer completes payment, it is fixed in rupees at the exchange rate the buyer\'s payment was actually converted at. That rupee amount is what you are paid. Later changes in the exchange rate do not raise or lower it. NexaCrest pays all bank charges.',
            AssociatePayoutService::howFixedBox(['based_in' => 'india'])['text']);
        self::assertSame('Your commission is a percentage of the goods value (FOB). It is fixed in US dollars on the day the buyer completes payment, and you are paid exactly that amount in US dollars to your verified account. NexaCrest pays its own and the intermediary bank\'s charges. Any conversion into your local currency is done by your own bank.',
            AssociatePayoutService::howFixedBox(['based_in' => 'abroad', 'currency_code' => 'USD'])['text']);
        IncentiveRepository::setSetting('associate_abroad_charges', 'SHA', $this->admin);
        self::assertStringContainsString('NexaCrest pays its own bank\'s charges; your own bank may charge you on receipt.', AssociatePayoutService::howFixedBox(['based_in' => 'abroad', 'currency_code' => 'USD'])['text']);
        IncentiveRepository::setSetting('associate_abroad_charges', 'OUR', $this->admin);

        // Abroad: fixed in USD, no rupees anywhere in the message.
        [$a, $client] = $this->associate('abroad', 'USD');
        $orderId = $this->order($client);
        self::assertTrue(AssociatePayoutService::autoFix($orderId));
        $msg = $this->lastNotice($a);
        self::assertStringContainsString('Your commission is now fixed at USD 1,250.00 (7% of the invoiced goods value). This amount will not change. It is due on 12 Dec 2026 and will be paid in the 05 Jan 2027 payout run to your verified bank account, paid in US dollars.', $msg);
        self::assertStringNotContainsString('₹', $msg);

        // Portal totals: the order currency.
        $t = AssociatePortalRepository::totals($a, 24, '2027-01-01');
        self::assertSame('USD 1,250.00', AssociatePortalRepository::fcText($t['fc']['waiting']));
        self::assertSame('USD 0.00', AssociatePortalRepository::fcText($t['fc']['paid']));
        $p = $this->payout($orderId);
        self::assertSame('Fixed — due 12 Dec 2026, paid in the 05 Jan 2027 payout run', AssociatePortalRepository::payoutLabel($p, false, '', '2026-11-20'));
        self::assertSame('Due — paid in the 05 Jan 2027 payout run', AssociatePortalRepository::payoutLabel($p, false, '', '2026-12-20'));
    }

    public function testPaidInCurrencyIndiaAlwaysInrAbroadAnActiveCurrency(): void
    {
        self::assertNotContains('INR', AssociateAdminController::abroadCurrencies());
        self::assertContains('USD', AssociateAdminController::abroadCurrencies());
        foreach ([['india', 'USD', 'INR'], ['abroad', 'EUR', 'EUR']] as [$basedIn, $posted, $saved]) {
            $_POST = ['name' => 'D264 Paid In ' . $basedIn, 'based_in' => $basedIn, 'currency_code' => $posted, 'commission_mode' => 'ladder', 'email' => 'd264-' . $basedIn . '-' . bin2hex(random_bytes(3)) . '@email.com'];
            $_SESSION['_csrf_token'] = 't';
            (new AssociateAdminController())->save([]);
            $stmt = Database::connection()->prepare('SELECT currency_code FROM associates WHERE email = :e');
            $stmt->execute(['e' => $_POST['email']]);
            self::assertSame($saved, $stmt->fetchColumn(), $basedIn);
        }
        $_POST = ['name' => 'D264 Abroad INR', 'based_in' => 'abroad', 'currency_code' => 'INR', 'commission_mode' => 'ladder', 'email' => 'd264-bad-' . bin2hex(random_bytes(3)) . '@email.com'];
        (new AssociateAdminController())->save([]);
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM associates WHERE email = :e');
        $stmt->execute(['e' => $_POST['email']]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'an associate abroad is never paid in rupees');
    }

    public function testPayoutDifferenceAbroadIsACompanyCostWithItsDescription(): void
    {
        [$a, $client] = $this->associate('abroad', 'USD');
        $orderId = $this->order($client);
        AssociatePayoutService::autoFix($orderId);
        $p = $this->payout($orderId);
        Database::connection()->prepare("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = 104312.50, tds_inr = 0, paid_reference = 'FT2612050001' WHERE id = :id")->execute(['id' => $p['id']]);
        AssociatePayoutService::recordPaymentCosts((int) $p['id'], 105125.00, 1850.00);
        $p = $this->payout($orderId);
        self::assertSame(['105125.00', '1850.00', '2662.50'], [$p['bank_debited_inr'], $p['bank_charges_inr'], $p['payout_difference_inr']]);
        $order = \App\Repositories\OrderRepository::find($orderId);
        self::assertSame('Associate commission payout — ' . $order['order_reference'] . ' — ' . AssociateRepository::find($a)['name'] . ' — USD 1,250.00 sent, SWIFT ref FT2612050001; exchange difference ₹812.50 and transfer charges ₹1,850.00',
            AssociatePayoutService::payoutCostDescription($p));
        self::assertSame(104312.50, round((float) IncentiveRepository::costEntries($orderId)['associate']['amount_inr'], 2), 'the order keeps the fixed commission');

        // India: the bank charges only, never deducted.
        self::assertSame(15.00, AssociatePayoutService::payoutDifference(['amount_inr' => 104312.50, 'tds_inr' => 2086.25], null, 15.00, true));
    }

    public function testClaimHoldsAndReleasesAndASettlementRecomputesAtTheSameFixedRate(): void
    {
        [$a, $client] = $this->associate();
        $orderId = $this->order($client);
        AssociatePayoutService::autoFix($orderId);
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_complaints (order_id, client_id, description, status, created_at) VALUES (:o, :c, 'Two slabs cracked', 'open', '2026-11-20 10:00:00')")->execute(['o' => $orderId, 'c' => $client]);
        self::assertSame(1, AssociatePayoutService::daily('2026-11-21')['held']);
        self::assertSame(['held', AssociatePayoutService::CLAIM_HOLD_REASON], [$this->payout($orderId)['status'], $this->payout($orderId)['hold_reason']]);

        // The claim is settled with an agreed USD 357.14 off the goods: 7% of USD 17,500.00 = USD 1,225.00 at the same ₹83.45.
        $pdo->prepare("INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                       VALUES (:o, 'balance', 12857.14, 12500.00, 357.14, 'written_off', 'Claim settled for two cracked slabs', :u, 'claim_settled')")->execute(['o' => $orderId, 'u' => $this->admin]);
        $pdo->prepare("UPDATE order_complaints SET status = 'resolved', resolved_at = NOW() WHERE order_id = :o")->execute(['o' => $orderId]);
        $out = AssociatePayoutService::daily('2026-11-25');
        self::assertSame([1, 1], [$out['recomputed'], $out['released']]);
        $p = $this->payout($orderId);
        self::assertSame(['1225.00', '102226.25', '83.450000', 'due'], [$p['amount_fc'], $p['amount_inr'], $p['fixed_rate'], $p['status']]);
        self::assertSame(102226.25, round((float) IncentiveRepository::costEntries($orderId)['associate']['amount_inr'], 2));
        $order = \App\Repositories\OrderRepository::find($orderId);
        self::assertStringContainsString('Order ' . $order['order_reference'] . ': after an agreed discount or a settled buyer claim, your commission is now USD 1,225.00 = ₹102,226.25, at the same fixed rate of ₹83.45 per USD.',
            implode("\n", Database::connection()->query("SELECT message FROM associate_events WHERE associate_id = {$a}")->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testTheMdChangesAFixedCommissionWithAReasonAtTheSameRate(): void
    {
        [$a, $client] = $this->associate();
        $orderId = $this->order($client);
        AssociatePayoutService::autoFix($orderId);
        $md = \App\Repositories\UserRepository::findById($this->admin) ?? ['id' => $this->admin];
        self::assertSame('Say why the commission is changed (10 to 500 characters).', AssociatePayoutService::changeCommission($orderId, '1000', 'short', $md));
        self::assertNull(AssociatePayoutService::changeCommission($orderId, '1000', 'Agreed with the associate after the late claim', $md));
        $p = $this->payout($orderId);
        self::assertSame(['1000.00', '83450.00'], [$p['amount_fc'], $p['amount_inr']]);
        $c = AssociateCommission::forOrder($orderId);
        self::assertSame([1000.0, 83450.0, true], [$c['amount_fc'], $c['amount_inr'], $c['override']]);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ASSOCIATE_COMMISSION_CHANGED' AND entity_id = " . (int) $p['id'])->fetchColumn());
        Database::connection()->prepare("UPDATE incentive_payouts SET status = 'paid', paid_on = '2027-01-05', paid_inr = 81781.00 WHERE id = :id")->execute(['id' => $p['id']]);
        self::assertSame('This commission is already paid, so it can no longer be changed.', AssociatePayoutService::changeCommission($orderId, '900', 'Second thoughts after the payment', $md));
    }

    public function testSmallAmountsAbroadCarryToTheNextRun(): void
    {
        [, $client] = $this->associate('abroad', 'USD');
        $orderId = $this->order($client);
        AssociatePayoutService::autoFix($orderId);
        $p = $this->payout($orderId);
        Database::connection()->prepare("UPDATE incentive_payouts SET amount_fc = 60.00, amount_inr = 5007.00, payout_run_on = '2027-01-05' WHERE id = :id")->execute(['id' => $p['id']]);
        self::assertGreaterThanOrEqual(1, AssociatePayoutService::carrySmallAbroad('2027-01-05'), 'this payout at least (the shared test database may hold others on that run)');
        $p = $this->payout($orderId);
        self::assertSame(['2027-02-05', '2027-02', 'Carried to the next payout run: the amount due is below the USD 100 minimum for a transfer abroad.'], [$p['payout_run_on'], $p['incentive_month'], $p['note']]);
    }
}
