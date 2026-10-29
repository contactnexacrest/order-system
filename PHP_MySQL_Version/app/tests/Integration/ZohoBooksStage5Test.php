<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderExpenseController;
use App\Helpers\Flash;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\CaFyLockRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExpenseRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Services\CaSyncService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions 2026-10-29 — who owns what between the app and Zoho
 * Books. The app owns what it sends: deleted in Books, it is sent again;
 * changed in Books, it is put back — except in a financial year the CA has
 * locked, where the CA is told instead. Books owns the expenses the CA
 * enters there: the import follows their changes and deletions, links one
 * to its order by the Reference #, and flags a possible duplicate of a cost
 * entered in the app. Removing an order expense in the app removes it from
 * Books. Every request goes to a fake Zoho. The Node suite has the same
 * test (zohoBooksStage5.test.js).
 */
final class ZohoBooksStage5Test extends DbTestCase
{
    private array $requests = [];
    private array $contacts = [];
    private array $invoices = [];
    private array $payments = [];
    private array $bills = [];
    private array $vendorPayments = [];
    private array $expenses = [];
    private array $journals = [];
    private array $accounts = [
        ['account_id' => 'AC-TRANS', 'account_name' => 'Transportation Expense'],
        ['account_id' => 'AC-OTHER', 'account_name' => 'Other Expenses'],
        ['account_id' => 'AC-TRAVEL', 'account_name' => 'Travel Expense'],
        ['account_id' => 'AC-POST', 'account_name' => 'Postage'],
        ['account_id' => 'AC-INS', 'account_name' => 'Insurance Expense'],
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
        $pdo = Database::connection();
        // The test database is shared by the whole run: earlier tests' TEST- records must not reach this test's fake Zoho.
        $pdo->exec("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
        $pdo->exec('UPDATE ca_bank_statement_lines SET matched_expense_id = NULL');
        $pdo->exec('DELETE FROM ca_expenses');
        $pdo->exec('UPDATE ca_fy_locks SET unlocked_at = NOW() WHERE unlocked_at IS NULL');
        $_SESSION = [];
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        CompanySettingsRepository::set('zoho_books_enabled', '0');
        Database::connection()->exec('UPDATE ca_fy_locks SET unlocked_at = NOW() WHERE unlocked_at IS NULL');
        $_POST = [];
        $_SESSION = [];
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
            return $json(['code' => 0, 'invoices' => $rows]);
        }
        if ($path === '/books/v3/invoices' && $method === 'POST') {
            $id = 'INV-' . (count($this->invoices) + 1);
            $total = 0.0;
            foreach ($payload['line_items'] as $line) {
                $total += round($line['quantity'] * $line['rate'], 2);
            }
            $this->invoices[$id] = ['invoice_id' => $id, 'invoice_number' => $payload['invoice_number'], 'customer_id' => $payload['customer_id'], 'reference_number' => $payload['reference_number'], 'status' => 'draft', 'total' => $total, 'balance' => $total];
            return $json(['code' => 0, 'invoice' => $this->invoices[$id]], 201);
        }
        if (preg_match('#^/books/v3/invoices/([^/]+)/status/sent$#', $path, $m) === 1) {
            $this->invoices[$m[1]]['status'] = 'sent';
            return $json(['code' => 0]);
        }
        $stores = [
            'customerpayments' => ['payments', 'payment', 'PAY-', 'customer_id'],
            'vendorpayments' => ['vendorPayments', 'vendorpayment', 'VP-', 'vendor_id'],
            'bills' => ['bills', 'bill', 'BL-', 'vendor_id'],
            'expenses' => ['expenses', 'expense', 'EX-', null],
            'journals' => ['journals', 'journal', 'JN-', null],
        ];
        foreach ($stores as $plural => [$store, $one, $prefix, $filter]) {
            $idKey = in_array($plural, ['customerpayments', 'vendorpayments'], true) ? 'payment_id' : $one . '_id';
            if ($path === "/books/v3/{$plural}" && $method === 'GET') {
                $rows = array_values(array_filter($this->{$store}, static fn (array $r): bool => ($filter === null || !isset($query[$filter]) || ($r[$filter] ?? null) === $query[$filter])
                    && (!isset($query['bill_number']) || ($r['bill_number'] ?? null) === $query['bill_number'])));
                return $json(['code' => 0, $plural => array_map(fn (array $r): array => $this->withTotals($plural, $r), $rows), 'page_context' => ['has_more_page' => false]]);
            }
            if ($path === "/books/v3/{$plural}" && $method === 'POST') {
                $id = $prefix . (count($this->{$store}) + 1 + count(array_filter($this->requests, static fn (array $r): bool => $r['method'] === 'DELETE')));
                $this->{$store}[$id] = $payload + [$idKey => $id];
                $key = $plural === 'customerpayments' ? 'payment' : $one;
                return $json(['code' => 0, $key => $this->withTotals($plural, $this->{$store}[$id])], 201);
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
                    $this->{$store}[$m[1]] = $payload + [$idKey => $m[1]];
                }
                $key = $plural === 'customerpayments' ? 'payment' : $one;
                return $json(['code' => 0, $key => $this->withTotals($plural, $this->{$store}[$m[1]])]);
            }
        }
        return $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400);
    }

    private function withTotals(string $plural, array $r): array
    {
        return match ($plural) {
            'bills' => $r + ['total' => (float) $r['line_items'][0]['rate'], 'balance' => (float) $r['line_items'][0]['rate'] - $this->paidOnBill((string) $r['bill_id'])],
            'expenses' => $r + ['total' => (float) $r['amount']],
            'journals' => $r + ['total' => array_sum(array_map(static fn (array $l): float => $l['debit_or_credit'] === 'debit' ? (float) $l['amount'] : 0.0, $r['line_items']))],
            default => $r,
        };
    }

    private function paidOnBill(string $billId): float
    {
        $paid = 0.0;
        foreach ($this->vendorPayments as $p) {
            foreach ($p['bills'] ?? [] as $applied) {
                $paid += $applied['bill_id'] === $billId ? $applied['amount_applied'] : 0;
            }
        }
        return $paid;
    }

    private function requestsTo(string $method, string $pathPrefix): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_starts_with((string) parse_url($r['url'], PHP_URL_PATH), $pathPrefix)));
    }

    private function messages(string $type): array
    {
        $stmt = Database::connection()->prepare('SELECT message FROM zoho_sync_log WHERE sync_type = :t ORDER BY id');
        $stmt->execute(['t' => $type]);
        return array_column($stmt->fetchAll(), 'message');
    }

    /**
     * A TEST- order in USD with: a CI in Books (paid by an LC payment), a
     * TEST- transporter's bill and its payment, an order expense, an ECGC
     * cost and a Duty Drawback received — all sent to the fake Zoho.
     *
     * @return array{order:int, bill:int, expense:int, cost:int, benefit:int}
     */
    private function sentOrder(): array
    {
        $pdo = Database::connection();
        $clientId = $this->createTestClient();
        $pdo->prepare('UPDATE clients SET company_legal_name = :n WHERE id = :id')->execute(['n' => 'TEST-Stage5 Buyer', 'id' => $clientId]);
        $orderId = $this->createTestOrder($clientId, 'CIF');
        $pdo->prepare("UPDATE orders SET buyer_inquiry_ref = :ref, payment_method = 'LC' WHERE id = :id")->execute(['ref' => "NX5-{$orderId}", 'id' => $orderId]);
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, hs_code) VALUES (:o, 1, 'Granite slab', 100, 'SQM', 12, 1200, '68022310')")->execute(['o' => $orderId]);
        $pdo->prepare("INSERT INTO order_shipping (order_id, shipping_bill_no, shipping_bill_date, customs_exchange_rate) VALUES (:id, 'TEST-SB-5', '2026-10-27', 83.5)")->execute(['id' => $orderId]);
        $pdo->prepare("INSERT INTO order_lc_details (order_id, lc_number, lc_amount, lc_confirmed_at) VALUES (:id, 'TEST-LC-5', 1200, '2026-10-10 10:00:00')")->execute(['id' => $orderId]);
        $doc = $this->createTestDocument($orderId, 'CI', 'approved');
        $pdo->prepare("UPDATE documents SET document_reference = :ref, document_date = '2026-10-28' WHERE id = :id")->execute(['ref' => "TEST-CI5/{$orderId}", 'id' => $doc]);
        OrderLcDetailRepository::recordReceived($orderId, '2026-10-28', 1200.0, 99500.0, 500.0, $this->userId);
        $pdo->exec("INSERT INTO logistics_partners (partner_name, service_type) VALUES ('TEST-Stage5 GTA', 'transportation')");
        $partner = (int) $pdo->lastInsertId();
        $bill = OrderVendorBillRepository::create($orderId, 'logistics', ['logistics_partner_id' => $partner, 'bill_for' => 'transport', 'doc_kind' => 'invoice', 'bill_no' => "TEST-GTA5-{$orderId}", 'bill_date' => '2026-10-26', 'taxable_inr' => 12000.0, 'gst_inr' => 0.0], $this->userId);
        OrderVendorBillRepository::addPayment($bill, null, '2026-10-27', 12000.0, 'IMPS', "TEST-UTR5-{$orderId}", $this->userId);
        $expense = OrderExpenseRepository::create($orderId, ['expense_date' => '2026-10-22', 'category' => 'courier', 'details' => 'Documents to buyer', 'amount_inr' => 1800, 'paid_by' => 'company_account', 'no_receipt_reason' => 'test'], $this->userId);
        $cost = OrderCostEntryRepository::create($orderId, 'ecgc_insurance', null, 4200.0, '2026-10-20', $this->userId, 'company_account');
        $benefit = CaExportBenefitRepository::record($orderId, 'Duty Drawback', null, 1800.0, '2026-10-28', 'INR', null, $this->userId);
        CaExportBenefitRepository::markReceived($benefit, 1750.0, '2026-10-29');
        CaSyncService::runFullSync('manual', null);
        return ['order' => $orderId, 'bill' => $bill, 'expense' => $expense, 'cost' => $cost, 'benefit' => $benefit];
    }

    private function idOf(string $store, callable $match): string
    {
        foreach ($this->{$store} as $id => $r) {
            if ($match($r)) {
                return (string) $id;
            }
        }
        self::fail("nothing in {$store} matches");
    }

    public function testWhatWasDeletedInBooksIsSentAgain(): void
    {
        $o = $this->sentOrder();
        self::assertCount(1, $this->payments);
        // Someone deletes, directly in Books: the LC payment, the bill and its payment, the expense, the cost and the journal.
        $this->payments = [];
        $this->vendorPayments = [];
        $this->bills = [];
        $this->expenses = [];
        $this->journals = [];

        $result = CaSyncService::runFullSync('manual', null);

        self::assertSame([1, 0], [$result['revenue']['synced'], $result['revenue']['failed']]);
        self::assertSame([1, 1, 2, 0], [$result['purchases']['bills'], $result['purchases']['payments'], $result['purchases']['expenses'], $result['purchases']['failed']]);
        self::assertSame([1, 0], [$result['benefits']['journals'], $result['benefits']['failed']]);
        self::assertCount(1, $this->payments);
        self::assertSame([['invoice_id' => 'INV-1', 'amount_applied' => 1200.0]], array_values($this->payments)[0]['invoices'], 'applied to the CI again');
        self::assertSame(12000.0, array_values($this->vendorPayments)[0]['bills'][0]['amount_applied'], 'the re-created payment is applied to the re-created bill');
        self::assertCount(2, $this->expenses);
        self::assertCount(1, $this->journals);
        foreach (['revenue_payment', 'vendor_bill', 'vendor_payment', 'order_expense', 'order_cost', 'export_benefit'] as $type) {
            $last = $this->messages($type);
            self::assertStringStartsWith('Re-created in Zoho Books — it had been deleted there.', (string) end($last), $type);
        }
        self::assertNotNull(OrderLcDetailRepository::find($o['order'])['lc_zoho_applied_at']);

        $again = CaSyncService::runFullSync('manual', null);
        self::assertSame([1, 1, 1], [$again['revenue']['skipped'], $again['purchases']['skipped'], $again['benefits']['skipped']], 'nothing to do the next time');
    }

    public function testWhatWasChangedInBooksIsPutBack(): void
    {
        $o = $this->sentOrder();
        $pay = array_key_first($this->payments);
        $this->payments[$pay]['amount'] = 1100.0;
        $bill = array_key_first($this->bills);
        $this->bills[$bill]['line_items'][0]['rate'] = 11000.0;
        $vp = array_key_first($this->vendorPayments);
        $this->vendorPayments[$vp]['amount'] = 11000.0;
        $exp = $this->idOf('expenses', static fn (array $e): bool => str_contains((string) $e['description'], 'Documents to buyer'));
        $this->expenses[$exp]['amount'] = 1500.0;
        $cost = $this->idOf('expenses', static fn (array $e): bool => str_contains((string) $e['description'], 'ECGC'));
        $this->expenses[$cost]['amount'] = 4000.0;
        $jn = array_key_first($this->journals);
        $this->journals[$jn]['line_items'][0]['amount'] = 1700.0;

        $result = CaSyncService::runFullSync('manual', null);

        self::assertSame(1, $result['revenue']['synced']);
        self::assertSame([1, 1, 2], [$result['purchases']['bills'], $result['purchases']['payments'], $result['purchases']['expenses']]);
        self::assertSame(1, $result['benefits']['journals']);
        self::assertSame(1200.0, $this->payments[$pay]['amount']);
        self::assertSame(6.0, $this->payments[$pay]['bank_charges'], 'the ₹500 bank charges, in USD, as first sent');
        self::assertSame(12000.0, $this->bills[$bill]['line_items'][0]['rate']);
        self::assertSame(12000.0, $this->vendorPayments[$vp]['amount']);
        self::assertSame([1800.0, 4200.0], [$this->expenses[$exp]['amount'], $this->expenses[$cost]['amount']]);
        self::assertSame(1750.0, $this->journals[$jn]['line_items'][0]['amount']);
        self::assertCount(1, $this->payments, 'put back in place, never a second one');
        self::assertCount(1, $this->bills);
        foreach (['revenue_payment', 'vendor_bill', 'vendor_payment', 'order_expense', 'order_cost', 'export_benefit'] as $type) {
            $last = $this->messages($type);
            self::assertStringStartsWith("Restored to the app's values — it had been changed in Zoho Books.", (string) end($last), $type);
        }
        self::assertSame(0, count($this->requestsTo('POST', '/books/v3/bills')) - 1, 'the bill was updated, not created again');
        unset($o);
    }

    public function testInALockedYearNothingIsRestoredAndTheCaIsToldOnce(): void
    {
        $this->sentOrder();
        CaFyLockRepository::lock('2026-27', $this->userId);
        $this->expenses = [];
        $this->bills[array_key_first($this->bills)]['line_items'][0]['rate'] = 11000.0;

        CaSyncService::runFullSync('manual', null);
        CaSyncService::runFullSync('manual', null);

        self::assertSame([], $this->expenses, 'not re-created');
        self::assertSame(11000.0, array_values($this->bills)[0]['line_items'][0]['rate'], 'not put back');
        $errors = Database::connection()->query("SELECT message FROM zoho_sync_log WHERE status = 'error' AND message LIKE '%which is locked: not restored%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(3, $errors, 'the bill, the expense and the cost — each logged once, not on every sync');
        self::assertContains('Bill TEST-GTA5-' . Database::connection()->query('SELECT order_id FROM order_vendor_bills ORDER BY id DESC LIMIT 1')->fetchColumn() . ' from TEST-Stage5 GTA was changed in Zoho Books, but it is dated in FY 2026-27, which is locked: not restored — CA to decide.', $errors);
    }

    public function testTheImportFollowsBooksAndLinksAnExpenseToItsOrder(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        Database::connection()->prepare('UPDATE orders SET buyer_inquiry_ref = :r WHERE id = :id')->execute(['r' => "NX5-LINK-{$orderId}", 'id' => $orderId]);
        $book = fn (string $id, float $amount, string $date, string $reference = '', string $description = 'CA entry') => $this->expenses[$id] = ['expense_id' => $id, 'account_name' => 'Postage', 'description' => $description, 'vendor_name' => 'DHL', 'amount' => $amount, 'currency_code' => 'INR', 'date' => $date, 'reference_number' => $reference];
        $book('CA-1', 2350.0, '2026-10-27', "Courier nx5-link-{$orderId}");
        $book('CA-2', 900.0, '2026-10-27');
        $book('CA-3', 400.0, '2026-10-27');
        $book('CA-OLD', 700.0, '2025-06-10');

        $first = CaSyncService::importPendingExpenses('manual', null);
        self::assertSame([4, 0, 0], [$first['imported'], $first['updated'], $first['removed']]);
        $byZoho = CaExpenseRepository::allByZohoId();
        self::assertSame($orderId, (int) $byZoho['CA-1']['order_id'], 'linked by its Reference #');
        self::assertNull($byZoho['CA-2']['order_id']);

        // The CA changes one, deletes three in Books: one is matched to a bank line, one is in a locked year.
        $this->expenses['CA-1']['amount'] = 2400.0;
        unset($this->expenses['CA-2'], $this->expenses['CA-3'], $this->expenses['CA-OLD']);
        Database::connection()->exec("INSERT INTO ca_bank_statement_lines (line_hash, transaction_date, description, debit_amount, matched_expense_id) VALUES (SHA2('stage5-ca3', 256), '2026-10-27', 'DHL', 400, {$byZoho['CA-3']['id']})");
        CaFyLockRepository::lock('2025-26', $this->userId);

        $second = CaSyncService::importPendingExpenses('manual', null);

        self::assertSame([0, 1, 1], [$second['imported'], $second['updated'], $second['removed']]);
        $after = CaExpenseRepository::allByZohoId();
        self::assertSame('2400.00', (string) $after['CA-1']['amount']);
        self::assertArrayNotHasKey('CA-2', $after);
        self::assertArrayHasKey('CA-3', $after, 'matched to a bank line: kept');
        self::assertArrayHasKey('CA-OLD', $after, 'locked year: kept');
        $audit = Database::connection()->query("SELECT action_type FROM audit_log WHERE action_type IN ('CA_EXPENSE_UPDATED_FROM_ZOHO', 'CA_EXPENSE_REMOVED_FROM_ZOHO') ORDER BY id DESC LIMIT 2")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertEqualsCanonicalizing(['CA_EXPENSE_UPDATED_FROM_ZOHO', 'CA_EXPENSE_REMOVED_FROM_ZOHO'], $audit);
        $messages = $this->messages('expense_import');
        self::assertStringContainsString('matched to a bank statement line: kept here', implode("\n", $messages));
        self::assertStringContainsString('dated in FY 2025-26, which is locked: kept here', implode("\n", $messages));
        Database::connection()->exec("DELETE FROM ca_bank_statement_lines WHERE line_hash = SHA2('stage5-ca3', 256)");
    }

    public function testAWholeListGoneAtOnceRemovesNothing(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->expenses["CA-{$i}"] = ['expense_id' => "CA-{$i}", 'account_name' => 'Postage', 'description' => 'x', 'vendor_name' => null, 'amount' => 10.0 * $i, 'currency_code' => 'INR', 'date' => '2026-10-27', 'reference_number' => ''];
        }
        CaSyncService::importPendingExpenses('manual', null);
        $this->expenses = [];

        $result = CaSyncService::importPendingExpenses('manual', null);

        self::assertSame([0, 1], [$result['removed'], $result['failed']]);
        self::assertCount(12, CaExpenseRepository::allByZohoId());
    }

    public function testAZohoExpenseThatRepeatsACostEnteredInTheAppIsFlaggedNotCounted(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderCostEntryRepository::create($orderId, 'ecgc_insurance', null, 4200.0, '2026-10-29', $this->userId, 'company_account');
        foreach ([['Z-DUP', 4200.0, 'INR'], ['Z-OK', 2350.0, 'INR'], ['Z-USD', 50.0, 'USD']] as [$zid, $amount, $cur]) {
            $id = CaExpenseRepository::insert($zid, 'Expense', null, null, $amount, $cur, '2026-10-29');
            CaExpenseRepository::linkToOrder($id, $orderId);
        }

        $rows = array_column(CaExpenseRepository::forOrderCounted($orderId), null, 'zoho_expense_id');

        self::assertSame([false, 'possible duplicate of a cost entered in the app — not counted'], [$rows['Z-DUP']['counted'], $rows['Z-DUP']['reason']]);
        self::assertSame([true, null], [$rows['Z-OK']['counted'], $rows['Z-OK']['reason']]);
        self::assertSame([false, 'not in rupees — not counted'], [$rows['Z-USD']['counted'], $rows['Z-USD']['reason']]);
    }

    public function testRemovingAnOrderExpenseRemovesItFromBooksToo(): void
    {
        $o = $this->sentOrder();
        $zid = OrderExpenseRepository::find($o['expense'])['zoho_expense_id'];
        self::assertArrayHasKey($zid, $this->expenses);
        Flash::pull();

        ob_start();
        (new OrderExpenseController())->delete(['id' => (string) $o['order'], 'expenseId' => (string) $o['expense']]);
        ob_end_clean();

        self::assertArrayNotHasKey($zid, $this->expenses);
        self::assertNull(OrderExpenseRepository::find($o['expense']));
        self::assertSame('Expense removed, here and in Zoho Books.', Flash::pull()[0]['message']);
        $last = $this->messages('order_expense');
        self::assertSame('Removed expense "Documents to buyer" (INR 1800.00) from Zoho Books: it was removed in the app.', end($last));
    }

    public function testTheSyncLogLinksAnImportedExpenseToTheExpensesNotToAnOrder(): void
    {
        $log = [
            ['created_at' => '2026-10-29 10:00:00', 'triggered_by' => 'manual', 'triggered_by_name' => 'A', 'entity_type' => 'ca_expenses', 'entity_id' => 98765, 'leg' => null, 'status' => 'success', 'zoho_reference' => 'Z-1', 'message' => 'Imported'],
        ];
        $isEnabled = true;
        $startDate = null;
        $pendingCount = $heldCount = $purchasesPendingCount = $benefitsPendingCount = 0;
        ob_start();
        include __DIR__ . '/../../views/ca/zoho_sync.php';
        $html = (string) ob_get_clean();

        self::assertStringContainsString('<a href="/ca/expenses">Expense #98765</a>', $html);
        self::assertStringNotContainsString('Order #98765', $html);
    }
}
