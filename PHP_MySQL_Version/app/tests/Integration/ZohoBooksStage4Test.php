<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderFinancialsController;
use App\Helpers\Flash;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Services\CaSyncService;
use App\Services\OrderZohoMatchService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions 2026-10-29: the order's real paid cost entries go to
 * Zoho Books as expenses (updated when corrected, removed when removed),
 * the money the bank pays under an LC as a customer payment applied to the
 * CI with its bank charges, received export benefits as journals (Duty
 * Drawback into the bank, RoDTEP / RoSCTL as e-scrips), and the per-order
 * "App vs Zoho" check. Every request goes to a fake Zoho. The Node suite
 * has the same test (zohoBooksStage4.test.js).
 */
final class ZohoBooksStage4Test extends DbTestCase
{
    private array $requests = [];
    private array $contacts = [];
    private array $invoices = [];
    private array $payments = [];
    private array $expenses = [];
    private array $journals = [];
    private array $accounts = [
        ['account_id' => 'AC-INS', 'account_name' => 'Insurance Expense'],
        ['account_id' => 'AC-COMM', 'account_name' => 'Commission Expense'],
        ['account_id' => 'AC-OTHER', 'account_name' => 'Other Expenses'],
        ['account_id' => 'AC-INSP', 'account_name' => 'Inspection Charges'],
        ['account_id' => 'AC-CARD', 'account_name' => 'Company Card'],
        ['account_id' => 'AC-INCOME', 'account_name' => 'Export Incentive Income'],
        ['account_id' => 'AC-SCRIP', 'account_name' => 'Export Incentive e-Scrips'],
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
        // The test database is shared by the whole run: earlier tests' TEST- orders must not reach this test's fake Zoho.
        Database::connection()->exec("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        CompanySettingsRepository::set('zoho_books_enabled', '0');
        $_POST = [];
        parent::tearDown();
    }

    /** @return array{status: int, body: string} */
    private function fakeZoho(string $method, string $url, array $payload): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload];
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $json = static fn (array $body, int $status = 200): array => ['status' => $status, 'body' => (string) json_encode($body)];
        $gone = $json(['code' => 1002, 'message' => 'Record does not exist.'], 404);

