<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Controllers\PaymentMilestoneController;
use App\Helpers\Flash;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentMilestoneRepository;
use App\Services\CaSyncService;
use App\Services\DocumentDataAssembler;
use App\Services\DocumentGenerationService;
use App\Services\StageGateService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-215 (5a, confirmed twice): an order can have its own
 * payment schedule — the advance first, the balance last, and milestones in
 * between. The PI prints one bullet and the OC one row per milestone; each
 * milestone is its own payment (remittance with bank reference and a
 * description built from the facts, cleared, pushed to Zoho Books); the
 * balance is cleared only after every milestone. An order without
 * milestones works exactly as before. The Node suite has the same test
 * (paymentMilestones.test.js).
 */
final class PaymentMilestoneTest extends DbTestCase
{
    private const DUE = 'on shipment — within 3 Calendar Days of receiving the scanned Bill of Lading';

    private int $orderId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Extended Terms — Before Shipment');
        OrderProductRepository::add($this->orderId, 1, 'Black granite headstone', '60x30', 'Polished', '100', false, 'PCS', '45.00');
        OrderPaymentStatusRepository::initializeForOrder($this->orderId);
        $this->userId = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->userId];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function call(string $method, array $post, array $params = []): string
    {
        $_POST = $post;
        ob_start();
        (new PaymentMilestoneController())->{$method}(['id' => (string) $this->orderId] + $params);
        ob_end_clean();
        $_POST = [];
        $all = Flash::pull();
        return (string) ((end($all) ?: [])['message'] ?? '');
    }

    private function schedule(): void
    {
        self::assertStringStartsWith('Payment schedule saved:', $this->call('saveSchedule', ['milestone_pct' => ['50', ''], 'milestone_due' => [self::DUE . '.', '']]));
    }

    public function testTheScheduleIsCheckedAndSetsTheBalance(): void
    {
        self::assertSame('Advance 30% + milestones 70% leave nothing for the balance — together they must stay below 100%.',
            $this->call('saveSchedule', ['milestone_pct' => ['70'], 'milestone_due' => [self::DUE]]));
        self::assertSame('Milestone 1 %: enter its share of the FOB value.', $this->call('saveSchedule', ['milestone_pct' => [''], 'milestone_due' => [self::DUE]]));
        self::assertStringStartsWith('Milestone 1: say when it is due', $this->call('saveSchedule', ['milestone_pct' => ['50'], 'milestone_due' => ['soon']]));

        self::assertSame('Payment schedule saved: 30% advance · 50% ' . self::DUE . ' · 20% balance. Regenerate the Proforma Invoice so the buyer sees it.',
            $this->call('saveSchedule', ['milestone_pct' => ['50', ''], 'milestone_due' => [self::DUE . '.', '']]));
        self::assertSame(20.0, (float) OrderRepository::find($this->orderId)['balance_pct']);
        self::assertSame([[2, '50.00', self::DUE]], array_map(static fn (array $m): array => [(int) $m['seq'], $m['pct'], $m['due_text']], PaymentMilestoneRepository::forOrder($this->orderId)));

        self::assertSame('Payment schedule back to advance and balance: 30% advance · 70% balance. Regenerate the Proforma Invoice so the buyer sees it.',
            $this->call('saveSchedule', ['milestone_pct' => [''], 'milestone_due' => ['']]));
        self::assertSame(70.0, (float) OrderRepository::find($this->orderId)['balance_pct']);

        $this->schedule();
        Database::connection()->exec("UPDATE order_payment_status SET advance_cleared_at = NOW() WHERE order_id = {$this->orderId}");
        self::assertSame('The payment schedule is fixed once the advance is cleared — change it with a payment terms amendment.', $this->call('saveSchedule', ['milestone_pct' => [''], 'milestone_due' => ['']]));
    }

    public function testThePiAndOcShowEachMilestone(): void
    {
        $this->schedule();
        $pi = DocumentDataAssembler::assemble($this->orderId, 'PI');
        self::assertSame('1,350.00', $pi['financial']['advance_amount']);
        self::assertSame([['50', self::DUE, '2,250.00']], array_map(static fn (array $m): array => [$m['pct'], $m['due_text'], $m['amount']], $pi['financial']['milestones']));
        self::assertSame('20', $pi['financial']['balance_pct']);
        self::assertSame('900.00', $pi['financial']['balance_amount']);

        $result = DocumentGenerationService::generate($this->orderId, 'PI', $this->userId, null, true, true);
        $file = FileStoreRepository::find((int) $result['pdf_file_id']);
        $text = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) @shell_exec('pdftotext ' . escapeshellarg($file['server_path']) . ' - 2>/dev/null')));
        if (trim((string) $text) === '') {
            self::fail('Could not read the generated PDF with pdftotext — install poppler-utils.');
        }
        self::assertStringContainsString('30% Advance Payment on FOB Value against this document.', $text);
        self::assertStringContainsString('50% Payment on FOB Value ' . self::DUE . '.', $text);
        self::assertStringContainsString('50% Amount: USD 2,250.00', $text);
        self::assertStringContainsString('20% Balance Payment on FOB Value', $text);
        self::assertStringContainsString('20% Balance Amount: USD 900.00', $text);
        self::assertNotEmpty($result['docx_file_id'], 'the Word copy is made too');

        $oc = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/OC/order_confirmation.html.twig');
        self::assertStringContainsString('<tr><td class="k">{{ m.pct }}% Payment Due ({{ order.currency_code }}) *</td><td class="v">{{ m.amount }}', $oc);
    }

    public function testAnOrderWithoutMilestonesPrintsAsBefore(): void
    {
        $pi = DocumentDataAssembler::assemble($this->orderId, 'PI');
        self::assertSame([], $pi['financial']['milestones']);
        self::assertSame('3,150.00', $pi['financial']['balance_amount']);
    }

    public function testEachMilestoneIsItsOwnPaymentAndComesBeforeTheBalance(): void
    {
        $this->schedule();
        $m = PaymentMilestoneRepository::forOrder($this->orderId)[0];
        $post = ['amount' => '2250', 'exchange_rate' => '84.1', 'bank_reference' => 'UTR778899', 'received_on' => date('Y-m-d')];
        self::assertSame('Generate the Proforma Invoice first — the buyer pays each milestone against it.', $this->call('record', $post, ['milestoneId' => (string) $m['id']]));

        $pi = DocumentGenerationService::generate($this->orderId, 'PI', $this->userId, null, false, true);
        $piRef = Database::connection()->query('SELECT document_reference FROM documents WHERE id = ' . (int) $pi['document_id'])->fetchColumn();
        self::assertSame('The bank reference is required (UTR / SWIFT / bank reference as on the bank\'s advice).',
            $this->call('record', ['bank_reference' => ''] + $post, ['milestoneId' => (string) $m['id']]));
        self::assertSame('Record the milestone 2 remittance before marking it cleared.', $this->call('clear', [], ['milestoneId' => (string) $m['id']]));

        $order = OrderRepository::find($this->orderId);
        $expected = 'Milestone 2 (50% ' . self::DUE . ') received from ' . $order['company_legal_name'] . ' for order ' . $order['buyer_inquiry_ref']
            . " · USD 2,250.00 at ₹84.10 · against Proforma Invoice {$piRef} · bank ref UTR778899";
        self::assertSame("Recorded: {$expected}. Mark it cleared once your bank confirms receipt.", $this->call('record', $post, ['milestoneId' => (string) $m['id']]));
        self::assertSame('Milestone 2 remittance is already recorded — nothing changed.', $this->call('record', $post, ['milestoneId' => (string) $m['id']]));

        // The balance waits for every milestone.
        for ($stage = 1; $stage <= 7; $stage++) {
            StageGateService::passAndUnlockNext($this->orderId, $stage, null);
        }
        OrderPaymentStatusRepository::recordBalanceReceived($this->orderId, 900.00, date('Y-m-d'));
        $this->createTestDocument($this->orderId, 'CI', 'approved');
        ob_start();
        (new OrderController())->clearBalancePayment(['id' => (string) $this->orderId]);
        ob_end_clean();
        $all = Flash::pull();
        self::assertSame('Clear payment milestone 2 (50% ' . self::DUE . ') before the balance.', (end($all) ?: [])['message'] ?? '');
        self::assertNull(OrderPaymentStatusRepository::find($this->orderId)['balance_cleared_at']);

        self::assertSame('Milestone 2 cleared. It goes to Zoho Books with the next revenue sync.', $this->call('clear', ['cleared_on' => date('Y-m-d')], ['milestoneId' => (string) $m['id']]));
        ob_start();
        (new OrderController())->clearBalancePayment(['id' => (string) $this->orderId]);
        ob_end_clean();
        Flash::pull();
        self::assertNotNull(OrderPaymentStatusRepository::find($this->orderId)['balance_cleared_at']);
    }

    public function testAClearedMilestoneGoesToZohoBooksWithItsDescription(): void
    {
        $this->schedule();
        Database::connection()->exec("UPDATE orders SET company_legal_name = CONCAT('OLD-', company_legal_name) WHERE company_legal_name LIKE 'TEST-%'");
        Database::connection()->exec("UPDATE orders SET company_legal_name = 'TEST-Milestone Buyer' WHERE id = {$this->orderId}");
        $m = PaymentMilestoneRepository::forOrder($this->orderId)[0];
        PaymentMilestoneRepository::recordReceived((int) $m['id'], 2250.0, date('Y-m-d'), 84.1, 'UTR778899', null, $this->userId);
        PaymentMilestoneRepository::markCleared((int) $m['id'], date('Y-m-d'), $this->userId);

        $_ENV['ZOHO_INTEGRATION_ENABLED'] = 'true';
        $_ENV['ZOHO_ENVIRONMENT'] = 'test';
        foreach (['zoho_books_enabled' => '1', 'zoho_books_client_id' => 'fake-client', 'zoho_books_client_secret' => 'fake-secret',
            'zoho_books_refresh_token' => 'fake-refresh', 'zoho_books_organization_id' => '600', 'zoho_books_deposit_account_id' => 'SBI-1'] as $key => $value) {
            CompanySettingsRepository::set($key, $value);
        }
        $payments = [];
        ZohoBooksService::setTransport(static function (string $method, string $url, array $payload) use (&$payments): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $json = static fn (array $body, int $status = 200): array => ['status' => $status, 'body' => (string) json_encode($body)];
            return match (true) {
                $path === '/oauth/v2/token' => $json(['access_token' => 'fake-access']),
                (bool) preg_match('#^/books/v3/organizations/#', $path) => $json(['code' => 0, 'organization' => ['organization_id' => '600', 'is_registered_for_gst' => false]]),
                $path === '/books/v3/contacts' && $method === 'GET' => $json(['code' => 0, 'contacts' => []]),
                $path === '/books/v3/contacts' && $method === 'POST' => $json(['code' => 0, 'contact' => ['contact_id' => 'CT-1', 'contact_name' => $payload['contact_name'], 'currency_code' => 'USD']], 201),
                (bool) preg_match('#^/books/v3/contacts/#', $path) => $json(['code' => 0, 'contact' => ['contact_id' => 'CT-1', 'currency_code' => 'USD']]),
                $path === '/books/v3/settings/currencies' => $json(['code' => 0, 'currencies' => [['currency_id' => 'C-USD', 'currency_code' => 'USD']]]),
                $path === '/books/v3/customerpayments' && $method === 'POST' => (static function () use (&$payments, $payload, $json): array {
                    $payments[] = $payload;
                    return $json(['code' => 0, 'payment' => ['payment_id' => 'PAY-' . count($payments)]], 201);
                })(),
                default => $json(['code' => 0, 'customerpayments' => [], 'invoices' => [], 'contacts' => []]),
            };
        });
        try {
            CaSyncService::syncPendingRevenue('manual', $this->userId);
        } finally {
            ZohoBooksService::setTransport(null);
            unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT']);
            CompanySettingsRepository::set('zoho_books_enabled', '0');
        }

        $mine = array_values(array_filter($payments, static fn (array $p): bool => str_ends_with((string) ($p['reference_number'] ?? ''), '-m2')));
        self::assertCount(1, $mine);
        self::assertSame(2250.0, (float) $mine[0]['amount']);
        $order = OrderRepository::find($this->orderId);
        self::assertSame('Milestone 2 (50% ' . self::DUE . ') received from TEST-Milestone Buyer for order ' . $order['buyer_inquiry_ref'] . ' · USD 2,250.00 at ₹84.10 · bank ref UTR778899', $mine[0]['description']);
        self::assertSame('PAY-1', PaymentMilestoneRepository::find((int) $m['id'])['zoho_reference']);
    }
}
