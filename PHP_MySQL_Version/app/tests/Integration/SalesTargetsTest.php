<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DashboardController;
use App\Controllers\OrderController;
use App\Controllers\ReportController;
use App\Controllers\UserController;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\SalesTargetRepository;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\SalesTargetService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-378 (15 Nov 2026): sales targets — spec section 5
 * (docs/decisions/D-378-sales-targets-spec.md).
 *
 * The disposable test database is shared by every test class and never reset between
 * them, so every scenario that confirms orders uses its own future dates (2033-2037; TIMESTAMP columns stop at 2038) as a
 * private sandbox, and compares only its own credit rows. Rows this class adds to the
 * feature's own tables (company target, targets, settings) are removed in tearDown().
 *
 * Node twin: NODE_MySQL_Version/app/tests/integration/salesTargets.test.js
 */
final class SalesTargetsTest extends DbTestCase
{
    private static ?Router $router = null;

    /** @var list<int> sales_target_company ids to remove */
    private array $companyRows = [];

    /**
     * What this class leaves behind is removed at the end: sync() credits every confirmed order of the
     * shared database, and runAlerts() records its "once" guards and notices.
     *
     * @var array{at:int, alert_id:int, notice_id:int}|null
     */
    private static ?array $started = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $pdo = Database::connection();
        self::$started = [
            'at' => (int) $pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn(), // seconds: the same in every session time zone
            'alert_id' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM compliance_alerts_sent')->fetchColumn(),
            'notice_id' => (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn(),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$started !== null) {
            $pdo = Database::connection();
            $pdo->prepare('DELETE FROM sales_target_credits WHERE UNIX_TIMESTAMP(created_at) >= :t')->execute(['t' => self::$started['at']]);
            $pdo->prepare("DELETE FROM compliance_alerts_sent WHERE id > :i AND alert_kind LIKE 'sales_target_%'")->execute(['i' => self::$started['alert_id']]);
            $pdo->prepare("DELETE FROM notifications WHERE id > :i AND type LIKE 'sales_target_%'")->execute(['i' => self::$started['notice_id']]);
            self::$started = null;
        }
        parent::tearDownAfterClass();
    }

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__sales-targets-build__';
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
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        SalesTargetService::resetCache();
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        foreach ($this->companyRows as $id) {
            $pdo->exec('DELETE FROM sales_target_company WHERE id = ' . (int) $id);
        }
        $pdo->exec("DELETE FROM sales_targets WHERE reason LIKE 'PHPUnit D-378%'");
        $pdo->exec("UPDATE sales_target_settings SET setting_value = NULL WHERE setting_key IN ('alert_behind_recipients', 'alert_review_recipients', 'alert_no_owner_recipients')");
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

    // ---------------------------------------------------------------- helpers

