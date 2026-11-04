<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\OrderCostEntryRepository;
use App\Services\PartPaymentService;
use App\Services\PermissionService;
use App\Services\RemittanceDifference;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions (2 Nov 2026):
 * (1) Logistics enters the CHA / transport / freight-forwarder bills
 *     (manage_logistics_bills); recording a payment stays with
 *     manage_supplier_payments (Finance / Accounts / management).
 * (2) A buyer remittance can carry "our bank's charges on this credit",
 *     booked at once as an order cost "Bank Charges" (D-212 description).
 *     Bank Charges cost entries follow the 29 Oct rule: not sent to Zoho
 *     Books (the CA books bank charges from the bank statement).
 * Node twin: logisticsBillsAndOwnBankCharges.test.js.
 */
final class LogisticsBillsAndOwnBankChargesTest extends DbTestCase
{
    private static ?Router $router = null;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__logistics-bills-build__';
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
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        http_response_code(200);
        parent::tearDown();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function post(string $path, array $post = []): array
    {
        Flash::pull();
        $_POST = $post + ['_csrf' => Csrf::token()];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch('POST', $path);
        } finally {
            $body = (string) ob_get_clean();
        }
        $flash = Flash::pull()[0] ?? null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null];
    }

    private function page(int $orderId): string
    {
        PermissionService::resetCache();
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    private function assign(int $orderId, int $userId): void
    {
        Database::connection()->prepare('INSERT IGNORE INTO order_assignees (order_id, user_id) VALUES (:o, :u)')->execute(['o' => $orderId, 'u' => $userId]);
    }

    private function partner(): int
    {
        Database::connection()->exec("INSERT INTO logistics_partners (partner_name, service_type, is_active) VALUES ('Test CHA & Transport', 'both', 1)");
        return (int) Database::connection()->lastInsertId();
    }

    private function bill(int $orderId, string $kind, ?int $partnerId, string $no): int
    {
        Database::connection()->prepare(
            "INSERT INTO order_vendor_bills (order_id, vendor_kind, logistics_partner_id, bill_for, bill_no, bill_date, taxable_inr, gst_inr, total_inr, payment_kind, doc_kind)
             VALUES (:o, :k, :p, :f, :n, CURDATE(), 1000, 180, 1180, 'advance', 'invoice')"
        )->execute(['o' => $orderId, 'k' => $kind, 'p' => $partnerId, 'f' => $kind === 'supplier' ? 'advance' : 'cha', 'n' => $no]);
        return (int) Database::connection()->lastInsertId();
    }

    public function testTheNewPermissionIsHeldByLogisticsAndEveryoneWhoCouldAlreadyPay(): void
    {
        $holders = Database::connection()->query(
            "SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
              WHERE p.permission_key = 'manage_logistics_bills' ORDER BY r.name"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['Logistics Executive', 'Logistics Manager', 'Logistics Director', 'Accounts Executive', 'Finance Manager', 'Finance Director', 'Admin', 'Managing Director', 'Executive Director'] as $role) {
            self::assertContains($role, $holders, $role);
        }
        foreach (['Export Compliance Executive', 'Export Compliance Manager', 'Export Compliance Director', 'Procurement Executive', 'Export Executive'] as $role) {
            self::assertNotContains($role, $holders, $role);
        }
        $payers = Database::connection()->query(
            "SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
              WHERE p.permission_key = 'manage_supplier_payments'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], array_values(array_diff($payers, $holders)), 'nobody who could pay a bill loses the right to add one');
        self::assertNotContains('Logistics Executive', $payers, 'Logistics still does not pay');
    }

    public function testLogisticsAddsAndRemovesALogisticsBillButCannotPayIt(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $logistics = $this->createTestUser('Logistics Executive');
        $this->assign($orderId, $logistics);
        $this->signIn($logistics);

        // Reaches the controller (not 403) — the form's own checks answer.
        $r = $this->post("/orders/{$orderId}/vendor-bills/logistics", ['logistics_partner_id' => '0']);
        self::assertNotSame(403, $r['code']);
        self::assertSame('Choose the logistics partner from the directory.', $r['flash']);

        $bill = $this->bill($orderId, 'logistics', $this->partner(), 'TEST-LB-1');
        $r = $this->post("/orders/{$orderId}/vendor-bills/{$bill}/pay", ['paid_amount_inr' => '1180', 'paid_at' => date('Y-m-d'), 'payment_mode' => 'NEFT', 'payment_ref' => 'UTR1']);
        self::assertSame(403, $r['code'], 'paying needs manage_supplier_payments');
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM order_vendor_bill_payments WHERE bill_id = {$bill}")->fetchColumn());

        $supplierBill = $this->bill($orderId, 'supplier', null, 'TEST-SB-1');
        $r = $this->post("/orders/{$orderId}/vendor-bills/{$supplierBill}/delete");
        self::assertSame(403, $r['code'], 'a supplier bill stays with manage_supplier_payments');
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM order_vendor_bills WHERE id = {$supplierBill}")->fetchColumn());

        $r = $this->post("/orders/{$orderId}/vendor-bills/{$bill}/delete");
        self::assertSame('Bill TEST-LB-1 removed.', $r['flash']);
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM order_vendor_bills WHERE id = {$bill}")->fetchColumn());
    }

    public function testTheCardShowsLogisticsTheAddFormButNoPaymentButton(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $this->bill($orderId, 'logistics', $this->partner(), 'TEST-LB-2');
        $logistics = $this->createTestUser('Logistics Executive');
        $this->assign($orderId, $logistics);
        $this->signIn($logistics);
        $page = $this->page($orderId);
        $start = strpos($page, 'id="logistics-bills"');
        self::assertNotFalse($start, 'Logistics sees the Logistics Partner Bills card');
        $card = substr($page, $start, (int) strpos($page, '</details>', (int) strpos($page, 'vendor-bills/logistics', $start)) - $start);
        self::assertStringContainsString('TEST-LB-2', $card);
        self::assertStringContainsString('+ Add a bill', $card);
        self::assertStringContainsString('action="/orders/' . $orderId . '/vendor-bills/logistics"', $card);
        self::assertStringNotContainsString('Mark Paid', $card);
        self::assertStringNotContainsString('/pay"', $card);
        self::assertStringContainsString('Accounts records the payment.', $card);
        self::assertStringContainsString('>Remove</button>', $card);
    }

    public function testAccountsStillPaysAndProcurementCannotAddLogisticsBills(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        $bill = $this->bill($orderId, 'logistics', $this->partner(), 'TEST-LB-3');

        $accounts = $this->createTestUser('Accounts Executive');
        $this->assign($orderId, $accounts);
        $this->signIn($accounts);
        $r = $this->post("/orders/{$orderId}/vendor-bills/{$bill}/pay", ['paid_amount_inr' => '1180', 'paid_at' => date('Y-m-d'), 'payment_mode' => 'NEFT / RTGS', 'payment_ref' => 'UTR778899']);
        self::assertNotSame(403, $r['code']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM order_vendor_bill_payments WHERE bill_id = {$bill}")->fetchColumn(), (string) $r['flash']);
        $page = $this->page($orderId);
        self::assertStringContainsString('action="/orders/' . $orderId . '/vendor-bills/logistics"', $page, 'Accounts can still add a bill');

        $procurement = $this->createTestUser('Procurement Executive');
        $this->assign($orderId, $procurement);
        $this->signIn($procurement);
        self::assertSame(403, $this->post("/orders/{$orderId}/vendor-bills/logistics", ['logistics_partner_id' => '0'])['code']);
        self::assertStringNotContainsString('action="/orders/' . $orderId . '/vendor-bills/logistics"', $this->page($orderId));
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

    private function recordAdvance(int $orderId, array $post): array
    {
        $_POST = $post;
        ob_start();
        (new OrderController())->recordAdvancePayment(['id' => (string) $orderId]);
        ob_end_clean();
        $flash = Flash::pull();
        return end($flash) ?: ['type' => '', 'message' => ''];
    }

    public function testOurBankChargesAreBookedAsAnOrderCostWithTheReceipt(): void
    {
        $orderId = $this->orderAtAdvance();
        $this->signIn($this->createTestUser('Accounts Executive'));
        $r = $this->recordAdvance($orderId, ['advance_amount' => '1800', 'advance_received_at' => '2026-10-20', 'advance_inr_unknown' => '1', 'advance_exchange_rate' => '84', 'advance_bank_reference' => 'UTR778801', 'own_bank_charges_inr' => '590']);
        self::assertSame('success', $r['type'], $r['message']);

        $s = PartPaymentService::summary($orderId, 'advance');
        self::assertSame([1800.0, 0.0], [$s['received'], $s['owed']], 'the received amount and clearing are unchanged');
        $receipt = $s['receipts'][0];
        self::assertSame('590.00', $receipt['own_bank_charges_inr']);

        $costs = OrderCostEntryRepository::forOrder($orderId);
        self::assertCount(1, $costs);
        $cost = $costs[0];
        self::assertSame((int) $cost['id'], (int) $receipt['own_bank_charges_cost_id'], 'the cost is linked to the receipt');
        self::assertSame(['bank_charges', '590.00', 'company_account', '2026-10-20'], [$cost['category'], $cost['amount_inr'], $cost['paid_from'], substr((string) $cost['incurred_at'], 0, 10)]);
        $order = \App\Repositories\OrderRepository::find($orderId);
        $ref = $order['buyer_inquiry_ref'] ?? $order['order_reference'] ?? "#{$orderId}";
        self::assertSame("Our bank's inward remittance charges on " . $order['company_legal_name'] . "'s advance remittance for order " . $ref . ', ₹590.00 incl. GST · bank ref UTR778801', $cost['description']);
        self::assertNotContains('bank_charges', OrderCostEntryRepository::ZOHO_CATEGORIES, 'owner rule 29 Oct: bank charges are booked by the CA from the bank statement, not sent to Zoho');
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'REMITTANCE_OWN_BANK_CHARGES_RECORDED' AND entity_id = " . (int) $receipt['id'])->fetchColumn());
        self::assertStringContainsString("our bank's charges &#8377;590.00 (order cost)", $this->page($orderId));
    }

    public function testOurBankChargesAreOptionalAndChecked(): void
    {
        self::assertNull(RemittanceDifference::postedOwnBankCharges([]));
        self::assertNull(RemittanceDifference::postedOwnBankCharges(['own_bank_charges_inr' => '  ']));
        self::assertSame(590.0, RemittanceDifference::postedOwnBankCharges(['own_bank_charges_inr' => '590']));
        self::assertSame("Our bank's charges must be an amount in rupees above 0 — or leave the field empty.", RemittanceDifference::postedOwnBankCharges(['own_bank_charges_inr' => '-5']));
        self::assertSame("Our bank's charges of ₹250,000.00 look too high for one credit (more than ₹100,000.00). Check the bank advice and enter the fee only.", RemittanceDifference::postedOwnBankCharges(['own_bank_charges_inr' => '250000']));

        $orderId = $this->orderAtAdvance();
        $this->signIn($this->createTestUser('Accounts Executive'));
        $r = $this->recordAdvance($orderId, ['advance_amount' => '1800', 'advance_received_at' => '2026-10-20', 'advance_inr_unknown' => '1', 'advance_exchange_rate' => '84', 'advance_bank_reference' => 'UTR778802']);
        self::assertSame('success', $r['type'], $r['message']);
        self::assertSame([], OrderCostEntryRepository::forOrder($orderId), 'left blank: no cost entry');
        $r = $this->recordAdvance($this->orderAtAdvance(), ['advance_amount' => '1800', 'advance_received_at' => '2026-10-20', 'advance_inr_unknown' => '1', 'advance_exchange_rate' => '84', 'advance_bank_reference' => 'UTR778803', 'own_bank_charges_inr' => 'abc']);
        self::assertSame('error', $r['type']);
        self::assertSame("Our bank's charges must be an amount in rupees above 0 — or leave the field empty.", $r['message']);

        $page = $this->page($orderId);
        self::assertMatchesRegularExpression('#<label>Our bank\'s charges on this credit \(&\#8377;, incl\. GST\) <small class="muted">[^<]*</small><input type="number" step="0\.01" min="0\.01" max="100000\.00" name="own_bank_charges_inr" placeholder="e\.g\. 590\.00"></label>#', $page);
    }
}
