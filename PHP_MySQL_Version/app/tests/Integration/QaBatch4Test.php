<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Repositories\CaRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderShippingRepository;
use App\Repositories\PaymentMilestoneRepository;
use App\Services\AssociateCommission;
use App\Services\AssociateTds;
use App\Services\CaSyncService;
use App\Services\IncentiveService;
use App\Services\OrderProfitabilityService;
use App\Services\PartPaymentService;
use App\Services\StageGateService;
use App\Services\ZohoBooksService;
use App\Services\ZohoSyncLock;
use App\Tests\Support\DbTestCase;

/**
 * QA fix batch 4 (5 Nov 2026): money, Zoho and the two order findings the
 * owner decided (ORD-09, ORD-14). Node twin: tests/integration/qaBatch4.test.js,
 * same figures.
 */
final class QaBatch4Test extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT']);
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    /** A TEST- USD order with one goods line worth $fob, advance $advPct % recorded and cleared at ₹83 (INR credited). */
    private function usdOrder(float $fob, float $advPct = 30.0): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $pdo->prepare('UPDATE orders SET advance_pct = :p WHERE id = :o')->execute(['p' => $advPct, 'o' => $orderId]);
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type) VALUES (:o, 1, 'Granite', 1, 'SQM', :f, :f2, 'goods')")
            ->execute(['o' => $orderId, 'f' => $fob, 'f2' => $fob]);
        $advance = round($fob * $advPct / 100, 2);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = :a, advance_remittance_received_at = '2026-10-05', advance_cleared_at = '2026-10-06 10:00:00',
            advance_inr_actual = :ai, advance_exchange_rate = 83 WHERE order_id = :o")->execute(['a' => $advance, 'ai' => round($advance * 83, 2), 'o' => $orderId]);
        return $orderId;
    }

    private function milestone(int $orderId, float $pct, ?float $received = null, ?float $credited = null): int
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO order_payment_milestones (order_id, seq, pct, due_text, received_amount, received_on, exchange_rate) VALUES (:o, 1, :p, 'on dispatch', :r, :d, :x)")
            ->execute(['o' => $orderId, 'p' => $pct, 'r' => $received, 'd' => $received !== null ? '2026-10-20' : null, 'x' => $received !== null ? 83 : null]);
        $id = (int) $pdo->lastInsertId();
        if ($received !== null) {
            $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, milestone_id, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'milestone', :m, :a, '2026-10-20', 83, :i, 'UTR-QA4-M1')")
                ->execute(['o' => $orderId, 'm' => $id, 'a' => $received, 'i' => $credited]);
        }
        return $id;
    }

    /** MON-1: TDS is never more than the payout; what the cap cuts off is carried to the next payout. */
    public function testMon1TdsIsCappedAtThePayoutAndTheRestCarriedForward(): void
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO associates (name, status, based_in, pan) VALUES ('QA4 Associate no PAN', 'active', 'india', NULL)")->execute();
        $a = (int) $pdo->lastInsertId();
        $orderId = $this->createTestOrder($this->createTestClient());
        $paidOn = date('Y-m-d');
        // ₹19,800 paid earlier this year with no TDS (below the ₹20,000 threshold).
        $pdo->prepare("INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status, paid_on, paid_inr, tds_inr)
                       VALUES (:o, 'associate', :a, 'associate', 19800, :m, 'paid', :d, 19800, 0)")->execute(['o' => $orderId, 'a' => $a, 'm' => substr($paidOn, 0, 7), 'd' => $paidOn]);
        // A ₹300 payout crosses it: 20% (no PAN) of ₹20,100 is ₹4,020, but only ₹300 can be kept back.
        $c = AssociateTds::compute(['id' => 0, 'associate_id' => $a, 'amount_inr' => '300.00'], $paidOn);
        self::assertSame([300.0, 0.0, 3720.0, 20.0], [$c['tds'], $c['net'], $c['shortfall'], $c['rate']]);
        self::assertStringContainsString('capped at the payout; ₹3,720.00 TDS carried to the next payout', $c['reason']);

        // Recorded on that payout, it is carried into the next one: ₹10,000 at 20% = ₹2,000 + ₹3,720.
        $pdo->prepare("INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status, paid_on, paid_inr, tds_inr, tds_shortfall_inr)
                       VALUES (:o, 'associate', :a, 'associate', 300, :m, 'paid', :d, 0, 300, 3720)")->execute(['o' => $orderId, 'a' => $a, 'm' => substr($paidOn, 0, 7), 'd' => $paidOn]);
        $next = AssociateTds::compute(['id' => 0, 'associate_id' => $a, 'amount_inr' => '10000.00'], $paidOn);
        self::assertSame([5720.0, 4280.0, 3720.0, 0.0], [$next['tds'], $next['net'], $next['carried_in'], $next['shortfall']]);
    }

    /** MON-2: milestone money is revenue, and a milestone not yet cleared means the order is not fully paid. */
    public function testMon2MilestonesCountInRevenueAndFullyPaid(): void
    {
        $orderId = $this->usdOrder(100000.0);
        $before = OrderProfitabilityService::computeForOrder($orderId, false)['revenue_inr'];
        $this->milestone($orderId, 40.0, 40000.0, 3320000.0);
        $after = OrderProfitabilityService::computeForOrder($orderId, false)['revenue_inr'];
        self::assertSame(3320000.0, round($after - $before, 2), 'USD 40,000 credited ₹33,20,000.00');

        $pdo = Database::connection();
        $pdo->prepare("UPDATE order_payment_status SET balance_amount = 30000, balance_cleared_at = '2026-10-25 10:00:00' WHERE order_id = :o")->execute(['o' => $orderId]);
        self::assertNull(IncentiveService::fullyPaidOn($orderId), 'the milestone is not cleared yet');
        $pdo->prepare("UPDATE order_payment_milestones SET cleared_on = '2026-10-21' WHERE order_id = :o")->execute(['o' => $orderId]);
        self::assertSame('2026-10-25', IncentiveService::fullyPaidOn($orderId));
    }

    /** MON-3: a line's value is rounded once, half away from zero — the same on both stacks. */
    public function testMon3LineValueRounding(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $id = OrderProductRepository::add($orderId, 1, 'Granite', null, null, '100.1', false, 'SQM', '18.45');
        self::assertSame('1846.85', (string) OrderProductRepository::find($id)['fob_value']);
    }

    /** MON-4: bank charges deducted in foreign currency are bank charges in the CA register, not a forex loss. */
    public function testMon4ForeignBankChargesAreNotForexLoss(): void
    {
        $pdo = Database::connection();
        $orderId = $this->usdOrder(10000.0, 100.0);
        // Advance due USD 10,000; USD 30 deducted by the banks, USD 9,970 received, ₹8,27,510 credited, weighted rate ₹83.00.
        $pdo->prepare('UPDATE order_payment_status SET advance_inr_actual = 827510 WHERE order_id = :o')->execute(['o' => $orderId]);
        $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference, bank_charges, bank_charges_inr)
                       VALUES (:o, 'advance', 9970, '2026-10-05', 83, 827510, 'UTR-QA4-BC', 30, 2490)")->execute(['o' => $orderId]);
        $row = array_values(array_filter(CaRepository::settlementRegister(), static fn (array $r): bool => $r['order_id'] === $orderId && $r['leg'] === 'advance'))[0];
        self::assertSame([0.0, 2490.0], [(float) $row['forex_gain_loss'], (float) $row['bank_charges_inr']]);
    }

    /** MON-6: the rupees the bank kept as LC charges count in the realised rate, as for every other receipt. */
    public function testMon6LcBankChargesCountInTheRealisedRate(): void
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        $pdo->prepare("UPDATE orders SET payment_method = 'LC' WHERE id = :o")->execute(['o' => $orderId]);
        $pdo->prepare("INSERT INTO order_lc_details (order_id, lc_amount, lc_received_amount, lc_received_at, lc_inr_credited, lc_bank_charges_inr)
                       VALUES (:o, 50000, 50000, '2026-10-20', 4162500, 12500)")->execute(['o' => $orderId]);
        self::assertSame(83.5, round(AssociateCommission::realisedRate(OrderRepository::find($orderId))['rate'], 4));
        self::assertSame(83.5, round(IncentiveService::productRate(OrderRepository::find($orderId)), 4));
    }

    /** MON-8: two genuine remittances of the same amount on the same day differ by their bank reference. */
    public function testMon8TheDuplicateGuardLooksAtTheBankReference(): void
    {
        $pdo = Database::connection();
        $orderId = $this->usdOrder(10000.0);
        $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 5000, '2026-10-05', 83, 415000, 'UTR-QA4-A')")->execute(['o' => $orderId]);
        $pdo->beginTransaction();
        try {
            self::assertTrue(PartPaymentService::receiptExists($orderId, 'advance', null, 5000.0, '2026-10-05', 83.0, 415000.0, 'UTR-QA4-A'), 'a second click of the same form');
            self::assertFalse(PartPaymentService::receiptExists($orderId, 'advance', null, 5000.0, '2026-10-05', 83.0, 415000.0, 'UTR-QA4-B'), 'another remittance');
        } finally {
            $pdo->rollBack();
        }
    }

    /** ORD-09: one balance formula — net of a milestone shortfall written off, gross when carried to the balance. */
    public function testOrd09TheBalanceIsNetOfWriteOffs(): void
    {
        $orderId = $this->usdOrder(10000.0);
        $m = $this->milestone($orderId, 30.0, 2900.0, 240700.0);
        self::assertSame(2900.0, PaymentMilestoneRepository::balanceDeduction($orderId, 10000.0, false));
        Database::connection()->prepare("INSERT INTO order_payment_shortfalls (order_id, leg, milestone_id, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by)
                       VALUES (:o, 'milestone', :m, 3000, 2900, 100, 'written_off', 'Agreed discount with the buyer', :u)")->execute(['o' => $orderId, 'm' => $m, 'u' => $this->admin]);
        self::assertSame(3000.0, PaymentMilestoneRepository::balanceDeduction($orderId, 10000.0, false));
        self::assertSame(4000.0, \App\Services\PaymentActionService::amountDue(OrderRepository::find($orderId), 'balance'), 'buyer side');
        self::assertSame(4000.0, PartPaymentService::expected($orderId, 'balance'), 'staff side');
    }

    /** ORD-14: Close Order needs the original BLs received from the CHA and endorsed. */
    public function testOrd14CloseOrderNeedsTheBlOriginals(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk', 'vessel_name' => 'MSC MAYA', 'etd' => '2026-06-01', 'eta' => '2026-06-20']);
        OrderShippingRepository::recordBl($orderId, 'MSCU7654321', '2026-06-15');
        for ($stage = 1; $stage <= 8; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $this->createTestDocument($orderId, 'BLI', 'approved');
        $this->createTestDocument($orderId, 'COOPREP', 'sent');
        self::assertSame(['record the original BLs received from the CHA (step 11a)', 'mark the original BLs endorsed'], OrderController::blOriginalsMissing($orderId));
        $_POST = ['courier_tracking_number' => 'DHL-QA4-1'];
        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();
        self::assertSame('active', OrderRepository::find($orderId)['status']);

        OrderShippingRepository::recordBlOriginalsReceived($orderId, 3);
        OrderShippingRepository::recordBlEndorsed($orderId, $this->admin);
        self::assertSame([], OrderController::blOriginalsMissing($orderId));
        ob_start();
        (new OrderController())->closeOrder(['id' => (string) $orderId]);
        ob_end_clean();
        self::assertSame('shipped_docs_sent', OrderRepository::find($orderId)['status']);
    }

    /** ZOH-1: a second sync while one runs stops at once with one Sync Log row. */
    public function testZoh1OnlyOneSyncAtATime(): void
    {
        $pdo = Database::connection();
        $before = (int) $pdo->query("SELECT COUNT(*) FROM zoho_sync_log WHERE message = " . $pdo->quote(ZohoSyncLock::BUSY))->fetchColumn();
        $inner = ZohoSyncLock::run(static fn (): string => 'ran', static fn (): string => 'busy', 'manual', null);
        self::assertSame('ran', $inner, 'nobody holds the lock');
        // Another connection (another request or the hourly run) holds the lock.
        $other = new \PDO('mysql:host=' . \App\Config\Env::get('DB_HOST', '127.0.0.1') . ';port=' . \App\Config\Env::get('DB_PORT', '3306') . ';dbname=' . $pdo->query('SELECT DATABASE()')->fetchColumn(), \App\Config\Env::get('DB_USERNAME'), \App\Config\Env::get('DB_PASSWORD', ''));
        self::assertSame(1, (int) $other->query("SELECT GET_LOCK(CONCAT('nx_zoho_sync_', DATABASE()), 0)")->fetchColumn());
        try {
            $r = CaSyncService::runFullSync('manual', $this->admin);
            self::assertSame(ZohoSyncLock::BUSY, $r['stopped']);
            self::assertSame($before + 1, (int) $pdo->query("SELECT COUNT(*) FROM zoho_sync_log WHERE message = " . $pdo->quote(ZohoSyncLock::BUSY))->fetchColumn());
        } finally {
            $other->query("SELECT RELEASE_LOCK(CONCAT('nx_zoho_sync_', DATABASE()))");
        }
    }

    /** ZOH-2: a customer payment already in Books under its reference is adopted, not posted again. */
    public function testZoh2ARetryAdoptsThePaymentAlreadyInBooks(): void
    {
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        foreach (['zoho_books_enabled' => '1', 'zoho_books_client_id' => 'fake-client', 'zoho_books_client_secret' => 'fake-secret', 'zoho_books_refresh_token' => 'fake-refresh',
            'zoho_books_organization_id' => '600', 'zoho_books_deposit_account_id' => 'SBI-1'] as $key => $value) {
            \App\Repositories\CompanySettingsRepository::set($key, $value);
        }
        $posts = 0;
        ZohoBooksService::setTransport(static function (string $method, string $url) use (&$posts): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $json = static fn (array $b, int $s = 200): array => ['status' => $s, 'body' => (string) json_encode($b)];
            if ($path === '/oauth/v2/token') {
                return $json(['access_token' => 'fake']);
            }
            if ($path === '/books/v3/contacts' && $method === 'GET') {
                return $json(['code' => 0, 'contacts' => [['contact_id' => 'CT-1', 'contact_name' => 'TEST-QA4 Buyer', 'currency_code' => 'INR']]]);
            }
            if (preg_match('#^/books/v3/contacts/#', $path) === 1) {
                return $json(['code' => 0, 'contact' => ['contact_id' => 'CT-1', 'currency_code' => 'INR']]);
            }
            if ($path === '/books/v3/customerpayments' && $method === 'GET') {
                return $json(['code' => 0, 'customerpayments' => [['payment_id' => 'PAY-EXISTING', 'reference_number' => 'NX-QA4-advance', 'customer_id' => 'CT-1', 'amount' => 41500.0, 'date' => '2026-10-05']]]);
            }
            if ($path === '/books/v3/customerpayments' && $method === 'POST') {
                $posts++;
                return $json(['code' => 0, 'payment' => ['payment_id' => 'PAY-NEW']], 201);
            }
            return $json(['code' => 0], 200);
        });
        try {
            $r = ZohoBooksService::pushRevenuePayment($this->createTestClient(), 'TEST-QA4 Buyer', 'INR', null, 41500.0, '2026-10-05', 'NX-QA4-advance');
        } finally {
            \App\Repositories\CompanySettingsRepository::set('zoho_books_enabled', '0');
        }
        self::assertSame(['PAY-EXISTING', 0], [$r['payment_id'], $posts]);
    }
}
