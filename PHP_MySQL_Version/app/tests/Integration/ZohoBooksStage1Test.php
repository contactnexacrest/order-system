<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\SettingsController;
use App\Repositories\ClientRepository;
use App\Repositories\CompanySettingsRepository;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Zoho integration Stage 1 (owner decisions 2026-10-29): zoho.in by
 * default, buyers created in Books as "overseas" customers in the order's
 * currency, payments in the foreign amount at the actual rate, the "Test
 * Books connection" button, and the cleanup of TEST- records. Every
 * request goes to a fake Zoho (ZohoBooksService::setTransport) — the
 * tests never reach the real one. The Node suite has the same test
 * (zohoBooksStage1.test.js).
 */
final class ZohoBooksStage1Test extends DbTestCase
{
    private const SETTINGS = [
        'zoho_books_enabled' => '1',
        'zoho_books_client_id' => 'fake-client',
        'zoho_books_client_secret' => 'fake-secret',
        'zoho_books_refresh_token' => 'fake-refresh',
        'zoho_books_organization_id' => '600',
        'zoho_books_deposit_account_id' => 'SBI-1',
    ];

    /** @var list<array{method: string, url: string, payload: array}> */
    private array $requests = [];
    /** @var array<string, mixed> contacts the fake Zoho knows, by id */
    private array $contacts = [];
    private array $currencies = [['currency_id' => 'C-USD', 'currency_code' => 'USD'], ['currency_id' => 'C-EUR', 'currency_code' => 'EUR'], ['currency_id' => 'C-INR', 'currency_code' => 'INR']];
    private string $organizationId = '600';
    private bool $gstRegistered = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        // The server-level lock (.env, ZohoGuard): on, in test — so only
        // TEST- customers may be sent.
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        $_ENV['ZOHO_EINVOICE_ENABLED'] = 'false';
        foreach (self::SETTINGS as $key => $value) {
            CompanySettingsRepository::set($key, $value);
        }
        ZohoBooksService::setTransport(fn (string $method, string $url, array $payload): array => $this->fakeZoho($method, $url, $payload));
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        // The credential settings are protected (cannot be blanked once
        // filled), so the fake values stay; switching Books off is enough
        // for every other test.
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
        if ($path === '/books/v3/organizations') {
            return $json(['code' => 0, 'organizations' => [['organization_id' => $this->organizationId, 'name' => 'NexaCrest TEST Org']]]);
        }
        if (preg_match('#^/books/v3/organizations/(\d+)$#', $path, $m) === 1) {
            return $json(['code' => 0, 'organization' => ['organization_id' => $m[1], 'is_registered_for_gst' => $this->gstRegistered]]);
        }
        if ($path === '/books/v3/invoices' && $method === 'GET') {
            return $json(['code' => 0, 'invoices' => []]);
        }
        if ($path === '/books/v3/bankaccounts') {
            return $json(['code' => 0, 'bankaccounts' => [['account_id' => 'SBI-1', 'account_name' => 'SBI Bank - Current Account']]]);
        }
        if ($path === '/books/v3/settings/currencies') {
            return $json(['code' => 0, 'currencies' => $this->currencies]);
        }
        if ($path === '/books/v3/contacts' && $method === 'GET') {
            $matches = array_values(array_filter($this->contacts, static fn (array $c): bool => isset($query['contact_name']) ? $c['contact_name'] === $query['contact_name'] : str_starts_with($c['contact_name'], (string) ($query['contact_name_startswith'] ?? ''))));
            return $json(['code' => 0, 'contacts' => $matches]);
        }
        if ($path === '/books/v3/contacts' && $method === 'POST') {
            $id = 'CT-' . (count($this->contacts) + 1);
            $currency = array_values(array_filter($this->currencies, static fn (array $c): bool => $c['currency_id'] === $payload['currency_id']))[0]['currency_code'];
            $this->contacts[$id] = ['contact_id' => $id, 'contact_name' => $payload['contact_name'], 'currency_code' => $currency];
            return $json(['code' => 0, 'contact' => $this->contacts[$id]], 201);
        }
        if (preg_match('#^/books/v3/contacts/(.+)$#', $path, $m) === 1) {
            if ($method === 'DELETE') {
                return isset($this->contacts[$m[1]]) ? $json(['code' => 0]) : $json(['code' => 1002, 'message' => 'not found'], 404);
            }
            return $json(['code' => 0, 'contact' => $this->contacts[$m[1]]]);
        }
        if ($path === '/books/v3/customerpayments' && $method === 'POST') {
            return $json(['code' => 0, 'payment' => ['payment_id' => 'PAY-1']], 201);
        }
        if ($path === '/books/v3/customerpayments' && $method === 'GET') {
            return $json(['code' => 0, 'customerpayments' => [['payment_id' => 'PAY-9', 'reference_number' => 'TEST-REF', 'currency_code' => 'USD', 'amount' => 100, 'date' => '2026-10-29']]]);
        }
        if (preg_match('#^/books/v3/customerpayments/#', $path) === 1) {
            return $json(['code' => 0]);
        }
        return $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400);
    }

    /** @return list<array{method: string, url: string, payload: array}> */
    private function requestsTo(string $method, string $pathPrefix): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_starts_with((string) parse_url($r['url'], PHP_URL_PATH), $pathPrefix)));
    }

    public function testTheSeededDomainsAreZohoIndia(): void
    {
        ZohoBooksService::testConnection();

        self::assertSame('accounts.zoho.in', parse_url($this->requestsTo('POST', '/oauth/v2/token')[0]['url'], PHP_URL_HOST));
        self::assertSame('www.zohoapis.in', parse_url($this->requestsTo('GET', '/books/v3/organizations')[0]['url'], PHP_URL_HOST));
    }

    public function testSeedDefaultsAreZohoIndia(): void
    {
        $seed = (string) file_get_contents(__DIR__ . '/../../../docs/seed.sql');
        self::assertStringContainsString("('zoho_books_accounts_domain',    'accounts.zoho.in'", $seed);
        self::assertStringContainsString("('zoho_books_api_domain',         'www.zohoapis.in'", $seed);
        self::assertStringContainsString("('zoho_accounts_domain', 'accounts.zoho.in'", $seed);
        self::assertStringContainsString("('zoho_api_domain',      'mail.zoho.in'", $seed);
        // The e-invoice switch lives in .env (ZOHO_EINVOICE_ENABLED), never in Admin -> Settings.
        self::assertStringNotContainsString('einvoice', $seed);
    }

    public function testTheConnectionTestReportsTheOrganisationTheDepositAccountAndMissingCurrencies(): void
    {
        $result = ZohoBooksService::testConnection();

        self::assertSame('NexaCrest TEST Org', $result['organization']);
        self::assertSame('SBI Bank - Current Account', $result['deposit_account']);
        self::assertSame(['GBP'], $result['missing_currencies']);
        self::assertSame([], $this->requestsTo('POST', '/books/'), 'the connection test only reads');
    }

    public function testTheConnectionTestNamesAnUnknownOrganisation(): void
    {
        $this->organizationId = '999';

        $this->expectExceptionMessage('Organization ID 600 was not found. This Zoho login can see: NexaCrest TEST Org (999).');
        ZohoBooksService::testConnection();
    }

    public function testTheSettingsButtonShowsTheResultAndIsAuditLogged(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;

        ob_start();
        (new SettingsController())->testZohoBooks([]);
        ob_end_clean();

        $last = end($_SESSION['_flash']);
        self::assertSame('warning', $last['type']);
        self::assertSame('Connected to Zoho Books: NexaCrest TEST Org. Payments are deposited into SBI Bank - Current Account. Turn on these currencies in Zoho Books -> Settings -> Currencies: GBP.', $last['message']);
        $log = Database::connection()->query("SELECT * FROM audit_log WHERE action_type = 'SETTINGS_TEST_ZOHO_BOOKS_CONNECTED' ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame($userId, (int) $log['user_id']);
    }

    public function testTheSettingsButtonShowsTheRealErrorWhenNotConfigured(): void
    {
        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $pdo = Database::connection();
        $pdo->exec("UPDATE company_settings SET is_protected = 0 WHERE setting_key = 'zoho_books_refresh_token'");
        $pdo->exec("UPDATE company_settings SET setting_value = '' WHERE setting_key = 'zoho_books_refresh_token'");

        try {
            ob_start();
            (new SettingsController())->testZohoBooks([]);
            ob_end_clean();
        } finally {
            $pdo->exec("UPDATE company_settings SET setting_value = 'fake-refresh', is_protected = 1 WHERE setting_key = 'zoho_books_refresh_token'");
        }

        $last = end($_SESSION['_flash']);
        self::assertSame('error', $last['type']);
        self::assertStringContainsString('Zoho Books connection failed: Zoho Books is enabled but not fully configured', $last['message']);
    }

    public function testAForeignCurrencyPaymentCreatesAnOverseasCustomerAndBooksTheForeignAmountAtTheActualRate(): void
    {
        $clientId = $this->createTestClient();

        $result = ZohoBooksService::pushRevenuePayment($clientId, 'TEST-Overseas Buyer', 'USD', 1800.0, 151020.0, '2026-10-29', 'NX-1-advance');

        self::assertSame(['payment_id' => 'PAY-1', 'applied' => 0.0], $result);
        $contact = $this->requestsTo('POST', '/books/v3/contacts')[0]['payload'];
        self::assertSame(['contact_name' => 'TEST-Overseas Buyer', 'company_name' => 'TEST-Overseas Buyer', 'contact_type' => 'customer', 'currency_id' => 'C-USD', 'gst_treatment' => 'overseas'], $contact);
        $payment = $this->requestsTo('POST', '/books/v3/customerpayments')[0]['payload'];
        self::assertSame(1800.0, $payment['amount']);
        self::assertSame(83.9, $payment['exchange_rate']);
        self::assertSame('SBI-1', $payment['account_id']);
        self::assertSame('CT-1', ClientRepository::find($clientId)['zoho_contact_id']);
    }

    public function testABooksOrganisationNotRegisteredForGstGetsTheCustomerWithoutGstTreatment(): void
    {
        // Seen in the test organisation (change log Z-025): an organisation
        // that is not GST-registered rejects gst_treatment.
        $this->gstRegistered = false;

        ZohoBooksService::pushRevenuePayment($this->createTestClient(), 'TEST-Plain Buyer', 'USD', 100.0, 8400.0, '2026-10-29', 'NX-7-advance');

        self::assertSame(['contact_name' => 'TEST-Plain Buyer', 'company_name' => 'TEST-Plain Buyer', 'contact_type' => 'customer', 'currency_id' => 'C-USD'], $this->requestsTo('POST', '/books/v3/contacts')[0]['payload']);
    }

    public function testOneAccessTokenServesARun(): void
    {
        ZohoBooksService::pushRevenuePayment($this->createTestClient(), 'TEST-Token Buyer A', 'USD', 100.0, 8400.0, '2026-10-29', 'NX-8-advance');
        ZohoBooksService::pushRevenuePayment($this->createTestClient(), 'TEST-Token Buyer B', 'USD', 100.0, 8400.0, '2026-10-29', 'NX-9-advance');

        self::assertCount(1, $this->requestsTo('POST', '/oauth/v2/token'), 'Zoho limits how many access tokens are issued');
    }

    public function testAnInrPaymentGoesInAsRupeesWithoutARate(): void
    {
        ZohoBooksService::pushRevenuePayment($this->createTestClient(), 'TEST-Rupee Buyer', 'INR', null, 50000.0, '2026-10-29', 'NX-2-advance');

        $payment = $this->requestsTo('POST', '/books/v3/customerpayments')[0]['payload'];
        self::assertSame(50000.0, $payment['amount']);
        self::assertArrayNotHasKey('exchange_rate', $payment);
    }

    public function testACurrencyBooksHasNotTurnedOnIsRefusedWithWhereToAddIt(): void
    {
        $this->expectExceptionMessage('Currency GBP is not turned on in Zoho Books. Add it in Zoho Books -> Settings -> Currencies, then sync again.');
        ZohoBooksService::pushRevenuePayment($this->createTestClient(), 'TEST-Pound Buyer', 'GBP', 100.0, 11000.0, '2026-10-29', 'NX-3-advance');
    }

    public function testACustomerAlreadyInBooksInAnotherCurrencyIsRefused(): void
    {
        $clientId = $this->createTestClient();
        ZohoBooksService::pushRevenuePayment($clientId, 'TEST-Dollar Buyer', 'USD', 100.0, 8400.0, '2026-10-29', 'NX-4-advance');

        try {
            ZohoBooksService::pushRevenuePayment($clientId, 'TEST-Dollar Buyer', 'EUR', 100.0, 9100.0, '2026-10-29', 'NX-5-advance');
            self::fail('a EUR payment must not be booked against a USD customer');
        } catch (\RuntimeException $e) {
            self::assertSame('TEST-Dollar Buyer is set up in Zoho Books in USD, but this payment is in EUR. A Zoho Books customer has one currency — ask the CA how to book it.', $e->getMessage());
        }
        self::assertCount(1, $this->requestsTo('POST', '/books/v3/customerpayments'));
    }

    public function testAForeignPaymentWithoutItsForeignAmountIsRefused(): void
    {
        $this->expectExceptionMessage('Payment NX-6-balance has no USD amount recorded, so its rate cannot be worked out.');
        ZohoBooksService::pushRevenuePayment($this->createTestClient(), 'TEST-No Amount Buyer', 'USD', null, 8400.0, '2026-10-29', 'NX-6-balance');
    }

    public function testCleanupListsTestCustomersWithTheirPaymentsAndTreatsAMissingRecordAsGone(): void
    {
        $this->contacts['CT-7'] = ['contact_id' => 'CT-7', 'contact_name' => 'TEST-Cleanup Buyer', 'currency_code' => 'USD'];
        $this->contacts['CT-8'] = ['contact_id' => 'CT-8', 'contact_name' => 'Real Buyer', 'currency_code' => 'USD'];

        $list = ZohoBooksService::listTestContacts();

        self::assertSame([['contact_id' => 'CT-7', 'name' => 'TEST-Cleanup Buyer', 'payments' => [['payment_id' => 'PAY-9', 'label' => 'TEST-REF USD 100 on 2026-10-29']], 'invoices' => []]], $list);
        ZohoBooksService::deleteRecord('customerpayment', 'PAY-9');
        ZohoBooksService::deleteRecord('contact', 'CT-7');
        ZohoBooksService::deleteRecord('contact', 'CT-404');
        self::assertSame(['/books/v3/customerpayments/PAY-9', '/books/v3/contacts/CT-7', '/books/v3/contacts/CT-404'], array_map(static fn (array $r): string => (string) parse_url($r['url'], PHP_URL_PATH), $this->requestsTo('DELETE', '/books/')));
    }
}
