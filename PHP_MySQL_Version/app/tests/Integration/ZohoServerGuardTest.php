<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\SettingsController;
use App\Repositories\CaRepository;
use App\Repositories\CompanySettingsRepository;
use App\Services\CaSyncService;
use App\Services\ZohoBooksService;
use App\Services\ZohoGuard;
use App\Services\ZohoMailService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions 2026-10-29 — the app works with and without Zoho, and
 * nothing from development or testing may ever reach the GST portal or any
 * other government portal. The server-level lock lives in .env
 * (ZOHO_INTEGRATION_ENABLED, ZOHO_ENVIRONMENT, ZOHO_EINVOICE_ENABLED,
 * always written out); Zoho Mail is switched off in code (mail goes
 * through Bluehost SMTP). Every request goes to a fake Zoho that records
 * it, so "nothing was sent" is checked, not assumed. The Node suite has
 * the same test (zohoServerGuard.test.js).
 */
final class ZohoServerGuardTest extends DbTestCase
{
    /** @var list<string> */
    private array $urls = [];
    /** @var list<int> */
    private array $flagged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        foreach (['zoho_books_enabled' => '1', 'zoho_books_client_id' => 'fake-client', 'zoho_books_client_secret' => 'fake-secret', 'zoho_books_refresh_token' => 'fake-refresh', 'zoho_books_organization_id' => '600', 'zoho_books_deposit_account_id' => 'SBI-1'] as $key => $value) {
            CompanySettingsRepository::set($key, $value);
        }
        ZohoBooksService::setTransport(function (string $method, string $url): array {
            $this->urls[] = "{$method} {$url}";
            return ['status' => 200, 'body' => (string) json_encode(['access_token' => 'fake', 'organizations' => [], 'code' => 0])];
        });
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        CompanySettingsRepository::set('zoho_books_enabled', '0');
        CompanySettingsRepository::set('zoho_mail_enabled', '0');
        // Test/sample-flagged orders would keep Test Mode from being
        // switched off in every later test sharing this database.
        foreach ($this->flagged as $orderId) {
            Database::connection()->prepare('UPDATE orders SET is_test_data = 0, is_sample_data = 0 WHERE id = :id')->execute(['id' => $orderId]);
        }
        parent::tearDown();
    }

    private function server(string $integration, string $environment, string $einvoice): void
    {
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = $integration;
        $_ENV['ZOHO_ENVIRONMENT'] = $environment;
        $_ENV['ZOHO_EINVOICE_ENABLED'] = $einvoice;
    }

    /** A cleared advance with its INR actual, waiting to go to Zoho. */
    private function clearedAdvance(string $buyerName, bool $test = false, bool $sample = false): int
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $pdo = Database::connection();
        $pdo->prepare('UPDATE orders SET company_legal_name = :name, is_test_data = :t, is_sample_data = :s WHERE id = :id')
            ->execute(['name' => $buyerName, 't' => (int) $test, 's' => (int) $sample, 'id' => $orderId]);
        if ($test || $sample) {
            $this->flagged[] = $orderId;
        }
        $pdo->prepare('INSERT INTO order_payment_status (order_id, advance_amount, advance_cleared_at, advance_inr_actual) VALUES (:id, 100, NOW(), 8400)')
            ->execute(['id' => $orderId]);
        return $orderId;
    }

    /** @return list<int> order ids among the legs this server would send */
    private function pendingOrderIds(array $orderIds): array
    {
        return array_values(array_intersect(array_map(static fn (array $l): int => (int) $l['order_id'], CaRepository::legsPendingZohoSync()), $orderIds));
    }

    public function testEnvExampleWritesOutAllThreeVariablesWithTheSafeValues(): void
    {
        $example = (string) file_get_contents(__DIR__ . '/../../.env.example');
        self::assertMatchesRegularExpression('/^ZOHO_INTEGRATION_ENABLED=false$/m', $example);
        self::assertMatchesRegularExpression('/^ZOHO_ENVIRONMENT=test$/m', $example);
        self::assertMatchesRegularExpression('/^ZOHO_EINVOICE_ENABLED=false$/m', $example);
    }

    public function testMissingOrUnknownValuesAreReadAsTheSafeChoice(): void
    {
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        $missing = ZohoGuard::missingVariables();
        if ($missing === ZohoGuard::VARIABLES) {
            self::assertFalse(ZohoGuard::integrationEnabled());
            self::assertSame('test', ZohoGuard::environment());
            self::assertFalse(ZohoGuard::governmentFeaturesAllowed());
        }
        $this->server('yes', 'live', 'TRUE');
        self::assertFalse(ZohoGuard::integrationEnabled(), 'only the word true switches Zoho on');
        self::assertSame('test', ZohoGuard::environment(), 'anything but production is test');
    }

    public function testWithZohoOffNothingIsSentAndTheSyncSaysWhy(): void
    {
        $this->server('false', 'production', 'true');

        self::assertFalse(ZohoBooksService::isEnabled(), 'the Settings tick alone does not switch Zoho on');
        try {
            ZohoBooksService::testConnection();
            self::fail('the connection test must refuse');
        } catch (\RuntimeException $e) {
            self::assertSame('Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED is not true in .env).', $e->getMessage());
        }
        $result = CaSyncService::runFullSync('manual', null);
        self::assertSame(1, $result['revenue']['skipped']);
        $log = Database::connection()->query("SELECT message FROM zoho_sync_log WHERE sync_type = 'revenue_payment' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertSame('Zoho is switched off on this server (ZOHO_INTEGRATION_ENABLED in .env) — nothing synced.', $log);
        self::assertSame([], $this->urls, 'no request may leave the server');
    }

    public function testGovernmentRequestsAreBlockedUnlessProductionWithEinvoiceOn(): void
    {
        foreach (['/books/v3/invoices/1/einvoice/push', '/books/v3/invoices/1/ewaybills', '/books/v3/irn/generate', '/books/v3/gstr1/file', '/books/v3/e-invoice', '/books/v3/gst-filing'] as $path) {
            self::assertTrue(ZohoGuard::isGovernmentUrl("https://www.zohoapis.in{$path}?organization_id=600"), $path);
        }
        foreach (['/books/v3/contacts', '/books/v3/customerpayments', '/books/v3/invoices', '/books/v3/settings/currencies', '/oauth/v2/token'] as $path) {
            self::assertFalse(ZohoGuard::isGovernmentUrl("https://www.zohoapis.in{$path}"), $path);
        }

        $url = 'https://www.zohoapis.in/books/v3/invoices/1/einvoice/push';
        foreach ([['true', 'test', 'true'], ['true', 'production', 'false'], ['false', 'production', 'true']] as [$on, $environment, $einvoice]) {
            $this->server($on, $environment, $einvoice);
            try {
                ZohoGuard::assertRequestAllowed($url);
                self::fail("must be blocked with {$on}/{$environment}/{$einvoice}");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($on === 'true' ? 'Blocked: GST e-invoice' : 'switched off', $e->getMessage());
            }
        }
        $this->server('true', 'production', 'true');
        ZohoGuard::assertRequestAllowed($url);
        self::assertTrue(ZohoGuard::governmentFeaturesAllowed(), 'only production with ZOHO_EINVOICE_ENABLED=true');
    }

    public function testInTestOnlyTestCustomersMayBeSent(): void
    {
        $this->server('true', 'test', 'false');

        try {
            ZohoBooksService::pushRevenuePayment($this->createTestClient(), 'Real Buyer GmbH', 'USD', 100.0, 8400.0, '2026-10-29', 'NX-1-advance');
            self::fail('a real customer must not be sent from test');
        } catch (\RuntimeException $e) {
            self::assertSame('Not sent: ZOHO_ENVIRONMENT is test, and only customers whose name starts with TEST- may go to Zoho (this one is "Real Buyer GmbH").', $e->getMessage());
        }
        self::assertSame([], $this->urls);

        $test = $this->clearedAdvance('TEST-Sync Buyer');
        $real = $this->clearedAdvance('Real Buyer GmbH');
        self::assertSame([$test], $this->pendingOrderIds([$test, $real]));
    }

    public function testInProductionTestAndSampleOrdersAreNeverSent(): void
    {
        $this->server('true', 'production', 'false');

        $real = $this->clearedAdvance('Real Buyer GmbH');
        $test = $this->clearedAdvance('Real Buyer GmbH', test: true);
        $sample = $this->clearedAdvance('Real Buyer GmbH', sample: true);
        self::assertSame([$real], $this->pendingOrderIds([$real, $test, $sample]));
    }

    public function testZohoMailIsNotUsedAndIsHiddenFromSettings(): void
    {
        CompanySettingsRepository::set('zoho_mail_enabled', '1');
        self::assertFalse(ZohoMailService::IN_USE);
        self::assertFalse(ZohoMailService::isEnabled(), 'ticking zoho_mail_enabled does not switch it on');

        $_SESSION['_auth_user_id'] = $this->createTestUser('Admin');
        $this->server('false', 'test', 'false');
        ob_start();
        (new SettingsController())->index([]);
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('Test Zoho Mail Connection', $html);
        self::assertStringNotContainsString('name="zoho_client_secret"', $html);
        self::assertStringContainsString('Zoho integration <strong>OFF</strong>', $html);
        self::assertStringContainsString('GST e-invoice / e-way bill / GST filing <strong>blocked</strong>', $html);

        $_POST = ['test_to' => 'someone@example.com'];
        ob_start();
        (new SettingsController())->testZohoEmail([]);
        ob_end_clean();
        $last = end($_SESSION['_flash']);
        self::assertSame('error', $last['type']);
        self::assertSame('Zoho Mail is not used by this application — all mail goes through the SMTP server in .env (Bluehost).', $last['message']);
    }
}
