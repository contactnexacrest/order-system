<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\UserRepository;
use App\Services\AssociateAccess;
use App\Services\AssociateCommission;
use App\Services\AssociateService;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Task #80 / register D-263 (owner, 6 Nov 2026):
 * - "Commission period shown to associates" (3–24 months, default 12): the portal totals and the
 *   commission list / statement / CSV / print show the last N months, plus anything still unpaid;
 * - an associate never sees a total of orders or business, the running count of their orders,
 *   the ₹ threshold, or a ladder level name; FOB in notices follows "Show FOB order value";
 *   My clients hides order numbers without "orders"; the dashboard upgrade suggestion is gated by
 *   "Manage associates", not the MD role.
 * Node twin: associateCommissionPeriod.test.js.
 */
final class AssociateCommissionPeriodTest extends DbTestCase
{
    private static ?Router $router = null;
    private int $admin;
    private string $savedMonths;

    /** Owner rule (6 Nov 2026): no aggregate count or total of orders or business, anywhere an associate looks. */
    private const AGGREGATE = [
        '/counting order no/i',
        '/\btotal\s+(?:of\s+)?(?:your\s+|all\s+)?(?:orders|business|order value|sales|turnover)/i',
        '/\b(?:orders|business|turnover|order value)\s*(?:\(\s*\d+\s*\)|:\s*(?:<[^>]+>\s*)*(?:₹|USD|EUR|\d))/iu',
        '/\b\d+\s+(?:completed,?\s+)?(?:fully paid\s+|paid\s+)?orders\b/i',
        '/under ₹[\d,]+ within range/iu',
        '/5,00,000|500,000|5 lakh/i',
    ];

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__associate-period-build__';
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
        AssociateAccess::resetCache();
        $this->admin = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$this->admin}");
        $this->savedMonths = (string) (IncentiveRepository::settings()['associate_portal_commission_months'] ?? '12');
    }

    protected function tearDown(): void
    {
        IncentiveRepository::setSetting('associate_portal_commission_months', $this->savedMonths, $this->admin);
        $pdo = Database::connection();
        foreach (AssociateAccess::defaultMatrix() as $level => $fs) {
            foreach ($fs as $f => $on) {
                if ($f !== 'profile') {
                    $pdo->prepare('UPDATE associate_access_level_settings SET allowed = :v WHERE level = :l AND feature_key = :f')->execute(['v' => $on ? 1 : 0, 'l' => $level, 'f' => $f]);
                }
            }
        }
        AssociateAccess::resetCache();
        Flash::pull();
        Flash::pullOld();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

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
        AssociateAccess::resetCache();
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $flash = Flash::pull()[0] ?? null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null];
    }

    private function associate(string $level): array
    {
        $id = AssociateRepository::create(['name' => 'Period Assoc ' . bin2hex(random_bytes(3)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'period-' . bin2hex(random_bytes(4)) . '@assoc.test', 'status' => 'active', 'portal_enabled' => 1], $this->admin);
        Database::connection()->prepare('UPDATE associates SET portal_enabled = 1, access_level = :l WHERE id = :id')->execute(['l' => $level, 'id' => $id]);
        return AssociateRepository::find($id);
    }

    private function custom(array $ticks, string $reason): array
    {
        $a = $this->associate('starter');
        $md = UserRepository::findById($this->createTestUser('Managing Director'));
        self::assertNull(AssociateAccess::setLevel($a, 'custom', $ticks, $reason, $md));
        return AssociateRepository::find((int) $a['id']);
    }

    /** The associate's client with one order (goods FOB 12,345.67). @return array{client:int, order:int, ref:string} */
    private function order(array $a): array
    {
        $pdo = Database::connection();
        $c = $this->createTestClient();
        $pdo->prepare('UPDATE clients SET associate_id = :a WHERE id = :c')->execute(['a' => (int) $a['id'], 'c' => $c]);
        $o = $this->createTestOrder($c);
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 123.4567, 12345.67)")->execute(['o' => $o]);
        $pdo->prepare('UPDATE orders SET associate_id = :a WHERE id = :o')->execute(['a' => (int) $a['id'], 'o' => $o]);
        $ref = $pdo->prepare('SELECT order_reference FROM orders WHERE id = :o');
        $ref->execute(['o' => $o]);
        return ['client' => $c, 'order' => $o, 'ref' => (string) $ref->fetchColumn()];
    }

    private function payout(array $a, int $orderId, string $month, string $status, float $amount, ?string $paidOn = null): int
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, amount_fc, currency_code, incentive_month, status, paid_on, paid_inr, tds_inr)
            VALUES (:o, 'associate', :a, 'associate', :amt, :fc, 'USD', :m, :s, :p, :pi, :t)")->execute([
            'o' => $orderId, 'a' => (int) $a['id'], 'amt' => $amount, 'fc' => round($amount / 85, 2), 'm' => $month, 's' => $status, 'p' => $paidOn,
            'pi' => $status === 'paid' ? round($amount * 0.9, 2) : null, 't' => $status === 'paid' ? round($amount * 0.1, 2) : null]);
        return (int) $pdo->lastInsertId();
    }

    private function asAssociate(array $a): void
    {
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
    }

    private function asStaff(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
    }

    /** The month (Y-m) `back` months before the current month. */
    private static function monthsAgo(int $back): string
    {
        return date('Y-m', strtotime(date('Y-m-01') . " -{$back} months"));
    }

    private static function assertNoAggregate(string $label, string $text): void
    {
        foreach (self::AGGREGATE as $re) {
            $m = [];
            self::assertSame(0, preg_match($re, $text, $m), "{$label}: shows an aggregate of orders or business: " . ($m[0] ?? ''));
        }
    }

    public function testTheWindowIsWorkedOutFromTheCurrentMonth(): void
    {
        self::assertSame('2025-12', AssociatePortalRepository::portalFromMonth(12, '2026-11-15'));
        self::assertSame('2026-09', AssociatePortalRepository::portalFromMonth(3, '2026-11-15'));
        self::assertSame('2024-12', AssociatePortalRepository::portalFromMonth(24, '2026-11-15'));
        self::assertSame('2025-11', AssociatePortalRepository::portalFromMonth(3, '2026-01-31'));
        self::assertSame(12, AssociateCommission::portalMonths([]));
        self::assertSame(12, AssociateCommission::portalMonths(['associate_portal_commission_months' => '7']));
        self::assertSame(18, AssociateCommission::portalMonths(['associate_portal_commission_months' => '18']));
        self::assertSame([3, 6, 9, 12, 15, 18, 24], AssociateCommission::PORTAL_MONTH_CHOICES);
    }

    public function testTotalsAndListShowTheLastNMonthsAndEverythingStillUnpaid(): void
    {
        $a = $this->associate('full');
        $o = $this->order($a);
        $ids = [
            'now' => $this->payout($a, $o['order'], '2026-11', 'paid', 100, '2026-11-10'),
            'june' => $this->payout($a, $o['order'], '2026-06', 'paid', 200, '2026-07-05'),
            'oldPaidLate' => $this->payout($a, $o['order'], '2025-10', 'paid', 400, '2025-12-03'),
            'oldDue' => $this->payout($a, $o['order'], '2025-03', 'due', 800),
            'oldHeld' => $this->payout($a, $o['order'], '2024-05', 'held', 1600),
        ];
        self::assertSame(['earned' => 300.0, 'waiting' => 800.0, 'paid' => 630.0, 'held' => 1600.0, 'months' => 12],
            array_diff_key(AssociatePortalRepository::totals((int) $a['id'], 12, '2026-11-15'), ['fc' => 1]));
        self::assertSame(['earned' => 100.0, 'waiting' => 800.0, 'paid' => 90.0, 'held' => 1600.0, 'months' => 3],
            array_diff_key(AssociatePortalRepository::totals((int) $a['id'], 3, '2026-11-15'), ['fc' => 1]));
        $list = static function (array $rows): array {
            $ids = array_map(static fn (array $p): int => (int) $p['id'], $rows);
            sort($ids);
            return $ids;
        };
        $want12 = [$ids['now'], $ids['june'], $ids['oldDue'], $ids['oldHeld']];
        sort($want12);
        self::assertSame($want12, $list(AssociatePortalRepository::portalPayouts((int) $a['id'], 12, '2026-11-15')));
        $want3 = [$ids['now'], $ids['oldDue'], $ids['oldHeld']];
        sort($want3);
        self::assertSame($want3, $list(AssociatePortalRepository::portalPayouts((int) $a['id'], 3, '2026-11-15')));
        self::assertCount(5, AssociatePortalRepository::payouts((int) $a['id']), 'staff screens keep everything');
    }

    public function testThePortalPagesCsvAndPrintFollowTheSetting(): void
    {
        $a = $this->associate('full');
        $recent = $this->order($a);
        $old = $this->order($a);
        $oldDue = $this->order($a);
        $this->payout($a, $recent['order'], self::monthsAgo(1), 'paid', 1000, self::monthsAgo(1) . '-15');
        $this->payout($a, $old['order'], self::monthsAgo(5), 'paid', 2000, self::monthsAgo(5) . '-20');
        $this->payout($a, $oldDue['order'], self::monthsAgo(8), 'due', 4000);
        IncentiveRepository::setSetting('associate_portal_commission_months', '3', $this->admin);
        $this->asAssociate($a);
        $home = $this->call('GET', '/associate')['body'];
        self::assertStringContainsString('Earned in the last 3 months', $home);
        self::assertStringContainsString('Paid in the last 3 months', $home);
        self::assertStringNotContainsString('this year', $home);
        $page = $this->call('GET', '/associate/commission')['body'];
        self::assertStringContainsString('data-commission-period', $page);
        self::assertStringContainsString('This shows the commission of the last 3 months, and any commission still waiting to be paid.', $page);
        self::assertStringContainsString($recent['ref'], $page);
        self::assertStringNotContainsString($old['ref'], $page, 'paid 5 months ago: outside 3 months');
        self::assertStringContainsString($oldDue['ref'], $page, 'still waiting: always shown');
        $csv = $this->call('GET', '/associate/commission?format=csv')['body'];
        self::assertStringContainsString($recent['ref'], $csv);
        self::assertStringNotContainsString($old['ref'], $csv);
        self::assertStringContainsString($oldDue['ref'], $csv);
        self::assertStringNotContainsString($old['ref'], $this->call('GET', '/associate/commission?print=1')['body']);

        IncentiveRepository::setSetting('associate_portal_commission_months', '12', $this->admin);
        self::assertStringContainsString($old['ref'], $this->call('GET', '/associate/commission')['body']);
        self::assertStringContainsString('Earned in the last 12 months', $this->call('GET', '/associate')['body']);
        $this->asStaff($this->admin);
        self::assertStringContainsString($old['ref'], $this->call('GET', "/associates/{$a['id']}")['body'], 'staff still see every payout');
    }

    public function testTheSettingIsValidatedMdGatedAndAuditLogged(): void
    {
        $md = $this->createTestUser('Managing Director');
        $ed = $this->createTestUser('Executive Director');
        $set = AssociateCommission::settings();
        $fields = [
            'associate_tds_pct' => '2', 'associate_bank_wait_days' => '2', 'associate_commission_due_days' => (string) $set['due_days'], 'associate_payout_run' => $set['run'],
            'associate_payout_day' => $set['last_day'] ? 'last' : (string) $set['day'], 'associate_counting_threshold_inr' => (string) $set['threshold_inr'],
            'associate_protection_months' => (string) $set['registration_months'], 'associate_gst_pct' => (string) $set['gst_pct'],
        ] + ($set['claim_hold'] ? ['associate_claim_hold' => '1'] : []);
        $this->asStaff($md);
        $form = $this->call('GET', '/associates/ladder')['body'];
        self::assertStringContainsString('name="associate_portal_commission_months"', $form);
        self::assertStringContainsString('Commission period shown to associates *', $form);
        $msg = 'Choose the commission period shown to associates: 3, 6, 9, 12, 15, 18 or 24 months.';
        self::assertSame($msg, $this->call('POST', '/associates/ladder', $fields + ['associate_portal_commission_months' => '7'])['flash']);
        self::assertSame($msg, $this->call('POST', '/associates/ladder', $fields + ['associate_portal_commission_months' => ''])['flash']);
        self::assertStringStartsWith('Saved.', (string) $this->call('POST', '/associates/ladder', $fields + ['associate_portal_commission_months' => '6'])['flash']);
        self::assertSame('6', IncentiveRepository::settings()['associate_portal_commission_months']);
        $log = Database::connection()->query("SELECT * FROM audit_log WHERE action_type = 'ASSOCIATE_SETTING_CHANGED' AND field_name = 'associate_portal_commission_months' ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame('6', $log['new_value']);
        self::assertSame($md, (int) $log['user_id']);
        // Without "Manage pricing" (Executive Director) the commission rules are not changed.
        $this->asStaff($ed);
        $this->call('POST', '/associates/ladder', $fields + ['associate_portal_commission_months' => '24']);
        self::assertSame('6', IncentiveRepository::settings()['associate_portal_commission_months']);
    }

    public function testNoAggregateCountOrTotalOfOrdersOrBusinessAtAnyLevel(): void
    {
        $all = array_values(array_filter(array_keys(AssociateAccess::FEATURES), static fn (string $f): bool => $f !== 'profile'));
        foreach (['starter', 'quoting', 'full', 'custom'] as $level) {
            $a = $level === 'custom' ? $this->custom($all, 'Everything on for the totals check') : $this->associate($level);
            $first = $this->order($a);
            $second = $this->order($a);
            $this->order($a);
            $this->payout($a, $first['order'], self::monthsAgo(0), 'paid', 1500, self::monthsAgo(0) . '-01');
            $this->payout($a, $second['order'], self::monthsAgo(1), 'due', 2500);
            AssociateService::notify($a, 'commission_earned', "Your client paid order {$first['ref']} in full. Commission earned: USD 617.28 (5% on the invoiced FOB of the goods, USD 12,345.67).",
                $first['client'], $first['order']);
            $this->asAssociate($a);
            $pages = ['/associate', '/associate/clients', '/associate/orders', "/associate/orders/{$first['order']}", '/associate/commission',
                '/associate/commission?format=csv', '/associate/commission?print=1', '/associate/messages', '/associate/catalogue', '/associate/prices',
                '/associate/profile', "/associate/clients/{$first['client']}/certificate"];
            foreach ($pages as $p) {
                $r = $this->call('GET', $p);
                self::assertLessThan(500, $r['code'], "{$level} {$p}");
                self::assertNoAggregate("{$level} {$p}", $r['body']);
            }
        }
    }

    public function testTheRateOnlyNeverALevelNameAndOnlyWithShowCommissionPct(): void
    {
        self::assertSame('5%', AssociateCommission::rateLabel(['name' => 'Level 2', 'kind' => 'pct', 'value' => '5.0000']));
        self::assertSame('USD 1.50 per unit', AssociateCommission::rateLabel(['name' => 'Level 9', 'kind' => 'fixed_usd', 'value' => '1.5']));
        self::assertSame('no commission level', AssociateCommission::rateLabel(null));
        self::assertSame('5%', AssociateAccess::rateOnly('Level 2 — 5%'));
        self::assertSame('5% (for now — fixed when the PI is issued)', AssociateAccess::rateOnly('Level 2 — 5% (for now — fixed when the PI is issued)'));
        self::assertSame('no level', AssociateAccess::rateOnly('no level'));

        $full = $this->associate('full');
        $filtered = AssociateAccess::filterOrder($full, ['fob_value' => 1.0, 'commission' => ['level' => 'Level 2 — 5%', 'rate' => 5.0,
            'detail' => ['lines' => [], 'level_label' => 'Level 2 — 5%', 'rate' => 5.0, 'counting_number' => 7, 'threshold_inr' => 500000.0, 'counts' => true, 'state' => 'paid']]]);
        self::assertSame('5%', $filtered['commission']['level']);
        self::assertSame('5%', $filtered['commission']['detail']['level_label']);
        self::assertNull($filtered['commission']['detail']['counting_number']);
        self::assertNull($filtered['commission']['detail']['threshold_inr']);
        self::assertTrue($filtered['commission']['detail']['portal']);

        [$level] = AssociateCommission::levelForAssociate($full, null);
        $this->asAssociate($full);
        $profile = $this->call('GET', '/associate/profile')['body'];
        // D-292 (owner 11 Nov 2026): only the current %, "of the FOB value of each order from the clients you introduced".
        self::assertStringContainsString('>' . AssociateCommission::rateLabel($level) . '</span> <b>of the FOB value of each order from the clients you introduced</b>', $profile);
        if ($level !== null) {
            self::assertStringNotContainsString($level['name'] . ' —', $profile);
        }
        self::assertStringNotContainsString('Ladder — your next order', $profile);

        $this->asAssociate($this->associate('starter'));
        self::assertStringContainsString('Your commission is as set out in your agreement.', $this->call('GET', '/associate/profile')['body']);
    }

    public function testTheFobInANoticeFollowsShowFobOrderValue(): void
    {
        $message = 'Your client paid order NX-1 in full. You have earned USD 617.28 commission (5% of the invoice value of the goods, USD 12,345.67).';
        self::assertSame($message, AssociateAccess::maskNotice($this->associate('full'), $message));
        self::assertSame('Your client paid order NX-1 in full. You have earned [amount in your portal] commission ([rate in your agreement] of the invoice value of the goods).',
            AssociateAccess::maskNotice($this->associate('starter'), $message));
        $c = $this->custom(['clients', 'commission', 'commission_pct', 'notice_amounts'], 'Amounts on, FOB value off for this one');
        self::assertSame('Your client paid order NX-1 in full. You have earned USD 617.28 commission (5% of the invoice value of the goods).', AssociateAccess::maskNotice($c, $message));
    }

    public function testMyClientsHidesOrderNumbersWhenOrdersIsOff(): void
    {
        $a = $this->custom(['clients', 'registration_links'], 'Clients only, no orders for this one');
        $o = $this->order($a);
        $this->asAssociate($a);
        $page = $this->call('GET', '/associate/clients')['body'];
        self::assertStringNotContainsString('<th>Orders</th>', $page);
        self::assertStringNotContainsString($o['ref'], $page);
        $s = $this->associate('starter');
        $so = $this->order($s);
        $this->asAssociate($s);
        $withOrders = $this->call('GET', '/associate/clients')['body'];
        self::assertStringContainsString('<th>Orders</th>', $withOrders);
        self::assertStringContainsString($so['ref'], $withOrders);
    }

    public function testTheDashboardUpgradeQuestionFollowsManageAssociatesNotTheMdRole(): void
    {
        $a = $this->associate('starter');
        $pdo = Database::connection();
        for ($i = 0; $i < 3; $i++) {
            $o = $this->order($a)['order'];
            $pdo->prepare("UPDATE orders SET status = 'complete' WHERE id = :o")->execute(['o' => $o]);
            $pdo->prepare('INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at) VALUES (:o, 1000, NOW())
                ON DUPLICATE KEY UPDATE advance_amount = 1000, advance_cleared_at = NOW(), balance_amount = NULL, freight_amount = NULL')->execute(['o' => $o]);
        }
        $this->asStaff($this->createTestUser('Executive Director'));
        self::assertStringContainsString('Associates ready for the Quoting level?', $this->call('GET', '/')['body']);
        $this->asStaff($this->createTestUser('Viewer / Auditor'));
        self::assertStringNotContainsString('Associates ready for the Quoting level?', $this->call('GET', '/')['body']);
    }
}
