<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderZohoInvoiceRepository;
use App\Services\CaSyncService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Zoho integration Stage 2 (2026-10-29): the Commercial Invoice and the
 * freight Debit Note go to Zoho Books as export invoices numbered with our
 * own references, and the buyer's payments are applied to them — the
 * advance held unapplied until the CI is in Books. Every request goes to a
 * fake Zoho that keeps the invoices and payments it is sent; the tests
 * never reach the real one. The Node suite has the same test
 * (zohoBooksStage2.test.js).
 */
final class ZohoBooksStage2Test extends DbTestCase
{
    /** @var list<array{method: string, url: string, payload: array}> */
    private array $requests = [];
    private array $contacts = [];
    /** @var array<string, array<string, mixed>> invoices by id */
    private array $invoices = [];
    /** @var array<string, array<string, mixed>> payments by id */
    private array $payments = [];
    private bool $gstRegistered = false;
    /** Answer an invoice create/update with HTTP 500 although it went through (seen in the test organisation). */
    private bool $invoiceWrites500 = false;

    /** Make the invoice list miss an invoice just created, once (the list lags behind a create, seen in the test organisation). */
    private bool $invoiceListLags = false;

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
        // The test database is shared by the whole run: earlier tests'
        // TEST- orders must not be synced into this test's fake Zoho.
        Database::connection()->exec("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
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
            return $json(['code' => 0, 'organization' => ['organization_id' => $m[1], 'is_registered_for_gst' => $this->gstRegistered]]);
        }
        if ($path === '/books/v3/settings/currencies') {
            return $json(['code' => 0, 'currencies' => [['currency_id' => 'C-USD', 'currency_code' => 'USD'], ['currency_id' => 'C-INR', 'currency_code' => 'INR']]]);
        }
        if ($path === '/books/v3/contacts' && $method === 'GET') {
            return $json(['code' => 0, 'contacts' => array_values(array_filter($this->contacts, static fn (array $c): bool => $c['contact_name'] === ($query['contact_name'] ?? null)))]);
        }
        if ($path === '/books/v3/contacts' && $method === 'POST') {
            $id = 'CT-' . (count($this->contacts) + 1);
            $this->contacts[$id] = ['contact_id' => $id, 'contact_name' => $payload['contact_name'], 'currency_code' => 'USD'];
            return $json(['code' => 0, 'contact' => $this->contacts[$id]], 201);
        }
        if (preg_match('#^/books/v3/contacts/(.+)$#', $path, $m) === 1) {
            return $json(['code' => 0, 'contact' => $this->contacts[$m[1]]]);
        }
        if ($path === '/books/v3/invoices' && $method === 'GET' && $this->invoiceListLags && isset($query['invoice_number']) && in_array($query['invoice_number'], array_column($this->invoices, 'invoice_number'), true)) {
            $this->invoiceListLags = false;
            return $json(['code' => 0, 'invoices' => []]);
        }
        if ($path === '/books/v3/invoices' && $method === 'GET') {
            $rows = array_values(array_filter($this->invoices, static fn (array $i): bool => !isset($query['invoice_number']) || $i['invoice_number'] === $query['invoice_number']));
            return $json(['code' => 0, 'invoices' => array_map(fn (array $i): array => $i + ['balance' => $this->invoiceBalance($i['invoice_id'])], $rows)]);
        }
        if ($path === '/books/v3/invoices' && $method === 'POST') {
            $id = 'INV-' . (count($this->invoices) + 1);
            $this->invoices[$id] = $this->storedInvoice($id, $payload, 'draft');
            return $this->invoiceWrites500 ? ['status' => 500, 'body' => '{"code":0,"message":"The invoice has been created.","invoice":{"invoice_id":"' . $id] : $json(['code' => 0, 'invoice' => $this->invoices[$id]], 201);
        }
        if (preg_match('#^/books/v3/invoices/([^/]+)/status/sent$#', $path, $m) === 1) {
            $this->invoices[$m[1]]['status'] = 'sent';
            return $json(['code' => 0, 'message' => 'Invoice status has been changed to Sent.']);
        }
        if (preg_match('#^/books/v3/invoices/([^/]+)$#', $path, $m) === 1 && $method === 'PUT') {
            if ($this->invoices[$m[1]]['status'] !== 'draft' && empty($payload['reason'])) {
                return $json(['code' => 110701, 'message' => 'Please enter the reason for updating a sent invoice.'], 400);
            }
            $this->invoices[$m[1]] = $this->storedInvoice($m[1], $payload, $this->invoices[$m[1]]['status']);
            return $json(['code' => 0, 'invoice' => $this->invoices[$m[1]]]);
        }
        if ($path === '/books/v3/customerpayments' && $method === 'POST') {
            $id = 'PAY-' . (count($this->payments) + 1);
            $this->payments[$id] = $payload;
            return $json(['code' => 0, 'payment' => ['payment_id' => $id]], 201);
        }
        if (preg_match('#^/books/v3/customerpayments/(.+)$#', $path, $m) === 1 && $method === 'PUT') {
            $this->payments[$m[1]] = $payload;
            return $json(['code' => 0, 'payment' => ['payment_id' => $m[1]]]);
        }
        return $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400);
    }

    private function storedInvoice(string $id, array $payload, string $status): array
    {
        $total = 0.0;
        foreach ($payload['line_items'] as $line) {
            $total += round($line['quantity'] * $line['rate'], 2);
        }
        return ['invoice_id' => $id, 'invoice_number' => $payload['invoice_number'], 'customer_id' => $payload['customer_id'], 'status' => $status, 'total' => $total, 'payload' => $payload];
    }

    private function invoiceBalance(string $invoiceId): float
    {
        $paid = 0.0;
        foreach ($this->payments as $payment) {
            foreach ($payment['invoices'] ?? [] as $applied) {
                $paid += $applied['invoice_id'] === $invoiceId ? $applied['amount_applied'] : 0;
            }
        }
        return round($this->invoices[$invoiceId]['total'] - $paid, 2);
    }

    /** @return list<array{method: string, url: string, payload: array}> */
    private function requestsTo(string $method, string $pathPrefix): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_starts_with((string) parse_url($r['url'], PHP_URL_PATH), $pathPrefix)));
    }

    /** A TEST- CIF order in USD: two products (FOB 1,275.00), freight 200 + insurance 15, the advance of 300 received at 84.10, shipping bill customs rate 84.40. */
    private function createCifOrder(string $buyer = 'TEST-Stage2 Buyer'): int
    {
        $pdo = Database::connection();
        $clientId = $this->createTestClient();
        $pdo->prepare('UPDATE clients SET company_legal_name = :name WHERE id = :id')->execute(['name' => $buyer, 'id' => $clientId]);
        $orderId = $this->createTestOrder($clientId, 'CIF');
        $pdo->prepare("UPDATE orders SET buyer_inquiry_ref = :ref, port_of_loading_name = 'Chennai', port_of_discharge_name = 'Hamburg' WHERE id = :id")->execute(['ref' => "NX-{$orderId}", 'id' => $orderId]);
        $insert = $pdo->prepare('INSERT INTO order_products (order_id, line_no, description, dimensions, finish, quantity, unit, unit_price, fob_value, hs_code) VALUES (:order_id, :line_no, :description, :dimensions, :finish, :quantity, :unit, :unit_price, :fob_value, :hs_code)');
        $insert->execute(['order_id' => $orderId, 'line_no' => 1, 'description' => 'Granite slab', 'dimensions' => '60x60x2 cm', 'finish' => 'Polished', 'quantity' => 100, 'unit' => 'SQM', 'unit_price' => 10, 'fob_value' => 1000, 'hs_code' => '68022310']);
        $insert->execute(['order_id' => $orderId, 'line_no' => 2, 'description' => 'Sandstone tile', 'dimensions' => null, 'finish' => null, 'quantity' => 50, 'unit' => 'SQM', 'unit_price' => 5.5, 'fob_value' => 275, 'hs_code' => '68029900']);
        $pdo->prepare('INSERT INTO order_freight (order_id, confirmed_freight_rate, insurance_amount) VALUES (:id, 200, 15)')->execute(['id' => $orderId]);
        $pdo->prepare("INSERT IGNORE INTO order_payment_status (order_id) VALUES (:id)")->execute(['id' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = 300, advance_cleared_at = '2026-10-20 11:00:00', advance_inr_actual = 25230, advance_exchange_rate = 84.1,
                       balance_exchange_rate = 84.5, freight_exchange_rate = 84.3 WHERE order_id = :id")->execute(['id' => $orderId]);
        $pdo->prepare("INSERT INTO order_shipping (order_id, shipping_bill_no, shipping_bill_date, customs_exchange_rate) VALUES (:id, 'TEST-SB-1', '2026-10-27', 84.4)")->execute(['id' => $orderId]);
        return $orderId;
    }

    private function approveDocument(int $orderId, string $typeCode, string $reference, int $revision = 0, ?string $documentDate = null): int
    {
        $id = $this->createTestDocument($orderId, $typeCode, 'approved');
        Database::connection()->prepare('UPDATE documents SET document_reference = :ref, revision_number = :rev, generated_at = :gen, document_date = :date WHERE id = :id')
            ->execute(['ref' => $reference, 'rev' => $revision, 'gen' => '2026-10-27 10:00:00', 'date' => $documentDate, 'id' => $id]);
        return $id;
    }

    private function sync(): array
    {
        return CaSyncService::syncPendingRevenue('manual', null);
    }

    private function logRows(int $orderId): array
    {
        $stmt = Database::connection()->prepare('SELECT sync_type, leg, status, message FROM zoho_sync_log WHERE entity_id = :id ORDER BY id');
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetchAll();
    }

    public function testTheAdvanceIsHeldUntilTheCiAndThenAppliedToIt(): void
    {
        $orderId = $this->createCifOrder();

        $first = $this->sync();
        self::assertSame(['synced' => 1, 'failed' => 0, 'skipped' => 0, 'invoices' => 0, 'applied' => 0], $first);
        self::assertArrayNotHasKey('invoices', $this->payments['PAY-1'], 'no CI yet: the advance is held unapplied');
        $rows = $this->logRows($orderId);
        self::assertStringContainsString('held unapplied until the Commercial Invoice is in Zoho Books', end($rows)['message']);

        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810001', 0, '2026-10-28');
        $second = $this->sync();

        self::assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 0, 'invoices' => 1, 'applied' => 1], $second);
        $invoice = $this->invoices['INV-1'];
        self::assertSame('sent', $invoice['status'], 'marked as sent so payments can be applied');
        self::assertSame(1275.0, $invoice['total'], 'the CI is the FOB value — freight is on the Debit Note');
        self::assertSame(['customer_id' => 'CT-1', 'invoice_number' => 'TEST-SC/CI/2026/2810001', 'date' => '2026-10-28', 'reference_number' => "NX-{$orderId}", 'line_items' => [
            ['name' => 'Granite slab', 'description' => 'Granite slab. Size: 60x60x2 cm. Finish: Polished. HS code 68022310', 'quantity' => 100.0, 'rate' => 10.0, 'unit' => 'SQM'],
            ['name' => 'Sandstone tile', 'description' => 'Sandstone tile. HS code 68029900', 'quantity' => 50.0, 'rate' => 5.5, 'unit' => 'SQM'],
        ], 'exchange_rate' => 84.4], $invoice['payload']);
        self::assertStringContainsString('ignore_auto_number_generation=true', $this->requestsTo('POST', '/books/v3/invoices')[0]['url']);
        self::assertSame([['invoice_id' => 'INV-1', 'amount_applied' => 300.0]], $this->payments['PAY-1']['invoices']);
        self::assertSame(300.0, $this->payments['PAY-1']['amount']);
        self::assertSame(84.1, $this->payments['PAY-1']['exchange_rate']);
        self::assertSame(975.0, $this->invoiceBalance('INV-1'));
        $row = Database::connection()->query("SELECT advance_zoho_applied_at FROM order_payment_status WHERE order_id = {$orderId}")->fetch();
        self::assertNotNull($row['advance_zoho_applied_at']);

        self::assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 1, 'invoices' => 0, 'applied' => 0], $this->sync(), 'nothing is sent twice');
    }

    public function testTheBalanceIsAppliedToTheCiStraightAwayAndTheFreightToTheDebitNote(): void
    {
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810002');
        $this->approveDocument($orderId, 'FDN', 'TEST-SC/DN/2026/2710001');
        Database::connection()->exec("UPDATE order_payment_status SET balance_amount = 975, balance_cleared_at = '2026-11-20 10:00:00', balance_inr_actual = 82615.5,
            freight_amount = 215, freight_cleared_at = '2026-10-27 15:00:00', freight_inr_actual = 18124.5 WHERE order_id = {$orderId}");

        $result = $this->sync();

        self::assertSame(['synced' => 3, 'failed' => 0, 'skipped' => 0, 'invoices' => 2, 'applied' => 3], $result);
        $debitNote = array_values(array_filter($this->invoices, static fn (array $i): bool => $i['invoice_number'] === 'TEST-SC/DN/2026/2710001'))[0];
        self::assertSame(215.0, $debitNote['total']);
        self::assertSame([
            ['name' => 'Ocean freight', 'description' => 'Ocean freight, Chennai to Hamburg (CIF). HS code 68022310', 'quantity' => 1.0, 'rate' => 200.0],
            ['name' => 'Marine insurance', 'description' => 'Marine insurance (CIF). HS code 68022310', 'quantity' => 1.0, 'rate' => 15.0],
        ], $debitNote['payload']['line_items']);
        self::assertSame(84.4, $debitNote['payload']['exchange_rate'], 'the shipping bill customs rate, not the freight leg rate');
        self::assertSame('2026-10-27', $debitNote['payload']['date']);
        self::assertSame(0.0, $this->invoiceBalance($debitNote['invoice_id']));
        $ci = array_values(array_filter($this->invoices, static fn (array $i): bool => $i['invoice_number'] === 'TEST-SC/CI/2026/2810002'))[0];
        self::assertSame(0.0, $this->invoiceBalance($ci['invoice_id']), 'advance 300 + balance 975 = the CI');
    }

    public function testARevisedCiUpdatesTheSameBooksInvoice(): void
    {
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810003');
        $this->sync();
        Database::connection()->exec("UPDATE order_products SET quantity = 110, fob_value = 1100 WHERE order_id = {$orderId} AND line_no = 1");
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810003', 1);

        $result = $this->sync();

        self::assertSame(1, $result['invoices']);
        self::assertCount(1, $this->invoices, 'no second invoice');
        self::assertSame(1375.0, $this->invoices['INV-1']['total']);
        self::assertCount(1, $this->requestsTo('PUT', '/books/v3/invoices/INV-1'));
        self::assertSame('Revised in the order system: Commercial Invoice TEST-SC/CI/2026/2810003 revision 1 approved.', $this->invoices['INV-1']['payload']['reason'], 'Books needs a reason to change a sent invoice');
        $rows = $this->logRows($orderId);
        self::assertStringStartsWith('Updated Commercial Invoice TEST-SC/CI/2026/2810003', end($rows)['message']);
    }

    public function testADraftRevisionIsIgnoredUntilApproved(): void
    {
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810004');
        $this->sync();
        $draft = $this->createTestDocument($orderId, 'CI', 'in_review');
        Database::connection()->exec("UPDATE documents SET revision_number = 1, document_reference = 'TEST-SC/CI/2026/2810004' WHERE id = {$draft}");
        Database::connection()->exec("UPDATE order_products SET unit_price = 11, fob_value = 1100 WHERE order_id = {$orderId} AND line_no = 1");

        $this->sync();

        self::assertCount(0, $this->requestsTo('PUT', '/books/v3/invoices/'), 'Books keeps the approved version until the revision is approved');

        Database::connection()->exec("UPDATE documents SET status = 'approved' WHERE id = {$draft}");
        $this->sync();
        self::assertSame(1375.0, $this->invoices['INV-1']['total'], 'approved: now it goes');
    }

    public function testACiFlaggedAsChangedSinceApprovalIsNotSent(): void
    {
        $orderId = $this->createCifOrder();
        $documentId = $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810011');
        Database::connection()->exec("UPDATE documents SET drift_flagged_at = NOW() WHERE id = {$documentId}");

        $this->sync();

        self::assertSame([], $this->invoices);
        $rows = array_values(array_filter($this->logRows($orderId), static fn (array $r): bool => $r['sync_type'] === 'invoice'));
        self::assertSame('Commercial Invoice not sent: TEST-SC/CI/2026/2810011 is flagged on the order page as possibly out of date (an earlier document was regenerated after it). Generate and approve a revised Commercial Invoice first.', $rows[0]['message']);
    }

    public function testAnInvoiceCreatedDespiteAnHttp500IsFoundAndNotDuplicated(): void
    {
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810005');
        $this->invoiceWrites500 = true;

        $result = $this->sync();

        self::assertSame(1, $result['invoices']);
        self::assertSame('INV-1', OrderZohoInvoiceRepository::find($orderId, 'ci')['zoho_invoice_id']);
        self::assertCount(1, $this->invoices);
    }

    public function testAnInvoiceTheListDoesNotShowYetIsTakenFromTheCreateResponse(): void
    {
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810017');
        $this->invoiceListLags = true;

        $result = $this->sync();

        self::assertSame([1, 0], [$result['invoices'], $result['failed']]);
        self::assertSame('INV-1', OrderZohoInvoiceRepository::find($orderId, 'ci')['zoho_invoice_id']);
        self::assertSame('sent', $this->invoices['INV-1']['status']);
        self::assertCount(1, $this->invoices);
    }

    public function testAGstRegisteredOrganisationGetsHsnAndOverseasTreatment(): void
    {
        $this->gstRegistered = true;
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'SC/CI/26/2810006');

        $this->sync();

        $payload = $this->invoices['INV-1']['payload'];
        self::assertSame('overseas', $payload['gst_treatment']);
        self::assertSame('68022310', $payload['line_items'][0]['hsn_or_sac']);
        self::assertSame('Granite slab. Size: 60x60x2 cm. Finish: Polished', $payload['line_items'][0]['description']);
    }

    public function testInAGstRegisteredOrganisationAnInvoiceNumberLongerThanSixteenCharactersIsRefused(): void
    {
        $this->gstRegistered = true;
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'SC/CI/2026/2810012');

        $this->sync();

        self::assertSame([], $this->invoices);
        $rows = array_values(array_filter($this->logRows($orderId), static fn (array $r): bool => $r['sync_type'] === 'invoice'));
        self::assertSame('Commercial Invoice SC/CI/2026/2810012 not sent: a GST invoice number may have at most 16 characters and this one has 18. Book it in Zoho Books by hand, or generate it again after the 2-digit-year change (SC/CI/26/...).', $rows[0]['message']);
    }

    public function testTheExchangeRateIsFrozenOnceTheInvoiceIsInBooks(): void
    {
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810013');
        $this->sync();
        self::assertSame(84.4, $this->invoices['INV-1']['payload']['exchange_rate'], 'sent at the shipping bill customs rate');

        // The shipping bill's rate is corrected afterwards: the invoice in Books must not be revalued.
        Database::connection()->exec("UPDATE order_shipping SET customs_exchange_rate = 86.2 WHERE order_id = {$orderId}");
        $this->sync();

        self::assertCount(0, $this->requestsTo('PUT', '/books/v3/invoices/'), 'no update just because the rate was changed later');
        self::assertSame('84.400000', (string) OrderZohoInvoiceRepository::find($orderId, 'ci')['exchange_rate']);
    }

    public function testOnlyRecordsDatedOnOrAfterTheGoLiveDateGo(): void
    {
        CompanySettingsRepository::set('zoho_books_sync_start_date', '2026-10-28');
        try {
            $before = $this->createCifOrder();
            $this->approveDocument($before, 'CI', 'TEST-SC/CI/2026/2710014', 0, '2026-10-27'); // advance cleared 2026-10-20
            $after = $this->createCifOrder();
            $this->approveDocument($after, 'CI', 'TEST-SC/CI/2026/2810015', 0, '2026-10-28');
            Database::connection()->exec("UPDATE order_payment_status SET advance_cleared_at = '2026-10-28 09:00:00' WHERE order_id = {$after}");

            $result = $this->sync();

            self::assertSame(['TEST-SC/CI/2026/2810015'], array_values(array_column($this->invoices, 'invoice_number')));
            self::assertSame([1, 1], [$result['invoices'], $result['synced']], 'only the order from the go-live date on');
        } finally {
            Database::connection()->exec("UPDATE company_settings SET setting_value = '' WHERE setting_key = 'zoho_books_sync_start_date'");
        }
    }

    public function testInProductionNothingIsSentUntilTheGoLiveDateIsSet(): void
    {
        $_ENV['ZOHO_ENVIRONMENT'] = 'production';
        $orderId = $this->createCifOrder('Real Buyer Stage2 GmbH');
        $this->approveDocument($orderId, 'CI', 'SC/CI/26/2810016');

        $result = $this->sync();

        self::assertSame(1, $result['failed']);
        self::assertSame([], $this->requestsTo('POST', '/books/'));
        $last = Database::connection()->query("SELECT status, message FROM zoho_sync_log ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame('error', $last['status']);
        self::assertStringStartsWith('Nothing sent: set the Zoho Books go-live date (zoho_books_sync_start_date) in Admin -> Settings first.', $last['message']);
        Database::connection()->exec("UPDATE orders SET company_legal_name = 'OLD-Real Buyer Stage2 GmbH' WHERE id = {$orderId}");
    }

    public function testAnInvoiceNumberUsedByAnotherCustomerIsRefused(): void
    {
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810007');
        $this->invoices['INV-X'] = ['invoice_id' => 'INV-X', 'invoice_number' => 'TEST-SC/CI/2026/2810007', 'customer_id' => 'CT-OTHER', 'customer_name' => 'Someone Else', 'status' => 'sent', 'total' => 5.0];

        $result = $this->sync();

        self::assertSame(1, $result['failed']);
        self::assertCount(0, $this->requestsTo('POST', '/books/v3/invoices'));
        $rows = array_values(array_filter($this->logRows($orderId), static fn (array $r): bool => $r['sync_type'] === 'invoice'));
        self::assertSame('Invoice number TEST-SC/CI/2026/2810007 is already used in Zoho Books for Someone Else. Nothing was changed.', $rows[0]['message']);
    }

    public function testNotReadyCasesAreRefusedWithAMessageStaffCanActOn(): void
    {
        $orderId = $this->createCifOrder();
        $this->approveDocument($orderId, 'CI', 'TEST-SC/CI/2026/2810008');
        Database::connection()->exec("UPDATE order_shipping SET customs_exchange_rate = NULL WHERE order_id = {$orderId}");

        $this->sync();
        $messages = array_column(array_filter($this->logRows($orderId), static fn (array $r): bool => $r['sync_type'] === 'invoice'), 'message');
        self::assertSame(["Commercial Invoice not sent: record the shipping bill's customs exchange rate on the order (Shipping Bill, on the order page) first."], array_values($messages));

        Database::connection()->exec("UPDATE order_shipping SET customs_exchange_rate = 84.4 WHERE order_id = {$orderId}");
        Database::connection()->exec("UPDATE order_products SET quantity_is_tbc = 1 WHERE order_id = {$orderId} AND line_no = 2");
        $this->sync();
        $messages = array_column(array_filter($this->logRows($orderId), static fn (array $r): bool => $r['sync_type'] === 'invoice'), 'message');
        self::assertSame('Commercial Invoice not sent: line 2 has no confirmed quantity or unit price.', end($messages));
        self::assertSame([], $this->invoices);
    }

    public function testOnlyTestCustomersInTestAndNoTestOrSampleOrdersInProduction(): void
    {
        $real = $this->createCifOrder('Real Buyer GmbH');
        $this->approveDocument($real, 'CI', 'SC/CI/2026/2810009');
        $test = $this->createCifOrder();
        $this->approveDocument($test, 'CI', 'TEST-SC/CI/2026/2810010');

        $this->sync();
        self::assertSame(['TEST-SC/CI/2026/2810010'], array_values(array_column($this->invoices, 'invoice_number')), 'test: only TEST- customers');

        $_ENV['ZOHO_ENVIRONMENT'] = 'production';
        Database::connection()->exec("UPDATE orders SET is_test_data = 1 WHERE id = {$real}");
        try {
            self::assertSame([], array_values(array_filter(OrderZohoInvoiceRepository::documentsToSync(), static fn (array $d): bool => $d['order_id'] === $real)), 'production: test orders never go');
        } finally {
            // Test data left behind keeps Test Mode from being switched off in later tests.
            Database::connection()->exec("UPDATE orders SET is_test_data = 0 WHERE id = {$real}");
        }
    }
}
