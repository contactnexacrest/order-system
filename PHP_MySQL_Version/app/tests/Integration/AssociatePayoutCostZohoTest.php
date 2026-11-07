<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\OrderRepository;
use App\Services\AssociatePayoutService;
use App\Services\CaSyncService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-264 (7 Nov 2026, "yes add the payout difference to zoho"): what paying an
 * associate cost against the fixed commission goes to Zoho Books as one journal per payout —
 * Associate payout costs debited, the bank credited (a saving the other way round), with the
 * description the app builds (rule D-212); sent once, updated on a change, skipped while the
 * Zoho account is not chosen. Mocked Zoho only (no live calls). Port: associatePayoutCostZoho.test.js.
 */
final class AssociatePayoutCostZohoTest extends DbTestCase
{
    private int $admin;
    /** @var array<string, array<string,mixed>> */
    private array $journals = [];
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
        foreach (['associate_commission_due_days' => '30', 'associate_claim_hold' => '1', 'associate_payout_run' => 'monthly', 'associate_payout_day' => '5',
            'associate_counting_threshold_inr' => '500000', 'associate_abroad_charges' => 'OUR', 'associate_abroad_min_payout_usd' => '100', 'zoho_payout_costs_account_id' => ''] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->admin);
        }
    }

    protected function tearDown(): void
    {
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        CompanySettingsRepository::set('zoho_books_enabled', '0');
        IncentiveRepository::setSetting('zoho_payout_costs_account_id', '', $this->admin);
        parent::tearDown();
    }

    /** The D-264 mockup order, paid abroad: USD 1,250.00 fixed at ₹1,04,312.50; ₹1,05,125.00 debited + ₹1,850.00 charges. */
    private function paidAbroad(string $buyer = 'TEST-Payout Buyer'): array
    {
        $pdo = Database::connection();
        $level = (int) $pdo->query("SELECT id FROM commission_levels WHERE name = 'Level 3'")->fetchColumn();
        $pdo->prepare("INSERT INTO associates (name, status, commission_mode, commission_level_id, based_in, currency_code) VALUES (:n, 'active', 'fixed', :l, 'abroad', 'USD')")
            ->execute(['n' => 'Demo Associate ' . bin2hex(random_bytes(3)), 'l' => $level]);
        $a = (int) $pdo->lastInsertId();
        $client = $this->createTestClient();
        $pdo->prepare("UPDATE clients SET associate_id = :a, associate_registered_on = '2026-09-01', company_legal_name = :b WHERE id = :c")->execute(['a' => $a, 'b' => $buyer, 'c' => $client]);
        $orderId = $this->createTestOrder($client, 'FOB', 'Standard — New Buyer', 'USD');
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value, line_type) VALUES (:o, 1, 'Granite', 100, 'CBM', 178.5714, 17857.14, 'goods')")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare("UPDATE order_payment_status SET advance_amount = 5000.00, advance_cleared_at = '2026-10-15 10:00:00', balance_amount = 12857.14, balance_cleared_at = '2026-11-12 11:00:00' WHERE order_id = :o")->execute(['o' => $orderId]);
        $pdo->prepare("INSERT INTO order_payment_receipts (order_id, leg, amount, received_on, exchange_rate, inr_credited, bank_reference) VALUES (:o, 'advance', 5000.00, '2026-10-15', 83.10, 415500.00, 'FIRC2610150003'), (:o2, 'balance', 12857.14, '2026-11-12', 83.59, 1074678.33, 'FIRC2611120007')")
            ->execute(['o' => $orderId, 'o2' => $orderId]);
        AssociatePayoutService::autoFix($orderId);
        $id = (int) $pdo->query("SELECT id FROM incentive_payouts WHERE order_id = {$orderId} AND payee_kind = 'associate' AND clawback_of_payout_id IS NULL")->fetchColumn();
        $pdo->prepare("UPDATE incentive_payouts SET status = 'paid', paid_on = '2026-12-05', paid_inr = 104312.50, tds_inr = 0, paid_reference = 'FT2612050001' WHERE id = :id")->execute(['id' => $id]);
        AssociatePayoutService::recordPaymentCosts($id, 105125.00, 1850.00);
        return [$this->payout($id), $orderId, $a];
    }

    private function payout(int $id): array
    {
        return IncentiveRepository::findPayout($id);
    }

    private function fakeZoho(): void
    {
        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        $_ENV['ZOHO_EINVOICE_ENABLED'] = 'false';
        foreach (['zoho_books_enabled' => '1', 'zoho_books_client_id' => 'fake-client', 'zoho_books_client_secret' => 'fake-secret', 'zoho_books_refresh_token' => 'fake-refresh', 'zoho_books_organization_id' => '600', 'zoho_books_deposit_account_id' => 'SBI-1'] as $k => $v) {
            CompanySettingsRepository::set($k, $v);
        }
        ZohoBooksService::setTransport(function (string $method, string $url, array $payload): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $json = static fn (array $b, int $s = 200): array => ['status' => $s, 'body' => (string) json_encode($b)];
            if (str_starts_with($path, '/books/v3/journals')) {
                $this->calls[] = $method . ' ' . $path;
            }
            return match (true) {
                $path === '/oauth/v2/token' => $json(['access_token' => 'fake-access']),
                $path === '/books/v3/journals' && $method === 'POST' => (function () use ($payload, $json): array {
                    $id = 'JN-' . (count($this->journals) + 1);
                    $this->journals[$id] = $payload;
                    return $json(['code' => 0, 'journal' => ['journal_id' => $id]], 201);
                })(),
                str_starts_with($path, '/books/v3/journals/') && $method === 'PUT' => (function () use ($path, $payload, $json): array {
                    $id = substr($path, strlen('/books/v3/journals/'));
                    $this->journals[$id] = $payload;
                    return $json(['code' => 0, 'journal' => ['journal_id' => $id]]);
                })(),
                default => $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400),
            };
        });
        // The test database is shared by the whole run: earlier tests' payout costs are not this test's.
        foreach (CaSyncService::payoutCostsToSync(null) as $p) {
            Database::connection()->prepare("UPDATE incentive_payouts SET zoho_cost_journal_id = 'OLD', zoho_cost_hash = :h WHERE id = :id")->execute(['h' => CaSyncService::payoutCostHash($p), 'id' => $p['id']]);
        }
    }

    public function testTheJournalBooksPayoutCostsAgainstTheBankAndASavingTheOtherWayRound(): void
    {
        [$p, $orderId, $a] = $this->paidAbroad();
        $ref = OrderRepository::find($orderId)['order_reference'];
        $description = "Associate commission payout — {$ref} — " . AssociateRepository::find($a)['name'] . ' — USD 1,250.00 sent, SWIFT ref FT2612050001; exchange difference ₹812.50 and transfer charges ₹1,850.00';
        self::assertSame([
            'journal_date' => '2026-12-05',
            'reference_number' => "APC-{$ref}-{$p['id']}",
            'notes' => "TEST- {$description}",
            'line_items' => [
                ['account_id' => 'AC-APC', 'debit_or_credit' => 'debit', 'amount' => 2662.5, 'description' => $description],
                ['account_id' => 'SBI-1', 'debit_or_credit' => 'credit', 'amount' => 2662.5, 'description' => 'Associate payout costs paid from the bank'],
            ],
        ], ZohoBooksService::payoutCostJournalPayload($p, 'AC-APC', 'SBI-1', null, true));

        // The rupee came out cheaper: ₹1,02,000.00 debited + ₹1,850.00 charges against ₹1,04,312.50 → a saving of ₹462.50.
        AssociatePayoutService::recordPaymentCosts((int) $p['id'], 102000.00, 1850.00);
        $p = $this->payout((int) $p['id']);
        self::assertSame('-462.50', (string) $p['payout_difference_inr']);
        $j = ZohoBooksService::payoutCostJournalPayload($p, 'AC-APC', 'SBI-1');
        self::assertSame([
            ['account_id' => 'AC-APC', 'debit_or_credit' => 'credit', 'amount' => 462.5, 'description' => "Associate commission payout — {$ref} — " . AssociateRepository::find($a)['name'] . ' — USD 1,250.00 sent, SWIFT ref FT2612050001; exchange difference ₹-2,312.50 and transfer charges ₹1,850.00'],
            ['account_id' => 'SBI-1', 'debit_or_credit' => 'debit', 'amount' => 462.5, 'description' => 'Associate payout cost saving (the rupee came out cheaper)'],
        ], $j['line_items']);
        self::assertStringStartsWith('Associate commission payout', $j['notes'], 'no TEST- prefix for a buyer that is not test data');
    }

    public function testSkippedWithAClearNoteWhileTheZohoAccountIsNotChosen(): void
    {
        $this->fakeZoho();
        [$p] = $this->paidAbroad();
        self::assertSame(['journals' => 0, 'failed' => 0, 'skipped' => 1], CaSyncService::syncPayoutCosts('manual', $this->admin));
        self::assertSame([], $this->calls, 'nothing sent to Zoho');
        $msg = (string) Database::connection()->query("SELECT message FROM zoho_sync_log WHERE sync_type = 'associate_payout_cost' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertSame(CaSyncService::PAYOUT_COSTS_UNMAPPED . ' (1 waiting.)', $msg);
        self::assertNull($this->payout((int) $p['id'])['zoho_cost_journal_id']);
    }

    public function testSentOnceThenUpdatedNeverTwice(): void
    {
        $this->fakeZoho();
        [$p, $orderId] = $this->paidAbroad();
        IncentiveRepository::setSetting('zoho_payout_costs_account_id', 'AC-APC', $this->admin);

        self::assertSame(['journals' => 1, 'failed' => 0, 'skipped' => 0], CaSyncService::syncPayoutCosts('manual', $this->admin));
        self::assertSame('JN-1', $this->payout((int) $p['id'])['zoho_cost_journal_id']);
        self::assertSame(ZohoBooksService::payoutCostJournalPayload($p, 'AC-APC', 'SBI-1', null, true), $this->journals['JN-1'], 'the exact journal (rule D-212 notes)');
        self::assertSame(['GET /books/v3/journals', 'POST /books/v3/journals'], $this->calls, 'looked for an earlier one first, then posted once');

        self::assertSame(['journals' => 0, 'failed' => 0, 'skipped' => 1], CaSyncService::syncPayoutCosts('manual', $this->admin), 'sent once');

        // Finance corrects the SWIFT reference: the same journal is updated, never a second one.
        Database::connection()->prepare("UPDATE incentive_payouts SET paid_reference = 'FT2612050009' WHERE id = :id")->execute(['id' => $p['id']]);
        self::assertSame(['journals' => 1, 'failed' => 0, 'skipped' => 0], CaSyncService::syncPayoutCosts('manual', $this->admin));
        self::assertSame('PUT /books/v3/journals/JN-1', end($this->calls));
        self::assertCount(1, $this->journals);
        self::assertStringContainsString('SWIFT ref FT2612050009', (string) $this->journals['JN-1']['notes']);
        $log = (string) Database::connection()->query("SELECT message FROM zoho_sync_log WHERE sync_type = 'associate_payout_cost' AND status = 'success' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringStartsWith('Updated as Zoho Books journal: Associate commission payout — ' . OrderRepository::find($orderId)['order_reference'], $log);
    }

    public function testAPayoutWithoutADifferenceSendsNothing(): void
    {
        $this->fakeZoho();
        [$p] = $this->paidAbroad();
        Database::connection()->prepare('UPDATE incentive_payouts SET payout_difference_inr = 0 WHERE id = :id')->execute(['id' => $p['id']]);
        IncentiveRepository::setSetting('zoho_payout_costs_account_id', 'AC-APC', $this->admin);
        self::assertSame(['journals' => 0, 'failed' => 0, 'skipped' => 1], CaSyncService::syncPayoutCosts('manual', $this->admin));
        self::assertSame([], $this->journals);
    }
}
