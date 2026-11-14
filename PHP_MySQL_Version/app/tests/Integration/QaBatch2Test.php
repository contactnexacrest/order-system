<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AmendmentController;
use App\Controllers\ClientIntakeReviewController;
use App\Controllers\DisputeController;
use App\Controllers\MerchantTradeController;
use App\Controllers\OrderController;
use App\Controllers\PaymentMilestoneController;
use App\Helpers\Flash;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\ImportRepository;
use App\Repositories\OrderCancellationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentMilestoneRepository;
use App\Repositories\UiTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\AmendmentService;
use App\Services\DivisionService;
use App\Services\DomesticOrderService;
use App\Services\ImportService;
use App\Services\MerchantTradeService;
use App\Services\PartPaymentService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA batch 2 (5 Nov 2026): order/workflow findings ORD-01…ORD-15, the
 * Merchant Trade money gate ACC-4, and the UI findings UI-6/7/8/13/14 that
 * live on the new division pages. Each test failed before its fix.
 * Node: qaBatch2.test.js.
 */
final class QaBatch2Test extends DbTestCase
{
    public static function tearDownAfterClass(): void
    {
        self::clearDivisionTradeData(); // leave no division-trade records for later test classes
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'planned' WHERE id <> 1");
        $pdo->exec('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
        DivisionService::resetCache();
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
        DivisionService::resetCache();
    }

    private function render(callable $action): string
    {
        ob_start();
        $action();
        return (string) ob_get_clean();
    }

    private static function flashError(): ?string
    {
        $error = null;
        foreach (Flash::pull() as $f) {
            if ($f['type'] === 'error') {
                $error = $f['message'];
            }
        }
        return $error;
    }

    private function addReceipt(int $orderId, string $leg = 'advance', float $amount = 3000.0): void
    {
        Database::connection()->prepare(
            "INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, :l, :a, '2026-10-10', 84, :i, :r)"
        )->execute(['o' => $orderId, 'l' => $leg, 'a' => $amount, 'i' => $amount * 84, 'r' => 'UTR-QA2-' . $orderId . '-' . $leg]);
    }

    private function requestCancellation(int $orderId): int
    {
        $this->actAs($this->createTestUser('Export Executive'));
        $_POST = ['reason' => 'The buyer has cancelled the purchase in writing.'];
        $this->render(fn () => (new OrderController())->requestCancellation(['id' => (string) $orderId]));
        Flash::pull();
        return (int) OrderCancellationRepository::findPendingForOrder($orderId)['id'];
    }

    private function approve(int $orderId, int $cancellationId, array $post): ?string
    {
        $this->actAs($this->createTestUser('Managing Director'));
        $_POST = $post;
        $this->render(fn () => (new OrderController())->approveCancellation(['id' => (string) $orderId, 'cancellationId' => (string) $cancellationId]));
        return self::flashError();
    }

    // ------------------------------------------------------------ ORD-01, ORD-03, ORD-04

    public function testAnOldCancellationRequestCannotCancelAShippedSettledOrLostOrder(): void
    {
        foreach (['shipped_docs_sent', 'complete', 'lost'] as $status) {
            $orderId = $this->createTestOrder($this->createTestClient());
            $cid = $this->requestCancellation($orderId);
            Database::connection()->prepare('UPDATE orders SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $orderId]);
            $error = $this->approve($orderId, $cid, ['refund_status' => 'not_applicable']);
            self::assertSame("This order is now \"{$status}\", so the cancellation can no longer be approved. Reject the request instead.", $error);
            self::assertSame($status, OrderRepository::find($orderId)['status'], "a {$status} order keeps its status");
            self::assertSame('pending', OrderCancellationRepository::find($cid)['status']);
            self::assertFalse(OrderRepository::cancel($orderId), 'the repository refuses too');
        }
    }

    public function testApprovalNeverMarksARefundCompletedAndChecksTheAmount(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $cid = $this->requestCancellation($orderId);
        self::assertSame('Approve with "Refund pending", then record the refund with the date it was paid and its bank reference.',
            $this->approve($orderId, $cid, ['refund_status' => 'completed', 'refund_amount' => '100']));
        self::assertSame('Enter the refund amount as a number above zero, or leave it empty.', $this->approve($orderId, $cid, ['refund_status' => 'pending', 'refund_amount' => '-500']));
        self::assertSame('Enter the refund amount as a number above zero, or leave it empty.', $this->approve($orderId, $cid, ['refund_status' => 'pending', 'refund_amount' => 'abc']));
        $this->addReceipt($orderId, 'advance', 3000.0);
        self::assertSame(3000.0, PartPaymentService::orderReceivedTotal($orderId));
        self::assertSame('The refund (3,000.01) is more than the buyer has paid on this order (3,000.00).', $this->approve($orderId, $cid, ['refund_status' => 'pending', 'refund_amount' => '3000.01']));
        self::assertSame('active', OrderRepository::find($orderId)['status']);

        self::assertNull($this->approve($orderId, $cid, ['refund_status' => 'pending', 'refund_amount' => '3000']));
        $c = OrderCancellationRepository::find($cid);
        self::assertSame(['approved', 'pending', '3000.00'], [$c['status'], $c['refund_status'], (string) $c['refund_amount']]);
        self::assertSame('cancelled', OrderRepository::find($orderId)['status']);
    }

    public function testMarkLostIsRefusedOnceTheBuyerHasPaidOrACancellationIsPending(): void
    {
        $paid = $this->createTestOrder($this->createTestClient());
        $this->addReceipt($paid);
        $this->actAs($this->createTestUser('Export Executive'));
        $_POST = ['reason' => 'The buyer stopped answering our emails.'];
        $this->render(fn () => (new OrderController())->markLost(['id' => (string) $paid]));
        self::assertSame('The buyer has already paid on this order, so it cannot be marked lost. Use Request Cancellation: a second person approves it and the refund is recorded.', self::flashError());
        self::assertSame('active', OrderRepository::find($paid)['status']);

        $pending = $this->createTestOrder($this->createTestClient());
        $this->requestCancellation($pending);
        $this->actAs($this->createTestUser('Export Executive'));
        $_POST = ['reason' => 'The buyer stopped answering our emails.'];
        $this->render(fn () => (new OrderController())->markLost(['id' => (string) $pending]));
        self::assertSame('This order has a cancellation request awaiting a decision — approve or reject it instead of marking the order lost.', self::flashError());

        $clean = $this->createTestOrder($this->createTestClient());
        $this->render(fn () => (new OrderController())->markLost(['id' => (string) $clean]));
        self::assertSame('lost', OrderRepository::find($clean)['status'], 'a walk-away before any money is still marked lost');
    }

    // ------------------------------------------------------------ ORD-02, ORD-06

    public function testAnAmendmentCannotLeaveANegativeBalanceOnAScheduledOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $advance = (float) OrderRepository::find($orderId)['advance_pct'];
        PaymentMilestoneRepository::replaceSchedule($orderId, [['pct' => 40.0, 'due_text' => 'on dispatch']], $advance);
        self::assertSame('Advance 70% + payment milestones 40% leave nothing for the balance — together they must stay below 100%. Change the payment schedule first.',
            AmendmentService::milestoneOverflowError($orderId, 70.0));
        self::assertNull(AmendmentService::milestoneOverflowError($orderId, 50.0));

        $this->actAs($this->createTestUser('Admin'));
        $_POST = ['reason' => 'The buyer asked for a larger advance.', 'requested_by' => 'importer', 'amended_advance_pct' => '70'];
        $this->render(fn () => (new AmendmentController())->create(['id' => (string) $orderId]));
        self::assertStringContainsString('leave nothing for the balance', (string) self::flashError());
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM amendments WHERE order_id = {$orderId}")->fetchColumn());
    }

    public function testAnLcOrdersScheduleIsFixedOnceTheLcIsConfirmed(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare('INSERT INTO order_lc_details (order_id, lc_amount, lc_confirmed_at) VALUES (:o, 1000, NOW())')->execute(['o' => $orderId]);
        $this->actAs($this->createTestUser('Admin'));
        $_POST = ['milestone_pct' => ['30'], 'milestone_due' => ['on dispatch']];
        $this->render(fn () => (new PaymentMilestoneController())->saveSchedule(['id' => (string) $orderId]));
        self::assertSame('The payment schedule is fixed once the LC is confirmed usable — change it with a payment terms amendment.', self::flashError());
        self::assertSame([], PaymentMilestoneRepository::forOrder($orderId));
    }

    // ------------------------------------------------------------ ORD-07, ORD-10, ORD-11, ORD-15

    public function testTheIncotermCannotChangeOnceTheFreightStageIsSettled(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB');
        Database::connection()->prepare(
            "UPDATE order_stages os JOIN stages_master sm ON sm.id = os.stage_id SET os.status = 'skipped' WHERE os.order_id = :o AND sm.stage_number = 6"
        )->execute(['o' => $orderId]);
        $order = OrderRepository::find($orderId);
        $cfr = (int) Database::connection()->query("SELECT id FROM incoterms WHERE code = 'CFR'")->fetchColumn();
        $this->actAs($this->createTestUser('Admin'));
        $_POST = ['container_type' => '20ft Standard', 'incoterm_id' => (string) $cfr, 'currency_id' => (string) $order['currency_id'], 'reason' => 'The buyer now wants CFR terms.'];
        $this->render(fn () => (new OrderController())->updateDetails(['id' => (string) $orderId]));
        self::assertSame('The incoterm can no longer change: the freight stage (Stage 6) is already skipped for the current incoterm. Agree the change with the buyer through an amendment, and ask an administrator to correct the order.', self::flashError());
        self::assertSame((int) $order['incoterm_id'], (int) OrderRepository::find($orderId)['incoterm_id']);
    }

    public function testTheLcRouteIsRefusedOnACashAdvanceOrder(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::assertSame('CASH_ADVANCE', OrderRepository::find($orderId)['payment_method']);
        $this->actAs($this->createTestUser('Admin'));
        $_POST = ['lc_amount' => '1000', 'lc_number' => 'LC-QA2'];
        $this->render(fn () => (new OrderController())->recordLcDetails(['id' => (string) $orderId]));
        self::assertSame('This order is not paid by Letter of Credit.', self::flashError());
        $this->render(fn () => (new OrderController())->confirmLcUsable(['id' => (string) $orderId]));
        self::assertSame('This order is not paid by Letter of Credit.', self::flashError());
        self::assertFalse((bool) Database::connection()->query("SELECT COUNT(*) FROM order_lc_details WHERE order_id = {$orderId}")->fetchColumn());
    }

    public function testADisputeNeedsARealPastNoticeDateAndAnActiveAssignee(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->actAs($this->createTestUser('Admin'));
        $count = static fn (): int => (int) Database::connection()->query("SELECT COUNT(*) FROM disputes WHERE order_id = {$orderId}")->fetchColumn();
        foreach (['31/10/2026', '2026-02-30', date('Y-m-d', strtotime('+1 day'))] as $bad) {
            $_POST = ['notice_date' => $bad, 'description' => 'Colour variation on two crates.'];
            $this->render(fn () => (new DisputeController())->create(['id' => (string) $orderId]));
            self::assertSame('Notice date: enter the date the notice was received (YYYY-MM-DD, today or earlier).', self::flashError(), $bad);
        }
        $inactive = $this->createTestUser('Export Executive');
        Database::connection()->exec("UPDATE users SET is_active = 0 WHERE id = {$inactive}");
        $_POST = ['notice_date' => date('Y-m-d'), 'description' => 'Colour variation on two crates.', 'assigned_to' => (string) $inactive];
        $this->render(fn () => (new DisputeController())->create(['id' => (string) $orderId]));
        self::assertSame('Assign the dispute to an active user, or leave it unassigned.', self::flashError());
        self::assertSame(0, $count());
        $_POST = ['notice_date' => date('Y-m-d'), 'description' => 'Colour variation on two crates.'];
        $this->render(fn () => (new DisputeController())->create(['id' => (string) $orderId]));
        self::assertSame(1, $count());
    }

    public function testTheArchivedListFollowsTheAssignmentRule(): void
    {
        $mine = $this->createTestUser('Export Executive');
        $assigned = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, $mine);
        $other = $this->createTestOrder($this->createTestClient());
        Database::connection()->exec("UPDATE orders SET is_archived = 1, archived_at = NOW() WHERE id IN ({$assigned}, {$other})");
        $pdo = Database::connection();
        $pdo->exec("INSERT IGNORE INTO role_permissions (role_id, permission_id, is_enabled)
            SELECT r.id, p.id, 1 FROM roles r JOIN permissions p ON p.permission_key = 'view_archived_orders' WHERE r.name = 'Export Executive'
              AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.role_id = r.id AND x.permission_id = p.id)");
        $this->actAs($mine);
        $ids = array_map(static fn (array $o): int => (int) $o['id'], OrderRepository::allArchived());
        self::assertContains($assigned, $ids);
        self::assertNotContains($other, $ids, 'an archived order not assigned to me is not listed');
        $this->actAs($this->createTestUser('Admin'));
        self::assertContains($other, array_map(static fn (array $o): int => (int) $o['id'], OrderRepository::allArchived()));
    }

