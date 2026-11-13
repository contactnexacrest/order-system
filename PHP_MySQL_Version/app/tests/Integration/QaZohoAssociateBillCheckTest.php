<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\ZohoPurchaseRepository;
use App\Services\OrderZohoMatchService;
use App\Services\ZohoBooksService;
use App\Services\ZohoRestoreService;
use App\Tests\Support\DbTestCase;

/**
 * QA ZOH-002: an associate's paid commission goes to Books as the associate's
 * own BILL (AS-12) and its id is kept in order_cost_entries.zoho_expense_id.
 * The App vs Zoho check, the daily restore and the test clean-up list read it
 * as a bill — not as an expense, which Books does not have. Fake Zoho only.
 * Node twin: qaZohoAssociateBillCheck.test.js.
 */
final class QaZohoAssociateBillCheckTest extends DbTestCase
{
    private array $requests = [];
    private array $bills = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        foreach ([
            'zoho_books_enabled' => '1', 'zoho_books_client_id' => 'fake-client', 'zoho_books_client_secret' => 'fake-secret',
            'zoho_books_refresh_token' => 'fake-refresh', 'zoho_books_organization_id' => '600', 'zoho_books_deposit_account_id' => 'SBI-1',
        ] as $k => $v) {
            CompanySettingsRepository::set($k, $v);
        }
        ZohoBooksService::setTransport(fn (string $method, string $url, array $payload): array => $this->fakeZoho($method, $url));
        // Earlier tests' TEST- records must not reach this fake Zoho.
        Database::connection()->exec("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT']);
        CompanySettingsRepository::set('zoho_books_enabled', '0');
        parent::tearDown();
    }

    /** @return array{status:int, body:string} */
    private function fakeZoho(string $method, string $url): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url];
        $path = (string) parse_url($url, PHP_URL_PATH);
        $json = static fn (array $b, int $s = 200): array => ['status' => $s, 'body' => (string) json_encode($b)];
        if ($path === '/oauth/v2/token') {
            return $json(['access_token' => 'fake-access']);
        }
        if (preg_match('#^/books/v3/bills/(.+)$#', $path, $m) === 1) {
            return isset($this->bills[$m[1]]) ? $json(['code' => 0, 'bill' => $this->bills[$m[1]]]) : $json(['code' => 1002, 'message' => 'Record does not exist.'], 404);
        }
        if (preg_match('#^/books/v3/expenses/(.+)$#', $path) === 1) {
            return $json(['code' => 1002, 'message' => 'Record does not exist.'], 404);
        }
        if ($path === '/books/v3/expenses') {
            return $json(['code' => 0, 'expenses' => [], 'page_context' => ['has_more_page' => false]]);
        }
        return $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400);
    }

    /** A TEST- order whose associate commission (INR 104,312.50) was paid and sent to Books as bill BILL-77. */
    private function orderWithSentCommission(): array
    {
        $pdo = Database::connection();
        $clientId = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET company_legal_name = 'TEST-ZOH002 Buyer' WHERE id = :id")->execute(['id' => $clientId]);
        $orderId = $this->createTestOrder($clientId, 'FOB');
        $pdo->prepare("UPDATE orders SET buyer_inquiry_ref = :ref, company_legal_name = 'TEST-ZOH002 Buyer' WHERE id = :id")->execute(['ref' => "TQZ-{$orderId}", 'id' => $orderId]);
        $userId = $this->createTestUser('Admin');
        $pdo->prepare("INSERT INTO order_cost_entries (order_id, category, description, amount_inr, incurred_at, recorded_by, paid_from, incentive_kind, zoho_expense_id, zoho_synced_at)
                       VALUES (:o, 'commission', 'Associate commission', 104312.50, CURDATE(), :u, 'company_account', 'associate', 'BILL-77', NOW())")
            ->execute(['o' => $orderId, 'u' => $userId]);
        return [$orderId, (int) $pdo->lastInsertId()];
    }

    public function testZoh002TheCheckReadsTheAssociateCommissionAsItsBill(): void
    {
        [$orderId] = $this->orderWithSentCommission();
        // The bill: the gross commission plus GST paid on the associate's tax invoice (D-256).
        $this->bills['BILL-77'] = ['bill_id' => 'BILL-77', 'bill_number' => 'COMM-1', 'sub_total' => 104312.50, 'total' => 123088.75];
        $rows = array_values(array_filter(OrderZohoMatchService::check($orderId)['rows'], static fn (array $r): bool => $r['group'] === 'Expenses'));
        self::assertCount(1, $rows);
        self::assertSame('match', $rows[0]['status'], $rows[0]['note']);
        self::assertSame('INR 104,312.50', $rows[0]['books']);
        self::assertSame([], array_values(array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], '/expenses/BILL-77'))), 'never read as an expense');

        // A bill really deleted in Books is still reported as missing.
        unset($this->bills['BILL-77']);
        $rows = array_values(array_filter(OrderZohoMatchService::check($orderId)['rows'], static fn (array $r): bool => $r['group'] === 'Expenses'));
        self::assertSame('missing', $rows[0]['status']);
    }

    public function testZoh002TheRestoreLeavesTheBillAloneAndPutsBackOnlyADeletedOne(): void
    {
        [, $costId] = $this->orderWithSentCommission();
        $this->bills['BILL-77'] = ['bill_id' => 'BILL-77', 'bill_number' => 'COMM-1', 'sub_total' => 104312.50, 'total' => 104312.50];
        $zohoId = static fn (): ?string => (($v = Database::connection()->query("SELECT zoho_expense_id FROM order_cost_entries WHERE id = {$costId}")->fetchColumn()) === false || $v === null) ? null : (string) $v;

        ZohoRestoreService::purchases(null, 'manual', null);
        self::assertSame('BILL-77', $zohoId(), 'the bill in Books is not taken for a deleted expense');

        unset($this->bills['BILL-77']);
        ZohoRestoreService::purchases(null, 'manual', null);
        self::assertNull($zohoId(), 'a deleted bill is sent again at the next sync');
    }

    public function testZoh002TheCleanUpListNamesTheCommissionAsABill(): void
    {
        $this->orderWithSentCommission();
        $mine = array_values(array_filter(ZohoPurchaseRepository::pushedRecords(), static fn (array $r): bool => $r['id'] === 'BILL-77'));
        self::assertSame('bill', $mine[0]['kind']);
        self::assertStringStartsWith('associate commission bill INR 104312.50', $mine[0]['label']);
    }
}
