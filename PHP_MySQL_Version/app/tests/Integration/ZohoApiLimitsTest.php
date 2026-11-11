<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Services\CaSyncService;
use App\Services\ZohoApiBudget;
use App\Services\ZohoBooksService;
use App\Services\ZohoPendingService;
use App\Services\ZohoRateLimited;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2 Nov 2026 (D-239, "Zoho limits yes"): this app's share of
 * the Zoho API calls of the organisation — a call meter, a daily and a
 * per-minute allowance, an immediate stop on Zoho's "too many requests", and
 * one access token shared by every process. Every request goes to a fake
 * Zoho — never the real one. Node twin: zohoApiLimits.test.js.
 */
final class ZohoApiLimitsTest extends DbTestCase
{
    private array $requests = [];
    /** @var list<callable(string,string,array):?array> answers tried before the normal fake */
    private array $overrides = [];
    private int $tokenNo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        foreach ([
            'zoho_books_enabled' => '1', 'zoho_books_client_id' => 'fake-client', 'zoho_books_client_secret' => 'fake-secret',
            'zoho_books_refresh_token' => 'fake-refresh', 'zoho_books_organization_id' => '600', 'zoho_books_deposit_account_id' => 'SBI-1',
            'zoho_sync_clients' => '0', 'zoho_sync_products' => '1', 'zoho_api_daily_limit' => '800', 'zoho_api_minute_limit' => '30',
        ] as $k => $v) {
            CompanySettingsRepository::set($k, $v);
        }
        ZohoBooksService::setTransport(fn (string $method, string $url, array $payload): array => $this->fakeZoho($method, $url, $payload));
        ZohoApiBudget::useSettingLimits(); // this test checks the real limits (other tests' fake Zoho is unlimited)
        $pdo = Database::connection();
        $pdo->exec("UPDATE clients SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
        $pdo->exec("UPDATE catalog_products SET name = CONCAT('OLD-', name) WHERE name LIKE 'TEST-%'");
        $pdo->exec('UPDATE catalog_products SET is_active = 0 WHERE zoho_item_id IS NULL');
        $pdo->exec('DELETE FROM zoho_pending_inactivations');
    }

    protected function tearDown(): void
    {
        ZohoApiBudget::setClock(null);
        ZohoApiBudget::setSleeper(null);
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT']);
        foreach (['zoho_books_enabled' => '0', 'zoho_sync_clients' => '0', 'zoho_sync_products' => '0', 'zoho_api_daily_limit' => '800', 'zoho_api_minute_limit' => '30'] as $k => $v) {
            CompanySettingsRepository::set($k, $v);
        }
        parent::tearDown();
    }

