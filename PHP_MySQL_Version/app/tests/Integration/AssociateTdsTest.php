<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociateTdsController;
use App\Controllers\IncentiveController;
use App\Helpers\Flash;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Services\AssociateTds;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2 Nov 2026 (D-235): TDS on associate commission worked out
 * by the app — 194H at 2% past ₹20,000 a financial year with catch-up, 20%
 * without a verified PAN, none for associates abroad once TRC / 10F / no-PE
 * are on file (and 15CA / 15CB per payment). Node twin: associateTds.test.js.
 */
final class AssociateTdsTest extends DbTestCase
{
    private int $admin;
    private int $finance1;
    private int $finance2;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $this->tag = bin2hex(random_bytes(3));
        $this->admin = $this->createTestUser('Admin');
        $this->finance1 = $this->createTestUser('Finance Manager');
        $this->finance2 = $this->createTestUser('Finance Director');
        $this->as($this->admin);
        foreach (['associate_tds_section' => '194H', 'associate_tds_pct' => '2.00', 'associate_tds_threshold_inr' => '20000', 'associate_tds_no_pan_pct' => '20.00',
            'associate_tds_foreign' => '0', 'associate_15cb_required' => '1', 'zoho_tds_tax_id' => '', 'associate_bank_wait_days' => '0'] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->admin);
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SESSION = [];
        Flash::pull();
        parent::tearDown();
    }

    private function as(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
    }

    private function associate(array $fields = [], bool $panVerified = true): array
    {
        $id = AssociateRepository::create($fields + ['name' => 'TDS Assoc ' . $this->tag . ' ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'tds-' . bin2hex(random_bytes(4)) . '@assoc-' . $this->tag . '.test', 'status' => 'active', 'pan' => 'ABCDE1234F'], $this->admin);
        if ($panVerified) {
            $this->doc($id, 'pan', null, 'accepted');
        }
        $bank = AssociateBankRepository::request($id, ['holder_name' => 'Holder', 'account_number' => '123456789012', 'ifsc' => 'HDFC0001234', 'swift' => '',
            'bank_name' => 'HDFC Bank', 'bank_branch_address' => '', 'intermediary_bank' => '', 'account_currency' => 'INR'], true, null);
        AssociateBankRepository::firstCheck($bank, $this->finance1, 'Called back on the known number; ₹1 test credit confirmed.');
        AssociateBankRepository::verify($bank, $this->finance2, 0);
        // The payouts below are dated 2 Nov 2026: make the account usable from before then,
        // so this test does not depend on the day it runs.
        \App\Config\Database::connection()->prepare("UPDATE associate_bank_accounts SET usable_from = '2026-04-01' WHERE id = :id")->execute(['id' => $bank]);
        return AssociateRepository::find($id);
    }

    private function doc(int $associateId, string $kind, ?int $payoutId, string $status = 'accepted'): void
    {
        $docId = AssociateDocumentRepository::add($associateId, $kind, $this->createTestFile(), $payoutId, null, false, $this->admin);
        Database::connection()->prepare('UPDATE associate_documents SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $docId]);
    }

    /** A due associate payout of $amount on a fresh order. */
    private function payout(array $a, float $amount, string $month = '2026-10'): array
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        Database::connection()->prepare("INSERT INTO incentive_payouts (order_id, payee_kind, associate_id, role, amount_inr, incentive_month, status) VALUES (:o, 'associate', :a, 'associate', :amt, :m, 'due')")
            ->execute(['o' => $orderId, 'a' => $a['id'], 'amt' => $amount, 'm' => $month]);
        return IncentiveRepository::findPayout((int) Database::connection()->lastInsertId());
    }

    private function pay(array $p, array $post = []): array
    {
        $_POST = $post + ['month' => (string) $p['incentive_month'], 'paid_on' => '2026-11-02', 'paid_via' => 'bank_transfer', 'paid_reference' => 'UTR' . strtoupper(bin2hex(random_bytes(4)))];
        ob_start();
        (new IncentiveController())->markPaid(['payoutId' => (string) $p['id']]);
        ob_end_clean();
        return Flash::pull()[0] ?? ['type' => '', 'message' => ''];
    }

    public function testBelowTheThresholdNoTdsThenTheCrossingPaymentCatchesUp(): void
    {
        $a = $this->associate();
        $p1 = $this->payout($a, 12000);
        $c1 = AssociateTds::compute($p1, '2026-11-02');
        self::assertSame([0.0, 12000.0], [$c1['tds'], $c1['net']]);
        self::assertSame('below threshold — FY 2026-27 total ₹12,000.00 of ₹20,000.00', $c1['reason']);
        self::assertSame('Payment recorded.', $this->pay($p1)['message']);
        $r1 = IncentiveRepository::findPayout((int) $p1['id']);
        self::assertSame(['12000.00', '0.00', 'below threshold — FY 2026-27 total ₹12,000.00 of ₹20,000.00'], [$r1['paid_inr'], $r1['tds_inr'], $r1['tds_reason']]);

        // Crossing payment: TDS on 10,000 + catch-up on 12,000 = 2% of 22,000 = 440.
        $p2 = $this->payout($a, 10000);
        $c2 = AssociateTds::compute($p2, '2026-11-02');
        self::assertSame([440.0, 9560.0, 2.0, '194H', 12000.0], [$c2['tds'], $c2['net'], $c2['rate'], $c2['section'], $c2['catch_up']]);
        self::assertSame('194H 2% — includes catch-up on ₹12,000.00 paid earlier this FY 2026-27 without TDS', $c2['reason']);
        self::assertSame('Payment recorded.', $this->pay($p2)['message']);
        $r2 = IncentiveRepository::findPayout((int) $p2['id']);
        self::assertSame(['9560.00', '440.00', '2.00', '194H'], [$r2['paid_inr'], $r2['tds_inr'], $r2['tds_rate'], $r2['tds_section']]);

        // Later in the year: full amount, no catch-up again.
        $c3 = AssociateTds::compute($this->payout($a, 5000), '2026-12-15');
        self::assertSame([100.0, 0.0, '194H 2%'], [$c3['tds'], $c3['catch_up'], $c3['reason']]);
        // A new financial year starts again below the threshold.
        self::assertSame(0.0, AssociateTds::compute($this->payout($a, 5000), '2027-04-10')['tds']);
    }

    public function testWithoutAVerifiedPanTheRateIsTwentyPercent(): void
    {
        $a = $this->associate([], false);
        $c = AssociateTds::compute($this->payout($a, 25000), '2026-11-02');
        self::assertSame([5000.0, 20.0, '206AA', 'no PAN 20% (206AA)'], [$c['tds'], $c['rate'], $c['section'], $c['reason']]);
    }

    public function testAChangedTdsNeedsAReasonAndPaidPlusTdsNeverExceedsTheCommission(): void
    {
        $a = $this->associate();
        $p = $this->payout($a, 25000);
        $f = $this->pay($p, ['paid_inr' => '25000', 'tds_inr' => '0']);
        self::assertStringStartsWith('The TDS worked out for this payment is ₹500.00 (194H 2%). To record a different TDS, give the reason', $f['message']);
        $f = $this->pay($p, ['paid_inr' => '24800', 'tds_inr' => '500']);
        self::assertSame('The amount paid plus the TDS cannot be more than the commission (₹25,000.00).', $f['message']);
        $f = $this->pay($p, ['paid_inr' => '24750', 'tds_inr' => '250', 'tds_override_reason' => 'Lower-deduction certificate u/s 197 at 1%.']);
        self::assertSame('Payment recorded.', $f['message']);
        $r = IncentiveRepository::findPayout((int) $p['id']);
        self::assertSame(['250.00', '1.00'], [$r['tds_inr'], $r['tds_rate']]);
        self::assertSame('changed by hand: Lower-deduction certificate u/s 197 at 1%. (worked out: ₹500.00, 194H 2%)', $r['tds_reason']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ASSOCIATE_TDS_OVERRIDDEN' AND entity_id = {$p['id']}")->fetchColumn());
    }

    public function testAbroadNoTdsButTrc10fNoPeAnd15ca15cbMustBeOnFile(): void
    {
        $a = $this->associate(['based_in' => 'abroad', 'country' => 'United Kingdom', 'currency_code' => 'GBP', 'pan' => null], false);
        $p = $this->payout($a, 40000);
        $c = AssociateTds::compute($p, '2026-11-02');
        self::assertSame([0.0, 40000.0], [$c['tds'], $c['net']]);
        self::assertSame(['Tax Residency Certificate (TRC)', 'Form 10F', 'No-PE declaration'], $c['missing_foreign']);
        self::assertSame(['Form 15CA', 'Form 15CB'], $c['missing_payout']);
        $f = $this->pay($p);
        self::assertSame('Before paying an associate based abroad, these must be on file: Tax Residency Certificate (TRC), Form 10F, No-PE declaration, Form 15CA, Form 15CB. The TRC, Form 10F and no-PE declaration are accepted on the associate\'s Documents tab; Form 15CA and 15CB are attached to this payment.', $f['message']);
        foreach (['tax_residency', 'form_10f', 'no_pe'] as $kind) {
            $this->doc((int) $a['id'], $kind, null);
        }
        $this->doc((int) $a['id'], 'form_15ca', (int) $p['id'], 'received');
        self::assertStringStartsWith('Before paying an associate based abroad, these must be on file: Form 15CB.', $this->pay($p)['message']);
        $this->doc((int) $a['id'], 'form_15cb', (int) $p['id'], 'received');
        self::assertSame('Payment recorded.', $this->pay($p)['message']);
        $r = IncentiveRepository::findPayout((int) $p['id']);
        self::assertSame(['40000.00', '0.00', 'abroad — no TDS (TRC/10F/no-PE on file)'], [$r['paid_inr'], $r['tds_inr'], $r['tds_reason']]);
    }

    public function testTheD212WordingAndTheZohoBillWithAndWithoutATdsTax(): void
    {
        self::assertSame('Associate commission for Ravi Kumar — order SC/ORD/26/001 — gross ₹25,000.00, TDS u/s 194H ₹500.00 (2%), net paid ₹24,500.00 · UTR UTR123456',
            AssociateTds::paymentDescription('Ravi Kumar', ['SC/ORD/26/001'], 25000, 500, 2.0, '194H', 24500, 'UTR123456'));
        self::assertSame('Associate commission for Ravi Kumar — orders A, B — gross ₹1,000.00, no TDS, net paid ₹1,000.00 · UTR U1',
            AssociateTds::paymentDescription('Ravi Kumar', ['A', 'B'], 1000, 0, null, null, 1000, 'U1'));

        $a = $this->associate();
        $p = $this->payout($a, 25000);
        $this->pay($p, ['paid_reference' => 'UTR555666']);
        $ref = (string) Database::connection()->query("SELECT buyer_inquiry_ref FROM orders WHERE id = {$p['order_id']}")->fetchColumn();
        $cost = ['order_id' => $p['order_id'], 'buyer_inquiry_ref' => $ref, 'buyer_name' => 'Buyer'];
        $c = ZohoBooksService::associateCommission($cost);
        self::assertSame([25000.0, 24500.0, 500.0, null, true], [$c['gross_inr'], $c['paid_inr'], $c['tds_inr'], $c['tds_tax_id'], $c['tds_left_for_ca']]);
        self::assertStringStartsWith("Associate commission for {$a['name']} — order {$ref} — gross ₹25,000.00, TDS u/s 194H ₹500.00 (2%), net paid ₹24,500.00 · UTR UTR555666 · against bill ", $c['payment_description']);
        IncentiveRepository::setSetting('zoho_tds_tax_id', '460000000012345', $this->admin);
        $c = ZohoBooksService::associateCommission($cost);
        self::assertSame(['460000000012345', false], [$c['tds_tax_id'], $c['tds_left_for_ca']]);
        // The order's cost stays the gross commission, TDS included.
        $entry = IncentiveRepository::costEntries((int) $p['order_id'])['associate'] ?? null;
        if ($entry !== null) {
            self::assertSame('25000.00', (string) Database::connection()->query("SELECT amount_inr FROM order_cost_entries WHERE id = {$entry['id']}")->fetchColumn());
        }
    }

    public function testTheRegisterTotalsChallanAndPermissions(): void
    {
        $a = $this->associate();
        $p = $this->payout($a, 25000);
        $this->pay($p);
        $rows = AssociateTds::register('2026-04-01', 3);
        $mine = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === (int) $p['id']));
        self::assertCount(1, $mine);
        self::assertSame([3, '500.00', '24500.00', 0], [$mine[0]['quarter'], $mine[0]['tds_inr'], $mine[0]['paid_inr'], (int) $mine[0]['form16a_count']]);
        self::assertSame([], array_values(array_filter(AssociateTds::register('2026-04-01', 2), static fn (array $r): bool => (int) $r['id'] === (int) $p['id'])));

        $_POST = ['tds_challan_no' => '0510308 07112026 00045', 'tds_challan_date' => '2026-11-02', 'fy' => '2026-04-01'];
        ob_start();
        (new AssociateTdsController())->saveChallan(['payoutId' => (string) $p['id']]);
        ob_end_clean();
        self::assertSame('Challan saved.', Flash::pull()[0]['message'] ?? '');
        self::assertSame('0510308 07112026 00045', IncentiveRepository::findPayout((int) $p['id'])['tds_challan_no']);

        $_GET = ['fy' => '2026-04-01', 'q' => '3'];
        ob_start();
        (new AssociateTdsController())->register([]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('TDS on associate commission', $html);
        self::assertStringContainsString('0510308 07112026 00045', $html);

        $role = static fn (string $name): int => (int) Database::connection()->query('SELECT id FROM roles WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
        $sales = $this->createTestUser('Export Executive');
        foreach (['manage_incentives', 'pay_incentives', 'ca_module_view'] as $perm) {
            self::assertFalse(\App\Services\PermissionService::can($sales, $role('Export Executive'), $perm), "Sales has no {$perm}, so no TDS register");
        }
        self::assertTrue(\App\Services\PermissionService::can($this->finance1, $role('Finance Manager'), 'pay_incentives'));

        // Reminders: deposit by the 7th, Form 26Q after the quarter.
        $types = array_column(AssociateTds::reminders('2026-12-03'), 'type');
        self::assertContains('associate_tds_deposit', $types);
        Database::connection()->exec("UPDATE incentive_payouts SET tds_challan_no = 'X' WHERE payee_kind = 'associate' AND tds_inr > 0 AND (tds_challan_no IS NULL OR tds_challan_no = '')");
        self::assertNotContains('associate_tds_deposit', array_column(AssociateTds::reminders('2026-12-03'), 'type'));
        self::assertContains('associate_tds_26q', array_column(AssociateTds::reminders('2027-01-25'), 'type'));
    }

    public function testSettingsAreSavedWithAReasonAndAudited(): void
    {
        $_POST = ['associate_tds_section' => '194H', 'associate_tds_pct' => '2', 'associate_tds_threshold_inr' => '20000', 'associate_tds_no_pan_pct' => '20',
            'associate_15cb_required' => '1', 'zoho_tds_tax_id' => '460000000012345', 'reason' => 'short'];
        ob_start();
        (new AssociateTdsController())->saveSettings([]);
        ob_end_clean();
        self::assertStringStartsWith('Give the reason for the change', Flash::pull()[0]['message'] ?? '');
        $_POST['reason'] = 'CA confirmed 194H at 2% on 2 Nov 2026.';
        ob_start();
        (new AssociateTdsController())->saveSettings([]);
        ob_end_clean();
        Flash::pull();
        self::assertSame('460000000012345', AssociateTds::settings()['zoho_tax_id']);
        self::assertGreaterThanOrEqual(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ASSOCIATE_TDS_SETTING_CHANGED' AND field_name = 'zoho_tds_tax_id'")->fetchColumn());
    }
}
