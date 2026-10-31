<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Repositories\ZohoPurchaseRepository;
use App\Services\CaSyncService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Zoho integration Stage 3 (2026-10-29): suppliers and logistics partners
 * as Books vendors, their tax invoices as bills, the payments made to them
 * as vendor payments applied to the bill (a payment on a proforma held as
 * an advance until the tax invoice replacing it is in Books), and the
 * order's expenses as Books expenses that the expense import then skips.
 * Every request goes to a fake Zoho; the tests never reach the real one.
 * The Node suite has the same test (zohoBooksStage3.test.js).
 */
final class ZohoBooksStage3Test extends DbTestCase
{
    private array $requests = [];
    private array $contacts = [];
    private array $bills = [];
    private array $vendorPayments = [];
    private array $expenses = [];
    private bool $gstRegistered = false;
    private array $accounts = [
        ['account_id' => 'AC-COGS', 'account_name' => 'Cost of Goods Sold'],
        ['account_id' => 'AC-TRANS', 'account_name' => 'Transportation Expense'],
        ['account_id' => 'AC-OTHER', 'account_name' => 'Other Expenses'],
        ['account_id' => 'AC-TRAVEL', 'account_name' => 'Travel Expense'],
        ['account_id' => 'AC-POST', 'account_name' => 'Postage'],
        ['account_id' => 'AC-EMP', 'account_name' => 'Employee Reimbursements'],
    ];
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        $_ENV['ZOHO_EINVOICE_ENABLED'] = 'false';
        foreach ([
            'zoho_books_enabled' => '1',
            'zoho_books_client_id' => 'fake-client',
            'zoho_books_client_secret' => 'fake-secret',
            'zoho_books_refresh_token' => 'fake-refresh',
            'zoho_books_organization_id' => '600',
            'zoho_books_deposit_account_id' => 'SBI-1',
        ] as $key => $value) {
            CompanySettingsRepository::set($key, $value);
        }
        ZohoBooksService::setTransport(fn (string $method, string $url, array $payload): array => $this->fakeZoho($method, $url, $payload));
        // The test database is shared by the whole run: earlier tests' TEST-
        // orders, bills and expenses must not reach this test's fake Zoho.
        $pdo = Database::connection();
        $pdo->exec("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
        $this->userId = $this->createTestUser('Admin');
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        CompanySettingsRepository::set('zoho_books_enabled', '0');
        parent::tearDown();
    }

