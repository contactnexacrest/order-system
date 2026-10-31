<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\GstRefundController;
use App\Helpers\Flash;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\GstRefundRepository;
use App\Services\CaSyncService;
use App\Services\PaymentDescription;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2026-10-31 (option A, confirmed): GST under LUT. The GST
 * paid on purchases is claimed back for a whole period; CA → GST refunds
 * (LUT) records each claim and each bank credit (with its RFD-05 / UTR
 * reference), and every credit goes to Zoho Books as a journal at the next
 * sync. The order keeps its GST in the cost. The Node suite has the same
 * test (gstRefund.test.js).
 */
final class GstRefundTest extends DbTestCase
{
    private int $admin;
    private array $journals = [];
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        $_POST = [];
        $this->tag = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    protected function tearDown(): void
    {
        Flash::pull();
        ZohoBooksService::setTransport(null);
        unset($_ENV['ZOHO_INTEGRATION_ENABLED'], $_ENV['ZOHO_ENVIRONMENT'], $_ENV['ZOHO_EINVOICE_ENABLED']);
        CompanySettingsRepository::set('zoho_books_enabled', '0');
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function post(string $action, array $body, array $params = []): array
    {
        $_POST = $body;
        (new GstRefundController())->{$action}($params);
        $all = Flash::pull();
        return end($all) ?: ['type' => null, 'message' => ''];
    }

    private function claim(string $arn, float $amount = 206000.0): int
    {
        $this->post('recordClaim', ['period_from' => '2026-10-01', 'period_to' => '2026-12-31', 'claim_arn' => $arn, 'filed_on' => date('Y-m-d'), 'amount_claimed' => (string) $amount]);
        return (int) Database::connection()->query("SELECT id FROM gst_refund_claims WHERE claim_arn = '{$arn}'")->fetchColumn();
    }

    public function testAClaimIsRecordedOnceWithItsPeriodAndAmount(): void
    {
        $arn = "AA27{$this->tag}0001";
        $body = ['period_from' => '2026-10-01', 'period_to' => '2026-12-31', 'claim_arn' => $arn, 'filed_on' => date('Y-m-d'), 'amount_claimed' => '206000'];
        self::assertStringContainsString('period the claim covers', $this->post('recordClaim', ['period_to' => '2026-09-30'] + $body)['message']);
        self::assertStringContainsString('RFD-01 claim no.', $this->post('recordClaim', ['claim_arn' => 'AB'] + $body)['message']);
        self::assertStringContainsString('amount claimed must be at least 0.01', $this->post('recordClaim', ['amount_claimed' => '0'] + $body)['message']);
        self::assertFalse(GstRefundRepository::arnTaken($arn), 'nothing saved');

        self::assertSame(['type' => 'success', 'message' => "Claim {$arn} for Oct–Dec 2026 recorded: ₹206,000.00 claimed."], $this->post('recordClaim', $body));
        self::assertSame("Claim {$arn} is already recorded. Nothing was saved.", $this->post('recordClaim', $body)['message']);
    }

    public function testACreditNeedsItsBankReferenceAndCannotExceedTheClaim(): void
    {
        $arn = "AA27{$this->tag}0002";
        $claimId = $this->claim($arn);
        $body = ['amount' => '185400', 'credited_on' => date('Y-m-d'), 'bank_reference' => 'RFD05-AA270226004411', 'part' => 'provisional'];
        $credit = fn (array $b): array => $this->post('recordCredit', $b, ['id' => (string) $claimId]);

        self::assertStringContainsString('bank reference (RFD-05 / UTR) is required', $credit(['bank_reference' => ''] + $body)['message']);
        self::assertStringContainsString('provisional part or the final', $credit(['part' => ''] + $body)['message']);
        self::assertStringContainsString('more than what is still open on this claim (₹206,000.00)', $credit(['amount' => '206000.01'] + $body)['message']);
        self::assertSame([], GstRefundRepository::creditsFor($claimId), 'nothing saved');

        $done = $credit($body);
        $description = "GST refund under LUT, Oct–Dec 2026, claim {$arn} · provisional · ₹185,400.00 credited " . date('j M Y') . ' · bank ref RFD05-AA270226004411';
        self::assertSame(['type' => 'success', 'message' => "Recorded: {$description}. It goes to Zoho Books at the next sync."], $done);

        // The balance, with part of the claim refused: credited + refused may not pass what is still open.
        self::assertStringContainsString('more than what is still open', $credit(['amount' => '20000', 'amount_refused' => '1000', 'part' => 'final'] + $body)['message']);
        $credit(['amount' => '19100', 'amount_refused' => '1500', 'part' => 'final', 'bank_reference' => 'RFD05-AA270326000123'] + $body);
        $claim = GstRefundRepository::findClaim($claimId);
        self::assertSame(['204500.00', '1500.00'], [number_format((float) $claim['amount_credited'], 2, '.', ''), (string) $claim['amount_refused']]);
        self::assertStringContainsString('₹1,500.00 refused', (string) $claim['refused_note']);
        self::assertStringContainsString('more than what is still open on this claim (₹0.00)', $credit(['amount' => '1'] + $body)['message'], 'the claim is closed');
    }

    public function testEachCreditGoesToZohoOnceAsAJournalAndIsThenFixed(): void
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
            return match (true) {
                $path === '/oauth/v2/token' => $json(['access_token' => 'fake-access']),
                $path === '/books/v3/chartofaccounts' => $json(['code' => 0, 'chartofaccounts' => [['account_id' => 'AC-GSTREF', 'account_name' => 'GST Refund Receivable']]]),
                $path === '/books/v3/journals' && $method === 'POST' => (function () use ($payload, $json): array {
                    $id = 'JN-' . (count($this->journals) + 1);
                    $this->journals[$id] = $payload;
                    return $json(['code' => 0, 'journal' => ['journal_id' => $id]], 201);
                })(),
                default => $json(['code' => 5, 'message' => "fake Zoho has no {$method} {$path}"], 400),
            };
        });
        // The test database is shared by the whole run: earlier tests' credits are not this test's.
        Database::connection()->exec("UPDATE gst_refund_credits SET zoho_journal_id = 'OLD' WHERE zoho_journal_id IS NULL");

        $arn = "TEST-{$this->tag}03";
        $claimId = $this->claim($arn);
        $this->post('recordCredit', ['amount' => '185400', 'credited_on' => date('Y-m-d'), 'bank_reference' => 'RFD05-AA270226004411', 'part' => 'provisional'], ['id' => (string) $claimId]);
        $creditId = (int) GstRefundRepository::creditsFor($claimId)[0]['id'];

        self::assertSame(['journals' => 1, 'failed' => 0, 'skipped' => 0], CaSyncService::syncGstRefunds('manual', $this->admin));
        $description = "GST refund under LUT, Oct–Dec 2026, claim {$arn} · provisional · ₹185,400.00 credited " . date('j M Y') . ' · bank ref RFD05-AA270226004411';
        self::assertSame([
            'journal_date' => date('Y-m-d'),
            'reference_number' => "GSTREF-{$arn}-{$creditId}",
            'notes' => "TEST- {$description}",
            'line_items' => [
                ['account_id' => 'SBI-1', 'debit_or_credit' => 'debit', 'amount' => 185400.0, 'description' => $description],
                ['account_id' => 'AC-GSTREF', 'debit_or_credit' => 'credit', 'amount' => 185400.0, 'description' => "GST refund under LUT, claim {$arn}"],
            ],
        ], $this->journals['JN-1'], 'bank received, GST Refund Receivable reduced, with the built description (rule D-212)');
        self::assertSame('JN-1', GstRefundRepository::findCredit($creditId)['zoho_journal_id']);

        self::assertSame(['journals' => 0, 'failed' => 0, 'skipped' => 1], CaSyncService::syncGstRefunds('manual', $this->admin), 'sent once');
        self::assertStringContainsString('already in Zoho Books (journal JN-1)', $this->post('deleteCredit', [], ['creditId' => (string) $creditId])['message']);
        self::assertNotNull(GstRefundRepository::findCredit($creditId), 'not removed once in Books');
    }

    public function testACreditNotYetInZohoCanBeRemoved(): void
    {
        $claimId = $this->claim("AA27{$this->tag}0004");
        $this->post('recordCredit', ['amount' => '1000', 'credited_on' => date('Y-m-d'), 'bank_reference' => 'UTR-TEST-0004', 'part' => 'provisional'], ['id' => (string) $claimId]);
        $creditId = (int) GstRefundRepository::creditsFor($claimId)[0]['id'];
        self::assertSame('success', $this->post('deleteCredit', [], ['creditId' => (string) $creditId])['type']);
        self::assertNull(GstRefundRepository::findCredit($creditId));
    }

    public function testTheOrderKeepsItsGstInTheCostAndShowsItForInformation(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->exec("INSERT INTO order_vendor_bills (order_id, vendor_kind, bill_for, doc_kind, bill_no, bill_date, taxable_inr, gst_inr, total_inr, created_by) VALUES
            ({$orderId}, 'supplier', 'balance', 'invoice', 'TEST-GST-1', CURDATE(), 300000, 54000, 354000, {$this->admin}),
            ({$orderId}, 'logistics', 'cha_transport', 'invoice', 'TEST-GST-2', CURDATE(), 20000, 3600, 23600, {$this->admin}),
            ({$orderId}, 'supplier', 'advance', 'proforma', 'TEST-GST-3', CURDATE(), 84745.76, 15254.24, 100000, {$this->admin})");
        self::assertSame(['supplier' => 54000.0, 'logistics' => 3600.0, 'total' => 57600.0], GstRefundRepository::gstOnOrderBills($orderId), 'tax invoices only (a proforma is replaced by its invoice)');

        ob_start();
        (new \App\Controllers\OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString("For information — GST paid on this order's purchases (INR)</span><span class=\"v\">57,600.00</span>", $html);
        self::assertStringContainsString('Supplier &#8377;54,000.00 · logistics &#8377;3,600.00. Refundable under LUT', $html);

        // CAFIN (owner confirmed the line twice): one information line under MARGIN; the figures do not change.
        $pl = \App\Services\CaFinancialSnapshotService::build($orderId)['pl'];
        self::assertSame(['line', "For information: GST paid on this order's purchases ₹57,600.00 (supplier ₹54,000.00 · logistics ₹3,600.00) is refundable under LUT", 'claimed for the period, not per order — not added to this profit', ''], end($pl));
        self::assertSame('MARGIN', $pl[count($pl) - 2][1]);
    }

    public function testThePeriodLabel(): void
    {
        self::assertSame('Oct–Dec 2026', PaymentDescription::periodLabel('2026-10-01', '2026-12-31'));
        self::assertSame('Oct 2026', PaymentDescription::periodLabel('2026-10-01', '2026-10-31'));
        self::assertSame('Dec 2026–Jan 2027', PaymentDescription::periodLabel('2026-12-01', '2027-01-31'));
    }
}
