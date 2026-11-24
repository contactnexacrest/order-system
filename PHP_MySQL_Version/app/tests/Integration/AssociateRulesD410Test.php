<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PricingRepository;
use App\Services\AssociateCommission;
use App\Services\AssociateRules;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-410 (24 Nov 2026, confirm 1 + confirm 2): new associate commission rules.
 *  - B1 every order of the client earns (no 10-day window) — orders under the new rules.
 *  - B2 below ₹5,00,000 (value of the goods) no commission, except the client's first order.
 *  - B3 orders of the same client within 15 days are one ladder step; a later one is a new step.
 *  - B4 a step counts when the advance has cleared; LC orders when the LC is confirmed.
 *  - B5 a cancelled order loses its step.
 *  - B6 orders whose PI was issued before the cut-over date keep the old rule (2A).
 *  - B7 the MD's per-associate price choice needs a reason and is kept in the Audit Log.
 *  - B8 the group days are 7–45 and a change needs a reason.
 *  - B9 changing what the client's price includes needs a reason.
 * Node twin: tests/integration/associateRulesD410.test.js.
 */
final class AssociateRulesD410Test extends DbTestCase
{
    private static ?Router $router = null;
    private int $md;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__d410-routes-build__';
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
        $this->md = $this->createTestUser('Managing Director');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$this->md}");
        IncentiveRepository::setSetting('associate_counting_threshold_inr', '500000', $this->md);
        IncentiveRepository::setSetting('associate_group_days', '15', $this->md);
        IncentiveRepository::setSetting('associate_rules_from', '', $this->md);
        PricingRepository::setSetting('pricing_fx_rate', '82.0000', $this->md);
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    /** @return array{code: int, body: string, flash: ?string, type: ?string} */
    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_SESSION = ['_auth_user_id' => $this->md, '_session_version' => AuthService::sessionVersion() ?? 0];
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_GET = [];
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
        $all = Flash::pull();
        $flash = end($all) ?: null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null, 'type' => $flash['type'] ?? null];
    }

    private function associate(): array
    {
        $id = AssociateRepository::create(['name' => 'D410 Associate ' . bin2hex(random_bytes(2)), 'based_in' => 'abroad', 'country' => 'USA', 'currency_code' => 'USD',
            'email' => null, 'phone' => null, 'tax_id' => null, 'agreement_signed_on' => '2025-12-01', 'status' => 'active', 'notes' => null], $this->md);
        return AssociateRepository::find($id);
    }

    /** An order of the client with goods of $fobUsd, PO received on $po; the advance cleared (or LC / no advance). */
    private function order(int $clientId, string $po, float $fobUsd, string $how = 'advance'): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM orders WHERE client_id = ' . $clientId)->fetchColumn();
        $pdo->prepare('UPDATE orders SET client_id = :c, sequence_no = :n, buyer_po_received_on = :po, created_at = :ca, payment_method = :pm, advance_pct = :ap WHERE id = :o')
            ->execute(['c' => $clientId, 'n' => $next, 'po' => $po, 'ca' => $po . ' 09:00:00', 'pm' => $how === 'lc' ? 'LC' : 'CASH_ADVANCE', 'ap' => $how === 'advance' ? 30 : 0, 'o' => $orderId]);
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', :p, :f)")
            ->execute(['o' => $orderId, 'p' => $fobUsd / 100, 'f' => $fobUsd]);
        if ($how === 'advance') {
            $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
            $pdo->prepare('UPDATE order_payment_status SET advance_cleared_at = :a WHERE order_id = :o')->execute(['a' => $po . ' 12:00:00', 'o' => $orderId]);
        }
        return $orderId;
    }

    private function steps(array $a): array
    {
        AssociateRules::reset();
        return array_map(static fn (array $r): string => AssociateRules::stepText($r), AssociateRules::history((int) $a['id']));
    }

    public function testB1B3OrdersOfTheSameClientWithin15DaysAreOneStep(): void
    {
        $a = $this->associate();
        $client = $this->createTestClient();
        AssociateRepository::linkClient($client, (int) $a['id'], '2025-12-05');
        $o1 = $this->order($client, '2026-01-01', 9000);
        $this->order($client, '2026-01-10', 9000);
        $this->order($client, '2026-01-20', 9000);
        $ref1 = (string) OrderRepository::find($o1)['order_reference'];
        self::assertSame(['Step 1', 'Same step as ' . $ref1 . ' (same client within 15 days)', 'Step 2'], $this->steps($a));
        self::assertSame(2, AssociateCommission::countingOrders((int) $a['id']));
    }

    public function testB2BelowTheMinimumEarnsNothingExceptTheFirstOrder(): void
    {
        $a = $this->associate();
        $client = $this->createTestClient();
        AssociateRepository::linkClient($client, (int) $a['id'], '2025-12-05');
        $first = $this->order($client, '2026-01-01', 1000);  // about ₹82,000: the client's first order
        $small = $this->order($client, '2026-03-01', 1000);
        AssociateRules::reset();
        self::assertFalse(AssociateCommission::forOrder($first)['below_min'], "the client's first order always earns");
        $c = AssociateCommission::forOrder($small);
        self::assertTrue($c['below_min']);
        self::assertSame(0.0, $c['amount_fc']);
        self::assertSame('Does not count — below ₹5,00,000', $this->steps($a)[1]);
    }

    public function testB4AStepCountsWhenTheAdvanceClearsOrTheLcIsConfirmed(): void
    {
        $a = $this->associate();
        $client = $this->createTestClient();
        AssociateRepository::linkClient($client, (int) $a['id'], '2025-12-05');
        $lc = $this->order($client, '2026-01-01', 9000, 'lc');
        self::assertSame(['Counts when the advance has cleared'], $this->steps($a));
        Database::connection()->prepare('INSERT INTO order_lc_details (order_id, lc_confirmed_at) VALUES (:o, :t)')->execute(['o' => $lc, 't' => '2026-01-05 10:00:00']);
        self::assertSame(['Step 1'], $this->steps($a));
    }

    public function testB5ACancelledOrderLosesItsStep(): void
    {
        $a = $this->associate();
        $client = $this->createTestClient();
        AssociateRepository::linkClient($client, (int) $a['id'], '2025-12-05');
        $o1 = $this->order($client, '2026-01-01', 9000);
        $this->order($client, '2026-02-01', 9000);
        self::assertSame(['Step 1', 'Step 2'], $this->steps($a));
        Database::connection()->exec("UPDATE orders SET status = 'cancelled' WHERE id = {$o1}");
        self::assertSame(['Step 1'], $this->steps($a));
    }

    public function testB6AnOrderWithAPiBeforeTheCutOverKeepsTheOldRule(): void
    {
        $a = $this->associate();
        $client = $this->createTestClient();
        AssociateRepository::linkClient($client, (int) $a['id'], '2025-12-05');
        $this->order($client, '2026-01-01', 9000);
        $old = $this->order($client, '2026-03-01', 9000);
        Database::connection()->exec("UPDATE orders SET associate_level_fixed_at = '2026-03-02 10:00:00' WHERE id = {$old}");
        AssociateRules::reset();
        self::assertNotNull(AssociateCommission::forOrder($old), 'no cut-over: the new rules');
        IncentiveRepository::setSetting('associate_rules_from', '2026-11-24', $this->md);
        AssociateRules::reset();
        self::assertNull(AssociateCommission::forOrder($old), 'PI before the cut-over and after day 10: the old rule, no commission');
    }

    public function testB7ThePerAssociatePriceChoiceNeedsAReason(): void
    {
        $a = $this->associate();
        $r = $this->call('POST', "/associates/{$a['id']}/price-rule", ['price_rule' => 'fixed', 'price_rule_fixed_pct' => '6', 'price_rule_reason' => 'short']);
        self::assertStringContainsString('Give the reason', (string) $r['flash']);
        self::assertNull(AssociateRepository::find((int) $a['id'])['price_rule_override']);
        $r = $this->call('POST', "/associates/{$a['id']}/price-rule", ['price_rule' => 'fixed', 'price_rule_fixed_pct' => '6', 'price_rule_reason' => 'Agreed for the new market']);
        self::assertSame('success', $r['type']);
        $row = AssociateRepository::find((int) $a['id']);
        self::assertSame(['fixed', '6.00'], [$row['price_rule_override'], (string) $row['price_rule_fixed_pct']]);
        self::assertSame(['rule' => 'fixed', 'pct' => 6.0, 'own' => true], AssociateRules::priceRuleFor($row));
        $audit = Database::connection()->query("SELECT new_value FROM audit_log WHERE action_type = 'ASSOCIATE_PRICE_RULE_CHANGED' AND entity_id = {$a['id']} ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertSame('fixed 6.00% — reason: Agreed for the new market', $audit);
        $this->call('POST', "/associates/{$a['id']}/price-rule", ['price_rule' => 'company', 'price_rule_reason' => 'Back to the company setting']);
        self::assertFalse(AssociateRules::priceRuleFor(AssociateRepository::find((int) $a['id']))['own']);
    }

    public function testB8TheGroupDaysAre7To45AndNeedAReason(): void
    {
        $form = ['associate_tds_pct' => '2.00', 'associate_commission_due_days' => '30', 'associate_payout_run' => 'monthly', 'associate_payout_day' => 'last',
            'associate_payout_holiday_rule' => 'before', 'associate_counting_threshold_inr' => '500000', 'associate_protection_months' => '6', 'associate_gst_pct' => '18.00',
            'associate_portal_commission_months' => '12', 'associate_abroad_charges' => 'OUR', 'associate_abroad_min_payout_usd' => '100.00'];
        IncentiveRepository::setSetting('associate_payout_run', 'monthly', $this->md);
        IncentiveRepository::setSetting('associate_payout_day', 'last', $this->md);
        IncentiveRepository::setSetting('associate_payout_holiday_rule', 'before', $this->md);
        self::assertStringContainsString('7 to 45', (string) $this->call('POST', '/associates/ladder', $form + ['associate_group_days' => '60'])['flash']);
        self::assertStringContainsString('Give the reason for changing the minimum order value or the days', (string) $this->call('POST', '/associates/ladder', $form + ['associate_group_days' => '20'])['flash']);
        self::assertSame(15, AssociateRules::groupDays());
        $this->call('POST', '/associates/ladder', $form + ['associate_group_days' => '20', 'ladder_reason' => 'Buyers split container orders over three weeks']);
        self::assertSame(20, AssociateRules::groupDays());
    }

    public function testB9ChangingWhatTheClientPriceIncludesNeedsAReason(): void
    {
        // The change first: PHP's + keeps the left-hand key.
        $r = $this->call('POST', '/pricing/settings', ['associate_price_rule' => 'ladder'] + PricingRepository::settings());
        self::assertSame('error', $r['type']);
        self::assertStringContainsString("Give the reason for changing what the client's price includes", (string) $r['flash']);
        self::assertSame('fixed', AssociateRules::companyPriceRule()['rule']);
    }

    /** Owner 24 Nov 2026 (recommendation 5): "Paid from our margin so far" counts earned commission only; open orders apart. */
    public function testMarginSoFarCountsEarnedCommissionAndShowsOpenOrdersApart(): void
    {
        $a = $this->associate();
        $pdo = Database::connection();
        $today = date('Y-m-d');
        $ids = [];
        foreach (['paid', 'open'] as $kind) {
            $client = $this->createTestClient();
            $pdo->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2025-12-01' WHERE id = :c")->execute(['a' => (int) $a['id'], 'c' => $client]);
            $id = $this->order($client, $today, 10000.0);
            $pdo->prepare("UPDATE orders SET associate_id = :a, associate_level_name = 'Level 3', associate_level_kind = 'pct', associate_level_value = 7,
                           associate_rate_pct = 7, associate_included_pct = 5, associate_level_fixed_at = NOW() WHERE id = :o")->execute(['a' => (int) $a['id'], 'o' => $id]);
            $pdo->prepare('UPDATE order_payment_status SET advance_amount = 3000, advance_inr_actual = 252000, advance_exchange_rate = 84, balance_amount = 7000,
                           balance_cleared_at = :b, balance_inr_actual = :bi, balance_exchange_rate = 84 WHERE order_id = :o')
                ->execute(['b' => $kind === 'paid' ? $today . ' 13:00:00' : null, 'bi' => $kind === 'paid' ? 588000 : null, 'o' => $id]);
            $ins = $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :amt, :d, 84, :inr, 'UTR-MSF')");
            $ins->execute(['o' => $id, 'l' => 'advance', 'amt' => 3000, 'd' => $today, 'inr' => 252000]);
            if ($kind === 'paid') {
                $ins->execute(['o' => $id, 'l' => 'balance', 'amt' => 7000, 'd' => $today, 'inr' => 588000]);
            }
            $ids[$kind] = $id;
        }
        AssociateRules::reset();
        $paid = AssociateCommission::forOrder($ids['paid']);
        $open = AssociateCommission::forOrder($ids['open']);
        self::assertSame(['paid', 'awaiting'], [$paid['state'], $open['state']]);
        $m = AssociateRules::marginSoFar((int) $a['id'], $today);
        self::assertSame([1, 1], [$m['orders'], $m['open_orders']], 'one earned order counted; the order still being paid shown apart');
        self::assertSame(round((float) $paid['amount_inr'] * 2 / 7, 2), $m['inr'], '2 % of the 7 % comes from our margin');
        self::assertSame(round((float) $open['amount_inr'] * 2 / 7, 2), $m['open_inr']);
    }

    public function testIncludedPercentFollowsTheChoice(): void
    {
        $a = $this->associate();
        $level7 = ['kind' => 'pct', 'value' => 7.0];
        self::assertSame(5.0, AssociateRules::includedPct($a, $level7), 'company default: a fixed 5 %');
        self::assertSame(7.0, AssociateRules::includedPct(['price_rule_override' => 'ladder'] + $a, $level7));
        self::assertSame(2.0, AssociateRules::includedPct(['price_rule_override' => 'starting'] + $a, $level7), "the standard ladder's first step (2 %)");
        self::assertNull(AssociateRules::includedPct($a, ['kind' => 'fixed_usd', 'value' => 5.0]), 'a USD-per-unit level is priced as before');
        self::assertSame('Right now for this associate: client price includes 5% · associate is at 7% · 2% comes from our margin.', AssociateRules::rightNow($a, $level7));
    }
}