    /** @return array{status:int, body:string} */
    private function fakeZoho(string $method, string $url, array $payload): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload];
        foreach ($this->overrides as $o) {
            $answer = $o($method, $url, $payload);
            if ($answer !== null) {
                return $answer;
            }
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $json = static fn (array $b, int $s = 200): array => ['status' => $s, 'body' => (string) json_encode($b)];
        if ($path === '/oauth/v2/token') {
            return $json(['access_token' => 'fake-access-' . (++$this->tokenNo)]);
        }
        if ($path === '/books/v3/organizations') {
            return $json(['code' => 0, 'organizations' => [['organization_id' => '600', 'name' => 'TEST org', 'currency_code' => 'INR']]]);
        }
        if (preg_match('#^/books/v3/organizations/(\d+)$#', $path, $m) === 1) {
            return $json(['code' => 0, 'organization' => ['organization_id' => $m[1], 'is_registered_for_gst' => true, 'tax_settings' => ['tax_reg_no' => '29AAAAA0000A1ZY']]]);
        }
        if ($path === '/books/v3/items' && $method === 'GET') {
            return $json(['code' => 0, 'items' => []]);
        }
        if ($path === '/books/v3/items' && $method === 'POST') {
            return $json(['code' => 0, 'item' => ['item_id' => 'IT-' . count($this->requests), 'name' => $payload['name'], 'status' => 'active']], 201);
        }
        return $json(['code' => 0, 'message' => 'not faked']);
    }

    private function product(string $name): int
    {
        Database::connection()->prepare("INSERT INTO catalog_products (name, specifications, hs_code, is_active) VALUES (:n, 'Granite', '68022310', 1)")->execute(['n' => $name]);
        return (int) Database::connection()->lastInsertId();
    }

    private function calls(string $method, string $pathPart): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_contains($r['url'], $pathPart)));
    }

    /** A token + the organisation read (what follows in testConnection() is not faked here). */
    private function connect(): void
    {
        try {
            ZohoBooksService::testConnection();
        } catch (\RuntimeException $e) {
            if ($e instanceof \App\Services\ZohoSyncStop || !str_contains($e->getMessage(), 'bankaccounts')) {
                throw $e;
            }
        }
    }

    private function lastLog(): ?string
    {
        $m = Database::connection()->query("SELECT message FROM zoho_sync_log WHERE sync_type = 'sync_run' ORDER BY id DESC LIMIT 1")->fetchColumn();
        return $m === false ? null : (string) $m;
    }

    public function testEveryCallIsCountedIncludingTheLoginToken(): void
    {
        $this->product('TEST-Meter Slab');
        $before = ZohoApiBudget::usage()['today'];
        CaSyncService::runFullSync('manual', null);
        self::assertNotEmpty($this->calls('POST', '/oauth/v2/token'), 'the login token is a Zoho call too');
        self::assertNotEmpty($this->calls('POST', '/books/v3/items'));
        $usage = ZohoApiBudget::usage();
        self::assertSame($before + count($this->requests), $usage['today'], 'every request counted once');
        self::assertSame(800, $usage['daily_limit']);
        self::assertSame(30, $usage['minute_limit']);
    }

    public function testTheDailyAllowanceStopsTheRunBeforeAnyCall(): void
    {
        CompanySettingsRepository::set('zoho_api_daily_limit', '5');
        Database::connection()->prepare("INSERT INTO zoho_api_usage (environment, bucket_type, bucket, calls) VALUES ('test', 'day', :d, 5)")
            ->execute(['d' => ZohoApiBudget::dayKey()]);
        $this->product('TEST-Over Budget');
        $r = CaSyncService::runFullSync('manual', null);
        self::assertSame([], $this->requests, 'no call is made once the day is used up');
        self::assertSame('Paused: daily Zoho allowance for this app (5 calls) used — resumes tomorrow.', $r['stopped']);
        self::assertSame($r['stopped'], $this->lastLog());
        self::assertTrue(ZohoApiBudget::usage()['exhausted']);
        self::assertSame('paused — daily Zoho allowance used, resumes tomorrow', ZohoPendingService::pausedText());
    }

    public function testTheMinuteLimitWaitsInTheBackgroundAndRefusesQuicklyForAPerson(): void
    {
        ZohoApiBudget::setTestLimits(1000, 2);
        $now = strtotime('2026-11-02 10:00:30 UTC'); // 15:30:30 IST — 31 s to the next minute
        ZohoApiBudget::setClock(static function () use (&$now): int {
            return $now;
        });
        $slept = [];
        ZohoApiBudget::setSleeper(static function (int $s) use (&$slept, &$now): void {
            $slept[] = $s;
            $now += $s;
        });

        ZohoApiBudget::acquire();
        ZohoApiBudget::acquire();
        try {
            ZohoApiBudget::acquire();
            self::fail('a person waits at most 5 seconds');
        } catch (ZohoRateLimited $e) {
            self::assertSame('Zoho speed limit for this app reached — try again in a minute.', $e->getMessage());
        }
        self::assertSame([], $slept);

        ZohoApiBudget::inBackground(static fn () => ZohoApiBudget::acquire());
        self::assertSame([31], $slept, 'the scheduled sync waits for the next minute');
        self::assertSame(1, ZohoApiBudget::usage()['minute'], 'counted in the new minute');
        self::assertSame(3, ZohoApiBudget::usage()['today']);
    }

    public function testZohoTooManyRequestsStopsTheRunAtOnce(): void
    {
        foreach (['TEST-Rate A', 'TEST-Rate B', 'TEST-Rate C'] as $n) {
            $this->product($n);
        }
        $this->overrides[] = static fn (string $m, string $u): ?array => $m === 'POST' && str_contains($u, '/books/v3/items') ? ['status' => 429, 'body' => '{"code":44,"message":"You have exceeded the maximum number of API requests."}'] : null;
        $r = CaSyncService::runFullSync('manual', null);
        self::assertCount(1, $this->calls('POST', '/books/v3/items'), 'the run stops — the other products are not tried');
        self::assertSame('Stopped: Zoho rate limit — remaining records will be sent at the next sync.', $r['stopped']);
        self::assertSame($r['stopped'], $this->lastLog());
        self::assertSame(0, $r['revenue']['synced'] + $r['revenue']['invoices'], 'nothing after the stop ran');
    }

    public function testCode44InA200BodyAndTheOauthTooManyRequestsAlsoStop(): void
    {
        $this->product('TEST-Code 44');
        $this->overrides[] = static fn (string $m, string $u): ?array => $m === 'POST' && str_contains($u, '/books/v3/items') ? ['status' => 400, 'body' => '{"code":44,"message":"Maximum number of API calls exceeded"}'] : null;
        self::assertSame(ZohoApiBudget::RATE_LIMIT_MESSAGE, CaSyncService::runFullSync('manual', null)['stopped']);

        ZohoBooksService::setTransport(fn (string $method, string $url, array $payload): array => $this->fakeZoho($method, $url, $payload));
        ZohoApiBudget::useSettingLimits();
        $this->overrides = [static fn (string $m, string $u): ?array => str_contains($u, '/oauth/v2/token') ? ['status' => 400, 'body' => '{"error_description":"You have made too many requests continuously. Please try again after some time.","error":"Access Denied","status":"failure"}'] : null];
        self::assertSame(ZohoApiBudget::RATE_LIMIT_MESSAGE, CaSyncService::runFullSync('manual', null)['stopped']);
    }

    public function testOneTokenIsSharedByEveryProcessAndRefetchedOnceAfterA401(): void
    {
        $this->connect();
        // A second process: nothing in memory, only the shared cache.
        $tokens = new \ReflectionProperty(ZohoBooksService::class, 'tokens');
        $tokens->setValue(null, []);
        $this->connect();
        self::assertCount(1, $this->calls('POST', '/oauth/v2/token'), 'the token is fetched once and shared');
        $stored = (string) Database::connection()->query('SELECT token_enc FROM zoho_token_cache')->fetchColumn();
        self::assertNotSame('', $stored);
        self::assertStringNotContainsString('fake-access-1', $stored, 'stored encrypted');

        // Zoho no longer accepts it: forget it, fetch a new one, try once more.
        $this->overrides[] = static fn (string $m, string $u, array $p): ?array => null;
        $headersSeen = [];
        ZohoBooksService::setTransport(function (string $method, string $url, array $payload, array $headers) use (&$headersSeen): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload];
            $auth = (string) (array_values(array_filter($headers, static fn ($h) => str_starts_with((string) $h, 'Authorization')))[0] ?? '');
            $headersSeen[] = $auth;
            if (str_contains($url, '/oauth/v2/token')) {
                return ['status' => 200, 'body' => (string) json_encode(['access_token' => 'fake-access-' . (++$this->tokenNo)])];
            }
            if (count(array_filter($headersSeen, static fn ($h) => $h !== '')) === 1) {
                return ['status' => 401, 'body' => '{"code":57,"message":"You are not authorized to perform this operation"}'];
            }
            return ['status' => 200, 'body' => '{"code":0,"organizations":[{"organization_id":"600","name":"TEST org","currency_code":"INR"}]}'];
        });
        ZohoApiBudget::useSettingLimits();
        $this->requests = [];
        $this->connect();
        self::assertCount(2, $this->calls('POST', '/oauth/v2/token'), 'first token, then a fresh one after the 401');
        $auths = array_values(array_filter($headersSeen, static fn ($h) => $h !== ''));
        self::assertNotSame($auths[0], $auths[1], 'the retry used the new token');
    }

    public function testTheSyncPageShowsTodaysUsageAndTheHistory(): void
    {
        ZohoApiBudget::acquire();
        ZohoApiBudget::acquire();
        $apiUsage = ZohoApiBudget::usage();
        $log = [];
        $isEnabled = true;
        $startDate = null;
        $pendingCount = $heldCount = $purchasesPendingCount = $benefitsPendingCount = 0;
        ob_start();
        include __DIR__ . '/../../views/ca/zoho_sync.php';
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Zoho calls today: 2 of 800 · this minute: 2 of 30', $html);
        self::assertStringContainsString('Zoho API calls — last 7 days (this app)', $html);
        self::assertStringContainsString('<td>' . ZohoApiBudget::dayKey() . '</td><td>2</td><td>800</td>', $html);
    }
}
