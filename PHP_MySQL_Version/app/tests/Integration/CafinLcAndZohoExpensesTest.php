<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CaController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\CaBankStatementRepository;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Services\CaFinancialSnapshotService;
use App\Services\OrderLedgerService;
use App\Services\OrderProfitabilityService;
use App\Services\ZohoRestoreService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2026-10-29 (CAFIN change confirmed twice): the money the
 * bank pays under an LC counts as money received from the buyer (CAFIN
 * receipts, revenue, cash position, INR Settlement Register with its own
 * FIRC / eBRC and bank match), and expenses booked in Zoho Books and linked
 * to the order count in its cost — except a possible duplicate of a cost
 * entered in the app. Also: the scheduled sync checks the records already
 * in Books once a day; Sync Now always does. The Node suite has the same
 * test (cafinLcAndZohoExpenses.test.js).
 */
final class CafinLcAndZohoExpensesTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        parent::tearDown();
    }

    /** An LC order in EUR: LC paid EUR 1,800 (₹160,650 credited, ₹450 charges), balance EUR 2,700 at ₹241,650. */
    private function lcOrder(): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
        $pdo->prepare("UPDATE orders SET payment_method = 'LC' WHERE id = :id")->execute(['id' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:id)')->execute(['id' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET balance_amount = 2700, balance_remittance_received_at = '2026-10-28 10:00:00', balance_cleared_at = '2026-10-29 10:00:00',
            balance_inr_actual = 241650, balance_exchange_rate = 89.5 WHERE order_id = :id")->execute(['id' => $orderId]);
        $pdo->prepare("INSERT INTO order_lc_details (order_id, lc_number, lc_amount, lc_confirmed_at) VALUES (:id, 'LC-T-1', 1800, '2026-10-15 10:00:00')")->execute(['id' => $orderId]);
        OrderLcDetailRepository::recordReceived($orderId, '2026-10-28', 1800.0, 160650.0, 450.0, $this->userId);
        return $orderId;
    }

    private function zohoExpense(int $orderId, string $zohoId, string $category, float $amount, string $date): int
    {
        $id = CaExpenseRepository::insert($zohoId, $category, $category . ' booked in Books', 'Vendor', $amount, 'INR', $date);
        CaExpenseRepository::linkToOrder($id, $orderId);
        return $id;
    }

    public function testTheLcPaymentIsMoneyReceivedFromTheBuyer(): void
    {
        $orderId = $this->lcOrder();

        $profit = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(402300.0, $profit['revenue_inr'], 'LC ₹160,650 credited + balance ₹241,650');
        self::assertFalse($profit['revenue_is_estimated']);

        $s = CaFinancialSnapshotService::build($orderId);
        self::assertSame(['LC payment (paid by the bank under the LC)', '1,800.00', '89.2500', '160,650.00', 'rupees credited (after ₹450.00 bank charges)'],
            [$s['receipts'][0]['leg'], $s['receipts'][0]['amount'], $s['receipts'][0]['rate'], $s['receipts'][0]['inr'], $s['receipts'][0]['basis']]);
        self::assertSame('402,300.00', $s['receipts_inr']);

        $ledger = OrderLedgerService::forOrder($orderId, 'EUR', true)['totals'];
        self::assertSame(402300.0, $ledger['buyer_inr']);

        $register = array_values(array_filter(CaRepository::settlementRegister(), static fn (array $r): bool => $r['order_id'] === $orderId && $r['leg'] === 'lc'));
        self::assertCount(1, $register);
        self::assertSame([1800.0, 160650.0, '2026-10-28'], [$register[0]['foreign_amount'], $register[0]['inr_actual'], $register[0]['cleared_at']]);
        self::assertSame('LC payment', CaRepository::legLabel('lc'));
        self::assertSame([], array_filter(CaRepository::legsPendingZohoSync(), static fn (array $r): bool => $r['leg'] === 'lc'), 'the LC payment has its own Zoho step — never sent twice');
    }

    /**
     * Owner decision 2026-10-29 (CAFIN change confirmed twice): an order
     * switched to LC after its cash advance came in — the LC pays the
     * balance, so the revenue is advance + LC, exactly the money received;
     * and LC money on top of two legs already paid is added, not dropped.
     */
    public function testAnOrderSwitchedToLcAfterItsCashAdvanceCountsTheLcAsTheBalance(): void
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
        $pdo->prepare("UPDATE orders SET payment_method = 'LC' WHERE id = :id")->execute(['id' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:id)')->execute(['id' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = 1800, advance_exchange_rate = 89, advance_inr_actual = 160200,
            advance_remittance_received_at = '2026-10-20 10:00:00', advance_cleared_at = '2026-10-20 10:00:00', balance_amount = 2700, balance_exchange_rate = 89.5 WHERE order_id = :id")->execute(['id' => $orderId]);
        $pdo->prepare("INSERT INTO order_lc_details (order_id, lc_number, lc_amount, lc_confirmed_at) VALUES (:id, 'LC-T-2', 2700, '2026-10-22 10:00:00')")->execute(['id' => $orderId]);
        OrderLcDetailRepository::recordReceived($orderId, '2026-10-28', 2700.0, 240300.0, 600.0, $this->userId);

        $profit = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(400500.0, $profit['revenue_inr'], 'advance ₹160,200 + LC ₹240,300 — not the balance estimated at ₹241,650');
        self::assertFalse($profit['revenue_is_estimated']);
        self::assertSame('400,500.00', CaFinancialSnapshotService::build($orderId)['receipts_inr'], 'revenue equals the money received');

        $pdo->prepare("UPDATE order_payment_status SET balance_inr_actual = 241650, balance_remittance_received_at = '2026-10-29 10:00:00', balance_cleared_at = '2026-10-29 10:00:00' WHERE order_id = :id")->execute(['id' => $orderId]);
        self::assertSame(642150.0, OrderProfitabilityService::computeForOrder($orderId)['revenue_inr'], 'both legs paid as well: the LC money is added on top');
    }

    public function testTheLcPaymentTakesItsFircAndCanBeMatchedToABankLine(): void
    {
        $orderId = $this->lcOrder();

        $_POST = ['lc_firc_reference' => 'EBRC-LC-1', 'lc_firc_received_at' => '2026-10-29'];
        ob_start();
        (new OrderFinancialsController())->recordLcFirc(['id' => (string) $orderId]);
        ob_end_clean();
        self::assertSame('success', Flash::pull()[0]['type']);
        $row = array_values(array_filter(CaRepository::settlementRegister(), static fn (array $r): bool => $r['order_id'] === $orderId && $r['leg'] === 'lc'))[0];
        self::assertSame(['EBRC-LC-1', '2026-10-29'], [$row['firc_reference'], (string) $row['firc_received_at']]);

        CaBankStatementRepository::insertLine('lc-line-' . $orderId, '2026-10-28', 'LC proceeds', 'REF', 160650.0, null, $this->userId);
        $lineId = (int) Database::connection()->query("SELECT id FROM ca_bank_statement_lines WHERE line_hash = 'lc-line-{$orderId}'")->fetchColumn();
        $_POST = ['order_id' => (string) $orderId, 'leg' => 'lc'];
        ob_start();
        (new CaController())->matchBankLineToRevenue(['id' => (string) $lineId]);
        ob_end_clean();
        self::assertSame('success', Flash::pull()[0]['type']);
        self::assertContains("{$orderId}:lc", CaBankStatementRepository::matchedRevenueKeys());
    }

    public function testZohoBookedExpensesCountInTheCostButNotAPossibleDuplicate(): void
    {
        $orderId = $this->lcOrder();
        OrderCostEntryRepository::create($orderId, 'ecgc_insurance', 'ECGC cover', 4200.0, '2026-10-29', $this->userId, 'company_account');
        $this->zohoExpense($orderId, 'Z-CNT-' . $orderId, 'Postage', 2350.0, '2026-10-27');
        $this->zohoExpense($orderId, 'Z-DUP-' . $orderId, 'Insurance Expense', 4200.0, '2026-10-29');

        $profit = OrderProfitabilityService::computeForOrder($orderId);
        self::assertSame(2350.0, $profit['zoho_expenses_inr'], 'the ECGC premium matches the ECGC cost already in the app: not counted');
        self::assertSame(6550.0, $profit['total_cost_inr'], 'ECGC 4,200 + courier 2,350');

        $s = CaFinancialSnapshotService::build($orderId);
        self::assertSame('2,350.00', $s['zoho_total']);
        $tags = array_column($s['zoho'], 'tag');
        self::assertContains('possible duplicate of a cost entered in the app — not counted', $tags);
        self::assertContains(['C5 Expenses booked in Zoho Books', '2,350.00'], $s['summary']);
        self::assertContains(['line', '− Expenses booked in Zoho Books (C5)', null, '2,350.00'], $s['pl']);

        $ledger = OrderLedgerService::forOrder($orderId, 'EUR', true)['totals'];
        self::assertSame(6550.0, $ledger['expenses_inr']);
    }

    public function testTheScheduledSyncChecksTheRecordsInBooksOnceADaySyncNowAlways(): void
    {
        CompanySettingsRepository::set('zoho_books_restore_checked_at', '');
        ZohoRestoreService::markScheduledCheckDone();
        self::assertTrue(ZohoRestoreService::due('manual'), 'Sync Now always checks');
        self::assertFalse(ZohoRestoreService::due('scheduled'), 'checked just now');

        CompanySettingsRepository::set('zoho_books_restore_checked_at', date('Y-m-d H:i:s', time() - 24 * 3600));
        $reset = new \ReflectionProperty(ZohoRestoreService::class, 'dailyDue');
        $reset->setValue(null, null);
        self::assertTrue(ZohoRestoreService::due('scheduled'), 'a day later the scheduled run checks again');
        CompanySettingsRepository::set('zoho_books_restore_checked_at', '');
        $reset->setValue(null, null);
    }
}
