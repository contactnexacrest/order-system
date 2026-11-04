<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CaController;
use App\Controllers\OrderController;
use App\Controllers\PaymentMilestoneController;
use App\Helpers\Flash;
use App\Repositories\CaRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Services\OrderProfitabilityService;
use App\Services\PartPaymentService;
use App\Services\RemittanceDifference;
use App\Services\RemittanceInr;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-244 (4 Nov 2026, "C yes"): buyer remittances are recorded
 * with the INR the bank credited; the rate is worked out from it. The bank's
 * rate on the advice is optional and, when it is more than ₹1 away from the
 * INR, the person says why (bank charges → an order cost; a typo → nothing
 * saved; another reason → kept on the receipt). "INR not known yet" keeps
 * the typed rate, and the CA register shows an estimate.
 * Node twin: remittanceInr.test.js.
 */
final class RemittanceInrTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        \App\Services\PermissionService::resetCache();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function call(string $action, int $orderId, array $post): array
    {
        $_POST = $post;
        ob_start();
        (new OrderController())->{$action}(['id' => (string) $orderId]);
        ob_end_clean();
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    /** USD order, products USD 4,500, advance 40% = USD 1,800 due, PI approved, at Stage 3. */
    private function orderAtAdvance(): int
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
        Database::connection()->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        Database::connection()->prepare('UPDATE orders SET advance_pct = 40 WHERE id = :o')->execute(['o' => $orderId]);
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        $this->createTestDocument($orderId, 'PI', 'approved');
        Database::connection()->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        return $orderId;
    }

    private function advance(string $amount, array $extra = []): array
    {
        return ['advance_amount' => $amount, 'advance_received_at' => '2026-10-20', 'advance_bank_reference' => 'UTR440001'] + $extra;
    }

    private function receipts(int $orderId): array
    {
        return PartPaymentService::receipts($orderId, 'advance');
    }

    private function registerRow(int $orderId): array
    {
        foreach (CaRepository::settlementRegister() as $row) {
            if ($row['order_id'] === $orderId && $row['leg'] === 'advance') {
                return $row;
            }
        }
        self::fail('advance leg not in the CA register');
    }

    public function testTheInrCreditedSetsTheRateAndTheInrActualAndTheRegisterShowsIt(): void
    {
        $orderId = $this->orderAtAdvance();
        $r = $this->call('recordAdvancePayment', $orderId, $this->advance('1800', ['advance_inr_credited' => '149850.00']));
        self::assertSame('success', $r['type'], $r['message']);
        self::assertStringContainsString('Received USD 1,800.00; ₹149,850.00 credited by the bank — exchange rate worked out ₹83.2500.', $r['message']);

        $receipt = $this->receipts($orderId)[0];
        self::assertSame(['149850.00', '83.2500', null, null, null], [$receipt['inr_credited'], $receipt['exchange_rate'], $receipt['bank_rate'], $receipt['rate_gap_inr'], $receipt['rate_gap_choice']]);
        $ops = OrderPaymentStatusRepository::find($orderId);
        self::assertSame(['83.2500', '149850.00'], [$ops['advance_exchange_rate'], $ops['advance_inr_actual']]);
        self::assertNotNull($ops['advance_inr_actual_recorded_at']);
        self::assertSame($this->admin, (int) $ops['advance_inr_actual_recorded_by']);

        $this->call('clearAdvancePayment', $orderId, ['advance_cleared_at' => '2026-10-21']);
        $row = $this->registerRow($orderId);
        self::assertSame([149850.0, null], [$row['inr_actual'], $row['inr_estimated']]);
        $csv = CaController::settlementRegisterCsvRows([$row]);
        self::assertSame([149850.0, null], [$csv['rows'][0]['INR Actual'], $csv['rows'][0]['INR Estimated (at recorded rate)']]);
    }

    public function testAGapUpToOneRupeeSavesWithoutAQuestion(): void
    {
        $orderId = $this->orderAtAdvance();
        // 1,800 × 83.25 = 149,850.00; credited 149,850.90 → ₹0.90 apart.
        $r = $this->call('recordAdvancePayment', $orderId, $this->advance('1800', ['advance_inr_credited' => '149850.90', 'advance_bank_rate' => '83.25']));
        self::assertSame('success', $r['type'], $r['message']);
        $receipt = $this->receipts($orderId)[0];
        self::assertSame(['149850.90', '83.2500', '-0.90', null], [$receipt['inr_credited'], $receipt['bank_rate'], $receipt['rate_gap_inr'], $receipt['rate_gap_choice']]);
        self::assertSame([], OrderCostEntryRepository::forOrder($orderId));
    }

    public function testAGapAboveOneRupeeNeedsAnAnswer(): void
    {
        $orderId = $this->orderAtAdvance();
        $r = $this->call('recordAdvancePayment', $orderId, $this->advance('1800', ['advance_inr_credited' => '149348.00', 'advance_bank_rate' => '83.25']));
        self::assertSame('error', $r['type']);
        self::assertSame("At the bank's rate of ₹83.2500, USD 1,800.00 is ₹149,850.00, but ₹149,348.00 was credited — ₹502.00 less. Say why: the bank deducted its charges, a figure was typed wrong, or another reason.", $r['message']);
        self::assertSame([], $this->receipts($orderId), 'nothing saved');
    }

    public function testTheBankDeductedItsChargesBooksTheGapAsBankCharges(): void
    {
        $orderId = $this->orderAtAdvance();
        $order = OrderRepository::find($orderId);
        $r = $this->call('recordAdvancePayment', $orderId, $this->advance('1800', ['advance_inr_credited' => '149348.00', 'advance_bank_rate' => '83.25', 'rate_gap_choice' => 'bank_charges']));
        self::assertSame('success', $r['type'], $r['message']);
        self::assertStringContainsString('₹502.00 the bank kept (at its rate of ₹83.2500) is recorded as an order cost "Bank Charges".', $r['message']);

        $receipt = $this->receipts($orderId)[0];
        self::assertSame(['149348.00', '83.2500', '502.00', 'bank_charges', '83.2500'], [$receipt['inr_credited'], $receipt['bank_rate'], $receipt['rate_gap_inr'], $receipt['rate_gap_choice'], $receipt['exchange_rate']],
            'valued at the bank\'s rate — the charges are the cost');
        $costs = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $costs);
        $expected = 'Bank charges deducted in rupees from ' . $order['company_legal_name'] . "'s advance remittance for order " . ($order['buyer_inquiry_ref'] ?? $order['order_reference'])
            . ": USD 1,800.00 at the bank's rate ₹83.2500 = ₹149,850.00, credited ₹149,348.00, charges ₹502.00 · bank ref UTR440001";
        self::assertSame(['bank_charges', '502.00', $expected, 'company_account', '2026-10-20'], [$costs[0]['category'], $costs[0]['amount_inr'], $costs[0]['description'], $costs[0]['paid_from'], substr((string) $costs[0]['incurred_at'], 0, 10)]);
        self::assertSame((int) $costs[0]['id'], (int) $receipt['rate_gap_cost_id']);

        // The leg holds the rupees credited; the charges travel with the payment and are counted once in profit.
        self::assertSame('149348.00', OrderPaymentStatusRepository::find($orderId)['advance_inr_actual']);
        self::assertSame(502.0, RemittanceDifference::chargesInr($orderId, 'advance'));
        self::assertSame(149850.0, round(OrderProfitabilityService::computeForOrder($orderId)['revenue_inr'], 2), 'credited + charges = the remittance at the bank\'s rate');
        $payload = (new \ReflectionMethod(\App\Services\ZohoBooksService::class, 'paymentPayload'))
            ->invoke(null, ['depositAccountId' => 'ACC1'], 'C1', 'USD', 1800.0, 149348.0, '2026-10-21', 'REF-advance', RemittanceDifference::chargesInr($orderId, 'advance'), 'desc');
        self::assertSame([1800.0, 83.25, 6.03], [$payload['amount'], $payload['exchange_rate'], $payload['bank_charges']], 'Zoho: deposit = credited, the rest bank charges');

        // Owner 4 Nov 2026: the register labels the ₹502 as bank charges, not as a forex loss.
        $this->call('clearAdvancePayment', $orderId, ['advance_cleared_at' => '2026-10-21']);
        $row = $this->registerRow($orderId);
        self::assertSame([0.0, 502.0], [(float) $row['forex_gain_loss'], $row['bank_charges_inr']]);
        $csv = CaController::settlementRegisterCsvRows([$row])['rows'][0];
        self::assertSame(502.0, $csv['Bank Charges (deducted from credit)']);
    }

    public function testATypoBlocksTheSave(): void
    {
        $orderId = $this->orderAtAdvance();
        $r = $this->call('recordAdvancePayment', $orderId, $this->advance('1800', ['advance_inr_credited' => '14985.00', 'advance_bank_rate' => '83.25', 'rate_gap_choice' => 'typo']));
        self::assertSame('error', $r['type']);
        self::assertStringContainsString("You said a figure was typed wrong: correct the amount, the INR credited or the bank's rate so they agree (within ₹1), then save again.", $r['message']);
        self::assertSame([], $this->receipts($orderId));
        self::assertNull(OrderPaymentStatusRepository::find($orderId)['advance_amount']);
    }

    public function testAnotherReasonIsSavedOnTheReceipt(): void
    {
        $orderId = $this->orderAtAdvance();
        $short = $this->call('recordAdvancePayment', $orderId, $this->advance('1800', ['advance_inr_credited' => '150300.00', 'advance_bank_rate' => '83.25', 'rate_gap_choice' => 'other', 'rate_gap_reason' => 'Bank']));
        self::assertSame(['error', "Type the reason the INR credited differs from the bank's rate (at least 10 characters)."], [$short['type'], $short['message']]);

        $r = $this->call('recordAdvancePayment', $orderId, $this->advance('1800', ['advance_inr_credited' => '150300.00', 'advance_bank_rate' => '83.25', 'rate_gap_choice' => 'other', 'rate_gap_reason' => 'Bank applied the next day\'s card rate']));
        self::assertSame('success', $r['type'], $r['message']);
        $receipt = $this->receipts($orderId)[0];
        self::assertSame(['150300.00', '-450.00', 'other', "Bank applied the next day's card rate", '83.5000'],
            [$receipt['inr_credited'], $receipt['rate_gap_inr'], $receipt['rate_gap_choice'], $receipt['rate_gap_reason'], $receipt['exchange_rate']], 'the rate is the one actually received');
        self::assertSame([], OrderCostEntryRepository::forOrder($orderId), 'no cost for another reason');

        // More credited than amount × bank's rate is never "bank charges".
        $orderId2 = $this->orderAtAdvance();
        $r2 = $this->call('recordAdvancePayment', $orderId2, $this->advance('1800', ['advance_inr_credited' => '150300.00', 'advance_bank_rate' => '83.25', 'rate_gap_choice' => 'bank_charges']));
        self::assertSame('error', $r2['type']);
        self::assertStringContainsString('so it cannot be bank charges', $r2['message']);
    }

    public function testInrNotKnownYetKeepsTheTypedRateAndTheRegisterShowsAnEstimate(): void
    {
        $orderId = $this->orderAtAdvance();
        $missing = $this->call('recordAdvancePayment', $orderId, $this->advance('1800'));
        self::assertSame('error', $missing['type']);
        self::assertStringStartsWith('Enter the INR credited by the bank (₹)', $missing['message']);

        $r = $this->call('recordAdvancePayment', $orderId, $this->advance('1800', ['advance_inr_unknown' => '1', 'advance_exchange_rate' => '83.40']));
        self::assertSame('success', $r['type'], $r['message']);
        self::assertStringContainsString('Received USD 1,800.00 at the exchange rate of ₹83.4000 (≈ ₹150,120.00, estimated until the INR credited is known).', $r['message']);
        self::assertNull($this->receipts($orderId)[0]['inr_credited']);
        self::assertNull(OrderPaymentStatusRepository::find($orderId)['advance_inr_actual']);

        $this->call('clearAdvancePayment', $orderId, ['advance_cleared_at' => '2026-10-21']);
        $row = $this->registerRow($orderId);
        self::assertNull($row['inr_actual']);
        self::assertSame(150120.0, round((float) $row['inr_estimated'], 2));
        self::assertSame(150120.0, CaController::settlementRegisterCsvRows([$row])['rows'][0]['INR Estimated (at recorded rate)']);

        ob_start();
        (new CaController())->index([]);
        $html = (string) ob_get_clean();
        self::assertMatchesRegularExpression('#&\#8377;150,120\.00\s*<div class="muted small">estimated at recorded rate#', $html);

        // The INR actual box fills it in later.
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 150000.0, $this->admin);
        self::assertSame([150000.0, null], [$this->registerRow($orderId)['inr_actual'], $this->registerRow($orderId)['inr_estimated']]);
    }

    public function testReceiptsOfALegAddUpAndALegWithoutInrIsNotKnown(): void
    {
        $orderId = $this->orderAtAdvance();
        $first = $this->call('recordAdvancePayment', $orderId, $this->advance('1000', ['advance_inr_credited' => '83250.00', 'difference_reason_choice' => (string) RemittanceDifference::reasonIdFor('short')]));
        self::assertSame('success', $first['type'], $first['message']);
        $second = $this->call('recordAdvancePayment', $orderId, $this->advance('800', ['advance_received_at' => '2026-10-22', 'advance_inr_credited' => '67000.00']));
        self::assertSame('success', $second['type'], $second['message']);
        $ops = OrderPaymentStatusRepository::find($orderId);
        self::assertSame(['1800.00', '150250.00'], [$ops['advance_amount'], $ops['advance_inr_actual']], 'the INR actual is the sum of the rupees credited');
        self::assertSame('83.4722', $ops['advance_exchange_rate'], 'the amount-weighted rate = 150,250 ÷ 1,800');

        // The same form sent twice records nothing more.
        $again = $this->call('recordAdvancePayment', $orderId, $this->advance('800', ['advance_received_at' => '2026-10-22', 'advance_inr_credited' => '67000.00']));
        self::assertSame('warning', $again['type']);
        self::assertCount(2, $this->receipts($orderId));

        // A further receipt whose INR is not known yet makes the leg's INR unknown again.
        $orderId2 = $this->orderAtAdvance();
        $this->call('recordAdvancePayment', $orderId2, $this->advance('1000', ['advance_inr_credited' => '83250.00', 'difference_reason_choice' => (string) RemittanceDifference::reasonIdFor('short')]));
        $this->call('recordAdvancePayment', $orderId2, $this->advance('800', ['advance_received_at' => '2026-10-22', 'advance_inr_unknown' => '1', 'advance_exchange_rate' => '83.5']));
        self::assertNull(OrderPaymentStatusRepository::find($orderId2)['advance_inr_actual']);
    }

    public function testAMilestoneTakesTheInrCreditedToo(): void
    {
        $orderId = $this->orderAtAdvance();
        Database::connection()->prepare("INSERT INTO order_payment_milestones (order_id, seq, pct, due_text) VALUES (:o, 1, 20, 'on dispatch')")->execute(['o' => $orderId]);
        $m = Database::connection()->query('SELECT * FROM order_payment_milestones WHERE order_id = ' . $orderId)->fetch();
        $_POST = ['amount' => '900', 'inr_credited' => '75150.00', 'bank_rate' => '83.5', 'bank_reference' => 'UTR440009', 'received_on' => date('Y-m-d')];
        ob_start();
        (new PaymentMilestoneController())->record(['id' => (string) $orderId, 'milestoneId' => (string) $m['id']]);
        ob_end_clean();
        $flash = Flash::pull();
        $f = end($flash);
        self::assertSame('success', $f['type'], $f['message']);
        $row = Database::connection()->query('SELECT exchange_rate FROM order_payment_milestones WHERE id = ' . (int) $m['id'])->fetchColumn();
        self::assertSame('83.5000', $row);
        $receipt = PartPaymentService::receipts($orderId, 'milestone', (int) $m['id'])[0];
        self::assertSame(['75150.00', '83.5000', '0.00'], [$receipt['inr_credited'], $receipt['bank_rate'], $receipt['rate_gap_inr']]);
    }

    public function testTheRulesInOnePlace(): void
    {
        self::assertSame(1.0, RemittanceInr::TOLERANCE_INR);
        $ok = RemittanceInr::fromPost(['p_inr_credited' => '8400', 'p_bank_rate' => '84.01'], 'p_', 100.0, 'USD');
        self::assertSame([8400.0, 84.0, 84.01, 1.0, null], [$ok['inr'], $ok['rate'], $ok['bank_rate'], $ok['gap'], $ok['choice']], 'exactly ₹1 apart is still rounding');
        $ask = RemittanceInr::fromPost(['p_inr_credited' => '8400', 'p_bank_rate' => '84.02'], 'p_', 100.0, 'USD');
        self::assertArrayHasKey('error', $ask, '₹2 apart asks why');
        self::assertSame(83.0, RemittanceInr::fromPost(['p_inr_unknown' => '1', 'p_exchange_rate' => '83'], 'p_', 100.0, 'USD')['rate']);
        self::assertNull(RemittanceInr::legInrActual([['inr_credited' => '10'], ['inr_credited' => null]]));
        self::assertSame(30.5, RemittanceInr::legInrActual([['inr_credited' => '10.25'], ['inr_credited' => '20.25']]));
    }
}