        if ($path === '/oauth/v2/token') {
            return $json(['access_token' => 'fake-access']);
        }
        if (preg_match('#^/books/v3/organizations/(\d+)$#', $path, $m) === 1) {
            return $json(['code' => 0, 'organization' => ['organization_id' => $m[1], 'is_registered_for_gst' => false]]);
        }
        if ($path === '/books/v3/settings/currencies') {
            return $json(['code' => 0, 'currencies' => [['currency_id' => 'C-USD', 'currency_code' => 'USD'], ['currency_id' => 'C-INR', 'currency_code' => 'INR']]]);
        }
        if ($path === '/books/v3/chartofaccounts') {
            return $json(['code' => 0, 'chartofaccounts' => $this->accounts]);
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
        if ($path === '/books/v3/invoices' && $method === 'GET') {
            $rows = array_values(array_filter($this->invoices, static fn (array $i): bool => (!isset($query['invoice_number']) || $i['invoice_number'] === $query['invoice_number'])
                && (!isset($query['customer_id']) || $i['customer_id'] === $query['customer_id'])));
            return $json(['code' => 0, 'invoices' => array_map(fn (array $i): array => $i + ['balance' => $this->invoiceBalance($i['invoice_id'])], $rows)]);
        }
        if ($path === '/books/v3/invoices' && $method === 'POST') {
            $id = 'INV-' . (count($this->invoices) + 1);
            $total = 0.0;
            foreach ($payload['line_items'] as $line) {
                $total += round($line['quantity'] * $line['rate'], 2);
            }
            $this->invoices[$id] = ['invoice_id' => $id, 'invoice_number' => $payload['invoice_number'], 'customer_id' => $payload['customer_id'], 'reference_number' => $payload['reference_number'], 'status' => 'draft', 'total' => $total, 'currency_code' => 'USD'];
            return $json(['code' => 0, 'invoice' => $this->invoices[$id]], 201);
        }
        if (preg_match('#^/books/v3/invoices/([^/]+)/status/sent$#', $path, $m) === 1) {
            $this->invoices[$m[1]]['status'] = 'sent';
            return $json(['code' => 0]);
        }
        if ($path === '/books/v3/customerpayments' && $method === 'GET') {
            return $json(['code' => 0, 'customerpayments' => array_values(array_map(static fn (string $id, array $p): array => ['payment_id' => $id, 'amount' => $p['amount'], 'reference_number' => $p['reference_number'], 'customer_id' => $p['customer_id']], array_keys($this->payments), $this->payments))]);
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
        foreach (['expenses' => ['expenses', 'expense', 'EX-', 'amount'], 'journals' => ['journals', 'journal', 'JN-', null]] as $plural => [$store, $one, $prefix, $amountKey]) {
            if ($path === "/books/v3/{$plural}" && $method === 'POST') {
                $id = $prefix . (count($this->{$store}) + 1);
                $this->{$store}[$id] = $payload + [$one . '_id' => $id];
                return $json(['code' => 0, $one => [$one . '_id' => $id]], 201);
            }
            if (preg_match("#^/books/v3/{$plural}/(.+)$#", $path, $m) === 1) {
                if (!isset($this->{$store}[$m[1]])) {
                    return $gone;
                }
                if ($method === 'DELETE') {
                    unset($this->{$store}[$m[1]]);
                    return $json(['code' => 0]);
                }
                if ($method === 'PUT') {
                    $this->{$store}[$m[1]] = $payload + [$one . '_id' => $m[1]];
                }
                $record = $this->{$store}[$m[1]];
                $record['total'] = $amountKey !== null ? $record[$amountKey] : array_sum(array_map(static fn (array $l): float => $l['debit_or_credit'] === 'debit' ? (float) $l['amount'] : 0.0, $record['line_items']));
                return $json(['code' => 0, $one => $record]);
            }
        }
        return $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400);
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

    private function requestsTo(string $method, string $pathPrefix): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_starts_with((string) parse_url($r['url'], PHP_URL_PATH), $pathPrefix)));
    }

    /** A TEST- CIF order in USD paid by LC: two products (FOB 1,275.00), customs rate 84.40. */
    private function createOrder(): int
    {
        $pdo = Database::connection();
        $clientId = $this->createTestClient();
        $pdo->prepare('UPDATE clients SET company_legal_name = :name WHERE id = :id')->execute(['name' => 'TEST-Stage4 Buyer', 'id' => $clientId]);
        $orderId = $this->createTestOrder($clientId, 'CIF');
        $pdo->prepare("UPDATE orders SET buyer_inquiry_ref = :ref, payment_method = 'LC' WHERE id = :id")->execute(['ref' => "NX-{$orderId}", 'id' => $orderId]);
        $insert = $pdo->prepare('INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, hs_code) VALUES (:o, :l, :d, :q, :u, :p, :v, :h)');
        $insert->execute(['o' => $orderId, 'l' => 1, 'd' => 'Granite slab', 'q' => 100, 'u' => 'SQM', 'p' => 10, 'v' => 1000, 'h' => '68022310']);
        $insert->execute(['o' => $orderId, 'l' => 2, 'd' => 'Sandstone tile', 'q' => 50, 'u' => 'SQM', 'p' => 5.5, 'v' => 275, 'h' => '68029900']);
        $pdo->prepare("INSERT INTO order_shipping (order_id, shipping_bill_no, shipping_bill_date, customs_exchange_rate) VALUES (:id, 'TEST-SB-4', '2026-10-27', 84.4)")->execute(['id' => $orderId]);
        $pdo->prepare("INSERT INTO order_lc_details (order_id, lc_number, lc_amount, lc_confirmed_at) VALUES (:id, 'TEST-LC-4', 1275, '2026-10-10 10:00:00')")->execute(['id' => $orderId]);
        return $orderId;
    }

    private function approveCi(int $orderId): void
    {
        $id = $this->createTestDocument($orderId, 'CI', 'approved');
        Database::connection()->prepare("UPDATE documents SET document_reference = :ref, document_date = '2026-10-28' WHERE id = :id")->execute(['ref' => "TEST-SC/CI/{$orderId}", 'id' => $id]);
    }

    private function messages(int $orderId, string $type): array
    {
        $stmt = Database::connection()->prepare('SELECT message FROM zoho_sync_log WHERE entity_id = :id AND sync_type = :t ORDER BY id');
        $stmt->execute(['id' => $orderId, 't' => $type]);
        return array_column($stmt->fetchAll(), 'message');
    }

    private function lastMessage(int $orderId, string $type): string
    {
        $messages = $this->messages($orderId, $type);
        return (string) end($messages);
    }

    public function testRealPaidCostsGoToBooksAsExpensesAndTheRestStayInTheApp(): void
    {
        $orderId = $this->createOrder();
        $ecgc = OrderCostEntryRepository::create($orderId, 'ecgc_insurance', 'ECGC cover', 4200.0, '2026-10-20', $this->userId, 'company_account');
        OrderCostEntryRepository::create($orderId, 'commission', null, 9000.0, '2026-10-21', $this->userId, 'company_card');
        OrderCostEntryRepository::create($orderId, 'cha_charges', null, 6000.0, '2026-10-21', $this->userId, 'company_account');
        OrderCostEntryRepository::create($orderId, 'bank_charges', null, 350.0, '2026-10-21', $this->userId, 'company_account');
        OrderCostEntryRepository::create($orderId, 'travel_expenses', 'Travel expenses (factory visits)', 5000.0, '2026-10-21', $this->userId);

        self::assertSame(2, CaSyncService::syncPurchases('manual', null)['expenses']);

        self::assertSame(['account_id' => 'AC-INS', 'paid_through_account_id' => 'SBI-1', 'amount' => 4200.0, 'date' => '2026-10-20', 'reference_number' => "NX-{$orderId}-COST-{$ecgc}",
            'description' => "TEST-Stage4 Buyer / NX-{$orderId}: ECGC Insurance — ECGC cover", 'expense_id' => 'EX-1'], $this->expenses['EX-1']);
        self::assertSame(['AC-COMM', 'AC-CARD', 9000.0], [$this->expenses['EX-2']['account_id'], $this->expenses['EX-2']['paid_through_account_id'], $this->expenses['EX-2']['amount']]);
        self::assertSame(0, CaSyncService::syncPurchases('manual', null)['expenses'], 'nothing is sent twice');
    }

    public function testACorrectedCostIsUpdatedInBooksAndARemovedOneIsRemovedThere(): void
    {
        $orderId = $this->createOrder();
        $id = OrderCostEntryRepository::create($orderId, 'inspection_fee', 'Pre-shipment inspection fee', 7500.0, '2026-10-22', $this->userId, 'company_account');
        CaSyncService::syncPurchases('manual', null);
        OrderCostEntryRepository::updateAmount($id, 8100.0, 'Pre-shipment inspection fee');

        self::assertSame(1, CaSyncService::syncPurchases('manual', null)['expenses']);
        self::assertCount(1, $this->requestsTo('PUT', '/books/v3/expenses/EX-1'));
        self::assertSame(8100.0, $this->expenses['EX-1']['amount']);
        self::assertSame(['AC-INSP'], [$this->expenses['EX-1']['account_id']]);

        ob_start();
        (new OrderFinancialsController())->deleteCostEntry(['id' => (string) $orderId, 'entryId' => (string) $id]);
        ob_end_clean();
        self::assertSame([], $this->expenses, 'removed from Books too');
        self::assertNull(OrderCostEntryRepository::find($id));
        self::assertSame('Removed order cost "Pre-Shipment Inspection Fee" (INR 8100.00) from Zoho Books: it was removed in the app.', $this->lastMessage($orderId, 'order_cost'));
    }

    public function testACostThatDoesNotSayWhatItWasPaidFromIsRefused(): void
    {
        $orderId = $this->createOrder();
        OrderCostEntryRepository::create($orderId, 'documentation', 'COO', 1500.0, '2026-10-22', $this->userId);

        self::assertSame(1, CaSyncService::syncPurchases('manual', null)['failed']);
        self::assertSame(['Order cost "Documentation" (INR 1500.00) not sent: it does not say what it was paid from. Remove it and record it again with "Paid from".'], $this->messages($orderId, 'order_cost'));
    }

    public function testTheLcPaymentIsHeldThenAppliedToTheCiWithTheBankCharges(): void
    {
        $orderId = $this->createOrder();
        OrderLcDetailRepository::recordReceived($orderId, '2026-11-05', 1275.0, 107100.0, 750.0, $this->userId, 'HDFC-LC-0915-778');

        $first = CaSyncService::syncPendingRevenue('manual', null);
        self::assertSame([1, 0], [$first['synced'], $first['applied']], 'no CI in Books yet: held');
        self::assertStringStartsWith("LC money received from TEST-Stage4 Buyer for order NX-{$orderId} · ", $this->payments['PAY-1']['description'], 'owner rule D-212');
        self::assertStringEndsWith('USD 1,275.00, ₹107,100.00 credited after ₹750.00 bank charges · bank ref HDFC-LC-0915-778', $this->payments['PAY-1']['description']);
        $payment = $this->payments['PAY-1'];
        self::assertSame(1275.0, $payment['amount']);
        self::assertSame(84.588235, $payment['exchange_rate'], '(credited 107,100 + charges 750) / 1,275');
        self::assertSame(8.87, $payment['bank_charges'], 'the charges in USD');
        self::assertSame("NX-{$orderId}-lc", $payment['reference_number']);

        $this->approveCi($orderId);
        $second = CaSyncService::syncPendingRevenue('manual', null);
        self::assertSame([1, 1], [$second['invoices'], $second['applied']]);
        self::assertSame([['invoice_id' => 'INV-1', 'amount_applied' => 1275.0]], $this->payments['PAY-1']['invoices']);
        self::assertSame(8.87, $this->payments['PAY-1']['bank_charges'], 're-sent whole, with the charges');
        self::assertStringEndsWith(' · against Commercial Invoice ' . $this->invoices['INV-1']['invoice_number'] . ' · bank ref HDFC-LC-0915-778', $this->payments['PAY-1']['description'], 'once applied, it names the CI');
        self::assertNotNull(OrderLcDetailRepository::find($orderId)['lc_zoho_applied_at']);
    }

    public function testTheLcPaymentIsRecordedOnlyOnAnLcOrderOnceUsableAndNotChangedOnceInBooks(): void
    {
        $orderId = $this->createOrder();
        $post = ['lc_received_at' => '2026-10-27', 'lc_received_amount' => '1275', 'lc_inr_credited' => '107100', 'lc_bank_charges_inr' => '750', 'lc_bank_reference' => 'HDFC-LC-0915-778'];
        $run = function (array $body) use ($orderId): array {
            $_POST = $body;
            ob_start();
            (new OrderFinancialsController())->recordLcReceived(['id' => (string) $orderId]);
            ob_end_clean();
            $messages = Flash::pull();
            return end($messages) ?: [];
        };

        self::assertSame('Enter the rupees credited to the bank account.', $run(['lc_inr_credited' => ''] + $post)['message']);
        self::assertSame('Enter the date the bank paid (not in the future).', $run(['lc_received_at' => '2099-01-01'] + $post)['message']);
        self::assertStringContainsString('bank reference of the credit is required', $run(['lc_bank_reference' => ''] + $post)['message'], 'owner rule D-212');
        self::assertNull(OrderLcDetailRepository::find($orderId)['lc_received_at'], 'nothing recorded without it');
        self::assertSame(['type' => 'success', 'message' => 'LC payment recorded: USD 1275, ₹107100 credited.'], $run($post));
        self::assertSame('750.00', (string) OrderLcDetailRepository::find($orderId)['lc_bank_charges_inr']);
        self::assertSame('HDFC-LC-0915-778', OrderLcDetailRepository::find($orderId)['lc_bank_reference']);

        CaSyncService::syncPendingRevenue('manual', null);
        self::assertSame('This LC payment is already in Zoho Books, so it cannot be changed here. Ask the CA to correct it there.', $run($post)['message']);

        Database::connection()->exec("UPDATE order_lc_details SET lc_confirmed_at = NULL WHERE order_id = {$orderId}");
        self::assertSame('Record the LC and confirm it usable (Step 5) first.', $run($post)['message']);
    }

    public function testReceivedExportBenefitsGoAsJournalsAndFollowCorrections(): void
    {
        $orderId = $this->createOrder();
        $drawback = CaExportBenefitRepository::record($orderId, 'Duty Drawback', 'SB-1', 18000.0, '2026-10-28', 'INR', null, $this->userId);
        $rodtep = CaExportBenefitRepository::record($orderId, 'RODTEP', 'SB-1', 9000.0, '2026-10-28', 'INR', null, $this->userId);
        $other = CaExportBenefitRepository::record($orderId, 'Other', null, 500.0, '2026-10-28', 'INR', null, $this->userId);
        CaExportBenefitRepository::record($orderId, 'RoSCTL', null, 700.0, '2026-10-28', 'INR', null, $this->userId); // claimed, not received: not booked
        CaExportBenefitRepository::markReceived($drawback, 17850.0, '2026-11-20', 'SBIN226311000555');
        CaExportBenefitRepository::markReceived($rodtep, 9000.0, '2026-11-25', '2610000123456');
        CaExportBenefitRepository::markReceived($other, 500.0, '2026-11-25', 'SBIN226311000777');

        $result = CaSyncService::syncBenefits('manual', null);

        self::assertSame(['journals' => 2, 'removed' => 0, 'failed' => 1, 'skipped' => 0], $result);
        self::assertSame([
            ['account_id' => 'SBI-1', 'debit_or_credit' => 'debit', 'amount' => 17850.0, 'description' => 'Duty Drawback paid into the bank'],
            ['account_id' => 'AC-INCOME', 'debit_or_credit' => 'credit', 'amount' => 17850.0, 'description' => 'Duty Drawback received'],
        ], $this->journals['JN-1']['line_items']);
        self::assertSame(['2026-11-20', "NX-{$orderId}-BEN-{$drawback}", "TEST-Stage4 Buyer / NX-{$orderId}: Duty Drawback received (claim SB-1) · ₹17,850.00 · bank ref SBIN226311000555"], [$this->journals['JN-1']['journal_date'], $this->journals['JN-1']['reference_number'], $this->journals['JN-1']['notes']], 'owner rule D-212: how it was credited');
        self::assertSame("TEST-Stage4 Buyer / NX-{$orderId}: RODTEP received (claim SB-1) · ₹9,000.00 · e-scrip 2610000123456", $this->journals['JN-2']['notes']);
        self::assertSame('AC-SCRIP', $this->journals['JN-2']['line_items'][0]['account_id'], 'RoDTEP: credited as e-scrips');
        self::assertStringContainsString('only Duty Drawback, RoDTEP and RoSCTL go to Zoho Books automatically', $this->lastMessage($orderId, 'export_benefit'));

        CaExportBenefitRepository::correct($drawback, 'SB-1', 18000.0, '2026-10-28', 17900.0, '2026-11-20');
        CaExportBenefitRepository::correct($rodtep, 'SB-1', 9000.0, '2026-10-28', null, null);
        $again = CaSyncService::syncBenefits('manual', null);
        self::assertSame([1, 1], [$again['journals'], $again['removed']]);
        self::assertSame(17900.0, $this->journals['JN-1']['line_items'][0]['amount']);
        self::assertArrayNotHasKey('JN-2', $this->journals, 'a cleared receipt is removed from Books');
        self::assertNull(CaExportBenefitRepository::find($rodtep)['zoho_journal_id']);
    }

    public function testAClaimWhoseReceiptIsInBooksCannotBeDeletedStraightAway(): void
    {
        $orderId = $this->createOrder();
        $id = CaExportBenefitRepository::record($orderId, 'Duty Drawback', null, 1000.0, '2026-10-28', 'INR', null, $this->userId);
        CaExportBenefitRepository::markReceived($id, 1000.0, '2026-11-20');
        CaSyncService::syncBenefits('manual', null);

        $_POST = ['reason' => 'Recorded against the wrong order by mistake'];
        ob_start();
        (new OrderFinancialsController())->deleteExportBenefit(['id' => (string) $orderId, 'benefitId' => (string) $id]);
        ob_end_clean();

        self::assertNotNull(CaExportBenefitRepository::find($id), 'still there');
        self::assertStringStartsWith('This Duty Drawback receipt is in Zoho Books.', Flash::pull()[0]['message'] ?? '');
    }

    public function testTheAppVsZohoCheckShowsWhatMatchesAndWhatDoesNot(): void
    {
        $orderId = $this->createOrder();
        $this->approveCi($orderId);
        OrderLcDetailRepository::recordReceived($orderId, '2026-11-05', 1275.0, 107100.0, 750.0, $this->userId);
        $cost = OrderCostEntryRepository::create($orderId, 'ecgc_insurance', null, 4200.0, '2026-10-20', $this->userId, 'company_account');
        OrderCostEntryRepository::create($orderId, 'commission', null, 900.0, '2026-10-20', $this->userId, 'company_account');
        $benefit = CaExportBenefitRepository::record($orderId, 'Duty Drawback', null, 1000.0, '2026-10-28', 'INR', null, $this->userId);
        CaExportBenefitRepository::markReceived($benefit, 1000.0, '2026-11-20');
        CaSyncService::runFullSync('manual', null);

        $clean = OrderZohoMatchService::check($orderId);
        self::assertSame(['match' => 5, 'not_sent' => 0, 'before_go_live' => 0, 'different' => 0, 'missing' => 0, 'books_only' => 0], $clean['counts']);

        // Someone changes Books directly, and the app gets a cost not sent yet.
        $this->expenses['EX-1']['amount'] = 4000.0;
        unset($this->journals['JN-1']);
        $this->invoices['INV-X'] = ['invoice_id' => 'INV-X', 'invoice_number' => 'HAND-1', 'customer_id' => 'CT-1', 'reference_number' => "NX-{$orderId}", 'status' => 'sent', 'total' => 50.0, 'currency_code' => 'USD'];
        OrderCostEntryRepository::create($orderId, 'other', 'Courier', 300.0, '2026-10-29', $this->userId);

        $rows = array_column(array_map(static fn (array $r): array => [$r['item'], $r['status']], OrderZohoMatchService::check($orderId)['rows']), 1, 0);
        self::assertSame('different', $rows['Cost: ECGC Insurance']);
        self::assertSame('missing', $rows['Duty Drawback received']);
        self::assertSame('books_only', $rows['Invoice HAND-1']);
        self::assertSame('not_sent', $rows['Cost: Other — Courier']);
        self::assertSame('match', $rows["Commercial Invoice TEST-SC/CI/{$orderId}"]);
        self::assertSame('match', $rows['LC payment']);
        self::assertSame(['GET'], array_values(array_unique(array_column(array_slice($this->requests, -6), 'method'))), 'the check only reads');
        self::assertSame((float) 4200, (float) OrderCostEntryRepository::find($cost)['amount_inr']);
    }
}