    // ------------------------------------------------------------ ORD-05, ORD-13

    public function testTheQuotationIntakeQueueIsExportsAndARejectNeedsAPendingSubmission(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'active' WHERE id = 2");
        $dom = $this->createTestUser('Export Executive');
        $pdo->exec("UPDATE users SET division_id = 2 WHERE id = {$dom}");
        $this->actAs($dom);
        self::assertSame('The quotation enquiry queue belongs to the Export division.', $this->render(fn () => (new ClientIntakeReviewController())->index([])));
        self::assertSame(403, http_response_code());
        http_response_code(200);
        $this->actAs($this->createTestUser('Export Executive'));
        self::assertStringContainsString('Quotation', $this->render(fn () => (new ClientIntakeReviewController())->index([])));

        $pdo->prepare("INSERT INTO client_intake_submissions (company_legal_name, billing_address, contact_person, email, country_of_destination, status) VALUES ('QA2 Buyer', 'Addr', 'Ann', :e, 'Germany', 'converted')")
            ->execute(['e' => 'qa2-' . bin2hex(random_bytes(3)) . '@buyer.example']);
        $converted = (int) $pdo->lastInsertId();
        $_POST = ['reason' => 'Duplicate of an existing enquiry.'];
        $this->render(fn () => (new ClientIntakeReviewController())->reject(['id' => (string) $converted]));
        self::assertSame('Submission not found, or already resolved.', self::flashError());
        self::assertSame('converted', ClientIntakeRepository::find($converted)['status']);
        $this->render(fn () => (new ClientIntakeReviewController())->reject(['id' => '999999999']));
        self::assertSame('Submission not found, or already resolved.', self::flashError());
        self::assertFalse(ClientIntakeRepository::markRejected($converted, 1, 'x'));
    }

