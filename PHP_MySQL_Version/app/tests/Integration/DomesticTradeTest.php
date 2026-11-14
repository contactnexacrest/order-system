<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\DivisionController;
use App\Controllers\DomesticOrderController;
use App\Controllers\OrderController;
use App\Helpers\AmountInWords;
use App\Helpers\Flash;
use App\Repositories\DivisionRepository;
use App\Repositories\DomesticOrderRepository;
use App\Repositories\OrderRepository;
use App\Services\DivisionService;
use App\Services\DomesticDocumentService;
use App\Services\DomesticOrderService;
use App\Services\GstCalculator;
use App\Services\OrderAccessService;
use App\Services\PermissionService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-257 Phase 2 (5 Nov 2026): the Domestic Trade (DOM)
 * division. DOM stays "planned" in the seed; this test activates it inside
 * the test only, to prove: while planned nothing changes; one DOM order runs
 * end to end (enquiry → … → closed) with its four documents, GST maths
 * (CGST + SGST in the same state, IGST across states, paise rounding, round
 * off, HSN summary, amount in words in lakh), the e-way bill rule and the
 * D-212 payment description; Export and DOM never see each other's orders;
 * the Zoho payload and the Division tag (only with two active divisions);
 * Admin › Divisions sets DOM up. Node: domesticTrade.test.js.
 */
final class DomesticTradeTest extends DbTestCase
{
    public static function tearDownAfterClass(): void
    {
        self::clearDivisionTradeData(); // leave no division-trade records for later test classes
        parent::tearDownAfterClass();
    }

    private const DOM = 2;
    private const BUYER_GSTIN = '29AAPFU0939F1ZR'; // Karnataka, like NexaCrest (seed GSTIN 29…)

