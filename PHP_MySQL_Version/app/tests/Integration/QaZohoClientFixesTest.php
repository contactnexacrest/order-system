<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Services\CaSyncService;
use App\Services\ZohoBooksService;
use App\Services\ZohoSignInFailed;
use App\Tests\Support\DbTestCase;

/**
 * QA 2026 Zoho batch — the Books client itself, against a fake Zoho (never
 * the real one). Node twin: qaZohoClientFixes.test.js.
 *  - ZOH-001: an empty billing address goes as a JSON object ({}), never [].
 *  - ZOH-003: after a 401 and a new token, every later request of the
 *    operation uses the new token.
 *  - ZOH-004: a refused refresh token stops at the first refusal with a
 *    sign-in message — never one token request per record, never "rate limit".
 */
final class QaZohoClientFixesTest extends DbTestCase
{
    /** @var list<array{method:string, url:string, payload:array, headers:array, body:string}> */
    private array $requests = [];
    /** @var callable(string, string, array, array): ?array */
    private $override;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        foreach ([
            'zoho_books_enabled' => '1', 'zoho_books_client_id' => 'fake-client', 'zoho_books_client_secret' => 'fake-secret',
            'zoho_books_refresh_token' => 'fake-refresh', 'zoho_books_organization_id' => '600', 'zoho_books_deposit_account_id' => 'SBI-1',
            'zoho_sync_clients' => '0', 'zoho_sync_products' => '0',
        ] as $k => $v) {
            CompanySettingsRepository::set($k, $v);
        }
        $this->override = static fn (): ?array => null;
        ZohoBooksService::setTransport(fn (string $method, string $url, array $payload, array $headers = []): array => $this->fakeZoho($method, $url, $payload, $headers));
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT']);
        foreach (['zoho_books_enabled', 'zoho_sync_clients', 'zoho_sync_products'] as $k) {
            CompanySettingsRepository::set($k, '0');
        }
        parent::tearDown();
    }

    /** @return array{status:int, body:string} */
    private function fakeZoho(string $method, string $url, array $payload, array $headers): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload, 'headers' => $headers, 'body' => (string) json_encode($payload)];
        $special = ($this->override)($method, $url, $payload, $headers);
        if ($special !== null) {
            return $special;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $json = static fn (array $b, int $s = 200): array => ['status' => $s, 'body' => (string) json_encode($b)];
        if ($path === '/oauth/v2/token') {
            return $json(['access_token' => 'fake-access']);
        }
        if (preg_match('#^/books/v3/organizations/(\d+)$#', $path, $m) === 1) {
            return $json(['code' => 0, 'organization' => ['organization_id' => $m[1], 'is_registered_for_gst' => true, 'tax_settings' => ['tax_reg_no' => '29AAAAA0000A1ZY']]]);
        }
        if ($path === '/books/v3/settings/currencies') {
            return $json(['code' => 0, 'currencies' => [['currency_id' => 'CUR-USD', 'currency_code' => 'USD']]]);
        }
        if ($path === '/books/v3/contacts' && $method === 'GET') {
            return $json(['code' => 0, 'contacts' => []]);
        }
        if ($path === '/books/v3/contacts' && $method === 'POST') {
            return $json(['code' => 0, 'contact' => ['contact_id' => 'CT-1', 'contact_name' => $payload['contact_name']]], 201);
        }
        return $json(['code' => 0, 'message' => 'not faked: ' . $method . ' ' . $path]);
    }

    private function tokenRequests(): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], '/oauth/v2/token')));
    }

    private static function client(string $name): array
    {
        return [
            'company_legal_name' => $name, 'billing_address' => 'Free-text address only',
            'billing_address_line1' => null, 'billing_address_line2' => null, 'billing_city' => null, 'billing_postcode' => null, 'billing_country' => null,
            'contact_person' => 'Project Buyer', 'email' => 'buyer@email.com', 'phone' => '+91-0000000000', 'vat_eori_tax_no' => null,
        ];
    }

    public function testZoh001EmptyBillingAddressIsSentAsJsonObject(): void
    {
        self::assertSame('{}', json_encode(ZohoBooksService::customerPayload(self::client('TEST-ZOH001 Buyer'), true)['billing_address']));
        ZohoBooksService::pushCustomerContact(self::client('TEST-ZOH001 Buyer'), null, 'USD');
        ZohoBooksService::pushCustomerContact(self::client('TEST-ZOH001 Buyer'), 'CT-1', 'USD');
        $writes = array_values(array_filter($this->requests, static fn (array $r): bool => in_array($r['method'], ['POST', 'PUT'], true) && preg_match('#/books/v3/contacts(/CT-1)?$#', (string) parse_url($r['url'], PHP_URL_PATH)) === 1));
        self::assertCount(2, $writes);
        foreach ($writes as $w) {
            self::assertStringContainsString('"billing_address":{}', $w['body']);
            self::assertStringNotContainsString('"billing_address":[]', $w['body']);
        }
        // A client with a structured address still sends its fields.
        $full = self::client('TEST-ZOH001 Full') + [];
        $full['billing_address_line1'] = '1 Harbour Road';
        $full['billing_city'] = 'Rotterdam';
        self::assertSame('{"address":"1 Harbour Road","city":"Rotterdam"}', json_encode(ZohoBooksService::customerPayload($full, true)['billing_address']));
    }

    public function testZoh003AfterA401EveryLaterRequestUsesTheNewToken(): void
    {
        $issued = 0;
        $this->override = function (string $method, string $url, array $payload, array $headers) use (&$issued): ?array {
            if (str_contains($url, '/oauth/v2/token')) {
                $issued++;
                return ['status' => 200, 'body' => (string) json_encode(['access_token' => $issued === 1 ? 'tok-old' : 'tok-new'])];
            }
            if (in_array('Authorization: Zoho-oauthtoken tok-old', $headers, true)) {
                return ['status' => 401, 'body' => (string) json_encode(['code' => 57, 'message' => 'You are not authorized to perform this operation'])];
            }
            return null;
        };
        // One operation, four requests on one token: organisation, contacts lookup, currencies, create.
        $r = ZohoBooksService::pushCustomerContact(self::client('TEST-ZOH003 Buyer'), null, 'USD');
        self::assertSame('created', $r['action']);
        self::assertSame(2, $issued, 'one refused token -> exactly one new token');
        $api = array_values(array_filter($this->requests, static fn (array $r): bool => !str_contains($r['url'], '/oauth/v2/token')));
        $old = array_filter($api, static fn (array $r): bool => in_array('Authorization: Zoho-oauthtoken tok-old', $r['headers'], true));
        self::assertCount(1, $old, 'only the first request goes with the refused token');
    }

    public function testZoh004RefusedRefreshTokenStopsAtTheFirstRefusalWithASignInMessage(): void
    {
        $this->override = static fn (string $method, string $url): ?array => str_contains($url, '/oauth/v2/token')
            ? ['status' => 200, 'body' => '{"error":"invalid_code"}'] : null;
        try {
            ZohoBooksService::pushCustomerContact(self::client('TEST-ZOH004 Buyer'), null, 'USD');
            self::fail('expected a sign-in stop');
        } catch (ZohoSignInFailed $e) {
            self::assertStringContainsString('Zoho sign-in failed', $e->getMessage());
            self::assertStringContainsString('invalid_code', $e->getMessage());
            self::assertStringNotContainsString('rate limit', $e->getMessage());
        }
        try {
            ZohoBooksService::pushCustomerContact(self::client('TEST-ZOH004 Buyer'), null, 'USD');
            self::fail('expected a sign-in stop');
        } catch (ZohoSignInFailed) {
        }
        self::assertCount(1, $this->tokenRequests(), 'Zoho is asked for a token once, not once per record');

        // A whole sync stops at once with the sign-in reason in the Sync Log.
        $pdo = Database::connection();
        CompanySettingsRepository::set('zoho_sync_clients', '1');
        $clientId = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET company_legal_name = 'TEST-ZOH004 Pending Client' WHERE id = :id")->execute(['id' => $clientId]);
        $this->createTestOrder($clientId, 'FOB', 'Standard — New Buyer', 'USD');
        $before =(int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM zoho_sync_log')->fetchColumn();
        $result = CaSyncService::runFullSync('manual', null);
        self::assertNotNull($result['stopped']);
        self::assertStringContainsString('Zoho sign-in failed', (string) $result['stopped']);
        self::assertCount(1, $this->tokenRequests());
        $stmt = $pdo->prepare("SELECT message FROM zoho_sync_log WHERE id > :id AND sync_type = 'sync_run'");
        $stmt->execute(['id' => $before]);
        self::assertStringContainsString('Zoho sign-in failed', (string) $stmt->fetchColumn());
    }
}