    // ------------------------------------------------------------ ORD-08, ORD-12 (Import & Distribution, Domestic Trade)

    public function testAnImportPoCannotChangeUnderItsPayments(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'active' WHERE id = 4");
        $pdo->exec("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 4 THEN 1 ELSE 0 END WHERE module_key = 'import_stock'");
        $staff = $this->createTestUser('Procurement Manager');
        $pdo->exec("UPDATE users SET division_id = 4 WHERE id = {$staff}");
        $this->actAs($staff);
        $code = 'QA2-' . bin2hex(random_bytes(3));
        $pdo->prepare("INSERT INTO products (product_code, name, hs_code) VALUES (:c, 'QA2 granite', '68029300')")->execute(['c' => $code]);
        $product = (int) $pdo->lastInsertId();
        $header = ['supplier_name' => 'TEST-QA2 Supplier', 'supplier_country' => 'China', 'currency_code' => 'USD', 'po_date' => date('Y-m-d', strtotime('-5 days'))];
        [$id, $error] = ImportService::create($header, $staff);
        self::assertNull($error);
        $id = (int) $id;
        self::assertNull(ImportService::addLine($id, ['product_id' => $product, 'quantity' => '10', 'unit' => 'sqm', 'unit_price_fc' => '100'], $staff));
        self::assertNull(ImportService::addLine($id, ['product_id' => $product, 'quantity' => '1', 'unit' => 'sqm', 'unit_price_fc' => '50'], $staff));
        ImportService::markPoGenerated($id, $staff);
        self::assertNull(ImportService::recordPayment($id, ['kind' => 'advance', 'paid_on' => date('Y-m-d'), 'amount_fc' => '1000', 'fx_rate' => '84', 'bank_reference' => 'SWIFT-QA2-' . $id], $staff));

        self::assertSame('The supplier has already been paid in USD, so the PO currency can no longer change.', ImportService::saveHeader($id, ['currency_code' => 'EUR'] + $header, $staff));
        $lines = ImportRepository::lines($id);
        self::assertSame('Removing this line would take the PO value (USD 50.00) below what the supplier has already been paid (USD 1,000.00).', ImportService::removeLine($id, (int) $lines[0]['id'], $staff));
        self::assertNotEmpty(ImportRepository::find($id)['ipo_generated_at']);
        self::assertNull(ImportService::removeLine($id, (int) $lines[1]['id'], $staff));
        self::assertNull(ImportRepository::find($id)['ipo_generated_at'], 'a PO generated before the change must be generated again');
        self::assertSame('This bank reference is already recorded. Check the payment is not entered twice.',
            ImportService::recordPayment($id, ['kind' => 'balance', 'paid_on' => date('Y-m-d'), 'amount_fc' => '1', 'fx_rate' => '84', 'bank_reference' => 'SWIFT-QA2-' . $id], $staff));
    }