    private int $md;
    private int $domStaff;
    private int $expStaff;
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = Database::connection();
        $this->md = $this->createTestUser('Managing Director');
        $this->domStaff = $this->createTestUser('Sales/Export Manager');
        $this->expStaff = $this->createTestUser('Sales/Export Manager');
        $pdo->prepare('UPDATE users SET division_id = NULL WHERE id = :id')->execute(['id' => $this->md]);
        $pdo->prepare('UPDATE users SET division_id = :d WHERE id = :id')->execute(['d' => self::DOM, 'id' => $this->domStaff]);
        $this->client = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET company_legal_name = 'Bengaluru Stone Traders', billing_address = :a WHERE id = :id")
            ->execute(['a' => "12 MG Road\nBengaluru 560001", 'id' => $this->client]);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $pdo = Database::connection();
        $pdo->exec("UPDATE divisions SET status = 'planned' WHERE id <> 1");
        $pdo->exec('UPDATE users SET division_id = 1 WHERE division_id IS NOT NULL AND division_id <> 1');
        $pdo->prepare('DELETE FROM client_divisions WHERE client_id = :c AND division_id <> 1')->execute(['c' => $this->client]);
        ZohoBooksService::setTransport(null);
        DivisionService::resetCache();
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        parent::tearDown();
    }

    private function activate(): void
    {
        Database::connection()->prepare("UPDATE divisions SET status = 'active' WHERE id = :d")->execute(['d' => self::DOM]);
        DivisionService::resetCache();
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

    /** @return int a DOM order for the test client (as DOM staff) */
    private function domOrder(string $placeOfSupply = '29', ?string $gstin = self::BUYER_GSTIN): int
    {
        $this->activate();
        // A client of Domestic Trade (DOM staff see only DOM's clients).
        Database::connection()->prepare('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)')->execute(['c' => $this->client, 'd' => self::DOM]);
        $this->actAs($this->domStaff);
        [$orderId, $error] = DomesticOrderService::create([
            'client_id' => $this->client, 'buyer_gstin' => $gstin, 'buyer_state_code' => $gstin !== null ? substr($gstin, 0, 2) : '29',
            'place_of_supply' => $placeOfSupply, 'delivery_address' => "Plot 4, Peenya\nBengaluru 560058", 'payment_terms_days' => '30', 'buyer_po_ref' => 'PO/77',
        ], $this->domStaff);
        self::assertNull($error);
        return (int) $orderId;
    }

    public function testGstMathsSameStateOtherStateRoundingAndWords(): void
    {
        $lines = [
            ['quantity' => '10.5', 'rate_inr' => '1999.99', 'gst_rate_pct' => '18.00', 'hsn_code' => '6802'],
            ['quantity' => '3', 'rate_inr' => '333.33', 'gst_rate_pct' => '12.00', 'hsn_code' => '2515'],
            ['quantity' => '1', 'rate_inr' => '0.05', 'gst_rate_pct' => '18.00', 'hsn_code' => '6802'],
        ];
        $intra = GstCalculator::compute($lines, '29', '29');
        self::assertTrue($intra['intra_state']);
        // 10.5 × 1999.99 = 20999.895 → 20999.90; 9 % of it = 1889.991 → 1889.99 each half.
        self::assertSame(['20999.90', '1889.99', '1889.99', '0.00'], [$intra['lines'][0]['taxable'], $intra['lines'][0]['cgst'], $intra['lines'][0]['sgst'], $intra['lines'][0]['igst']]);
        // 0.05 × 9 % = 0.0045 → 0.00 each half (paise, half away from zero).
        self::assertSame('0.00', $intra['lines'][2]['cgst']);
        self::assertSame(['21999.94', '1949.99', '1949.99', '0.00'], [$intra['taxable_total'], $intra['cgst_total'], $intra['sgst_total'], $intra['igst_total']]);
        self::assertSame(['25899.92', '0.08', '25900.00'], [$intra['gross_total'], $intra['round_off'], $intra['invoice_total']]);
        self::assertCount(2, $intra['hsn_summary'], 'one row per HSN and rate');
        self::assertSame(['6802', '18.00', '20999.95', '3779.98'], [$intra['hsn_summary'][0]['hsn_code'], $intra['hsn_summary'][0]['gst_rate_pct'], $intra['hsn_summary'][0]['taxable'], $intra['hsn_summary'][0]['tax_total']]);

        $inter = GstCalculator::compute($lines, '29', '27');
        self::assertFalse($inter['intra_state']);
        // 18 % of 20999.90 = 3779.982 → 3779.98 as IGST.
        self::assertSame(['0.00', '0.00', '3779.98'], [$inter['lines'][0]['cgst'], $inter['lines'][0]['sgst'], $inter['lines'][0]['igst']]);
        self::assertSame(['3899.99', '25899.93', '0.07', '25900.00'], [$inter['igst_total'], $inter['gross_total'], $inter['round_off'], $inter['invoice_total']]);
        // Rounding down: 100.40 → 100.00, round off −0.40.
        $down = GstCalculator::compute([['quantity' => '1', 'rate_inr' => '85.08', 'gst_rate_pct' => '18', 'hsn_code' => '6802']], '29', '27');
        self::assertSame(['15.31', '100.39', '-0.39', '100.00'], [$down['igst_total'], $down['gross_total'], $down['round_off'], $down['invoice_total']]);

        self::assertTrue(GstCalculator::isValidGstin(self::BUYER_GSTIN));
        self::assertFalse(GstCalculator::isValidGstin('29AAPFU0939F1ZA'), 'wrong check character');
        self::assertFalse(GstCalculator::isValidGstin('29AAPFU0939F1Z'), 'too short');
        self::assertSame('1,23,45,678.50', GstCalculator::inr('12345678.5'));
        self::assertSame('Indian Rupees Twenty-Five Thousand Nine Hundred Only', AmountInWords::formatIndian('25900.00'));
        self::assertSame('Indian Rupees One Crore Twenty-Three Lakh Forty-Five Thousand Six Hundred Seventy-Eight and Fifty Paise Only', AmountInWords::formatIndian('12345678.50'));
        self::assertSame('US Dollars One Million Only', AmountInWords::format('1000000', 'USD'), 'the export wording is unchanged');
    }

    public function testWhileDomesticTradeIsPlannedNothingChanges(): void
    {
        self::assertFalse(DomesticOrderService::isActive());
        $this->actAs($this->md);
        [$id, $error] = DomesticOrderService::create(['client_id' => $this->client, 'buyer_state_code' => '29', 'place_of_supply' => '29'], $this->md);
        self::assertNull($id);
        self::assertStringContainsString('not active', (string) $error);
        $page = $this->render(fn () => (new DomesticOrderController())->create([]));
        self::assertStringContainsString('dom-not-active', $page);
        self::assertStringNotContainsString('dom-create-form', $page);
        $clientPage = $this->render(fn () => (new \App\Controllers\ClientController())->show(['id' => (string) $this->client]));
        self::assertStringNotContainsString('client-gst', $clientPage, 'no GST section on the client page while DOM is planned');
        // INR is never offered on an Export order, and refused if posted.
        $create = $this->render(fn () => (new OrderController())->create([]));
        self::assertDoesNotMatchRegularExpression('/<option[^>]*>\s*INR\b/', $create);
        $inr = (int) Database::connection()->query("SELECT id FROM currencies WHERE code = 'INR'")->fetchColumn();
        self::assertGreaterThan(0, $inr);
        $_POST = ['client_id' => (string) $this->client, 'container_type' => '20ft Standard', 'incoterm_id' => '1', 'currency_id' => (string) $inr, 'payment_preset_id' => '1'];
        $before = (int) Database::connection()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $this->render(fn () => (new OrderController())->store([]));
        self::assertSame($before, (int) Database::connection()->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertStringContainsString('Orders in rupees belong to Domestic Trade', implode(' ', array_column(Flash::pull(), 'message')));
        // Admin › Divisions: Domestic Trade's pre-flight lists what it needs.
        $labels = array_column(DivisionRepository::preflight(self::DOM), 'label');
        self::assertContains('GST rates set (confirm them with the CA)', $labels);
        self::assertContains('NexaCrest\'s GSTIN is valid (it gives the GST state)', $labels);
    }

    public function testOneDomesticOrderEndToEnd(): void
    {
        $orderId = $this->domOrder();
        $order = OrderRepository::find($orderId);
        self::assertSame(self::DOM, (int) $order['division_id']);
        self::assertSame('INR', $order['currency_code']);
        self::assertStringStartsWith('DOM/', (string) $order['order_reference'], 'DOM counts its own order numbers');
        self::assertSame(self::BUYER_GSTIN, DomesticOrderRepository::client($this->client)['gstin'], 'the client gets the GSTIN given');

        // Enquiry: a line is needed; the GST rate comes from the HSN (6802 → 18 %).
        self::assertSame(['Add at least one line.'], DomesticOrderService::blockers($orderId));
        self::assertSame('Enter the HSN code (4 to 8 digits).', DomesticOrderService::addLine($orderId, ['description' => 'Granite', 'hsn_code' => '68', 'quantity' => '1', 'unit' => 'SQM', 'rate_inr' => '10'], $this->domStaff));
        self::assertNull(DomesticOrderService::addLine($orderId, ['description' => 'Black granite slabs, polished, 18 mm', 'hsn_code' => '68029300', 'quantity' => '120.5', 'unit' => 'sqm', 'rate_inr' => '1450'], $this->domStaff));
        self::assertNull(DomesticOrderService::addLine($orderId, ['description' => 'Edge polishing', 'hsn_code' => '99887766', 'quantity' => '1', 'unit' => 'LOT', 'rate_inr' => '2500', 'gst_rate_pct' => '12'], $this->domStaff));
        $lines = DomesticOrderRepository::lines($orderId);
        self::assertSame(['18.00', '12.00'], array_column($lines, 'gst_rate_pct'));
        self::assertNull(DomesticOrderService::advance($orderId, 'enquiry', $this->domStaff));
        self::assertSame('Someone has already moved this order on. The page shows where it is now.', DomesticOrderService::advance($orderId, 'enquiry', $this->domStaff));

        // Quotation: the DQT document is needed.
        self::assertSame(['Generate the quotation.'], DomesticOrderService::blockers($orderId));
        [, $err] = DomesticDocumentService::generate($orderId, 'GTI', $this->domStaff);
        self::assertStringContainsString('from the Dispatch stage on', (string) $err, 'no tax invoice before dispatch');
        [$qtFile, $err] = DomesticDocumentService::generate($orderId, 'DQT', $this->domStaff);
        self::assertNull($err);
        self::assertMatchesRegularExpression('#^DOM/QT/\d{2}-\d{2}/\d{4}$#', DomesticOrderRepository::latestDocument($orderId, 'DQT')['reference']);
        self::assertNull(DomesticOrderService::advance($orderId, 'quotation', $this->domStaff));

        // Order confirmation, then purchase.
        [, $err] = DomesticDocumentService::generate($orderId, 'DOC', $this->domStaff);
        self::assertNull($err);
        self::assertNull(DomesticOrderService::advance($orderId, 'order_confirmation', $this->domStaff));
        self::assertSame(['Record the supplier PO.'], DomesticOrderService::blockers($orderId));
        self::assertNull(DomesticOrderService::savePurchase($orderId, ['supplier_name' => 'Sri Balaji Granites', 'supplier_po_ref' => 'NC/SPO/45'], $this->domStaff));
        self::assertNull(DomesticOrderService::advance($orderId, 'purchase', $this->domStaff));

        // Dispatch: the tax invoice number is fixed, totals frozen, lines locked.
        $dom = DomesticOrderRepository::find($orderId);
        self::assertMatchesRegularExpression('#^DOM/\d{2}-\d{2}/\d{4}$#', (string) $dom['tax_invoice_no']);
        self::assertLessThanOrEqual(16, strlen((string) $dom['tax_invoice_no']), 'a GST invoice number has at most 16 characters');
        // 120.5 × 1450 = 174725.00 (CGST 15725.25 + SGST 15725.25); 2500 at 12 % (150 + 150).
        self::assertSame(['177225.00', '15875.25', '15875.25', '0.00', '0.50', '208976.00'], [$dom['taxable_total'], $dom['cgst_total'], $dom['sgst_total'], $dom['igst_total'], $dom['round_off'], $dom['invoice_total']]);
        self::assertStringContainsString('lines can no longer change', (string) DomesticOrderService::addLine($orderId, ['description' => 'x', 'hsn_code' => '6802', 'quantity' => '1', 'unit' => 'PCS', 'rate_inr' => '1'], $this->domStaff));
        self::assertTrue(DomesticOrderService::needsEwayBill($dom), '₹2,08,976 is above the ₹50,000 threshold');
        self::assertContains('Enter the e-way bill number: the consignment is above ₹50,000.00.', DomesticOrderService::blockers($orderId));
        self::assertSame('Enter the vehicle number (e.g. KA01AB1234).', DomesticOrderService::saveDispatch($orderId, ['dispatch_date' => date('Y-m-d'), 'transporter_name' => 'VRL', 'vehicle_no' => '1234'], $this->domStaff));
        self::assertNull(DomesticOrderService::saveDispatch($orderId, ['dispatch_date' => date('Y-m-d'), 'transporter_name' => 'VRL Logistics', 'vehicle_no' => 'ka 01 ab 1234', 'lr_no' => 'LR-1', 'eway_bill_no' => '331012345678', 'eway_bill_date' => date('Y-m-d')], $this->domStaff));
        [$gtiFile, $err] = DomesticDocumentService::generate($orderId, 'GTI', $this->domStaff);
        self::assertNull($err);
        [, $err] = DomesticDocumentService::generate($orderId, 'DCH', $this->domStaff);
        self::assertNull($err);
        self::assertSame((string) $dom['tax_invoice_no'], DomesticOrderRepository::latestDocument($orderId, 'GTI')['reference']);

        // The tax invoice: CGST + SGST, HSN summary, words in lakh, e-way bill; nothing from the export documents.
        $html = DomesticDocumentService::html($orderId, 'GTI', (string) $dom['tax_invoice_no']);
        foreach (['TAX INVOICE', (string) $dom['tax_invoice_no'], 'CGST', 'SGST', 'HSN SUMMARY', '68029300', '1,74,725.00', '2,08,976.00',
            'Indian Rupees Two Lakh Eight Thousand Nine Hundred Seventy-Six Only', self::BUYER_GSTIN, 'Karnataka (29)', '331012345678', 'KA01AB1234', 'Tax payable under reverse charge: No.'] as $needle) {
            self::assertStringContainsString($needle, $html, $needle);
        }
        self::assertStringNotContainsString('IGST (₹)', $html);
        self::assertStringNotContainsString('LUT', $html);
        self::assertStringContainsString('DELIVERY CHALLAN', DomesticDocumentService::html($orderId, 'DCH', 'DOM/DC/26-27/0001'));
        self::assertStringStartsWith('%PDF', (string) file_get_contents(\App\Repositories\FileStoreRepository::find((int) $gtiFile)['server_path']));
        self::assertSame('selling', \App\Repositories\FileStoreRepository::moneyClassFor((int) $qtFile), 'the documents show prices: only for who may see selling prices');
        self::assertNull(DomesticOrderService::advance($orderId, 'dispatch', $this->domStaff));

        // Tax invoice sent; payments with the D-212 description; closed only when fully paid.
        self::assertSame(['Mark the tax invoice as sent to the buyer.'], DomesticOrderService::blockers($orderId));
        self::assertNull(DomesticOrderService::markInvoiceSent($orderId, date('Y-m-d'), $this->domStaff));
        self::assertNull(DomesticOrderService::advance($orderId, 'tax_invoice', $this->domStaff));
        self::assertSame('Enter the bank reference (UTR / NEFT / RTGS / cheque number).', DomesticOrderService::recordPayment($orderId, ['received_on' => date('Y-m-d'), 'amount_inr' => '100000'], $this->domStaff));
        self::assertNull(DomesticOrderService::recordPayment($orderId, ['received_on' => date('Y-m-d'), 'amount_inr' => '100000', 'bank_reference' => 'UTR SBIN226000111', 'note' => 'part payment'], $this->domStaff));
        self::assertSame('This bank reference is already recorded. Check the payment is not entered twice.', DomesticOrderService::recordPayment($orderId, ['received_on' => date('Y-m-d'), 'amount_inr' => '5', 'bank_reference' => 'UTR SBIN226000111'], $this->domStaff));
        $payment = DomesticOrderRepository::payments($orderId)[0];
        self::assertSame("Domestic sale receipt of ₹1,00,000.00 from Bengaluru Stone Traders against GST tax invoice {$dom['tax_invoice_no']} (order {$order['order_reference']}), bank ref UTR SBIN226000111; note: part payment", $payment['description']);
        self::assertSame(['Still to receive: ₹1,08,976.00.'], DomesticOrderService::blockers($orderId));
        self::assertNull(DomesticOrderService::recordPayment($orderId, ['received_on' => date('Y-m-d'), 'amount_inr' => '108975.50', 'bank_reference' => 'UTR SBIN226000222'], $this->domStaff));
        self::assertSame([], DomesticOrderService::blockers($orderId), 'paid within the ₹1 tolerance');
        self::assertNull(DomesticOrderService::advance($orderId, 'payment', $this->domStaff));
        self::assertSame('closed', DomesticOrderRepository::find($orderId)['stage']);
        self::assertSame('complete', OrderRepository::find($orderId)['status']);

        // The order page is the domestic page, for DOM staff.
        $page = $this->render(fn () => (new OrderController())->show(['id' => (string) $orderId]));
        self::assertStringContainsString('data-testid="dom-order"', $page);
        self::assertStringContainsString('Domestic sale receipt of ₹1,00,000.00', $page);
    }

    public function testOtherStateIsIgstAndAnUnregisteredBuyerWaitsForItsGstin(): void
    {
        $orderId = $this->domOrder('27', null);
        DomesticOrderService::addLine($orderId, ['description' => 'Marble tiles', 'hsn_code' => '6802', 'quantity' => '100', 'unit' => 'SQM', 'rate_inr' => '400'], $this->domStaff);
        $t = DomesticOrderService::totals($orderId);
        self::assertFalse($t['intra_state']);
        self::assertSame(['40000.00', '7200.00', '47200.00'], [$t['taxable_total'], $t['igst_total'], $t['invoice_total']]);
        $html = DomesticDocumentService::html($orderId, 'DQT', 'DOM/QT/26-27/0009');
        self::assertStringContainsString('IGST', $html);
        self::assertStringContainsString('Maharashtra (27)', $html);
        self::assertStringContainsString('Unregistered', $html);
        DomesticOrderService::advance($orderId, 'enquiry', $this->domStaff);
        DomesticDocumentService::generate($orderId, 'DQT', $this->domStaff);
        DomesticOrderService::advance($orderId, 'quotation', $this->domStaff);
        self::assertContains('Enter the buyer\'s GSTIN (a reseller buys on its GST registration).', DomesticOrderService::blockers($orderId));
        self::assertStringContainsString('does not match', (string) DomesticOrderService::saveParty($orderId, ['buyer_gstin' => self::BUYER_GSTIN, 'buyer_state_code' => '27', 'place_of_supply' => '27'], $this->domStaff));
        self::assertNull(DomesticOrderService::saveParty($orderId, ['buyer_gstin' => self::MH_GSTIN_VALID(), 'buyer_state_code' => '27', 'place_of_supply' => '27'], $this->domStaff));
        self::assertNotContains('Enter the buyer\'s GSTIN (a reseller buys on its GST registration).', DomesticOrderService::blockers($orderId));
        self::assertFalse(DomesticOrderService::needsEwayBill(['invoice_total' => '50000.00']), 'exactly the threshold needs none');
        self::assertTrue(DomesticOrderService::needsEwayBill(['invoice_total' => '50000.01']));
    }

    /** A valid Maharashtra GSTIN for the test (same PAN, its own check character). */
    private static function MH_GSTIN_VALID(): string
    {
        return '27AAPFU0939F1Z' . GstCalculator::gstinCheckChar('27AAPFU0939F1Z');
    }

    public function testExportAndDomesticNeverSeeEachOthersOrders(): void
    {
        $domOrder = $this->domOrder();
        $expClient = $this->createTestClient();
        $expOrder = $this->createTestOrder($expClient);
        $pdo = Database::connection();
        $pdo->prepare('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (:o, :u, :u2)')->execute(['o' => $expOrder, 'u' => $this->domStaff, 'u2' => $this->md]);
        $pdo->prepare('INSERT IGNORE INTO order_assignees (order_id, user_id, assigned_by) VALUES (:o, :u, :u2)')->execute(['o' => $domOrder, 'u' => $this->expStaff, 'u2' => $this->md]);

        $this->actAs($this->expStaff);
        self::assertNotNull(OrderAccessService::denialReason(OrderRepository::find($domOrder), $this->expStaff, null), 'Export staff cannot open a DOM order, even if assigned');
        self::assertNotContains($domOrder, array_map('intval', array_column(OrderRepository::allVisibleTo($this->expStaff, true), 'id')));
        $_POST = ['description' => 'x', 'hsn_code' => '6802', 'quantity' => '1', 'unit' => 'PCS', 'rate_inr' => '1'];
        $out = $this->render(fn () => (new DomesticOrderController())->addLine(['id' => (string) $domOrder]));
        self::assertSame([], DomesticOrderRepository::lines($domOrder), 'the line was not added');
        self::assertNotSame('', $out, 'refused with the access message');

        $this->actAs($this->domStaff);
        self::assertNotNull(OrderAccessService::denialReason(OrderRepository::find($expOrder), $this->domStaff, null), 'DOM staff cannot open an Export order');
        $ids = array_map('intval', array_column(OrderRepository::allVisibleTo($this->domStaff, true), 'id'));
        self::assertContains($domOrder, $ids);
        self::assertNotContains($expOrder, $ids);
        // Not a DOM order: the domestic actions refuse an Export order outright.
        $this->actAs($this->md);
        $out = $this->render(fn () => (new DomesticOrderController())->addLine(['id' => (string) $expOrder]));
        self::assertStringContainsString('Order not found', $out);
        // The company level sees both.
        $ids = array_map('intval', array_column(OrderRepository::allVisibleTo($this->md, true), 'id'));
        self::assertContains($domOrder, $ids);
        self::assertContains($expOrder, $ids);
    }

    public function testZohoInvoicePayloadAndTheDivisionTag(): void
    {
        $orderId = $this->domOrder('27', self::MH_GSTIN_VALID());
        DomesticOrderService::addLine($orderId, ['description' => 'Marble tiles', 'hsn_code' => '6802', 'quantity' => '10', 'unit' => 'SQM', 'rate_inr' => '400'], $this->domStaff);
        foreach (['enquiry', 'quotation', 'order_confirmation', 'purchase'] as $stage) {
            match ($stage) {
                'quotation' => DomesticDocumentService::generate($orderId, 'DQT', $this->domStaff),
                'order_confirmation' => DomesticDocumentService::generate($orderId, 'DOC', $this->domStaff),
                'purchase' => DomesticOrderService::savePurchase($orderId, ['supplier_name' => 'S', 'supplier_po_ref' => 'P1'], $this->domStaff),
                default => null,
            };
            self::assertNull(DomesticOrderService::advance($orderId, $stage, $this->domStaff), $stage);
        }
        $dom = DomesticOrderRepository::find($orderId);
        $payload = ZohoBooksService::domesticInvoicePayload($orderId, 'C-1', static fn (string $name): string => 'T-' . $name);
        self::assertSame('business_gst', $payload['gst_treatment']);
        self::assertSame(self::MH_GSTIN_VALID(), $payload['gst_no']);
        self::assertSame('MH', $payload['place_of_supply']);
        self::assertSame((string) $dom['tax_invoice_no'], $payload['invoice_number']);
        self::assertSame('T-IGST18', $payload['line_items'][0]['tax_id'], 'another state: IGST');
        self::assertSame('6802', $payload['line_items'][0]['hsn_or_sac']);
        self::assertSame("Domestic GST tax invoice {$dom['tax_invoice_no']} for order " . OrderRepository::find($orderId)['order_reference'] . ', Bengaluru Stone Traders, place of supply Maharashtra.', $payload['notes']);
        self::assertArrayNotHasKey('tags', $payload, 'no Division tag until it is set up in Settings');

        $pdo = Database::connection();
        $pdo->exec("INSERT INTO company_settings (setting_key, setting_value, value_type, category, description, is_sensitive) VALUES
            ('zoho_division_tag_id', 'TAG-1', 'string', 'zoho', 'test', 0), ('zoho_division_tag_option_dom', 'OPT-DOM', 'string', 'zoho', 'test', 0)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        try {
            $payload = ZohoBooksService::domesticInvoicePayload($orderId, 'C-1', static fn (string $name): string => 'T-' . $name);
            self::assertSame([['tag_id' => 'TAG-1', 'tag_option_id' => 'OPT-DOM']], $payload['tags'], 'two active divisions: the Division tag goes with it');
            $pdo->exec("UPDATE divisions SET status = 'planned' WHERE id = " . self::DOM);
            DivisionService::resetCache();
            self::assertSame([], ZohoBooksService::divisionTags(1), 'one active division: no tag, so Export\'s Zoho records are unchanged');
        } finally {
            $pdo->exec("DELETE FROM company_settings WHERE setting_key IN ('zoho_division_tag_id', 'zoho_division_tag_option_dom')");
        }
    }

    public function testAdminDivisionsSetsUpDomesticTrade(): void
    {
        $this->actAs($this->md);
        $pdo = Database::connection();
        $_POST = [];
        $this->render(fn () => (new DivisionController())->starterDepartments(['id' => (string) self::DOM]));
        $made = $pdo->query("SELECT code, division_id FROM departments WHERE code IN ('DOM_SALES','DOM_PURCHASE','DOM_DISPATCH') ORDER BY code")->fetchAll();
        self::assertSame(['DOM_DISPATCH', 'DOM_PURCHASE', 'DOM_SALES'], array_column($made, 'code'));
        self::assertSame([self::DOM, self::DOM, self::DOM], array_map('intval', array_column($made, 'division_id')));
        $_POST = ['user_id' => (string) $this->expStaff];
        $this->render(fn () => (new DivisionController())->addStaff(['id' => (string) self::DOM]));
        self::assertSame(self::DOM, (int) $pdo->query('SELECT division_id FROM users WHERE id = ' . $this->expStaff)->fetchColumn());
        $_POST = ['user_id' => (string) $this->md];
        $this->render(fn () => (new DivisionController())->addStaff(['id' => (string) self::DOM]));
        self::assertNull($pdo->query('SELECT division_id FROM users WHERE id = ' . $this->md)->fetchColumn() ?: null, 'a company-level person stays company level');
        $page = $this->render(fn () => (new DivisionController())->show(['id' => (string) self::DOM]));
        self::assertStringContainsString('division-setup', $page);
        self::assertStringContainsString('/admin/gst-rates', $page);
        $checks = [];
        foreach (DivisionRepository::preflight(self::DOM) as $i) {
            $checks[$i['label']] = $i['ok'];
        }
        self::assertTrue($checks['At least one department works in this division']);
        self::assertTrue($checks['At least one active staff member works in this division']);
        self::assertTrue($checks['Export-only modules switched off (export shipping, LUT, benefits)']);
        $pdo->exec("DELETE FROM departments WHERE code IN ('DOM_SALES','DOM_PURCHASE','DOM_DISPATCH')");
    }

    /**
     * HNT-L03 (QA 13 Nov 2026, briefed as HNT-L07): a Domestic Trade line took any
     * quantity up to 10 digits and any rate up to 11 digits, so quantity × rate could
     * pass what PHP's whole numbers hold — the GST maths then failed (TypeError, an
     * HTTP 500 on the order page) while Node worked it out. Now, as for export order
     * lines: quantity at most 1,00,00,000, the line value (quantity × rate) and the
     * invoice total at most ₹9,99,99,99,99,999.99 (999999999999.99). Node: the same
     * test in domesticTrade.test.js.
     */
    public function testHntL03ExtremeLineAmountsAreRefusedNotA500(): void
    {
        $orderId = $this->domOrder();
        // Item 10a: a unit without a large-quantity level (BOX), so only the hard limits apply here.
        $line = static fn (string $qty, string $rate, string $gst = '18'): array => ['description' => 'Granite', 'hsn_code' => '6802', 'quantity' => $qty, 'unit' => 'BOX', 'rate_inr' => $rate, 'gst_rate_pct' => $gst];

        // The QA case: 99,99,99,999.999 × ₹99,99,999.99 — refused, nothing saved, the totals still work.
        self::assertSame('The quantity can be at most 10,00,000.', DomesticOrderService::addLine($orderId, $line('99999999.999', '9999999.99'), $this->domStaff));
        self::assertSame('The line value (quantity × rate) can be at most ₹9,99,99,99,99,999.99.', DomesticOrderService::addLine($orderId, $line('1000000', '99999999999'), $this->domStaff));
        self::assertSame('The line value (quantity × rate) can be at most ₹9,99,99,99,99,999.99.', DomesticOrderService::addLine($orderId, $line('1000000', '1000000'), $this->domStaff));
        self::assertSame([], DomesticOrderRepository::lines($orderId));
        self::assertSame('0.00', DomesticOrderService::totals($orderId)['invoice_total']);

        // At the limits: accepted, and the GST maths works.
        self::assertNull(DomesticOrderService::addLine($orderId, $line('1000000', '999999.99', '0'), $this->domStaff));
        self::assertSame('999999990000.00', DomesticOrderService::totals($orderId)['invoice_total']);
        // The invoice total (with GST, rounded to the rupee) cannot pass the limit either.
        self::assertSame('With this line the invoice total would be more than ₹9,99,99,99,99,999.99.', DomesticOrderService::addLine($orderId, $line('1', '100000'), $this->domStaff));
        self::assertNull(DomesticOrderService::addLine($orderId, $line('1', '9999.49', '0'), $this->domStaff));
        self::assertSame('999999999999.00', DomesticOrderService::totals($orderId)['invoice_total']);
        self::assertCount(2, DomesticOrderRepository::lines($orderId));

        // The browser checks the same limits before the form is sent.
        $this->actAs($this->domStaff);
        $page = $this->render(fn () => DomesticOrderController::show(OrderRepository::find($orderId)));
        self::assertMatchesRegularExpression('/name="quantity"[^>]*max="1000000"/', $page);
        self::assertMatchesRegularExpression('/name="rate_inr"[^>]*max="99999999999.99"[^>]*data-max-product="quantity"[^>]*data-max-product-value="999999999999.99"/', $page);
    }

    /**
     * Owner decision item 10a (13 Nov 2026): a Domestic Trade line follows the same two levels
     * as an export order line — refused above 10,00,000 in any unit, and above about 20
     * containers of its unit (SQM 30,000 … settings) saved only with "I confirm this quantity"
     * ticked, which is audited. Node: the same test in domesticTrade.test.js.
     */
    public function testItem10aQuantityLimits(): void
    {
        $orderId = $this->domOrder();
        $line = static fn (string $qty, string $unit, array $more = []): array => array_merge(['description' => 'Granite item 10a', 'hsn_code' => '6802', 'quantity' => $qty, 'unit' => $unit, 'rate_inr' => '10', 'gst_rate_pct' => '18'], $more);
        $from = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();

        self::assertSame('The quantity can be at most 10,00,000.', DomesticOrderService::addLine($orderId, $line('1000000.001', 'BOX', ['confirm_large_quantity' => '1']), $this->domStaff));
        self::assertSame("This is unusually large for one line (more than about 20 containers of SQM). Check the quantity, or tick 'I confirm this quantity' to save it.", DomesticOrderService::addLine($orderId, $line('30000.5', 'sqm'), $this->domStaff));
        self::assertSame("This is unusually large for one line (more than about 20 containers of CBM). Check the quantity, or tick 'I confirm this quantity' to save it.", DomesticOrderService::addLine($orderId, $line('501', 'CBM'), $this->domStaff));
        self::assertSame([], DomesticOrderRepository::lines($orderId));
        self::assertNull(DomesticOrderService::addLine($orderId, $line('30000', 'SQM'), $this->domStaff), 'at the level: no tick needed');
        self::assertNull(DomesticOrderService::addLine($orderId, $line('1000000', 'BOX'), $this->domStaff), 'a unit without a level: only the hard block');
        self::assertNull(DomesticOrderService::addLine($orderId, $line('501', 'CBM', ['confirm_large_quantity' => '1']), $this->domStaff), 'with the tick: saved');
        self::assertCount(3, DomesticOrderRepository::lines($orderId));
        $audit = Database::connection()->query("SELECT entity_type, entity_id, new_value FROM audit_log WHERE action_type = 'LARGE_QUANTITY_CONFIRMED' AND id > {$from}")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['entity_type' => 'orders', 'entity_id' => $orderId, 'new_value' => '501 CBM']], array_map(static fn (array $a): array => ['entity_type' => $a['entity_type'], 'entity_id' => (int) $a['entity_id'], 'new_value' => $a['new_value']], $audit));

        // The browser: the same hard block and per-unit confirmation on the add-line form.
        $this->actAs($this->domStaff);
        $page = $this->render(fn () => DomesticOrderController::show(OrderRepository::find($orderId)));
        self::assertMatchesRegularExpression('/name="quantity"[^>]*max="1000000" data-max-value="1000000"[^>]*data-confirm-above="\{[^"]*&quot;SQM&quot;:30000[^"]*\}" data-confirm-unit="unit" data-confirm-message="This is unusually large/', $page);
        self::assertStringContainsString('<input type="checkbox" name="confirm_large_quantity" value="1" data-confirm-tick=""> I confirm this quantity</label>', $page);
    }
}
