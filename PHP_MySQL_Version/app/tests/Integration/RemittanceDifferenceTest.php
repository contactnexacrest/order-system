<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Services\OrderProfitabilityService;
use App\Services\PartPaymentService;
use App\Services\PaymentDescription;
use App\Services\RemittanceDifference;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2 Nov 2026, "18 yes"): a buyer remittance that differs
 * from what is still due says why — bank charges deducted by the banks (the
 * leg counts as paid in full, the charges are an order cost with a D-212
 * description and go to Zoho Books with the payment), the buyer paid less,
 * or the buyer paid more. Node twin: remittanceDifference.test.js.
 */
final class RemittanceDifferenceTest extends DbTestCase
{
    private int $admin;
    private int $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $this->admin = $this->createTestUser('Admin');
        $this->accounts = $this->createTestUser('Accounts Executive');
        $this->as($this->admin);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function as(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
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
        return ['advance_amount' => $amount, 'advance_received_at' => '2026-10-20', 'advance_exchange_rate' => '84', 'advance_bank_reference' => 'UTR778801'] + $extra;
    }

    public function testTheRulesBehindEachReason(): void
    {
        $e = RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 0.0, 1795.0, null, null, 3.0);
        self::assertSame([null, 0.0], [$e['reason'], $e['charges']], 'within 1% needs no reason');
        self::assertSame('The advance still due is USD 1,800.00. You entered USD 1,500.00 — a difference of USD 300.00. Choose why it is different in the Reason list and save again.',
            RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 0.0, 1500.0, null, null, 3.0)['error']);
        self::assertSame(20.0, RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 0.0, 1780.0, 'bank_charges', null, 3.0)['charges'], 'charges default to the difference');
        self::assertSame(15.0, RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 0.0, 1780.0, 'bank_charges', 15.0, 3.0)['charges'], 'charges typed from the bank advice');
        self::assertSame('Bank charges of USD 25.00 are more than the difference of USD 20.00 between the advance still due and what arrived.',
            RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 0.0, 1780.0, 'bank_charges', 25.0, 3.0)['error']);
        self::assertSame('Bank charges of USD 100.00 are more than 3% of the advance due (USD 54.00). If the buyer paid less, choose a "Buyer paid less" reason instead.',
            RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 0.0, 1700.0, 'bank_charges', null, 3.0)['error']);
        self::assertSame('Nothing is short: USD 1,900.00 covers the advance still due (USD 1,800.00).',
            RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 0.0, 1900.0, 'short', null, 3.0)['error']);
        self::assertSame(100.0, RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 0.0, 1900.0, 'excess', null, 3.0)['excess']);
        self::assertSame('The advance still due is USD 800.00. You entered USD 900.00 — a difference of USD 100.00. Choose why it is different in the Reason list and save again.',
            RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 1000.0, 900.0, null, null, 3.0)['error'], 'more than is still due always says why');
        self::assertArrayNotHasKey('error', RemittanceDifference::evaluate('Advance', 'USD', 1800.0, 1000.0, 500.0, null, null, 3.0), 'a further part payment needs no reason');
    }

    public function testBankChargesCountTheAdvanceAsPaidInFullAndBecomeAnOrderCost(): void
    {
        $orderId = $this->orderAtAdvance();
        $order = OrderRepository::find($orderId);
        $this->as($this->accounts);
        $r = $this->call('recordAdvancePayment', $orderId, $this->advance('1780', ['difference_reason_choice' => (string) \App\Services\RemittanceDifference::reasonIdFor('bank_charges'), 'bank_charges' => '']));
        self::assertSame('success', $r['type'], $r['message']);
        self::assertStringContainsString('Bank charges USD 20.00 = ₹1,680.00 recorded as an order cost — the advance counts as received in full.', $r['message']);

        self::assertSame('1800.00', OrderPaymentStatusRepository::find($orderId)['advance_amount'], 'the leg holds what the buyer paid');
        $s = PartPaymentService::summary($orderId, 'advance');
        self::assertSame([1800.0, 0.0], [$s['received'], $s['owed']]);
        $receipt = $s['receipts'][0];
        self::assertSame(['1780.00', 'bank_charges', '20.00', '1680.00'], [$receipt['amount'], $receipt['difference_reason'], $receipt['bank_charges'], $receipt['bank_charges_inr']]);
        self::assertSame([(int) \App\Services\RemittanceDifference::reasonIdFor('bank_charges'), 'Intermediary / correspondent bank charges deducted', 0],
            [(int) $receipt['difference_reason_id'], $receipt['difference_reason_text'], (int) $receipt['difference_reason_custom']], 'the receipt keeps the reason chosen from the list');

        $costs = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $costs);
        $expected = 'Bank charges deducted from ' . $order['company_legal_name'] . "'s advance remittance for order " . ($order['buyer_inquiry_ref'] ?? $order['order_reference'])
            . ': received USD 1,780.00 of USD 1,800.00 due, charges USD 20.00 (₹1,680.00) · bank ref UTR778801 · Reason: Intermediary / correspondent bank charges deducted';
        self::assertSame(['bank_charges', '1680.00', $expected, 'company_account'], [$costs[0]['category'], $costs[0]['amount_inr'], $costs[0]['description'], $costs[0]['paid_from']], 'D-212 description built from the facts');
        self::assertSame((int) $costs[0]['id'], (int) $receipt['bank_charges_cost_id']);
        $audit = Database::connection()->prepare("SELECT COUNT(*) FROM audit_log WHERE action_type = 'REMITTANCE_BANK_CHARGES_RECORDED' AND entity_id = :r");
        $audit->execute(['r' => $receipt['id']]);
        self::assertSame(1, (int) $audit->fetchColumn());

        // Cleared without a manager: nothing is owed.
        $clear = $this->call('clearAdvancePayment', $orderId, ['advance_cleared_at' => '2026-10-21']);
        self::assertNotSame('error', $clear['type'], $clear['message']);
        self::assertNotNull(OrderPaymentStatusRepository::find($orderId)['advance_cleared_at']);

        // Zoho Books and the payment description carry the charges; profit counts them once.
        self::assertSame(1680.0, RemittanceDifference::chargesInr($orderId, 'advance'));
        self::assertStringContainsString('USD 1,800.00 at ₹84.00 · ₹1,680.00 bank charges deducted by the banks', PaymentDescription::buyerPayment($orderId, 'advance', null));
        OrderPaymentStatusRepository::setAdvanceInrActual($orderId, 149520.0, $this->admin);
        self::assertSame(151200.0, OrderProfitabilityService::computeForOrder($orderId)['revenue_inr'], 'rupees credited + bank charges = what the buyer paid');
    }

    public function testTheZohoPaymentSettlesTheInvoiceInFull(): void
    {
        $payload = (new \ReflectionMethod(\App\Services\ZohoBooksService::class, 'paymentPayload'))
            ->invoke(null, ['depositAccountId' => 'ACC1'], 'C1', 'USD', 1800.0, 149520.0, '2026-10-21', 'REF-advance', 1680.0, 'desc');
        self::assertSame([1800.0, 84.0, 20.0], [$payload['amount'], $payload['exchange_rate'], $payload['bank_charges']]);
    }

    public function testShortAndExcessAreRecordedAsTheySayAndChargesAboveTheLimitAreRefused(): void
    {
        $orderId = $this->orderAtAdvance();
        $refused = $this->call('recordAdvancePayment', $orderId, $this->advance('1700', ['difference_reason_choice' => (string) \App\Services\RemittanceDifference::reasonIdFor('bank_charges')]));
        self::assertSame('Bank charges of USD 100.00 are more than 3% of the advance due (USD 54.00). If the buyer paid less, choose a "Buyer paid less" reason instead.', $refused['message']);
        self::assertNull(OrderPaymentStatusRepository::find($orderId)['advance_amount']);

        $short = $this->call('recordAdvancePayment', $orderId, $this->advance('1700', ['difference_reason_choice' => (string) \App\Services\RemittanceDifference::reasonIdFor('short')]));
        self::assertStringContainsString('Short by USD 100.00 — record the rest when it arrives, or a manager clears the advance short and the difference is carried into the balance.', $short['message']);
        self::assertSame(100.0, PartPaymentService::summary($orderId, 'advance')['owed']);
        self::assertSame([], OrderCostEntryRepository::forOrder($orderId), 'no cost for a short payment');

        $excess = $this->call('recordAdvancePayment', $orderId, $this->advance('300', ['advance_received_at' => '2026-10-22', 'difference_reason_choice' => (string) \App\Services\RemittanceDifference::reasonIdFor('excess')]));
        self::assertStringContainsString('Excess USD 200.00 — the balance due goes down by the same amount.', $excess['message']);
        $audit = Database::connection()->prepare("SELECT action_type FROM audit_log WHERE entity_type = 'order_payment_receipts' AND action_type LIKE 'REMITTANCE_%' AND entity_id IN (SELECT id FROM order_payment_receipts WHERE order_id = :o) ORDER BY id");
        $audit->execute(['o' => $orderId]);
        self::assertSame(['REMITTANCE_SHORT_RECORDED', 'REMITTANCE_EXCESS_RECORDED'], $audit->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testTheOrderPageAsksWhyOnEveryBuyerRemittanceForm(): void
    {
        $orderId = $this->orderAtAdvance();
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $page = (string) ob_get_clean();
        self::assertStringNotContainsString('confirm_amount_differs', $page);
        self::assertStringContainsString('Why is it different?', $page);
        self::assertStringContainsString('name="difference_reason_choice"', $page);
        self::assertStringContainsString('name="bank_charges" data-remit-charges', $page);
    }
}