    public function testADomesticReceiptNeverTakesThePaymentsAboveTheTaxInvoice(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO domestic_orders (order_id, stage, place_of_supply, seller_state_code, tax_invoice_no, invoice_total) VALUES (:o, 'payment', '29', '29', :n, '50000.00')")
            ->execute(['o' => $orderId, 'n' => 'DOM/QA2/' . $orderId]);
        $userId = $this->createTestUser('Admin');
        $this->actAs($userId);
        $pay = static fn (string $amount, string $ref): ?string => DomesticOrderService::recordPayment($orderId, ['received_on' => date('Y-m-d'), 'amount_inr' => $amount, 'bank_reference' => $ref], $userId);
        self::assertNull($pay('30000', 'UTR-QA2-A-' . $orderId));
        self::assertSame("This is more than the buyer still owes on tax invoice DOM/QA2/{$orderId} (₹20000.00).", $pay('20000.01', 'UTR-QA2-B-' . $orderId));
        self::assertNull($pay('20000', 'UTR-QA2-C-' . $orderId));
        self::assertSame('50000.00', DomesticOrderService::paidTotal($orderId));
        // Leave no domestic order behind (other tests count them).
        $pdo->prepare('DELETE FROM domestic_payments WHERE order_id = :o')->execute(['o' => $orderId]);
        $pdo->prepare('DELETE FROM domestic_orders WHERE order_id = :o')->execute(['o' => $orderId]);
    }

