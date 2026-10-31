<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\CaController;
use App\Controllers\IncentiveController;
use App\Controllers\OrderController;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\IncentiveRepository;
use App\Tests\Support\DbTestCase;

/**
 * Owner rule D-212 (2026-10-31): every money record has a correct
 * description — so the forms that record money refuse to save without the
 * bank / payment reference it is built from. Batch 1: buyer remittances,
 * incentive payouts, staff-added costs, export benefits received and
 * cancellation refunds. (The LC payment and the Zoho Books descriptions are
 * checked in ZohoBooksStage2Test and ZohoBooksStage4Test.) The Node suite
 * has the same test (paymentDescriptionRule.test.js).
 */
final class PaymentDescriptionRuleTest extends DbTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function flash(): array
    {
        $all = Flash::pull();
        return end($all) ?: ['type' => null, 'message' => ''];
    }

    private function value(string $sql): mixed
    {
        $v = Database::connection()->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function testABuyerRemittanceNeedsItsBankReference(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->exec("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES ({$orderId}, 1, 'Granite slab', 100, 'SQM', 40, 4000)");
        Database::connection()->exec("INSERT IGNORE INTO order_payment_status (order_id) VALUES ({$orderId})");
        $post = ['advance_amount' => '1600', 'advance_received_at' => date('Y-m-d'), 'advance_exchange_rate' => '84.10'];

        $_POST = $post;
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        self::assertStringContainsString('bank reference is required', (string) $this->flash()['message']);
        self::assertNull($this->value("SELECT advance_remittance_received_at FROM order_payment_status WHERE order_id = {$orderId}"), 'nothing recorded without it');

        $_POST = $post + ['advance_bank_reference' => 'HDFCN26304123456'];
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        self::assertSame('success', $this->flash()['type']);
        self::assertSame('HDFCN26304123456', $this->value("SELECT advance_bank_reference FROM order_payment_status WHERE order_id = {$orderId}"));
        self::assertSame("Advance received from PHPUnit Test Buyer Ltd for order " . $this->value("SELECT buyer_inquiry_ref FROM orders WHERE id = {$orderId}") . ' · USD 1,600.00 at ₹84.10 · bank ref HDFCN26304123456',
            \App\Services\PaymentDescription::buyerPayment($orderId, 'advance'));
    }

    public function testAPayoutNeedsHowItWasPaidAndItsReference(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $month = date('Y-m');
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by, incentive_kind) VALUES ({$orderId}, 'commission', 'Team incentive — test month', 5000, CURDATE(), {$this->admin}, 'team')");
        $pdo->exec("INSERT INTO incentive_payouts (order_id, payee_kind, user_id, role, amount_inr, incentive_month, status) VALUES ({$orderId}, 'staff', {$this->admin}, 'sales', 5000, '{$month}', 'due')");
        $payout = (int) $pdo->lastInsertId();
        $post = ['month' => $month, 'paid_on' => date('Y-m-d'), 'paid_inr' => '5000'];
        $pay = function (array $body) use ($payout): array {
            $_POST = $body;
            (new IncentiveController())->markPaid(['payoutId' => (string) $payout]);
            return $this->flash();
        };

        self::assertStringContainsString('Choose how it was paid', (string) $pay($post + ['paid_reference' => 'Payroll Nov-2026'])['message']);
        self::assertStringContainsString('payment reference is required', (string) $pay($post + ['paid_via' => 'salary'])['message']);
        self::assertSame('due', $this->value("SELECT status FROM incentive_payouts WHERE id = {$payout}"));

        self::assertSame('success', $pay($post + ['paid_via' => 'salary', 'paid_reference' => 'Payroll Nov-2026'])['type']);
        self::assertSame(['salary', 'Payroll Nov-2026'], array_values($pdo->query("SELECT paid_via, paid_reference FROM incentive_payouts WHERE id = {$payout}")->fetch(\PDO::FETCH_ASSOC)));
        self::assertSame('Team incentive — test month · paid with the salary (payroll), ref Payroll Nov-2026',
            $this->value("SELECT description FROM order_cost_entries WHERE order_id = {$orderId} AND incentive_kind = 'team'"), 'the cost line (and so the Zoho expense) says how it was paid');
        self::assertSame(array_keys(IncentiveRepository::PAID_VIA), ['salary', 'bank_transfer', 'cheque']);
    }

    public function testACostNeedsADescription(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $_POST = ['category' => 'commission', 'amount_inr' => '9000', 'incurred_at' => date('Y-m-d'), 'paid_from' => 'company_account', 'description' => 'Agent'];
        (new OrderFinancialsController())->addCostEntry(['id' => (string) $orderId]);
        self::assertStringContainsString('who was paid and for what', (string) $this->flash()['message']);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM order_cost_entries WHERE order_id = {$orderId}"));

        $_POST['description'] = 'Agent commission — Ravi Traders, invoice RT/45';
        (new OrderFinancialsController())->addCostEntry(['id' => (string) $orderId]);
        self::assertSame('Agent commission — Ravi Traders, invoice RT/45', $this->value("SELECT description FROM order_cost_entries WHERE order_id = {$orderId}"));
    }

    public function testAnExportBenefitReceivedSaysHowItWasCredited(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $claim = CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB 4417893', 2350.0, date('Y-m-d'), 'INR', null, $this->admin);

        $_POST = ['received_amount' => '2350', 'received_at' => date('Y-m-d')];
        (new CaController())->markExportBenefitReceived(['id' => (string) $claim]);
        self::assertStringContainsString('How it was credited (bank UTR / e-scrip no.) is required', (string) $this->flash()['message']);
        self::assertNull($this->value("SELECT received_amount FROM ca_export_benefits WHERE id = {$claim}"));

        $_POST['received_reference'] = '2610000123456';
        (new CaController())->markExportBenefitReceived(['id' => (string) $claim]);
        self::assertSame(['2350.00', '2610000123456'], array_values(Database::connection()->query("SELECT received_amount, received_reference FROM ca_export_benefits WHERE id = {$claim}")->fetch(\PDO::FETCH_ASSOC)));

        // Step 13g's table: a row with a received amount needs its credit reference too.
        Database::connection()->exec("UPDATE orders SET status = 'complete' WHERE id = {$orderId}");
        Database::connection()->exec("INSERT IGNORE INTO order_payment_status (order_id) VALUES ({$orderId})");
        Database::connection()->exec("UPDATE order_payment_status SET balance_remittance_received_at = NOW() WHERE order_id = {$orderId}");
        $_POST = ['benefits' => [['scheme_name' => 'Duty Drawback', 'claimed_amount' => '1800', 'claimed_at' => date('Y-m-d'), 'received_amount' => '1785', 'received_at' => date('Y-m-d')]]];
        (new OrderFinancialsController())->saveExportBenefits(['id' => (string) $orderId]);
        self::assertStringContainsString('Duty Drawback: How it was credited', (string) $this->flash()['message']);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM ca_export_benefits WHERE order_id = {$orderId} AND scheme_name = 'Duty Drawback'"), 'nothing saved');
    }

    public function testARefundNeedsItsDateAndBankReference(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO order_cancellations (order_id, reason, requested_by, status, decided_by, decided_at, refund_amount, refund_status) VALUES ({$orderId}, 'Buyer withdrew the order', {$this->admin}, 'approved', {$this->admin}, NOW(), 3200, 'pending')");
        $cancellation = (int) $pdo->lastInsertId();
        $complete = function (array $body) use ($orderId, $cancellation): array {
            $_POST = $body;
            (new OrderController())->completeCancellationRefund(['id' => (string) $orderId, 'cancellationId' => (string) $cancellation]);
            return $this->flash();
        };

        self::assertStringContainsString('date the refund was paid', (string) $complete(['refund_reference' => 'HDFCO26311000777'])['message']);
        self::assertStringContainsString('bank reference of the refund is required', (string) $complete(['refund_paid_at' => date('Y-m-d')])['message']);
        self::assertSame('pending', $this->value("SELECT refund_status FROM order_cancellations WHERE id = {$cancellation}"));

        self::assertSame('success', $complete(['refund_paid_at' => date('Y-m-d'), 'refund_reference' => 'HDFCO26311000777'])['type']);
        self::assertSame(['completed', date('Y-m-d'), 'HDFCO26311000777'], array_values($pdo->query("SELECT refund_status, refund_paid_at, refund_reference FROM order_cancellations WHERE id = {$cancellation}")->fetch(\PDO::FETCH_ASSOC)));
    }

    /** Batch 2 (A13): an expense typed in Zoho Books without a description is flagged, so the CA fixes it there. */
    public function testAZohoExpenseWithoutADescriptionIsFlagged(): void
    {
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO ca_expenses (zoho_expense_id, category, description, vendor_name, amount, currency_code, expense_date) VALUES ('TEST-ZX-1', 'Courier', '', 'Blue Dart D212', 850, 'INR', CURDATE()), ('TEST-ZX-2', 'Courier', 'Courier of BL copies to the buyer', 'DHL', 2100, 'INR', CURDATE())");
        ob_start();
        (new CaController())->expenses([]);
        $html = (string) ob_get_clean();
        self::assertMatchesRegularExpression('#Blue Dart D212</td>\s*<td[^>]*><span class="badge badge-danger"[^>]*>No description in Zoho</span>#', $html);
        self::assertDoesNotMatchRegularExpression('#DHL</td>\s*<td[^>]*><span class="badge badge-danger"#', $html, 'an expense with a description is not flagged');
        self::assertStringContainsString('Courier of BL copies to the buyer', $html);
    }

    /** Batch 2: the descriptions built for the inspection fee, the LC loan interest and the travel cost. */
    public function testTheBuiltCostDescriptions(): void
    {
        self::assertSame('Pre-shipment inspection fee — SGS India Pvt Ltd, invoice SGS/MUM/26/0457', \App\Services\PaymentDescription::inspectionFee(' SGS India Pvt Ltd ', 'SGS/MUM/26/0457'));
        self::assertSame('Bank loan interest (LC order) · LC LC-778 · loan ₹250,000.00 at 9.5%', \App\Services\PaymentDescription::lcLoanInterest('LC-778', 250000.0, 9.5));
        self::assertSame('Bank loan interest (LC order)', \App\Services\PaymentDescription::lcLoanInterest(null, null, null));
        self::assertSame('Travel expenses (factory visits) — 175 km to the factory, 5% of the order value USD 8,000.00 at ₹84.10', \App\Services\PaymentDescription::travelExpenses(175.0, 5.0, 'USD', 8000.0, 84.1));
        self::assertSame('Travel expenses (factory visits) — 7.5% of the order value INR 500,000.00', \App\Services\PaymentDescription::travelExpenses(null, 7.5, 'INR', 500000.0, 1.0));
    }
}