    /** @return array{status: int, body: string} */
    private function fakeZoho(string $method, string $url, array $payload): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload];
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $json = static fn (array $body, int $status = 200): array => ['status' => $status, 'body' => (string) json_encode($body)];

        if ($path === '/oauth/v2/token') {
            return $json(['access_token' => 'fake-access']);
        }
        if (preg_match('#^/books/v3/organizations/(\d+)$#', $path, $m) === 1) {
            return $json(['code' => 0, 'organization' => ['organization_id' => $m[1], 'is_registered_for_gst' => $this->gstRegistered, 'tax_settings' => ['tax_reg_no' => $this->gstRegistered ? '29AAKCN8733G1ZZ' : '']]]);
        }
        if ($path === '/books/v3/settings/taxes') {
            $taxes = [];
            foreach ([0, 5, 12, 18, 28, 40] as $rate) {
                $taxes[] = ['tax_id' => "TX-GST{$rate}", 'tax_name' => "GST{$rate}", 'tax_percentage' => $rate];
                $taxes[] = ['tax_id' => "TX-IGST{$rate}", 'tax_name' => "IGST{$rate}", 'tax_percentage' => $rate];
            }
            return $json(['code' => 0, 'taxes' => $taxes]);
        }
        if ($path === '/books/v3/chartofaccounts') {
            return $json(['code' => 0, 'chartofaccounts' => $this->accounts]);
        }
        if ($path === '/books/v3/contacts' && $method === 'GET') {
            return $json(['code' => 0, 'contacts' => array_values(array_filter($this->contacts, static fn (array $c): bool => $c['contact_name'] === ($query['contact_name'] ?? null)))]);
        }
        if ($path === '/books/v3/contacts' && $method === 'POST') {
            $id = 'VN-' . (count($this->contacts) + 1);
            $this->contacts[$id] = ['contact_id' => $id, 'contact_name' => $payload['contact_name'], 'contact_type' => $payload['contact_type'], 'payload' => $payload];
            return $json(['code' => 0, 'contact' => $this->contacts[$id]], 201);
        }
        if ($path === '/books/v3/bills' && $method === 'GET') {
            $rows = array_values(array_filter($this->bills, static fn (array $b): bool => $b['bill_number'] === ($query['bill_number'] ?? $b['bill_number']) && $b['vendor_id'] === ($query['vendor_id'] ?? $b['vendor_id'])));
            return $json(['code' => 0, 'bills' => array_map(fn (array $b): array => $b + ['balance' => $this->billBalance($b['bill_id'])], $rows)]);
        }
        if ($path === '/books/v3/bills' && $method === 'POST') {
            $id = 'BL-' . (count($this->bills) + 1);
            $line = $payload['line_items'][0];
            $percent = isset($line['tax_id']) ? (int) preg_replace('/\D/', '', $line['tax_id']) : 0;
            $this->bills[$id] = ['bill_id' => $id, 'bill_number' => $payload['bill_number'], 'vendor_id' => $payload['vendor_id'], 'total' => round($line['rate'] * (1 + $percent / 100), 2), 'payload' => $payload];
            return $json(['code' => 0, 'bill' => $this->bills[$id]], 201);
        }
        if ($path === '/books/v3/vendorpayments' && $method === 'GET') {
            $rows = array_map(static fn (string $id, array $p): array => ['payment_id' => $id, 'amount' => $p['amount'], 'vendor_id' => $p['vendor_id']], array_keys($this->vendorPayments), $this->vendorPayments);
            return $json(['code' => 0, 'vendorpayments' => array_values(array_filter($rows, static fn (array $r): bool => !isset($query['vendor_id']) || $r['vendor_id'] === $query['vendor_id']))]);
        }
        if ($path === '/books/v3/vendorpayments' && $method === 'POST') {
            $id = 'VP-' . (count($this->vendorPayments) + 1);
            $this->vendorPayments[$id] = $payload;
            return $json(['code' => 0, 'vendorpayment' => ['payment_id' => $id]], 201);
        }
        if (preg_match('#^/books/v3/vendorpayments/(.+)$#', $path, $m) === 1 && $method === 'PUT') {
            $this->vendorPayments[$m[1]] = $payload;
            return $json(['code' => 0, 'vendorpayment' => ['payment_id' => $m[1]]]);
        }
        if ($path === '/books/v3/expenses' && $method === 'POST') {
            $id = 'EX-' . (count($this->expenses) + 1);
            $this->expenses[$id] = $payload + ['expense_id' => $id];
            return $json(['code' => 0, 'expense' => ['expense_id' => $id]], 201);
        }
        if ($path === '/books/v3/expenses' && $method === 'GET') {
            return $json(['code' => 0, 'expenses' => array_map(static fn (array $e): array => ['expense_id' => $e['expense_id'], 'account_name' => 'Travel Expense', 'description' => $e['description'], 'total' => $e['amount'], 'currency_code' => 'INR', 'date' => $e['date']], array_values($this->expenses)), 'page_context' => ['has_more_page' => false]]);
        }
        return $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400);
    }

    private function billBalance(string $billId): float
    {
        $paid = 0.0;
        foreach ($this->vendorPayments as $payment) {
            foreach ($payment['bills'] ?? [] as $applied) {
                $paid += $applied['bill_id'] === $billId ? $applied['amount_applied'] : 0;
            }
        }
        return round($this->bills[$billId]['total'] - $paid, 2);
    }

    private function requestsTo(string $method, string $pathPrefix): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_starts_with((string) parse_url($r['url'], PHP_URL_PATH), $pathPrefix)));
    }

    /** A TEST- order with a TEST- supplier (on a supplier PO) and a TEST- freight forwarder. @return array{order:int, po:int, partner:int, supplier:int} */
    private function createOrder(string $buyer = 'TEST-Stage3 Buyer', string $supplierName = 'TEST-Stage3 Granite Supplier'): array
    {
        $pdo = Database::connection();
        $clientId = $this->createTestClient();
        $pdo->prepare('UPDATE clients SET company_legal_name = :name WHERE id = :id')->execute(['name' => $buyer, 'id' => $clientId]);
        $orderId = $this->createTestOrder($clientId, 'CIF');
        $pdo->prepare('UPDATE orders SET buyer_inquiry_ref = :ref WHERE id = :id')->execute(['ref' => "NX-{$orderId}", 'id' => $orderId]);
        $pdo->prepare("INSERT INTO suppliers (supplier_legal_name, gstin, pan, state) VALUES (:name, '29ABCDE1234F1Z5', 'ABCDE1234F', 'Karnataka')")->execute(['name' => $supplierName]);
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO order_supplier_po (order_id, supplier_id, supplier_legal_name, supplier_po_reference) VALUES (:o, :s, :n, 'TEST-SPO-1')")->execute(['o' => $orderId, 's' => $supplierId, 'n' => $supplierName]);
        $poId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO logistics_partners (partner_name, service_type) VALUES ('TEST-Stage3 Forwarder', 'freight_forwarder')")->execute();
        return ['order' => $orderId, 'po' => $poId, 'partner' => (int) $pdo->lastInsertId(), 'supplier' => $supplierId];
    }

    private function supplierBill(array $o, string $docKind, string $no, float $taxable, float $gst, ?int $replaces = null): int
    {
        return OrderVendorBillRepository::create($o['order'], 'supplier', [
            'supplier_po_id' => $o['po'], 'bill_for' => 'advance', 'doc_kind' => $docKind, 'bill_no' => $no, 'bill_date' => '2026-10-25',
            'taxable_inr' => $taxable, 'gst_inr' => $gst, 'requested_amount_inr' => $docKind === 'proforma' ? $taxable + $gst : null, 'replaces_bill_id' => $replaces,
        ], $this->userId);
    }

    private function sync(): array
    {
        return CaSyncService::syncPurchases('manual', null);
    }

    private function messages(int $orderId, string $type): array
    {
        $stmt = Database::connection()->prepare('SELECT message FROM zoho_sync_log WHERE entity_id = :id AND sync_type = :t ORDER BY id');
        $stmt->execute(['id' => $orderId, 't' => $type]);
        return array_column($stmt->fetchAll(), 'message');
    }

    public function testAProformaPaymentIsHeldThenAppliedToTheTaxInvoiceAndTheBalanceGoesStraightOn(): void
    {
        $o = $this->createOrder();
        $proforma = $this->supplierBill($o, 'proforma', 'TEST-PI-77', 42372.88, 7627.12);
        OrderVendorBillRepository::addPayment($proforma, 'advance', '2026-10-20', 50000.0, 'NEFT / RTGS', 'TEST-UTR-ADV', $this->userId);

        self::assertSame(['bills' => 0, 'payments' => 1, 'applied' => 0, 'expenses' => 0, 'failed' => 0, 'skipped' => 0], $this->sync());
        self::assertSame([], $this->bills, 'a proforma is an advance request, not a bill');
        self::assertArrayNotHasKey('bills', $this->vendorPayments['VP-1'], 'held as an advance');
        self::assertSame(['vendor_id' => 'VN-1', 'amount' => 50000.0, 'date' => '2026-10-20', 'payment_mode' => 'NEFT / RTGS', 'reference_number' => 'TEST-UTR-ADV',
            'description' => "Supplier advance payment for order NX-{$o['order']} · Supplier PO TEST-SPO-1 · against Proforma TEST-PI-77", 'paid_through_account_id' => 'SBI-1'], $this->vendorPayments['VP-1'], 'the UTR stays the reference; the description says which payment it is (owner, 2026-10-31)');
        self::assertSame(['contact_name' => 'TEST-Stage3 Granite Supplier', 'company_name' => 'TEST-Stage3 Granite Supplier', 'contact_type' => 'vendor', 'pan_no' => 'ABCDE1234F'], $this->contacts['VN-1']['payload'], 'not GST-registered: no GST fields');
        self::assertStringContainsString('held unapplied until the tax invoice for proforma TEST-PI-77 is in Zoho Books', $this->messages($o['order'], 'vendor_payment')[0]);

        $invoice = $this->supplierBill($o, 'invoice', 'TEST-INV-77', 100000.0, 18000.0, $proforma);
        self::assertSame(['bills' => 1, 'payments' => 0, 'applied' => 1, 'expenses' => 0, 'failed' => 0, 'skipped' => 0], $this->sync());
        $bill = $this->bills['BL-1'];
        self::assertSame('TEST-INV-77', $bill['bill_number']);
        self::assertSame(118000.0, $bill['total']);
        self::assertSame('AC-COGS', $bill['payload']['line_items'][0]['account_id']);
        self::assertStringStartsWith("Supplier advance bill for order NX-{$o['order']} (Supplier PO TEST-SPO-1): taxable 100000.00 + GST 18000.00", $bill['payload']['line_items'][0]['description'], 'owner rule D-212: the bill names its Supplier PO');
        self::assertSame("NX-{$o['order']}", $bill['payload']['reference_number']);
        self::assertSame([['bill_id' => 'BL-1', 'amount_applied' => 50000.0]], $this->vendorPayments['VP-1']['bills']);

        OrderVendorBillRepository::addPayment($invoice, 'balance', '2026-10-28', 68000.0, 'NEFT / RTGS', 'TEST-UTR-BAL', $this->userId);
        self::assertSame(['bills' => 0, 'payments' => 1, 'applied' => 1, 'expenses' => 0, 'failed' => 0, 'skipped' => 0], $this->sync());
        self::assertSame(0.0, $this->billBalance('BL-1'));
        self::assertSame('TEST-UTR-BAL', $this->vendorPayments['VP-2']['reference_number']);
        self::assertSame("Supplier balance payment for order NX-{$o['order']} · Supplier PO TEST-SPO-1 · against Tax invoice TEST-INV-77", $this->vendorPayments['VP-2']['description']);
        self::assertCount(1, $this->requestsTo('POST', '/books/v3/contacts'), 'the vendor is created once and remembered');
        self::assertSame('VN-1', Database::connection()->query("SELECT zoho_contact_id FROM suppliers WHERE id = {$o['supplier']}")->fetchColumn());

        self::assertSame(['bills' => 0, 'payments' => 0, 'applied' => 0, 'expenses' => 0, 'failed' => 0, 'skipped' => 1], $this->sync(), 'nothing is sent twice');
    }

    public function testALogisticsBillGoesToTransportAndItsPaymentIsApplied(): void
    {
        $o = $this->createOrder();
        $billId = OrderVendorBillRepository::create($o['order'], 'logistics', [
            'logistics_partner_id' => $o['partner'], 'bill_for' => 'ocean_freight', 'payment_kind' => 'final', 'doc_kind' => 'invoice',
            'bill_no' => 'TEST-FF-901', 'bill_date' => '2026-10-27', 'taxable_inr' => 16000.0, 'gst_inr' => 0.0,
        ], $this->userId);
        OrderVendorBillRepository::addPayment($billId, null, '2026-10-29', 16000.0, 'IMPS', 'TEST-UTR-FF', $this->userId);

        self::assertSame(['bills' => 1, 'payments' => 1, 'applied' => 1, 'expenses' => 0, 'failed' => 0, 'skipped' => 0], $this->sync());
        self::assertSame('AC-TRANS', $this->bills['BL-1']['payload']['line_items'][0]['account_id']);
        self::assertSame('Ocean freight bill', $this->bills['BL-1']['payload']['line_items'][0]['name']);
        self::assertSame('TEST-Stage3 Forwarder', $this->contacts['VN-1']['contact_name']);
        self::assertSame(0.0, $this->billBalance('BL-1'));
        self::assertSame(['TEST-UTR-FF', "Ocean freight — final payment for order NX-{$o['order']} · against Tax invoice TEST-FF-901"],
            [$this->vendorPayments['VP-1']['reference_number'], $this->vendorPayments['VP-1']['description']]);
    }

    public function testOrderExpensesGoToBooksAndAreNotImportedBack(): void
    {
        $o = $this->createOrder();
        $staff = OrderExpenseRepository::create($o['order'], ['expense_date' => '2026-10-22', 'category' => 'travel_ticket', 'details' => 'Train to the factory', 'amount_inr' => 2450, 'paid_by' => 'staff', 'staff_name' => 'Ravi', 'no_receipt_reason' => 'test'], $this->userId);
        OrderExpenseRepository::create($o['order'], ['expense_date' => '2026-10-23', 'category' => 'courier', 'details' => 'Samples to buyer', 'amount_inr' => 1800, 'paid_by' => 'company_account', 'no_receipt_reason' => 'test'], $this->userId);

        self::assertSame(2, $this->sync()['expenses']);
        $staffExpense = $this->expenses['EX-1'];
        self::assertSame(['account_id' => 'AC-TRAVEL', 'paid_through_account_id' => 'AC-EMP', 'amount' => 2450.0, 'date' => '2026-10-22', 'reference_number' => "NX-{$o['order']}-EXP-{$staff}",
            'description' => "TEST-Stage3 Buyer / NX-{$o['order']}: Travel — train / flight / bus — Train to the factory (paid by Ravi)", 'expense_id' => 'EX-1'], $staffExpense);
        self::assertSame(['AC-POST', 'SBI-1'], [$this->expenses['EX-2']['account_id'], $this->expenses['EX-2']['paid_through_account_id']]);

        $import = CaSyncService::importPendingExpenses('manual', null);
        self::assertSame(0, $import['imported'], 'the expense import skips what this app sent');
        self::assertTrue(ZohoPurchaseRepository::isPushedExpense('EX-1'));
    }

    public function testAMissingBooksAccountIsRefusedWithWhereToAddIt(): void
    {
        $o = $this->createOrder();
        OrderExpenseRepository::create($o['order'], ['expense_date' => '2026-10-22', 'category' => 'hotel', 'details' => 'Hotel', 'amount_inr' => 3000, 'paid_by' => 'company_card', 'no_receipt_reason' => 'test'], $this->userId);

        self::assertSame(1, $this->sync()['failed']);
        self::assertSame(['Zoho Books has no account named "Company Card". Add it in Zoho Books -> Accountant -> Chart of Accounts (or ask the CA which account to use), then sync again.'], $this->messages($o['order'], 'order_expense'));
        self::assertSame([], $this->expenses);
    }

    public function testInAGstRegisteredOrganisationABillCarriesItsGstIgstForAnotherStateCgstSgstForKarnataka(): void
    {
        $this->gstRegistered = true;
        $o = $this->createOrder();
        Database::connection()->exec("UPDATE suppliers SET gstin = '08ZZTPT1111Z1ZG' WHERE id = {$o['supplier']}");
        $this->supplierBill($o, 'invoice', 'TEST-INV-RJ', 100000.0, 18000.0);
        Database::connection()->exec("UPDATE logistics_partners SET gstin = '29ZZTPT2222Z1Z6' WHERE id = {$o['partner']}");
        OrderVendorBillRepository::create($o['order'], 'logistics', [
            'logistics_partner_id' => $o['partner'], 'bill_for' => 'cha', 'doc_kind' => 'invoice', 'bill_no' => 'TEST-CHA-KA', 'bill_date' => '2026-10-27', 'taxable_inr' => 10000.0, 'gst_inr' => 1800.0,
        ], $this->userId);

        self::assertSame(2, $this->sync()['bills']);

        $rajasthan = $this->bills['BL-1'];
        self::assertSame(['rate' => 100000.0, 'tax_id' => 'TX-IGST18'], array_intersect_key($rajasthan['payload']['line_items'][0], ['rate' => 1, 'tax_id' => 1]), 'another state: IGST on the taxable value');
        self::assertSame(118000.0, $rajasthan['total']);
        self::assertSame(['gst_treatment' => 'business_gst', 'gst_no' => '08ZZTPT1111Z1ZG'], array_intersect_key($this->contacts['VN-1']['payload'], ['gst_treatment' => 1, 'gst_no' => 1]));
        self::assertSame('TX-GST18', $this->bills['BL-2']['payload']['line_items'][0]['tax_id'], 'Karnataka, like NexaCrest: CGST + SGST (the GST group)');
        self::assertSame(11800.0, $this->bills['BL-2']['total']);
    }

    public function testInAGstRegisteredOrganisationABillWithoutGstFromARegisteredVendorGoesInAtZeroAndFromAnUnregisteredOneWithoutTax(): void
    {
        $this->gstRegistered = true;
        $o = $this->createOrder();
        $this->supplierBill($o, 'invoice', 'TEST-INV-ZERO', 5000.0, 0.0);
        OrderVendorBillRepository::create($o['order'], 'logistics', [
            'logistics_partner_id' => $o['partner'], 'bill_for' => 'other', 'doc_kind' => 'invoice', 'bill_no' => 'TEST-NOGST-1', 'bill_date' => '2026-10-27', 'taxable_inr' => 500.0, 'gst_inr' => 0.0,
        ], $this->userId);

        self::assertSame(2, $this->sync()['bills']);
        self::assertSame('TX-GST0', $this->bills['BL-1']['payload']['line_items'][0]['tax_id'], 'registered supplier in Karnataka (29...)');
        self::assertArrayNotHasKey('tax_id', $this->bills['BL-2']['payload']['line_items'][0], 'unregistered forwarder: no tax');
        self::assertSame('business_none', $this->contacts['VN-2']['payload']['gst_treatment']);
    }

    public function testInAGstRegisteredOrganisationGstFromAVendorWithoutGstinOrAnOddRateIsRefused(): void
    {
        $this->gstRegistered = true;
        $o = $this->createOrder();
        $this->supplierBill($o, 'invoice', 'TEST-INV-ODD', 100000.0, 15000.0);
        OrderVendorBillRepository::create($o['order'], 'logistics', [
            'logistics_partner_id' => $o['partner'], 'bill_for' => 'transport', 'doc_kind' => 'invoice', 'bill_no' => 'TEST-TR-NOGSTIN', 'bill_date' => '2026-10-27', 'taxable_inr' => 2000.0, 'gst_inr' => 360.0,
        ], $this->userId);

        self::assertSame(2, $this->sync()['failed']);
        self::assertSame([
            'Bill TEST-INV-ODD not sent: GST 15000.00 on taxable 100000.00 is not 5, 12, 18, 28 or 40 % — correct the bill (a bill with several GST rates needs the CA).',
            'Bill TEST-TR-NOGSTIN not sent: it carries GST (360.00), but TEST-Stage3 Forwarder has no GSTIN in the app. Add the GSTIN to the supplier / logistics partner, or correct the bill.',
        ], $this->messages($o['order'], 'vendor_bill'));
        self::assertSame([], $this->bills);
    }

    /** Owner decision 2026-10-29 (CA advice): a road transporter (GTA) that charges no GST is booked under reverse charge at 5%. */
    public function testInAGstRegisteredOrganisationAReverseChargeTransportBillCarriesTheFivePercentAsReverseCharge(): void
    {
        $this->gstRegistered = true;
        $o = $this->createOrder();
        OrderVendorBillRepository::create($o['order'], 'logistics', [
            'logistics_partner_id' => $o['partner'], 'bill_for' => 'transport', 'doc_kind' => 'invoice', 'bill_no' => 'TEST-GTA-1', 'bill_date' => '2026-10-27', 'taxable_inr' => 10000.0, 'gst_inr' => 0.0, 'gst_reverse_charge' => 1,
        ], $this->userId);
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO logistics_partners (partner_name, service_type, gstin) VALUES ('TEST-Stage3 GTA Chennai', 'transportation', '33ZZTPT4444Z1Z5')");
        $tamilNadu = (int) $pdo->lastInsertId();
        OrderVendorBillRepository::create($o['order'], 'logistics', [
            'logistics_partner_id' => $tamilNadu, 'bill_for' => 'transport', 'doc_kind' => 'invoice', 'bill_no' => 'TEST-GTA-2', 'bill_date' => '2026-10-27', 'taxable_inr' => 8000.0, 'gst_inr' => 0.0, 'gst_reverse_charge' => 1,
        ], $this->userId);
        OrderVendorBillRepository::create($o['order'], 'logistics', [
            'logistics_partner_id' => $tamilNadu, 'bill_for' => 'transport', 'doc_kind' => 'invoice', 'bill_no' => 'TEST-GTA-BAD', 'bill_date' => '2026-10-27', 'taxable_inr' => 1000.0, 'gst_inr' => 50.0, 'gst_reverse_charge' => 1,
        ], $this->userId);

        $result = $this->sync();

        self::assertSame([2, 1], [$result['bills'], $result['failed']]);
        $unregistered = $this->bills['BL-1']['payload'];
        self::assertTrue($unregistered['is_reverse_charge_applied']);
        self::assertSame(['rate' => 10000.0, 'reverse_charge_tax_id' => 'TX-GST5'], array_intersect_key($unregistered['line_items'][0], ['rate' => 1, 'tax_id' => 1, 'reverse_charge_tax_id' => 1]), 'no GSTIN: our own state, the GST group; the bill stays at its taxable value');
        self::assertSame(10000.0, $this->bills['BL-1']['total']);
        self::assertSame('TX-IGST5', $this->bills['BL-2']['payload']['line_items'][0]['reverse_charge_tax_id'], 'a transporter registered in another state: IGST');
        self::assertArrayNotHasKey('tax_id', $this->bills['BL-2']['payload']['line_items'][0]);
        self::assertSame([
            'Sent bill TEST-GTA-1 from TEST-Stage3 Forwarder (INR 10000.00, reverse charge) as Zoho Books bill.',
            'Sent bill TEST-GTA-2 from TEST-Stage3 GTA Chennai (INR 8000.00, reverse charge) as Zoho Books bill.',
            'Bill TEST-GTA-BAD not sent: it is marked reverse charge but carries GST (50.00). Under reverse charge the transporter charges no GST — correct the bill.',
        ], $this->messages($o['order'], 'vendor_bill'));
    }

    public function testABillAlreadyInBooksIsReusedNotDuplicated(): void
    {
        $o = $this->createOrder();
        $this->contacts['VN-9'] = ['contact_id' => 'VN-9', 'contact_name' => 'TEST-Stage3 Granite Supplier', 'contact_type' => 'vendor'];
        $this->bills['BL-9'] = ['bill_id' => 'BL-9', 'bill_number' => 'TEST-INV-DUP', 'vendor_id' => 'VN-9', 'total' => 118000.0];
        $billId = $this->supplierBill($o, 'invoice', 'TEST-INV-DUP', 100000.0, 18000.0);

        self::assertSame(1, $this->sync()['bills']);
        self::assertCount(0, $this->requestsTo('POST', '/books/v3/bills'));
        self::assertSame('BL-9', ZohoPurchaseRepository::findBill($billId)['zoho_bill_id']);
        self::assertCount(0, $this->requestsTo('POST', '/books/v3/contacts'), 'the existing vendor is found by name');
    }

    public function testInTestOnlyTestOrdersAndTestVendorsGo(): void
    {
        $realVendor = $this->createOrder('TEST-Stage3 Buyer', 'Real Granite Pvt Ltd');
        $this->supplierBill($realVendor, 'invoice', 'REAL-INV-1', 1000.0, 0.0);
        $realBuyer = $this->createOrder('Real Buyer GmbH');
        $this->supplierBill($realBuyer, 'invoice', 'TEST-INV-RB', 1000.0, 0.0);
        OrderExpenseRepository::create($realBuyer['order'], ['expense_date' => '2026-10-22', 'category' => 'food', 'details' => 'Lunch', 'amount_inr' => 500, 'paid_by' => 'company_account', 'no_receipt_reason' => 'test'], $this->userId);

        self::assertSame(['bills' => 0, 'payments' => 0, 'applied' => 0, 'expenses' => 0, 'failed' => 0, 'skipped' => 1], $this->sync());
        self::assertSame([], $this->requestsTo('POST', '/books/'));

        $_ENV['ZOHO_ENVIRONMENT'] = 'production';
        Database::connection()->exec("UPDATE suppliers SET is_sample_data = 1 WHERE id = {$realBuyer['supplier']}");
        try {
            $ids = array_map(static fn (array $b): string => (string) $b['bill_no'], ZohoPurchaseRepository::billsToSync());
            self::assertNotContains('TEST-INV-RB', $ids, 'production: a sample supplier never goes');
            self::assertContains('REAL-INV-1', $ids);
        } finally {
            // Sample data left behind would hold up the sample-data tests.
            Database::connection()->exec("UPDATE suppliers SET is_sample_data = 0 WHERE id = {$realBuyer['supplier']}");
            Database::connection()->exec("UPDATE order_vendor_bills SET zoho_bill_id = 'DONE' WHERE bill_no IN ('REAL-INV-1', 'TEST-INV-RB')");
        }
    }
}