    // ------------------------------------------------------------ ACC-4, UI-6, UI-8, UI-13 (Merchant Trade and Imports pages)

    /** A deal on an active Merchant Trade division, with one line, a supplier payment and a buyer receipt. */
    private function merchantDeal(int $staff): int
    {
        [$id, $error] = MerchantTradeService::create([
            'deal_date' => date('Y-m-d', strtotime('-40 days')), 'supplier_name' => 'TEST-QA2 Marble', 'supplier_country' => 'Turkey',
            'purchase_currency' => 'EUR', 'buyer_name' => 'TEST-QA2 Gulf Buyer', 'buyer_country' => 'United Arab Emirates',
            'sale_currency' => 'USD', 'origin_country' => 'Turkey',
        ], $staff);
        self::assertNull($error);
        self::assertNull(MerchantTradeService::addLine((int) $id, ['description' => 'Marble', 'hsn_code' => '68022100', 'quantity' => '10', 'unit' => 'sqm', 'buy_price_fc' => '30', 'sell_price_fc' => '45'], $staff));
        self::assertNull(MerchantTradeService::recordPayment((int) $id, ['leg' => 'buy', 'moved_on' => date('Y-m-d', strtotime('-30 days')), 'amount_fc' => '100', 'fx_rate' => '91', 'bank_reference' => 'QA2-B-' . $id], $staff));
        self::assertNull(MerchantTradeService::recordPayment((int) $id, ['leg' => 'sell', 'moved_on' => date('Y-m-d', strtotime('-29 days')), 'amount_fc' => '200', 'fx_rate' => '84', 'bank_reference' => 'QA2-S-' . $id], $staff));
        return (int) $id;
    }

