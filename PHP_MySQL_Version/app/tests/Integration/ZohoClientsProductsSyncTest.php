<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\ProductRepository;
use App\Services\CaSyncService;
use App\Services\ZohoBooksService;
use App\Services\ZohoMasterDataSync;
use App\Services\ZohoPendingService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2 Nov 2026 (D-238, "Zoho clients/products yes"): clients
 * and the Products menu kept in Zoho Books, each behind its own switch.
 * Every request goes to a fake Zoho — never the real one. Node twin:
 * zohoClientsProductsSync.test.js.
 */
final class ZohoClientsProductsSyncTest extends DbTestCase
{
    private array $requests = [];
    private array $contacts = [];
    private array $items = [];

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
        ZohoBooksService::setTransport(fn (string $method, string $url, array $payload): array => $this->fakeZoho($method, $url, $payload));
        // The test database is shared by the whole run: earlier tests' TEST- records must not reach this fake Zoho.
        $pdo = Database::connection();
        $pdo->exec("UPDATE clients SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
        $pdo->exec("UPDATE catalog_products SET name = CONCAT('OLD-', name) WHERE name LIKE 'TEST-%'");
        $pdo->exec('DELETE FROM zoho_pending_inactivations');
        // QA batch 4: the full sync now also sends Import & Distribution and Merchant Trade records (D-257 Zoho);
        // other tests' TEST- imports and deals must not reach this fake Zoho either.
        $pdo->exec("UPDATE import_shipments SET supplier_name = CONCAT('OLD-', supplier_name) WHERE supplier_name LIKE 'TEST-%'");
        $pdo->exec("UPDATE merchant_deals SET supplier_name = CONCAT('OLD-', supplier_name) WHERE supplier_name LIKE 'TEST-%'");
        $pdo->exec("UPDATE merchant_deals SET buyer_name = CONCAT('OLD-', buyer_name) WHERE buyer_name LIKE 'TEST-%'");
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
    private function fakeZoho(string $method, string $url, array $payload): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'payload' => $payload];
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $json = static fn (array $b, int $s = 200): array => ['status' => $s, 'body' => (string) json_encode($b)];
        if ($path === '/oauth/v2/token') {
            return $json(['access_token' => 'fake-access']);
        }
        if (preg_match('#^/books/v3/organizations/(\d+)$#', $path, $m) === 1) {
            return $json(['code' => 0, 'organization' => ['organization_id' => $m[1], 'is_registered_for_gst' => true, 'tax_settings' => ['tax_reg_no' => '29AAAAA0000A1ZY']]]);
        }
        if ($path === '/books/v3/settings/currencies') {
            return $json(['code' => 0, 'currencies' => [['currency_id' => 'CUR-USD', 'currency_code' => 'USD'], ['currency_id' => 'CUR-EUR', 'currency_code' => 'EUR']]]);
        }
        if ($path === '/books/v3/contacts' && $method === 'GET') {
            return $json(['code' => 0, 'contacts' => array_values(array_filter($this->contacts, static fn (array $c): bool => $c['contact_name'] === ($q['contact_name'] ?? null)))]);
        }
        if ($path === '/books/v3/contacts' && $method === 'POST') {
            $id = 'CT-' . (count($this->contacts) + 1);
            $this->contacts[$id] = ['contact_id' => $id, 'contact_name' => $payload['contact_name'], 'contact_type' => $payload['contact_type'], 'status' => 'active'];
            return $json(['code' => 0, 'contact' => $this->contacts[$id]], 201);
        }
        if (preg_match('#^/books/v3/contacts/([^/]+)(?:/(active|inactive))?$#', $path, $m) === 1) {
            if (isset($m[2])) {
                $this->contacts[$m[1]]['status'] = $m[2];
            }
            return $json(['code' => 0, 'contact' => $this->contacts[$m[1]] ?? []]);
        }
        if ($path === '/books/v3/items' && $method === 'GET') {
            return $json(['code' => 0, 'items' => array_values(array_filter($this->items, static fn (array $i): bool => $i['name'] === ($q['name'] ?? null)))]);
        }
        if ($path === '/books/v3/items' && $method === 'POST') {
            $id = 'IT-' . (count($this->items) + 1);
            $this->items[$id] = ['item_id' => $id, 'name' => $payload['name'], 'status' => 'active'];
            return $json(['code' => 0, 'item' => $this->items[$id]], 201);
        }
        if (preg_match('#^/books/v3/items/([^/]+)(?:/(active|inactive))?$#', $path, $m) === 1) {
            if (isset($m[2])) {
                $this->items[$m[1]]['status'] = $m[2];
            }
            return $json(['code' => 0, 'item' => $this->items[$m[1]] ?? []]);
        }
        return $json(['code' => 0, 'message' => 'not faked: ' . $method . ' ' . $path]);
    }

    private function testClient(string $name, ?string $currency = 'USD'): int
    {
        $id = $this->createTestClient();
        Database::connection()->prepare("UPDATE clients SET company_legal_name = :n, billing_address_line1 = '1 Harbour Road', billing_city = 'Rotterdam', billing_postcode = '3011', billing_country = 'Netherlands', vat_eori_tax_no = 'NL123456789B01', contact_person = 'Anna Buyer', phone = '+31 10 555 0101' WHERE id = :id")
            ->execute(['n' => $name, 'id' => $id]);
        if ($currency !== null) {
            $this->createTestOrder($id, 'FOB', 'Standard — New Buyer', $currency);
        }
        return $id;
    }

    private function testProduct(string $name, string $hs = '68022310'): int
    {
        Database::connection()->prepare("INSERT INTO catalog_products (name, specifications, hs_code, default_factory_cost, default_cha_cost, is_active) VALUES (:n, 'Granite slab, polished, 20 mm', :hs, 45000, 9999, 1)")
            ->execute(['n' => $name, 'hs' => $hs]);
        return (int) Database::connection()->lastInsertId();
    }

    private function calls(string $method, string $pathPart): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method && str_contains((string) parse_url($r['url'], PHP_URL_PATH), $pathPart)));
    }

    public function testBothSwitchesOffSendNothing(): void
    {
        $this->testClient('TEST-Off Client');
        $this->testProduct('TEST-Off Product');
        $r = CaSyncService::runFullSync('manual', null);
        self::assertSame(['clients' => 0, 'products' => 0, 'inactivated' => 0, 'failed' => 0, 'skipped' => 0], $r['master_data']);
        self::assertSame([], $this->calls('POST', '/contacts'));
        self::assertSame([], $this->calls('POST', '/items'));
        $by = ZohoPendingService::counts()['by_type'];
        self::assertArrayNotHasKey('client', $by);
        self::assertArrayNotHasKey('product', $by);
    }

    public function testClientCreateLinkUpdateNoOpAndInactivate(): void
    {
        CompanySettingsRepository::set('zoho_sync_clients', '1');
        $a = $this->testClient('TEST-Alpha Stone BV');
        $this->testClient('TEST-No Order Yet', null); // waits for its first order to set the currency
        $this->contacts['CT-EXIST'] = ['contact_id' => 'CT-EXIST', 'contact_name' => 'TEST-Beta Marble GmbH', 'contact_type' => 'customer', 'status' => 'active'];
        $b = $this->testClient('TEST-Beta Marble GmbH', 'EUR');

        self::assertSame(2, ZohoPendingService::counts()['by_type']['client'] ?? 0);
        $r = ZohoMasterDataSync::run('manual', null);
        self::assertSame(2, $r['clients']);
        $created = $this->calls('POST', '/contacts');
        self::assertCount(1, array_filter($created, static fn (array $c): bool => !str_contains($c['url'], '/active')), 'one created, the other linked by name');
        $p = $created[0]['payload'];
        self::assertSame('TEST-Alpha Stone BV', $p['contact_name']);
        self::assertSame('customer', $p['contact_type']);
        self::assertSame('CUR-USD', $p['currency_id']);
        self::assertSame(['address' => '1 Harbour Road', 'city' => 'Rotterdam', 'zip' => '3011', 'country' => 'Netherlands'], $p['billing_address']);
        self::assertSame('Anna Buyer', $p['contact_persons'][0]['first_name']);
        self::assertStringContainsString('NL123456789B01', $p['notes']);
        self::assertSame('overseas', $p['gst_treatment']);
        $pdo = Database::connection();
        self::assertSame('CT-EXIST', $pdo->query("SELECT zoho_contact_id FROM clients WHERE id = {$b}")->fetchColumn());
        self::assertNotNull($pdo->query("SELECT zoho_contact_id FROM clients WHERE id = {$a}")->fetchColumn());
        self::assertSame(0, ZohoPendingService::counts()['by_type']['client'] ?? 0);

        // Unchanged → nothing sent.
        $this->requests = [];
        self::assertSame(0, ZohoMasterDataSync::run('manual', null)['clients']);
        self::assertSame([], $this->calls('PUT', '/contacts'));

        // Changed in the app → updated (currency never sent on update).
        $pdo->exec("UPDATE clients SET billing_city = 'Amsterdam' WHERE id = {$a}");
        self::assertSame(1, ZohoMasterDataSync::run('manual', null)['clients']);
        $put = $this->calls('PUT', '/contacts');
        self::assertCount(1, $put);
        self::assertSame('Amsterdam', $put[0]['payload']['billing_address']['city']);
        self::assertArrayNotHasKey('currency_id', $put[0]['payload']);

        // Deactivated → marked inactive, never deleted.
        $pdo->exec("UPDATE clients SET is_active = 0 WHERE id = {$a}");
        $r = ZohoMasterDataSync::run('manual', null);
        self::assertSame(1, $r['inactivated']);
        $contactId = (string) $pdo->query("SELECT zoho_contact_id FROM clients WHERE id = {$a}")->fetchColumn();
        self::assertSame('inactive', $this->contacts[$contactId]['status']);
        self::assertSame([], $this->calls('DELETE', '/contacts'));
        self::assertGreaterThan(0, (int) $pdo->query("SELECT COUNT(*) FROM zoho_sync_log WHERE sync_type = 'client' AND entity_id = {$a} AND status = 'success'")->fetchColumn());
    }

    public function testProductCreateSendsNoCostThenInactivateAndDelete(): void
    {
        CompanySettingsRepository::set('zoho_sync_products', '1');
        $id = $this->testProduct('TEST-Absolute Black Slab');
        $this->testProduct('Real Product Not In Test Org'); // test organisation: only TEST- names go
        self::assertSame(1, ZohoPendingService::counts()['by_type']['product'] ?? 0);

        self::assertSame(1, ZohoMasterDataSync::run('manual', null)['products']);
        $post = $this->calls('POST', '/items');
        self::assertCount(1, $post);
        $p = $post[0]['payload'];
        self::assertSame('TEST-Absolute Black Slab', $p['name']);
        self::assertSame(0, $p['rate'], 'rate 0 — the catalogue has no selling price');
        self::assertSame('68022310', $p['hsn_or_sac']);
        self::assertSame('goods', $p['product_type']);
        self::assertSame('sales', $p['item_type']);
        $encoded = (string) json_encode($p);
        foreach (['45000', '9999', 'cost', 'factory', 'purchase'] as $never) {
            self::assertStringNotContainsStringIgnoringCase($never, $encoded, "no cost may reach Zoho ({$never})");
        }

        $pdo = Database::connection();
        $pdo->exec("UPDATE catalog_products SET is_active = 0 WHERE id = {$id}");
        self::assertSame(1, ZohoMasterDataSync::run('manual', null)['inactivated']);
        $itemId = (string) $pdo->query("SELECT zoho_item_id FROM catalog_products WHERE id = {$id}")->fetchColumn();
        self::assertSame('inactive', $this->items[$itemId]['status']);

        // Deleted from the Products menu → remembered, then marked inactive at the next sync.
        $pdo->exec("UPDATE catalog_products SET is_active = 1, zoho_synced_hash = NULL WHERE id = {$id}");
        ZohoMasterDataSync::run('manual', null);
        ProductRepository::delete($id);
        self::assertSame(1, ZohoPendingService::counts()['by_type']['product'] ?? 0);
        self::assertSame(1, ZohoMasterDataSync::run('manual', null)['inactivated']);
        self::assertSame('inactive', $this->items[$itemId]['status']);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM zoho_pending_inactivations')->fetchColumn());
    }

    public function testRepushAllAndPerOrderSyncSkipsMasterData(): void
    {
        CompanySettingsRepository::set('zoho_sync_clients', '1');
        CompanySettingsRepository::set('zoho_sync_products', '1');
        $this->testClient('TEST-Gamma Granite Ltd');
        $this->testProduct('TEST-Kashmir White');
        ZohoMasterDataSync::run('manual', null);
        self::assertSame([], array_intersect(['client', 'product'], array_keys(ZohoPendingService::counts()['by_type'])));
        ZohoMasterDataSync::forgetHashes();
        $by = ZohoPendingService::counts()['by_type'];
        self::assertGreaterThanOrEqual(1, $by['client'] ?? 0);
        self::assertGreaterThanOrEqual(1, $by['product'] ?? 0);
        // "Sync this order" leaves clients and products to the full sync.
        $r = CaSyncService::runForOrder($this->createTestOrder($this->createTestClient()), null);
        self::assertArrayNotHasKey('master_data', $r);
    }

    public function testSwitchesAreOrdinaryZohoSettingsSavedWithAReason(): void
    {
        $rows = Database::connection()->query("SELECT setting_key, setting_value, value_type, category FROM company_settings WHERE setting_key IN ('zoho_sync_clients', 'zoho_sync_products') ORDER BY setting_key")->fetchAll();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('boolean', $row['value_type']);
            self::assertSame('zoho_books', $row['category'], 'shown in Settings → Zoho Books, saved with the page\'s mandatory reason and audit');
        }
    }
}