    private function signIn(int $userId): void
    {
        self::router(); // loading public_html/index.php resets $_SESSION, so it must happen before signing in
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => 0];
        $_SESSION['_session_version'] = (int) Database::connection()->query("SELECT session_version FROM users WHERE id = {$userId}")->fetchColumn();
        PermissionService::resetCache();
    }

    /** @return array{0: int, 1: string} response code and body of one request through the real Router */
    private function get(string $path): array
    {
        self::router();
        $_POST = [];
        $_GET = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch('GET', (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        return [(int) http_response_code(), $body];
    }

    private function render(callable $action): string
    {
        ob_start();
        try {
            $action();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    private function order(?int $clientId = null): int
    {
        return $this->createTestOrder($clientId ?? $this->createTestClient());
    }

    private function receipt(int $orderId, string $on): void
    {
        Database::connection()->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on) VALUES (:o, 'advance', 1000.00, :d)")
            ->execute(['o' => $orderId, 'd' => $on]);
    }

    private function salesDepartmentId(): int
    {
        return (int) Database::connection()->query("SELECT id FROM departments WHERE code = 'SALES'")->fetchColumn();
    }

    private function salesOwner(int $orderId, int $userId): void
    {
        Database::connection()->prepare('INSERT INTO order_department_owners (order_id, department_id, user_id, assigned_by) VALUES (:o, :d, :u, :u2)')
            ->execute(['o' => $orderId, 'd' => $this->salesDepartmentId(), 'u' => $userId, 'u2' => $userId]);
    }

    private function salesDesignationId(string $title = 'Sales/Export Executive'): int
    {
        $stmt = Database::connection()->prepare("SELECT dd.id FROM department_designations dd JOIN departments d ON d.id = dd.department_id WHERE d.code = 'SALES' AND dd.title = :t");
        $stmt->execute(['t' => $title]);
        return (int) $stmt->fetchColumn();
    }

    private function member(int $userId, int $designationId): void
    {
        Database::connection()->prepare('INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default) VALUES (:u, :d, CURDATE(), 1)')
            ->execute(['u' => $userId, 'd' => $designationId]);
    }

    /** @return array<int, array> this test's own credit rows */
    private function credits(array $orderIds): array
    {
        return array_intersect_key(SalesTargetRepository::credits(), array_flip($orderIds));
    }

    private function sendQt(int $orderId, string $sentAt): void
    {
        $doc = $this->createTestDocument($orderId, 'QT', 'sent');
        Database::connection()->prepare(
            "INSERT INTO email_log (order_id, document_id, recipient_email, subject, body_snapshot, sent_at, status)
             VALUES (:o, :d, 'buyer@phpunit-test.test', 'Quotation', 'Body', :s, 'sent')"
        )->execute(['o' => $orderId, 'd' => $doc, 's' => $sentAt]);
    }

    // ---------------------------------------------------------------- periods

    public function testPeriodsFollowTheIndianFinancialYear(): void
    {
        $q = SalesTargetService::period('quarter', '2026-11-15');
        self::assertSame(['2026-10-01', '2026-12-31', 3, 'Oct–Dec 2026'], [$q['start'], $q['end'], $q['months'], $q['label']]);
        $m = SalesTargetService::period('month', '2026-02-10');
        self::assertSame(['2026-02-01', '2026-02-28', 1, 'Feb 2026'], [$m['start'], $m['end'], $m['months'], $m['label']]);
        $h1 = SalesTargetService::period('half', '2026-06-30');
        self::assertSame(['2026-04-01', '2026-09-30', 'Apr–Sep 2026'], [$h1['start'], $h1['end'], $h1['label']]);
        $h2 = SalesTargetService::period('half', '2027-02-01');
        self::assertSame(['2026-10-01', '2027-03-31', 'Oct 2026–Mar 2027'], [$h2['start'], $h2['end'], $h2['label']]);
        $y = SalesTargetService::period('year', '2026-03-31');
        self::assertSame(['2025-04-01', '2026-03-31', 12, 'Apr 2025–Mar 2026'], [$y['start'], $y['end'], $y['months'], $y['label']]);
        self::assertSame('2026-07-01', SalesTargetService::previous($q)['start']);
        self::assertSame(['2026-10-01', '2026-07-01', '2026-04-01', '2026-01-01'], array_column(SalesTargetService::recentPeriods('quarter', '2026-11-15'), 'key'));
        self::assertSame(['2026-10-01', '2026-11-01', '2026-12-01'], SalesTargetService::monthsOf($q));
        self::assertSame(50, SalesTargetService::elapsedPct(SalesTargetService::period('month', '2026-11-15'), '2026-11-15'));
    }

    // ---------------------------------------------------------------- confirmation

    public function testConfirmationRulesAsPureFacts(): void
    {
        self::assertSame(['2034-01-05', 'advance'], SalesTargetService::confirmation(['advance_receipt_on' => '2034-01-05', 'advance_status_on' => '2034-01-01', 'advance_pct' => '40']));
        self::assertSame(['2034-01-01', 'advance'], SalesTargetService::confirmation(['advance_receipt_on' => null, 'advance_status_on' => '2034-01-01', 'advance_pct' => '40']));
        self::assertSame(['2034-01-03', 'lc'], SalesTargetService::confirmation(['advance_receipt_on' => '2034-01-09', 'lc_received_on' => '2034-01-03', 'advance_pct' => '40']));
        // PO + PI: only with no advance, the later of the two.
        $poPi = ['advance_pct' => '0.00', 'buyer_po_received_on' => '2034-01-10', 'frozen_on' => '2034-01-07'];
        self::assertSame(['2034-01-10', 'po_pi'], SalesTargetService::confirmation($poPi));
        self::assertSame([null, null], SalesTargetService::confirmation(['advance_pct' => '40.00'] + $poPi));
        self::assertSame(['2034-01-10', 'po_pi'], SalesTargetService::confirmation(['advance_pct' => null] + $poPi), 'no advance % counts as 0');
        self::assertSame([null, null], SalesTargetService::confirmation(['advance_pct' => '0', 'buyer_po_received_on' => '2034-01-10', 'frozen_on' => null]));
    }

    public function testSyncConfirmsByAdvanceLcAndPoPiAndSkipsTestData(): void
    {
        $pdo = Database::connection();
        $advance = $this->order();
        $this->receipt($advance, '2034-02-10');
        $fallback = $this->order();
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($fallback);
        $pdo->exec("UPDATE order_payment_status SET advance_remittance_received_at = '2034-02-18 09:00:00' WHERE order_id = {$fallback}");
        $lc = $this->order();
        $pdo->exec("INSERT INTO order_lc_details (order_id, lc_number, lc_received_at) VALUES ({$lc}, 'LC-D378', '2034-02-12')");
        $poPi = $this->order();
        $pdo->exec("UPDATE orders SET advance_pct = 0, buyer_po_received_on = '2034-02-14', frozen_at = '2034-02-16 10:00:00' WHERE id = {$poPi}");
        $withAdvanceTerms = $this->order();
        $pdo->exec("UPDATE orders SET advance_pct = 40, buyer_po_received_on = '2034-02-14', frozen_at = '2034-02-16 10:00:00' WHERE id = {$withAdvanceTerms}");
        $testData = $this->order();
        $pdo->exec("UPDATE orders SET is_test_data = 1 WHERE id = {$testData}");
        $this->receipt($testData, '2034-02-10');
        $lostFirst = $this->order();
        $pdo->exec("UPDATE orders SET status = 'lost', lost_at = '2034-02-01 10:00:00' WHERE id = {$lostFirst}");
        $this->receipt($lostFirst, '2034-02-03');

        SalesTargetService::sync('2034-03-01');
        $c = $this->credits([$advance, $fallback, $lc, $poPi, $withAdvanceTerms, $testData, $lostFirst]);

        self::assertSame(['2034-02-10', 'advance'], [$c[$advance]['confirmed_on'], $c[$advance]['how']]);
        self::assertSame(['2034-02-18', 'advance'], [$c[$fallback]['confirmed_on'], $c[$fallback]['how']]);
        self::assertSame(['2034-02-12', 'lc'], [$c[$lc]['confirmed_on'], $c[$lc]['how']]);
        self::assertSame(['2034-02-16', 'po_pi'], [$c[$poPi]['confirmed_on'], $c[$poPi]['how']]);
        self::assertArrayNotHasKey($withAdvanceTerms, $c, 'PO + PI counts only on no-advance terms');
        self::assertArrayNotHasKey($testData, $c, 'test data never counts');
        $pdo->exec("UPDATE orders SET is_test_data = 0 WHERE id = {$testData}"); // leave no test data behind (Test Mode cannot be switched off while any exists)
        self::assertArrayNotHasKey($lostFirst, $c, 'lost before it was confirmed never counts');
        // Containers: the estimate from the goods lines, at least 1 (these orders have none).
        self::assertSame(1, (int) $c[$advance]['containers_confirmed']);
        self::assertSame(1, SalesTargetService::estimatedContainers($advance));

        // A second run changes nothing (dates are kept).
        $pdo->exec("UPDATE order_payment_receipts SET received_on = '2034-01-01' WHERE order_id = {$advance}");
        SalesTargetService::sync('2034-03-02');
        self::assertSame('2034-02-10', $this->credits([$advance])[$advance]['confirmed_on']);
    }

    // ---------------------------------------------------------------- corrections

    public function testCorrectionsAreDatedEvents(): void
    {
        $row = static fn (int $id, string $conf, int $cc, ?string $shipped = null, ?int $cs = null, ?string $rev = null): array => [
            'order_id' => $id, 'client_id' => $id, 'confirmed_on' => $conf, 'how' => 'advance', 'containers_confirmed' => $cc,
            'shipped_on' => $shipped, 'containers_shipped' => $cs, 'reversed_on' => $rev, 'reversed_why' => $rev ? 'cancelled' : null, 'is_new_buyer' => 0,
        ];
        // Cancelled in the same period: 0.
        self::assertSame(0, SalesTargetService::periodCounts([1 => $row(1, '2035-04-05', 2, null, null, '2035-05-01')], '2035-04-01', '2035-06-30')['counted']);
        // Cancelled in a later period: +2 stays in Apr–Jun, −2 in Jul–Sep.
        $later = [1 => $row(1, '2035-04-05', 2, null, null, '2035-07-20')];
        self::assertSame(2, SalesTargetService::periodCounts($later, '2035-04-01', '2035-06-30')['counted']);
        $q3 = SalesTargetService::periodCounts($later, '2035-07-01', '2035-09-30');
        self::assertSame([-2, -2], [$q3['counted'], $q3['corrections']]);
        // Shipped fewer containers than confirmed: the difference on the shipping day.
        $less = [1 => $row(1, '2035-04-05', 3, '2035-08-02', 2)];
        self::assertSame(3, SalesTargetService::periodCounts($less, '2035-04-01', '2035-06-30')['counted']);
        self::assertSame(-1, SalesTargetService::periodCounts($less, '2035-07-01', '2035-09-30')['counted']);
        $same = SalesTargetService::periodCounts([1 => $row(1, '2035-04-05', 3, '2035-05-02', 2)], '2035-04-01', '2035-06-30');
        self::assertSame([2, 3, -1, 2, 0], [$same['counted'], $same['confirmed'], $same['corrections'], $same['shipped'], $same['awaiting']]);
    }

    public function testSyncRecordsCancellationLossAndShipment(): void
    {
        $pdo = Database::connection();
        $requester = $this->createTestUser('Export Executive');
        $cancelledLater = $this->order();
        $this->receipt($cancelledLater, '2036-04-05');
        $lostSame = $this->order();
        $this->receipt($lostSame, '2036-04-06');
        $shipped = $this->order();
        $this->receipt($shipped, '2036-04-07');
        SalesTargetService::sync('2036-04-10');

        $pdo->exec("UPDATE orders SET status = 'cancelled' WHERE id = {$cancelledLater}");
        $pdo->exec("INSERT INTO order_cancellations (order_id, reason, requested_by, status, decided_at) VALUES ({$cancelledLater}, 'PHPUnit D-378 cancellation', {$requester}, 'approved', '2036-07-20 10:00:00')");
        $pdo->exec("UPDATE orders SET status = 'lost', lost_at = '2036-05-01 10:00:00' WHERE id = {$lostSame}");
        $ci = $this->createTestDocument($shipped, 'CI', 'approved');
        $pdo->exec("UPDATE documents SET document_date = '2036-05-10' WHERE id = {$ci}");
        $pdo->exec("INSERT INTO order_shipping (order_id, container_count) VALUES ({$shipped}, 3)");
        SalesTargetService::sync('2036-08-01');

        $c = $this->credits([$cancelledLater, $lostSame, $shipped]);
        self::assertSame(['2036-07-20', 'cancelled'], [$c[$cancelledLater]['reversed_on'], $c[$cancelledLater]['reversed_why']]);
        self::assertSame(['2036-05-01', 'lost'], [$c[$lostSame]['reversed_on'], $c[$lostSame]['reversed_why']]);
        self::assertSame(['2036-05-10', 3], [$c[$shipped]['shipped_on'], (int) $c[$shipped]['containers_shipped']]);

        $q2 = SalesTargetService::periodCounts($c, '2036-04-01', '2036-06-30');
        // +1 +1 +1 confirmed, −1 lost the same quarter, +2 shipped more than confirmed.
        self::assertSame([4, 3, 1], [$q2['counted'], $q2['confirmed'], $q2['corrections']]);
        self::assertSame(-1, SalesTargetService::periodCounts($c, '2036-07-01', '2036-09-30')['counted'], 'the later cancellation is a correction in its own quarter');
    }

    // ---------------------------------------------------------------- new buyer and credit

    public function testTheFirstConfirmedOrderOfAClientIsTheNewBuyer(): void
    {
        $client = $this->createTestClient();
        $second = $this->order($client);
        $this->receipt($second, '2034-08-02');
        $first = $this->order($client);
        $this->receipt($first, '2034-08-01');
        SalesTargetService::sync('2034-08-10');
        $c = $this->credits([$first, $second]);
        self::assertSame(1, (int) $c[$first]['is_new_buyer']);
        self::assertSame(0, (int) $c[$second]['is_new_buyer']);
        self::assertSame(1, SalesTargetService::periodCounts($c, '2034-07-01', '2034-09-30')['new_buyers']);
    }

    public function testCreditGoesToTheSalesOwnerUnlessTheMdMovedIt(): void
    {
        $owner = $this->createTestUser('Export Executive');
        $other = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        $orderId = $this->order();
        self::assertNull(SalesTargetRepository::creditUsers([$orderId])[$orderId], 'nobody: Unassigned');
        $this->salesOwner($orderId, $owner);
        self::assertSame($owner, SalesTargetRepository::creditUsers([$orderId])[$orderId]);

        self::assertNotNull(SalesTargetService::moveCredit($orderId, (string) $other, 'short', $md), 'a reason is required');
        self::assertNull(SalesTargetService::moveCredit($orderId, (string) $other, 'PHPUnit D-378 closed while the owner was away', $md));
        self::assertSame($other, SalesTargetRepository::creditUsers([$orderId])[$orderId]);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ORDER_SALES_CREDIT_MOVED' AND entity_id = {$orderId}")->fetchColumn());
        self::assertNull(SalesTargetService::moveCredit($orderId, 'auto', 'PHPUnit D-378 back to the department owner', $md));
        self::assertSame($owner, SalesTargetRepository::creditUsers([$orderId])[$orderId]);
    }

    // ---------------------------------------------------------------- company target

    public function testCompanyTargetOffBreakEvenAndProfit(): void
    {
        $period = SalesTargetService::period('quarter', '2037-11-15');
        self::assertFalse(SalesTargetService::companyTarget($period, '2037-11-15', [])['on'], 'no row: off');
        $this->companyRows[] = SalesTargetRepository::insertCompanyRow('2037-10-01', 0.0, 0.0, 'PHPUnit D-378 zero', 1);
        self::assertFalse(SalesTargetService::companyTarget($period, '2037-11-15', [])['on'], 'costs and profit 0: off');
        $this->companyRows[] = SalesTargetRepository::insertCompanyRow('2037-10-01', 100000.0, 50000.0, 'PHPUnit D-378 target', 1);
        $on = SalesTargetService::companyTarget($period, '2037-11-15', []);
        self::assertTrue($on['on']);
        self::assertSame(450000.0, $on['needed'], '(costs + profit) × 3 months');
        self::assertNull($on['avg_margin'], 'no shipment in the last 3 months: not enough history yet');
        self::assertNull($on['containers_needed']);

        $math = SalesTargetService::companyMath(100000.0, 50000.0, 3, 30000.0);
        self::assertSame([450000.0, 15, 10], [$math['needed'], $math['containers_needed'], $math['breakeven_containers']]);
        self::assertSame([16, 11], [
            SalesTargetService::companyMath(100000.0, 50000.0, 3, 29000.0)['containers_needed'],
            SalesTargetService::companyMath(100000.0, 50000.0, 3, 29000.0)['breakeven_containers'],
        ], 'rounded up');
        self::assertNull(SalesTargetService::companyMath(100000.0, 0.0, 3, null)['breakeven_containers']);
        // The row that applies is the latest one on or before the period start.
        self::assertFalse(SalesTargetService::companyTarget(SalesTargetService::period('quarter', '2037-08-15'), '2037-08-15', [])['on']);
    }

    // ---------------------------------------------------------------- build-up and personal targets

    public function testBuildUpFactors(): void
    {
        self::assertSame([0.0, 0.0, 0.5, 1.0, 1.0], array_map(static fn (int $k): float => SalesTargetService::buildupFactor($k, 3), [0, 1, 2, 3, 4]));
        self::assertSame([0.0, 0.5, 0.5, 1.0], array_map(static fn (int $k): float => SalesTargetService::buildupFactor($k, 4), [1, 2, 3, 4]));
        self::assertSame(1.0, SalesTargetService::buildupFactor(1, 1));
        self::assertSame(1.0, SalesTargetService::buildupFactor(1, 0));
        self::assertSame(0.0, SalesTargetService::buildupFactor(0, 0), 'a month before joining');
        self::assertSame(1, SalesTargetService::monthsSinceJoining('2034-11-20', '2034-11-01'));
        self::assertSame(3, SalesTargetService::monthsSinceJoining('2034-11-20', '2035-01-01'));
    }

    public function testPersonalTargetUsesTheDesignationDefaultAndTheBuildUp(): void
    {
        $person = $this->createTestUser('Export Executive');
        $designation = $this->salesDesignationId();
        $this->member($person, $designation);
        SalesTargetRepository::saveTarget('designation', $designation, ['containers' => 6, 'quotations' => 10, 'reason' => 'PHPUnit D-378 default'], 1);
        Database::connection()->exec("UPDATE users SET joined_on = '2034-11-10' WHERE id = {$person}");
        $settings = ['buildup_months' => '3'] + SalesTargetService::settings();

        // Oct (before joining) 0, Nov (month 1) 0, Dec (month 2) 0.5 → 6 × 0.5 / 3 = 1.
        $t = SalesTargetService::personalTarget($person, SalesTargetService::period('quarter', '2034-11-15'), $settings);
        self::assertSame([1.0, 'designation', true, 10], [$t['containers'], $t['source'], $t['in_buildup'], $t['quotations']]);
        $full = SalesTargetService::personalTarget($person, SalesTargetService::period('quarter', '2035-05-15'), $settings);
        self::assertSame([6.0, false], [$full['containers'], $full['in_buildup']]);

        // Own target wins; build-up switched off for the person.
        SalesTargetRepository::saveTarget('user', $person, ['containers' => 9, 'buildup_enabled' => 0, 'multiple' => 2.5, 'reason' => 'PHPUnit D-378 own'], 1);
        $own = SalesTargetService::personalTarget($person, SalesTargetService::period('quarter', '2034-11-15'), $settings);
        self::assertSame([9.0, 'own', false, 2.5], [$own['containers'], $own['source'], $own['in_buildup'], $own['multiple']]);
    }

    public function testSavingAPersonsTargetNeedsAReasonAndMarksItReviewed(): void
    {
        $person = $this->createTestUser('Export Executive');
        $md = $this->createTestUser('Managing Director');
        self::assertNotNull(SalesTargetService::savePerson($person, ['containers' => '5', 'reason' => ''], $md));
        self::assertNotNull(SalesTargetService::savePerson($person, ['containers' => 'five', 'reason' => 'PHPUnit D-378 quarterly review'], $md));
        self::assertNull(SalesTargetService::savePerson($person, ['containers' => '5', 'new_buyers' => '2', 'multiple' => '', 'buildup_present' => '1', 'reason' => 'PHPUnit D-378 quarterly review'], $md));
        $row = SalesTargetRepository::target('user', $person);
        self::assertSame(['5.0', '2', null, '0'], [$row['containers'], (string) $row['new_buyers'], $row['multiple'], (string) $row['buildup_enabled']]);
        self::assertNotNull($row['reviewed_at']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'SALES_TARGET_CHANGED' AND entity_id = {$person}")->fetchColumn());
    }

    // ---------------------------------------------------------------- who sees what

    public function testOnlyHoldersOpenTheTargetsPage(): void
    {
        $this->signIn($this->createTestUser('Export Executive'));
        self::assertSame(403, $this->get('/sales-targets')[0]);
        self::assertSame(403, $this->get('/sales-targets/settings')[0]);

        $this->signIn($this->createTestUser('Managing Director'));
        [$code, $body] = $this->get('/sales-targets');
        self::assertSame(200, $code);
        self::assertStringContainsString('Sales targets —', $body);
        self::assertStringContainsString('id="target-team"', $body);
        [$code, $body] = $this->get('/sales-targets/settings');
        self::assertSame(200, $code);
        self::assertStringContainsString('name="costs_monthly"', $body, 'the MD also sees the price build-up: the money fields');
    }

    public function testMoneyFiguresNeedViewPriceBuildupToo(): void
    {
        $pdo = Database::connection();
        $pdo->exec("INSERT IGNORE INTO roles (name, description, is_system_role) VALUES ('Test Role — Targets Only', 'Test only', 0)");
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT r.id, p.id FROM roles r JOIN permissions p ON p.permission_key = 'manage_sales_targets' WHERE r.name = 'Test Role — Targets Only'");
        // D-379: the full Targets page is the All level of the Sales targets report.
        $pdo->exec("INSERT IGNORE INTO role_report_access (role_id, report_key, level)
                    SELECT r.id, 'sales_targets', 'all' FROM roles r WHERE r.name = 'Test Role — Targets Only'");
        $this->companyRows[] = SalesTargetRepository::insertCompanyRow('2000-01-01', 100000.0, 50000.0, 'PHPUnit D-378 money', 1);

        $this->signIn($this->createTestUser('Test Role — Targets Only'));
        [$code, $body] = $this->get('/sales-targets');
        self::assertSame(200, $code);
        self::assertStringContainsString('Containers needed', $body);
        self::assertStringNotContainsString('Needed (₹)', $body);
        self::assertStringNotContainsString('Average margin per container, last 3 months', $body);
        self::assertStringNotContainsString('name="costs_monthly"', $this->get('/sales-targets/settings')[1]);

        $this->signIn($this->createTestUser('Managing Director'));
        self::assertStringContainsString('Needed (₹)', $this->get('/sales-targets')[1]);
        self::assertStringContainsString('id="cockpit-sales-target"', $this->get('/cockpit')[1], 'the cockpit box while the company target is on');
    }

    public function testTheSalespersonSeesOnlyTheirOwnTargetInContainers(): void
    {
        $person = $this->createTestUser('Export Executive');
        $colleague = $this->createTestUser('Export Executive');
        SalesTargetRepository::saveTarget('user', $person, ['containers' => 4, 'reason' => 'PHPUnit D-378 own'], 1);
        $mine = $this->order();
        $this->salesOwner($mine, $person);
        $this->receipt($mine, date('Y-m-d'));
        $theirs = $this->order();
        $this->salesOwner($theirs, $colleague);
        $this->receipt($theirs, date('Y-m-d'));
        SalesTargetService::sync();
        $ref = static fn (int $id): string => (string) Database::connection()->query("SELECT order_reference FROM orders WHERE id = {$id}")->fetchColumn();

        $this->signIn($person);
        $page = $this->render(fn () => (new DashboardController())->index([]));
        $start = strpos($page, 'id="your-target"');
        self::assertNotFalse($start);
        $box = substr($page, $start, (int) (strpos($page, '<div class="card', $start) ?: strlen($page)) - $start);
        self::assertStringContainsString('Your target —', $box);
        self::assertStringContainsString('4 containers', $box);
        self::assertStringContainsString($ref($mine), $box);
        self::assertStringNotContainsString($ref($theirs), $box, "a colleague's order is never shown");
        self::assertStringNotContainsString('₹', $box, 'no money');
        self::assertSame(1, SalesTargetService::dashboardFor($person, date('Y-m-d'), false)['counts']['counted']);

        $this->signIn($colleague);
        self::assertStringNotContainsString('id="your-target"', $this->render(fn () => (new DashboardController())->index([])), 'no target, no box');
    }

    public function testAssociatesSeeNothingOfTheTargets(): void
    {
        $dir = dirname(__DIR__, 2) . '/views/associate_portal';
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $text = (string) file_get_contents($file);
            self::assertStringNotContainsString('sales-target', $text, basename($file));
            self::assertStringNotContainsString('SalesTarget', $text, basename($file));
        }
        $routes = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/index.php');
        preg_match_all("#\\\$router->(?:get|post)\\('/sales-targets[^']*',[^\n]*#", $routes, $m);
        self::assertCount(6, $m[0]);
        foreach ($m[0] as $line) {
            self::assertStringNotContainsString('AssociateAuth', $line);
            if (str_starts_with($line, "\$router->get('/sales-targets',")) {
                // D-379: the page opens at the Department level of the Sales targets report (read-only team view).
                self::assertStringContainsString("ReportAccessCheck::requires('sales_targets', 'department')", $line);
                continue;
            }
            self::assertStringContainsString("PermissionCheck::requires('manage_sales_targets')", $line);
            self::assertStringContainsString("ReportAccessCheck::requires('sales_targets', 'all')", $line);
        }
    }

    public function testTheCalculatorInputsHaveNoNameAndSitOutsideEveryForm(): void
    {
        $this->signIn($this->createTestUser('Managing Director'));
        [, $body] = $this->get('/sales-targets');
        $at = strpos($body, 'id="target-calculator"');
        self::assertNotFalse($at);
        $end = strpos($body, '</script>', $at);
        $calc = substr($body, $at, $end - $at);
        self::assertStringContainsString('id="calc-cost"', $calc);
        self::assertStringNotContainsString('name=', $calc, 'nothing typed in the calculator can be posted');
        self::assertStringNotContainsString('<form', $calc);
        $before = substr($body, 0, $at);
        self::assertSame(substr_count($before, '<form'), substr_count($before, '</form>'), 'not inside a form');
    }

    // ---------------------------------------------------------------- Sales Performance

    public function testSalesPerformanceDefaultPageIsUnchangedAndBySalespersonIsOptional(): void
    {
        $this->signIn($this->createTestUser('Managing Director'));
        $_GET = ['date_from' => '2033-03-01', 'date_to' => '2033-03-31'];
        $plain = $this->render(fn () => (new ReportController())->performance([]));
        $_GET = ['date_from' => '2033-03-01', 'date_to' => '2033-03-31', 'salesperson' => ''];
        $empty = $this->render(fn () => (new ReportController())->performance([]));
        self::assertSame($plain, $empty, "salesperson '' renders exactly the default page");
        self::assertStringNotContainsString('id="performance-by-salesperson"', $plain);

        $seller = $this->createTestUser('Export Executive');
        $won = $this->order();
        $this->salesOwner($won, $seller);
        $this->sendQt($won, '2033-03-05 10:00:00');
        $this->createTestDocument($won, 'PI', 'sent');
        Database::connection()->exec("UPDATE orders SET pi_date = '2033-03-20' WHERE id = {$won}");
        $lost = $this->order();
        $this->salesOwner($lost, $seller);
        Database::connection()->exec("UPDATE orders SET status = 'lost', lost_at = '2033-03-10 10:00:00', lost_category = 'price' WHERE id = {$lost}");

        $rows = SalesTargetService::performanceBySalesperson('2033-03-01', '2033-03-31', (string) $seller);
        self::assertCount(1, $rows);
        self::assertSame([1, 1, 1, 0, 100.0, 15.0, 'Price'], [
            $rows[0]['quotes'], $rows[0]['won'], $rows[0]['lost_before_pi'], $rows[0]['lost_after_pi'],
            $rows[0]['win_rate_pct'], $rows[0]['avg_days_qt_to_pi'], $rows[0]['top_lost_category'],
        ]);
        $_GET = ['date_from' => '2033-03-01', 'date_to' => '2033-03-31', 'salesperson' => 'all'];
        $all = $this->render(fn () => (new ReportController())->performance([]));
        self::assertStringContainsString('id="performance-by-salesperson"', $all);
        // The CSV export is unchanged (the lost-orders table only).
        self::assertSame(['Order', 'Client', 'Lost On', 'Stage Reached', 'Reason'], ReportController::performanceCsvRows(['lost_orders' => []])['headers']);
    }

    // ---------------------------------------------------------------- Mark Lost, joined_on

    public function testMarkLostSavesTheOptionalCategoryOnlyWhenListed(): void
    {
        $md = $this->createTestUser('Managing Director');
        $this->signIn($md);
        $cases = [['price', 'price'], ['bogus', null], ['', null]];
        foreach ($cases as [$posted, $expected]) {
            $orderId = $this->order();
            $_POST = ['reason' => 'PHPUnit D-378 buyer chose another supplier', 'lost_category' => $posted];
            $this->render(fn () => (new OrderController())->markLost(['id' => (string) $orderId]));
            $row = Database::connection()->query("SELECT status, lost_category FROM orders WHERE id = {$orderId}")->fetch();
            self::assertSame(['lost', $expected], [$row['status'], $row['lost_category']], "category '{$posted}'");
        }
        $orderId = $this->order();
        $_POST = ['reason' => '', 'lost_category' => 'price'];
        $this->render(fn () => (new OrderController())->markLost(['id' => (string) $orderId]));
        self::assertSame('active', Database::connection()->query("SELECT status FROM orders WHERE id = {$orderId}")->fetchColumn(), 'the reason stays required');
    }

    public function testJoinedOnIsSavedOnlyWhenTheFormCarriesIt(): void
    {
        $admin = $this->createTestUser('Admin');
        $this->signIn($admin);
        $person = $this->createTestUser('Export Executive');
        $u = Database::connection()->query("SELECT name, email, role_id FROM users WHERE id = {$person}")->fetch();
        $base = ['name' => $u['name'], 'email' => $u['email'], 'phone' => '', 'role_id' => (string) $u['role_id']];

        $_POST = $base + ['joined_on_present' => '1', 'joined_on' => '2026-03-01'];
        $this->render(fn () => (new UserController())->update(['id' => (string) $person]));
        self::assertSame('2026-03-01', Database::connection()->query("SELECT joined_on FROM users WHERE id = {$person}")->fetchColumn());

        $_POST = $base + ['joined_on' => ''];
        $this->render(fn () => (new UserController())->update(['id' => (string) $person]));
        self::assertSame('2026-03-01', Database::connection()->query("SELECT joined_on FROM users WHERE id = {$person}")->fetchColumn(), 'not on the form: unchanged');

        $_POST = $base + ['joined_on_present' => '1', 'joined_on' => '2026-02-31'];
        $this->render(fn () => (new UserController())->update(['id' => (string) $person]));
        self::assertSame('2026-03-01', Database::connection()->query("SELECT joined_on FROM users WHERE id = {$person}")->fetchColumn(), 'an invalid date is refused');

        $_POST = $base + ['joined_on_present' => '1', 'joined_on' => ''];
        $this->render(fn () => (new UserController())->update(['id' => (string) $person]));
        self::assertNull(Database::connection()->query("SELECT joined_on FROM users WHERE id = {$person}")->fetchColumn() ?: null, 'cleared');
    }

    // ---------------------------------------------------------------- alerts

    public function testAlertRecipientsDefaultsAndSave(): void
    {
        $seededMd = (int) Database::connection()->query("SELECT id FROM users WHERE email = 'md@email.com'")->fetchColumn();
        $salesManager = $this->createTestUser('Sales/Export Manager');
        $behind = SalesTargetService::alertRecipients('behind');
        self::assertContains($seededMd, $behind, 'default: the MD and the ED');
        self::assertNotContains($salesManager, $behind);
        self::assertContains($salesManager, SalesTargetService::alertRecipients('no_owner'), 'no salesperson: the MD and the Sales/Export Manager');
        self::assertContains($seededMd, SalesTargetService::alertRecipients('no_owner'));

        $admin = $this->createTestUser('Admin');
        // Saving the defaults unchanged keeps them as defaults (NULL).
        $form = SalesTargetService::alertRecipientsForForm();
        self::assertSame([], SalesTargetService::saveAlertRecipients($form, $admin, 'PHPUnit D-378 no change'));
        self::assertNull(SalesTargetService::settings()['alert_behind_recipients']);

        $changes = SalesTargetService::saveAlertRecipients(['behind' => [(string) $salesManager], 'review' => [], 'no_owner' => $form['no_owner']], $admin, 'PHPUnit D-378 recipients');
        self::assertCount(2, $changes);
        self::assertSame([$salesManager], SalesTargetService::alertRecipients('behind'));
        self::assertSame('', SalesTargetService::settings()['alert_review_recipients'], 'nobody ticked is stored as empty');
        self::assertContains($seededMd, SalesTargetService::alertRecipients('review'), 'nobody ticked: the MD');
        self::assertNull(SalesTargetService::settings()['alert_no_owner_recipients']);
    }

    public function testAnOrderWithNoSalespersonIsAlertedOnce(): void
    {
        // One person ticked for this alert (Admin › E-mail settings): sync() credits every confirmed order of the
        // shared test database, and the defaults (every MD and Sales/Export Manager the other classes made) would
        // multiply the notices by hundreds. The defaults themselves are checked in the alert recipients test above.
        $recipient = $this->createTestUser('Managing Director');
        Database::connection()->prepare("UPDATE sales_target_settings SET setting_value = :v WHERE setting_key = 'alert_no_owner_recipients'")->execute(['v' => (string) $recipient]);
        $orderId = $this->order();
        $this->receipt($orderId, '2035-04-02');
        SalesTargetService::sync('2035-04-05');
        $count = static fn (): int => (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE type = 'sales_target_no_owner' AND related_order_id = {$orderId}")->fetchColumn();
        SalesTargetService::runAlerts('2035-04-05');
        self::assertSame(1, $count(), 'the one person ticked');
        SalesTargetService::runAlerts('2035-04-06');
        self::assertSame(1, $count(), 'once per order');
    }

    // ---------------------------------------------------------------- seed

    public function testThePermissionAndDefaultSettingsAreSeeded(): void
    {
        $pdo = Database::connection();
        self::assertSame('reports', $pdo->query("SELECT category FROM permissions WHERE permission_key = 'manage_sales_targets'")->fetchColumn());
        $roles = $pdo->query("SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                               WHERE p.permission_key = 'manage_sales_targets' ORDER BY r.name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('Managing Director', $roles);
        self::assertContains('Executive Director', $roles);
        self::assertNotContains('Export Executive', $roles);
        $s = SalesTargetService::settings();
        self::assertSame(['quarter', '3', '3', '12', '60'], [$s['period'], $s['multiple'], $s['buildup_months'], $s['review_months'], $s['alert_pct']]);
        self::assertSame(8, (int) $pdo->query('SELECT COUNT(*) FROM sales_target_settings')->fetchColumn());
    }
}