    public function testProcurementSeesNeitherTheSaleNorTheBuyerOfAMerchantDeal(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'active' WHERE id = 3");
        $pdo->exec("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 3 THEN 1 ELSE 0 END WHERE module_key = 'merchant_trade'");
        $md = $this->createTestUser('Managing Director');
        $pdo->exec("UPDATE users SET division_id = NULL WHERE id = {$md}");
        $this->actAs($md);
        $id = $this->merchantDeal($md);
        $proc = $this->createTestUser('Procurement Manager');
        $pdo->exec("UPDATE users SET division_id = 3 WHERE id = {$proc}");

        $this->actAs($md);
        $full = $this->render(fn () => (new MerchantTradeController())->show(['id' => (string) $id]));
        foreach (['TEST-QA2 Gulf Buyer', 'Sell USD', 'Received from the buyer', 'QA2-S-' . $id, 'Download sale invoice'] as $text) {
            self::assertStringContainsString($text, $full, "the MD sees: {$text}");
        }

        $this->actAs($proc);
        $page = $this->render(fn () => (new MerchantTradeController())->show(['id' => (string) $id]));
        self::assertStringContainsString('QA2-B-' . $id, $page, 'the supplier leg stays visible');
        foreach (['TEST-QA2 Gulf Buyer', 'Sell USD', 'Received from the buyer', 'QA2-S-' . $id, 'Download sale invoice', 'margin ₹'] as $text) {
            self::assertStringNotContainsString($text, $page, "Procurement does not see: {$text}");
        }
        foreach ([[], ['view' => 'list']] as $get) {
            $_GET = $get;
            $list = $this->render(fn () => (new MerchantTradeController())->index([]));
            self::assertStringNotContainsString('TEST-QA2 Gulf Buyer', $list);
            self::assertStringNotContainsString('Sell USD', $list);
        }
        $_GET = [];
        $reports = $this->render(fn () => (new MerchantTradeController())->reports([]));
        self::assertStringNotContainsString('TEST-QA2 Gulf Buyer', $reports);
        self::assertStringNotContainsString('Margin per deal', $reports);
        $_POST = ['kind' => 'invoice'];
        $this->render(fn () => (new MerchantTradeController())->document(['id' => (string) $id]));
        self::assertSame('The sale invoice carries selling prices your department does not see.', self::flashError());
        self::assertNull(\App\Repositories\MerchantTradeRepository::find($id)['sale_invoice_no'], 'no invoice number was fixed');
    }

    public function testTheMerchantAndImportCardsCarryWhatTheirListsShowAndAnEmptyStageSaysSo(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'active' WHERE id IN (3, 4)");
        $pdo->exec("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 3 THEN 1 ELSE 0 END WHERE module_key = 'merchant_trade'");
        $pdo->exec("UPDATE division_modules SET is_enabled = CASE WHEN division_id = 4 THEN 1 ELSE 0 END WHERE module_key = 'import_stock'");
        $md = $this->createTestUser('Managing Director');
        $pdo->exec("UPDATE users SET division_id = NULL WHERE id = {$md}");
        UserRepository::updateUiTemplatePreference($md, (int) UiTemplateRepository::findByKey('kanban')['id']);
        $this->actAs($md);
        $deal = $this->merchantDeal($md);
        $dealDate = (string) \App\Repositories\MerchantTradeRepository::find($deal)['deal_date'];
        [$imp] = ImportService::create(['supplier_name' => 'TEST-QA2 Card Supplier', 'supplier_country' => 'China', 'currency_code' => 'USD', 'po_date' => date('Y-m-d', strtotime('-9 days'))], $md);
        ImportRepository::update((int) $imp, ['bl_no' => 'QA2BL123', 'boe_no' => '7654321'], $md);

        $_GET = [];
        $board = $this->render(fn () => (new MerchantTradeController())->index([]));
        self::assertStringContainsString('kanban-board', $board);
        self::assertStringContainsString('Deal date ' . $dealDate, $board, 'UI-6: the card shows the deal date like the list');
        $importBoard = $this->render(fn () => (new \App\Controllers\ImportController())->index([]));
        self::assertStringContainsString('BL QA2BL123 · Bill of entry 7654321', $importBoard, 'UI-6: the card shows BL and bill of entry like the list');

        $_GET = ['view' => 'list', 'stage' => 'closed'];
        self::assertStringContainsString('None at this stage.', $this->render(fn () => (new MerchantTradeController())->index([])), 'UI-13');
        self::assertStringContainsString('None at this stage.', $this->render(fn () => (new \App\Controllers\ImportController())->index([])), 'UI-13');

        // UI-8: the browser checks the cross-field rules before the round trip.
        $_GET = [];
        $show = $this->render(fn () => (new MerchantTradeController())->show(['id' => (string) $deal]));
        self::assertStringContainsString('name="switch_bl_no" maxlength="40"', $show);
        self::assertMatchesRegularExpression('/name="switch_bl_no"[^>]*data-required-when="switch_bl=1"/', $show);
        self::assertMatchesRegularExpression('/name="bl_date" required min="' . $dealDate . '"/', $show);
        $importShow = $this->render(fn () => (new \App\Controllers\ImportController())->show(['id' => (string) $imp]));
        self::assertMatchesRegularExpression('/name="supplier_country"[^>]*data-pattern-message="An import comes from a supplier abroad: the country cannot be India."/', $importShow);
    }
}
