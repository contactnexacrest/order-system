<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociateAdminController;
use App\Controllers\AssociatePortalController;
use App\Controllers\ClientIntakeController;
use App\Controllers\ClientIntakeReviewController;
use App\Controllers\IncentiveController;
use App\Helpers\Flash;
use App\Middleware\AssociateAuth;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociateEventRepository;
use App\Repositories\AssociateInvitationRepository;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociateIntakeService;
use App\Services\AssociatePortalService;
use App\Services\AssociateService;
use App\Services\IncentiveService;
use App\Services\ZohoBooksService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 1 Nov 2026 ("AS all as recommended", AS-1 … AS-17): the
 * associate system — personal codes and one-off links, the buyer's own
 * registration with e-mail code and confirmation, first completed
 * registration wins, the 12-month lock and a direct approach caught; the
 * standard ladder and personal rates fixed on the order; commission only
 * when the buyer has paid in full, pro rata on short pay, held while
 * suspended, clawed back after a refund; bank details checked by two
 * people with a waiting period; the portal shows only the associate's own,
 * never prices, costs or profit. The Node suite has the same test
 * (associateSystem.test.js).
 */
final class AssociateSystemTest extends DbTestCase
{
    private int $admin;
    private int $md;
    private int $ed;
    private int $finance1;
    private int $finance2;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SESSION = [];
        $this->tag = bin2hex(random_bytes(3));
        $this->admin = $this->createTestUser('Admin');
        $this->md = $this->createTestUser('Managing Director');
        $this->ed = $this->createTestUser('Executive Director');
        $this->finance1 = $this->createTestUser('Finance Manager');
        $this->finance2 = $this->createTestUser('Finance Director');
        $this->as($this->admin);
        foreach (['associate_bank_wait_days' => '7', 'associate_protection_months' => '12', 'associate_tds_pct' => '2.00', 'cutoff_day' => '20',
            'associate_commission_due_days' => '30', 'associate_claim_hold' => '1', 'associate_payout_run' => 'monthly', 'associate_payout_day' => '5'] as $k => $v) {
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

    private function associate(array $fields = []): array
    {
        $id = AssociateRepository::create($fields + ['name' => 'Assoc ' . $this->tag . ' ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'assoc-' . bin2hex(random_bytes(4)) . '@assoc-' . $this->tag . '.test', 'status' => 'active'], $this->admin);
        AssociateCodeService::issue($id, 'R' . strtoupper($this->tag));
        return AssociateRepository::find($id);
    }

    /** A client with its own name, VAT and e-mail domain, so the identity match sees only what the test means. */
    private function client(string $name): int
    {
        $id = $this->createTestClient();
        Database::connection()->prepare('UPDATE clients SET company_legal_name = :n, vat_eori_tax_no = :v, email = :e WHERE id = :id')
            ->execute(['n' => $name . ' ' . $this->tag, 'v' => 'VAT' . strtoupper(bin2hex(random_bytes(4))), 'e' => 'buyer@' . bin2hex(random_bytes(4)) . '.example', 'id' => $id]);
        return $id;
    }

    /** USD order of $client: products USD 4,500; advance USD 1,800 and balance USD $balance (cleared if $cleared). */
    private function order(int $clientId, float $balance = 2700, bool $cleared = true): int
    {
        $pdo = Database::connection();
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->admin]);
        $next = (int) $pdo->query('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM orders WHERE client_id = ' . $clientId)->fetchColumn();
        $pdo->prepare('UPDATE orders SET client_id = :c, sequence_no = :n WHERE id = :o')->execute(['c' => $clientId, 'n' => $next, 'o' => $orderId]);
        $pdo->prepare("INSERT INTO order_products (order_id, line_no, description, quantity, unit, unit_price, fob_value) VALUES (:o, 1, 'Granite slabs', 100, 'PCS', 45, 4500)")->execute(['o' => $orderId]);
        $pdo->prepare('INSERT IGNORE INTO order_payment_status (order_id) VALUES (:o)')->execute(['o' => $orderId]);
        $pdo->prepare('UPDATE order_payment_status SET advance_amount = 1800, advance_cleared_at = :a, advance_inr_actual = 150000, advance_exchange_rate = 83.3333,
            balance_amount = :bal, balance_cleared_at = :b, balance_inr_actual = :binr, balance_exchange_rate = 92.5926 WHERE order_id = :o')
            ->execute(['a' => '2026-10-10 10:00:00', 'bal' => $balance, 'b' => $cleared ? '2026-10-25 11:00:00' : null, 'binr' => $cleared ? round($balance * 92.5926, 2) : null, 'o' => $orderId]);
        AssociateService::onOrderCreated($orderId);
        return $orderId;
    }

    private function flash(): array
    {
        return Flash::pull()[0] ?? ['type' => '', 'message' => ''];
    }

    private function render(callable $call): string
    {
        ob_start();
        try {
            $call();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    private function verifiedBank(array $a): int
    {
        $id = AssociateBankRepository::request((int) $a['id'], ['holder_name' => $a['name'], 'account_number' => '123456789012', 'ifsc' => 'HDFC0001234', 'swift' => '',
            'bank_name' => 'HDFC Bank', 'bank_branch_address' => '', 'intermediary_bank' => '', 'account_currency' => 'INR'], true, null);
        AssociateBankRepository::firstCheck($id, $this->finance1, 'Called back on the known number; ₹1 test credit confirmed.');
        AssociateBankRepository::verify($id, $this->finance2, 0);
        return $id;
    }

    // ---------------------------------------------------------------- codes

    public function testAPersonalCodeHasACheckLetterAndBelongsToOneAssociateForEver(): void
    {
        $a = $this->associate();
        $code = (string) $a['personal_code'];
        self::assertMatchesRegularExpression('/^NXA-R[0-9A-Z]+-[A-Z0-9]{2}$/', $code);
        self::assertTrue(AssociateCodeService::isValid($code));
        self::assertSame((int) $a['id'], (int) AssociateCodeService::resolve(strtolower($code))['id'], 'case does not matter');
        self::assertSame((int) $a['id'], (int) AssociateCodeService::resolve(' ' . str_replace('O', '0', $code) . ' ')['id'], 'O and 0 are the same');
        $last = substr($code, -1);
        $typo = substr($code, 0, -1) . ($last === 'A' ? 'B' : 'A');
        self::assertNull(AssociateCodeService::resolve($typo), 'a mistyped check letter is caught');

        $new = AssociateCodeService::issue((int) $a['id'], 'NEWNAME');
        // D-254 (owner, 4 Nov 2026): the old code keeps bringing buyers to the same associate…
        self::assertSame((int) $a['id'], (int) AssociateCodeService::resolve($code)['id'], 'the old code keeps working');
        // …until staff block it.
        $newer = AssociateCodeService::issue((int) $a['id'], 'NEWER', true, 'Leaked in a public post');
        self::assertNull(AssociateCodeService::resolve($new), 'a blocked code no longer brings new clients');
        $new = $newer;
        self::assertSame((int) $a['id'], (int) AssociateCodeService::resolve($new)['id']);
        $count = (int) Database::connection()->query("SELECT COUNT(*) FROM associate_codes WHERE code = " . Database::connection()->quote($code))->fetchColumn();
        self::assertSame(1, $count, 'the retired code is kept, so it is never given to anyone else');
    }

    // ---------------------------------------------------------------- rates (AS-17)

    private function level(string $name): int
    {
        return (int) Database::connection()->query('SELECT id FROM commission_levels WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
    }

    /** Owner decision D-245 P3: the commission level is the associate's fixed level or the ladder step; none when marked "no commission". */
    public function testTheCommissionLevelIsTheFixedLevelOrTheLadderAndNoCommission(): void
    {
        $ladder = $this->associate();
        self::assertSame('Level 1 — 2%', \App\Services\AssociateCommission::levelLabel(\App\Services\AssociateCommission::levelForAssociate($ladder, null)[0]), 'first order on the ladder: step 1');
        $fixed = $this->associate();
        \App\Repositories\PricingRepository::setAssociateCommission((int) $fixed['id'], 'fixed', $this->level('Level 3'));
        self::assertSame('Level 3 — 7%', \App\Services\AssociateCommission::levelLabel(\App\Services\AssociateCommission::levelForAssociate(AssociateRepository::find((int) $fixed['id']), null)[0]));
        $none = $this->associate(['no_commission' => 1]);
        $client = $this->client('No Level Buyer');
        AssociateService::linkClient($client, (int) $none['id'], 'code', null, '2026-10-01', $this->admin);
        self::assertNull(IncentiveService::calculate($this->order($client))['associate'], 'no commission under the agreement');
    }

    public function testTheLevelIsFixedAtThePiAndEarnedOnlyWhenTheBuyerHasPaidEverything(): void
    {
        $a = $this->associate();
        \App\Repositories\PricingRepository::setAssociateCommission((int) $a['id'], 'fixed', $this->level('Level 2'));
        $client = $this->client('Rate Fixed Buyer');
        AssociateService::linkClient($client, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        $orderId = $this->order($client, 2700, false);
        $row = Database::connection()->query("SELECT associate_id, associate_rate_pct, associate_rate_source FROM orders WHERE id = {$orderId}")->fetch();
        self::assertSame([(string) $a['id'], '5.00', 'fixed'], [(string) $row['associate_id'], $row['associate_rate_pct'], $row['associate_rate_source']]);
        self::assertTrue(AssociateEventRepository::hasEvent((int) $a['id'], 'order_created', $client, $orderId), 'the associate is told of the new order');

        \App\Services\AssociateCommission::fixLevelAtPi($orderId);
        \App\Repositories\PricingRepository::setAssociateCommission((int) $a['id'], 'fixed', $this->level('Level 4'));
        IncentiveService::lockIfReady($orderId, $this->admin);
        self::assertNull(IncentiveRepository::forOrder($orderId), 'advance alone: nothing earned');
        $line = AssociatePortalRepository::orders((int) $a['id'], $orderId)[0]['commission'];
        self::assertSame(['expected', 5.0, 225.0, 'Level 2 — 5%'], [$line['state'], $line['rate'], $line['amount'], $line['level']], 'the order keeps the level fixed at its PI');

        Database::connection()->prepare("UPDATE order_payment_status SET balance_cleared_at = '2026-10-25 11:00:00', balance_inr_actual = 250000 WHERE order_id = :o")->execute(['o' => $orderId]);
        IncentiveService::lockIfReady($orderId, $this->admin);
        $p = array_values(array_filter(IncentiveRepository::payoutsForOrder($orderId), static fn (array $p): bool => $p['payee_kind'] === 'associate'))[0];
        self::assertSame(['225.00', 'USD', 'due', '2026-12', '2026-11-24', '2026-12-05'], [$p['amount_fc'], $p['currency_code'], $p['status'], $p['incentive_month'], $p['due_on'], $p['payout_run_on']]);
        self::assertTrue(AssociateEventRepository::hasEvent((int) $a['id'], 'commission_earned', $client, $orderId));
        self::assertSame('earned', AssociatePortalRepository::orders((int) $a['id'], $orderId)[0]['commission']['state']);
    }

    public function testNoCommissionAssociateCancelledOrderAndAnUnpaidShortfallEarnsNothing(): void
    {
        $none = $this->associate(['no_commission' => 1]);
        $c1 = $this->client('No Commission Buyer');
        AssociateService::linkClient($c1, (int) $none['id'], 'code', null, '2026-10-01', $this->admin);
        $o1 = $this->order($c1);
        self::assertNull(IncentiveService::calculate($o1)['associate'], 'no commission under the agreement');
        self::assertSame('No commission under your agreement', AssociatePortalRepository::orders((int) $none['id'], $o1)[0]['commission']['label']);

        // D-245 P3: no pro rata — a buyer who did not pay in full earns the associate nothing.
        $a = $this->associate();
        $c2 = $this->client('Short Pay Buyer');
        AssociateService::linkClient($c2, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        $o2 = $this->order($c2, 2250);
        Database::connection()->prepare("INSERT INTO order_payment_shortfalls (order_id, leg, expected_amount, received_amount, shortfall_amount, treatment, reason, approved_by, settlement_kind)
                                          VALUES (:o, 'balance', 2700, 2250, 450, 'written_off', 'The buyer did not pay the rest', :u, 'unpaid')")->execute(['o' => $o2, 'u' => $this->admin]);
        $calc = IncentiveService::calculate($o2)['associate'];
        self::assertSame(['unpaid', 0.0], [$calc['state'], $calc['amount_fc']], 'USD 4,050 of 4,500 received: nothing');
        IncentiveService::lockIfReady($o2, $this->admin);
        self::assertSame([], array_values(array_filter(IncentiveRepository::payoutsForOrder($o2), static fn (array $p): bool => $p['payee_kind'] === 'associate')));
        self::assertSame('No commission — the buyer did not pay this order in full', AssociatePortalRepository::orders((int) $a['id'], $o2)[0]['commission']['label']);

        Database::connection()->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :o")->execute(['o' => $o1]);
        self::assertSame('No commission — order cancelled', AssociatePortalRepository::orders((int) $none['id'], $o1)[0]['commission']['label']);
    }

    // ---------------------------------------------------------------- bank (AS-7)

    public function testBankDetailsNeedTwoDifferentPeopleAndAWaitBeforeTheFirstPayment(): void
    {
        $a = $this->associate();
        $_POST = ['holder_name' => 'X', 'account_number' => '123456789012', 'account_number_confirm' => '123456789013', 'ifsc' => 'HDFC0001234', 'bank_name' => 'HDFC Bank', 'account_currency' => 'INR'];
        self::assertSame('Enter the account holder\'s name as the bank has it (as on the PAN).', AssociateAdminController::validateBank($a, $_POST));
        $_POST['holder_name'] = 'Ravi Kumar';
        self::assertSame('The two account numbers do not match — type it again.', AssociateAdminController::validateBank($a, $_POST));
        $_POST['account_number_confirm'] = '1234 5678 9012';
        $_POST['ifsc'] = 'HDFC1234';
        self::assertSame('Enter the 11-character IFSC (e.g. HDFC0001234).', AssociateAdminController::validateBank($a, $_POST));
        $abroad = $a + ['based_in' => 'abroad'];
        $abroad['based_in'] = 'abroad';
        self::assertSame('Enter the bank\'s SWIFT / BIC (8 or 11 characters).', AssociateAdminController::validateBank($abroad, ['holder_name' => 'R K', 'account_number' => 'DE89370400440532013000', 'account_number_confirm' => 'DE89370400440532013000', 'bank_name' => 'Deutsche Bank', 'account_currency' => 'EUR']));

        $old = $this->verifiedBank($a);
        $id = AssociateBankRepository::request((int) $a['id'], ['holder_name' => 'Ravi Kumar', 'account_number' => '999988887777', 'ifsc' => 'ICIC0001234', 'swift' => '',
            'bank_name' => 'ICICI Bank', 'bank_branch_address' => '', 'intermediary_bank' => '', 'account_currency' => 'INR'], true, null);
        self::assertNotSame('999988887777', Database::connection()->query("SELECT account_number_enc FROM associate_bank_accounts WHERE id = {$id}")->fetchColumn(), 'stored encrypted');
        self::assertSame('999988887777', AssociateBankRepository::fullNumber($id));

        $this->as($this->finance1);
        $_POST = ['check_method' => 'Called back on the known number; ₹1 test credit confirmed.'];
        (new AssociateAdminController())->firstCheck(['id' => (string) $a['id'], 'bankId' => (string) $id]);
        self::assertSame('success', $this->flash()['type']);
        (new AssociateAdminController())->verifyBank(['id' => (string) $a['id'], 'bankId' => (string) $id]);
        self::assertSame('A different person must verify — you already acted on this request.', $this->flash()['message']);
        $this->as($this->finance2);
        (new AssociateAdminController())->verifyBank(['id' => (string) $a['id'], 'bankId' => (string) $id]);
        self::assertStringStartsWith('Verified. Usable from ', $this->flash()['message']);
        self::assertSame($old, (int) AssociateBankRepository::usable((int) $a['id'])['id'], 'during the 7 days the old verified account is still used');
        self::assertSame($id, (int) AssociateBankRepository::usable((int) $a['id'], date('Y-m-d', strtotime('+7 days')))['id'], 'then the new one');
        self::assertTrue(AssociateEventRepository::hasEvent((int) $a['id'], 'bank_changed', null, null), 'the associate is e-mailed');
    }

    public function testAnAssociateIsPaidOnlyIntoAVerifiedAccountAndAClawBackComesOffTheNextPayment(): void
    {
        $a = $this->associate();
        $c1 = $this->client('Pay Buyer One');
        $c2 = $this->client('Pay Buyer Two');
        AssociateService::linkClient($c1, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        AssociateService::linkClient($c2, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        $o1 = $this->order($c1);
        IncentiveService::lockIfReady($o1, $this->admin);
        $p1 = array_values(array_filter(IncentiveRepository::payoutsForOrder($o1), static fn (array $p): bool => $p['payee_kind'] === 'associate'))[0];

        $_POST = ['month' => '2026-11', 'paid_on' => date('Y-m-d'), 'paid_via' => 'bank_transfer', 'paid_reference' => 'UTR' . strtoupper($this->tag) . '01'];
        (new IncentiveController())->markPaid(['payoutId' => (string) $p1['id']]);
        self::assertStringStartsWith('This associate has no verified bank account usable on ', $this->flash()['message']);
        $this->verifiedBank($a);
        $this->completeAssociateKyc((int) $a['id']); // D-252: paid only once the KYC is complete
        // D-245 P3: paid only from its due date (30 days after full payment).
        $_POST['month'] = $p1['incentive_month'];
        (new IncentiveController())->markPaid(['payoutId' => (string) $p1['id']]);
        self::assertStringStartsWith('This commission is due on 24 Nov 2026', $this->flash()['message']);
        Database::connection()->prepare("UPDATE incentive_payouts SET due_on = '2026-10-30', incentive_month = '2026-11' WHERE order_id = :o")->execute(['o' => $o1]);
        $_POST['month'] = '2026-11';
        $_POST['paid_inr'] = (string) round((float) $p1['amount_inr'] * 0.98, 2);
        $_POST['tds_inr'] = (string) round((float) $p1['amount_inr'] * 0.02, 2);
        // D-235: below the ₹20,000 threshold the app works out no TDS; a different figure needs a reason.
        $_POST['tds_override_reason'] = 'CA advised deducting from the first payment.';
        (new IncentiveController())->markPaid(['payoutId' => (string) $p1['id']]);
        self::assertSame('Payment recorded.', $this->flash()['message']);
        self::assertTrue(AssociateEventRepository::hasEvent((int) $a['id'], 'payout_paid', null, $o1));

        $paid = IncentiveRepository::findPayout((int) $p1['id']);
        // D-245 P3: a later refund to the buyer does not take commission back — only fraud or breach does.
        $_POST = ['amount_inr' => '1000', 'reason' => 'Buyer refunded 20% for a damaged crate.'];
        (new AssociateAdminController())->clawback(['payoutId' => (string) $p1['id']]);
        self::assertStringContainsString('only for the associate\'s fraud or breach', $this->flash()['message']);
        $_POST = ['amount_inr' => '1000', 'reason' => 'Invented a second buyer quote to inflate the order.', 'takeback_kind' => 'fraud'];
        (new AssociateAdminController())->clawback(['payoutId' => (string) $p1['id']]);
        self::assertSame('success', $this->flash()['type']);
        $claw = Database::connection()->query("SELECT * FROM incentive_payouts WHERE clawback_of_payout_id = {$p1['id']}")->fetch();
        self::assertSame(['-1000.00', 'due', 'fraud'], [$claw['amount_inr'], $claw['status'], $claw['takeback_kind']]);
        self::assertStringStartsWith('Taken back for fraud: commission paid on order ', (string) $claw['note']);
        $_POST = ['amount_inr' => (string) ((float) $paid['paid_inr']), 'reason' => 'Breach found later as well.', 'takeback_kind' => 'breach'];
        (new AssociateAdminController())->clawback(['payoutId' => (string) $p1['id']]);
        self::assertStringStartsWith('Enter the amount to claw back, above 0 and at most what is left', $this->flash()['message'], 'never more than was paid');

        Database::connection()->prepare('UPDATE incentive_payouts SET incentive_month = :m WHERE id = :id')->execute(['m' => '2026-11', 'id' => $claw['id']]);
        $o2 = $this->order($c2);
        IncentiveService::lockIfReady($o2, $this->admin);
        Database::connection()->prepare("UPDATE incentive_payouts SET due_on = '2026-10-30', incentive_month = '2026-11' WHERE order_id = :o")->execute(['o' => $o2]);
        $p2 = array_values(array_filter(IncentiveRepository::payoutsForOrder($o2), static fn (array $p): bool => $p['payee_kind'] === 'associate'))[0];
        $_POST = ['month' => '2026-11', 'paid_on' => date('Y-m-d'), 'paid_via' => 'bank_transfer', 'paid_reference' => 'UTR' . strtoupper($this->tag) . '02', 'paid_inr' => (string) ((float) $p2['amount_inr'] - 1000)];
        (new IncentiveController())->markPaid(['payoutId' => (string) $p2['id']]);
        $claw = IncentiveRepository::findPayout((int) $claw['id']);
        self::assertSame(['paid', 'Deducted from payout no. ' . $p2['id']], [$claw['status'], $claw['paid_reference']]);
    }

    public function testSuspensionHoldsCommissionUntilReleased(): void
    {
        $a = $this->associate();
        $c = $this->client('Held Buyer');
        AssociateService::linkClient($c, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        $this->verifiedBank($a);
        $this->as($this->md);
        $_POST = ['status' => 'suspended', 'reason' => 'Agreement under review.'];
        (new AssociateAdminController())->changeStatus(['id' => (string) $a['id']]);
        self::assertSame('Status changed to Suspended.', $this->flash()['message']);
        $o = $this->order($c);
        IncentiveService::lockIfReady($o, $this->admin);
        $p = array_values(array_filter(IncentiveRepository::payoutsForOrder($o), static fn (array $p): bool => $p['payee_kind'] === 'associate'))[0];
        self::assertSame(['held', 'The associate is suspended.'], [$p['status'], $p['hold_reason']]);
        self::assertSame('held', AssociatePortalRepository::orders((int) $a['id'], $o)[0]['commission']['state']);
        (new AssociateAdminController())->releasePayout(['payoutId' => (string) $p['id']]);
        self::assertSame('due', IncentiveRepository::findPayout((int) $p['id'])['status']);
    }

    // ---------------------------------------------------------------- the buyer's own registration (AS-5)

    private function intakePost(string $company, array $extra = []): array
    {
        return $extra + ['company_legal_name' => $company, 'billing_address_line1' => '1 Harbour Road', 'billing_city' => 'Rotterdam', 'billing_country' => 'Netherlands',
            'vat_eori_tax_no' => 'NL' . strtoupper(bin2hex(random_bytes(5))), 'contact_person' => 'Jan Buyer', 'email' => 'jan@' . bin2hex(random_bytes(4)) . '-buyer.example',
            'country_of_destination' => 'Netherlands', 'incoterm_preference' => 'FOB', 'consignee_same_as_buyer' => '1', 'website' => ''];
    }

    /** Submits the public form; returns the submission id and the e-mail code. */
    private function submit(array $post): array
    {
        $_SERVER['REMOTE_ADDR'] = '10.9.' . random_int(0, 250) . '.' . random_int(1, 250);
        $_POST = $post;
        $_SESSION = [];
        $this->render(fn () => (new ClientIntakeController())->submit([]));
        $id = (int) Database::connection()->query('SELECT MAX(id) FROM client_intake_submissions')->fetchColumn();
        $code = null;
        foreach (Flash::pull() as $f) {
            if (preg_match('/the e-mail code is (\d{6})/', $f['message'], $m)) {
                $code = $m[1];
            }
        }
        if ($code === null && isset($_SESSION[AssociateIntakeService::SESSION_KEY])) {
            $code = AssociateIntakeService::sendEmailCode($id, (string) $post['email']);
        }
        return [$id, $code];
    }

    private function verify(int $id, string $code, string $introduced): void
    {
        $_SESSION[AssociateIntakeService::SESSION_KEY] = $id;
        $_POST = ['code' => $code, 'introduced' => $introduced];
        $this->render(fn () => (new ClientIntakeController())->verify([]));
    }

    private function accept(int $id): array
    {
        $this->as($this->admin);
        (new ClientIntakeReviewController())->accept(['id' => (string) $id]);
        return $this->flash();
    }

    public function testAOneOffLinkTheBuyerVerifiesAndConfirmsThenStaffAcceptAndTheTwelveMonthLockStarts(): void
    {
        $a = $this->associate();
        $inv = AssociateInvitationRepository::createLink((int) $a['id'], 'Link Buyer BV', 'Netherlands');
        $_GET = ['inv' => $inv['token']];
        $form = $this->render(fn () => (new ClientIntakeController())->show([]));
        // Owner decision 1 Nov 2026 (revised): the link travels as a hidden field — nothing for the buyer to tick or type.
        self::assertStringContainsString('<input type="hidden" name="associate_inv" value="' . $inv['token'] . '">', $form);
        self::assertStringNotContainsString('introduction_confirmed', $form);
        self::assertStringNotContainsString(htmlspecialchars((string) $a['name']), $form, 'the associate is not named on the buyer\'s form');
        self::assertSame('opened', AssociateInvitationRepository::find($inv['id'])['status']);

        $company = 'Link Buyer BV ' . $this->tag;
        [$id, $code] = $this->submit($this->intakePost($company, ['associate_inv' => $inv['token']]));
        self::assertNotNull($code);
        self::assertStringContainsString('has not yet verified their e-mail', $this->accept($id)['message'], 'not verified: cannot be accepted yet');

        $this->verify($id, '000000' === $code ? '111111' : '000000', 'yes');
        self::assertSame('That code is not correct.', $this->flash()['message']);
        $this->verify($id, $code, 'yes');
        self::assertSame('registered', AssociateInvitationRepository::find($inv['id'])['status']);

        $f = $this->accept($id);
        self::assertStringContainsString("Linked to associate {$a['name']} — protected until ", $f['message']);
        $client = Database::connection()->query('SELECT * FROM clients WHERE company_legal_name = ' . Database::connection()->quote($company))->fetch();
        self::assertSame([(string) $a['id'], 'link', date('Y-m-d', strtotime('+12 months'))], [(string) $client['associate_id'], $client['associate_source'], $client['associate_locked_until']]);
        self::assertSame('accepted', AssociateInvitationRepository::find($inv['id'])['status']);
        self::assertTrue(AssociateEventRepository::hasEvent((int) $a['id'], 'client_accepted', (int) $client['id'], null));

        $_POST = [];
        $_GET = ['inv' => $inv['token']];
        // A6: a used link shows the buyer the normal form, no error; it travels silently so staff see it.
        $usedForm = $this->render(fn () => (new ClientIntakeController())->show([]));
        self::assertStringNotContainsString('expired', $usedForm);
        self::assertStringContainsString('<input type="hidden" name="associate_inv" value="' . $inv['token'] . '">', $usedForm);
    }

    public function testThePersonalCodeTypedOrInTheLinkAndWhatIsRefused(): void
    {
        $a = $this->associate();
        $company = 'Code Buyer GmbH ' . $this->tag;
        [$id, $code] = $this->submit($this->intakePost($company, ['associate_code' => strtolower((string) $a['personal_code'])]));
        self::assertSame((int) $a['id'], (int) AssociateIntakeService::submission($id)['associate_id'], 'a code typed in lower case works');
        $this->verify($id, (string) $code, 'yes');
        $this->accept($id);
        $client = Database::connection()->query('SELECT associate_id, associate_source FROM clients WHERE company_legal_name = ' . Database::connection()->quote($company))->fetch();
        self::assertSame([(string) $a['id'], 'code'], [(string) $client['associate_id'], $client['associate_source']]);
        $inv = AssociateInvitationRepository::forAssociate((int) $a['id'])[0];
        self::assertSame(['personal_code', 'accepted'], [$inv['kind'], $inv['status']], 'a code registration shows like any invitation');

        // Owner decision 4 Nov 2026 (A6): a bad code never stops the buyer — they register as a direct buyer and staff see why.
        $typo = substr((string) $a['personal_code'], 0, -1) . (substr((string) $a['personal_code'], -1) === 'A' ? 'B' : 'A');
        [$typoId] = $this->submit($this->intakePost('Typo Buyer ' . $this->tag, ['associate_code' => $typo]));
        $typoRow = AssociateIntakeService::submission($typoId);
        self::assertSame(['Typo Buyer ' . $this->tag, null], [$typoRow['company_legal_name'], $typoRow['associate_id']], 'saved as a direct buyer');
        self::assertStringContainsString('given but not applied: the code is not valid (mistyped?).', (string) $typoRow['associate_note']);

        $suspended = $this->associate(['status' => 'suspended']);
        [$suspId] = $this->submit($this->intakePost('Suspended Code Buyer ' . $this->tag, ['associate_code' => (string) $suspended['personal_code']]));
        self::assertNull(AssociateIntakeService::submission($suspId)['associate_id']);
        self::assertStringContainsString('given but not applied: the associate is suspended.', (string) AssociateIntakeService::submission($suspId)['associate_note']);
    }

    public function testTheQuotationFormHasNoAssociateCheckboxOrCodeField(): void
    {
        $_GET = [];
        $plain = $this->render(fn () => (new ClientIntakeController())->show([]));
        foreach (['associate_code', 'associate_inv', 'introduction_confirmed', 'Associate code'] as $never) {
            self::assertStringNotContainsString($never, $plain, "the plain form has no {$never}");
        }
        $a = $this->associate();
        $_GET = ['code' => (string) $a['personal_code']];
        $withCode = $this->render(fn () => (new ClientIntakeController())->show([]));
        self::assertStringContainsString('<input type="hidden" name="associate_code" value="' . $a['personal_code'] . '">', $withCode);
        self::assertStringNotContainsString('type="checkbox" name="introduction_confirmed"', $withCode);
        $_GET = [];
        $company = 'Hidden Code Buyer ' . $this->tag;
        [$id, $code] = $this->submit($this->intakePost($company, ['associate_code' => (string) $a['personal_code']]));
        self::assertSame(1, (int) AssociateIntakeService::submission($id)['introduction_confirmed'], 'the link or code is enough — no question to the buyer');
        $this->verify($id, (string) $code, '');
        self::assertStringContainsString("Linked to associate {$a['name']}", $this->accept($id)['message']);
    }

    public function testTwoAssociatesClaimOneBuyerTheFirstCompletedRegistrationWins(): void
    {
        $first = $this->associate();
        $second = $this->associate();
        $company = 'Contested Buyer SA ' . $this->tag;
        $vat = 'FR' . strtoupper(bin2hex(random_bytes(5)));
        [$idB, $codeB] = $this->submit($this->intakePost($company, ['associate_code' => (string) $second['personal_code'], 'vat_eori_tax_no' => $vat]));
        [$idA, $codeA] = $this->submit($this->intakePost($company, ['associate_code' => (string) $first['personal_code'], 'vat_eori_tax_no' => $vat]));
        $this->verify($idA, (string) $codeA, 'yes');
        sleep(1);
        $this->verify($idB, (string) $codeB, 'yes');
        self::assertStringContainsString("the first completed registration counts, so decide that one first", $this->accept($idB)['message'], 'B submitted first but verified second');
        $this->accept($idA);
        $client = Database::connection()->query('SELECT id, associate_id FROM clients WHERE vat_eori_tax_no = ' . Database::connection()->quote($vat))->fetch();
        self::assertSame((string) $first['id'], (string) $client['associate_id']);
        self::assertContains("Same company as {$company}, already the client of associate {$first['name']}.", AssociateIntakeService::reviewInfo(AssociateIntakeService::submission($idB))['flags']);
    }

    public function testADirectApproachIsRecognisedAndOnlyTheMdChangesTheLink(): void
    {
        $a = $this->associate();
        $other = $this->associate();
        $client = $this->client('Loyal Buyer');
        $row = Database::connection()->query("SELECT * FROM clients WHERE id = {$client}")->fetch();
        AssociateService::linkClient($client, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);

        $this->submit($this->intakePost('Other name', ['vat_eori_tax_no' => (string) $row['vat_eori_tax_no']]));
        self::assertTrue(AssociateEventRepository::hasEvent((int) $a['id'], 'direct_contact', $client, null), 'the same VAT number without the code: the associate is told');

        $this->as($this->ed);
        $_POST = ['reason' => 'The buyer says another agent introduced them.', 'move_to' => (string) $other['id']];
        (new AssociateAdminController())->unlinkClient(['id' => (string) $a['id'], 'clientId' => (string) $client]);
        self::assertSame('Only the Managing Director can change or remove a client\'s associate.', $this->flash()['message']);
        self::assertSame((string) $a['id'], (string) Database::connection()->query("SELECT associate_id FROM clients WHERE id = {$client}")->fetchColumn());

        $this->as($this->md);
        $_POST = ['reason' => 'short', 'move_to' => ''];
        (new AssociateAdminController())->unlinkClient(['id' => (string) $a['id'], 'clientId' => (string) $client]);
        self::assertSame('Write the reason (10 to 300 characters) — the associate is shown it.', $this->flash()['message']);
        $_POST = ['reason' => 'The buyer says another agent introduced them.', 'move_to' => (string) $other['id']];
        (new AssociateAdminController())->unlinkClient(['id' => (string) $a['id'], 'clientId' => (string) $client]);
        self::assertSame((string) $other['id'], (string) Database::connection()->query("SELECT associate_id FROM clients WHERE id = {$client}")->fetchColumn());
        self::assertTrue(AssociateEventRepository::hasEvent((int) $a['id'], 'link_changed', $client, null), 'the old associate is told');
        self::assertTrue(AssociateEventRepository::hasEvent((int) $other['id'], 'link_changed', $client, null), 'and the new one');
    }

    public function testRemindersBeforeTheProtectionEnds(): void
    {
        $a = $this->associate();
        $client = $this->client('Quiet Buyer');
        AssociateService::linkClient($client, (int) $a['id'], 'code', null, '2026-01-01', $this->admin);
        Database::connection()->prepare('UPDATE clients SET associate_locked_until = :d WHERE id = :id')->execute(['d' => date('Y-m-d', strtotime('+30 days')), 'id' => $client]);
        AssociateService::dailyReminders();
        self::assertTrue(AssociateEventRepository::hasEvent((int) $a['id'], 'lock_ending_30', $client, null));
        $count = (int) Database::connection()->query("SELECT COUNT(*) FROM associate_events WHERE associate_id = {$a['id']} AND event = 'lock_ending'")->fetchColumn();
        AssociateService::dailyReminders();
        self::assertSame($count, (int) Database::connection()->query("SELECT COUNT(*) FROM associate_events WHERE associate_id = {$a['id']} AND event = 'lock_ending'")->fetchColumn(), 'once only');
    }

    // ---------------------------------------------------------------- the portal (AS-1 … AS-4, AS-8)

    public function testTwoStepLoginAndLockAfterFiveWrongPasswords(): void
    {
        $a = $this->associate();
        AssociateRepository::setFields((int) $a['id'], ['portal_enabled' => 1]);
        $token = AssociatePortalService::issueToken((int) $a['id'], 'set_password', 60);
        self::assertTrue(AssociatePortalService::setPassword($token, 'Secret12345'));
        self::assertFalse(AssociatePortalService::setPassword($token, 'Secret12345'), 'the link works once');

        $r = AssociatePortalService::attemptLogin((string) $a['email'], 'Secret12345');
        self::assertSame('code_sent', $r['status']);
        self::assertNull(AssociatePortalService::currentId(), 'not in before the code');
        self::assertFalse(AssociatePortalService::verifyCode($r['code'] === '000000' ? '000001' : '000000'));
        self::assertTrue(AssociatePortalService::verifyCode($r['code']));
        self::assertSame((int) $a['id'], AssociatePortalService::currentId());

        for ($i = 0; $i < 5; $i++) {
            self::assertSame('invalid', AssociatePortalService::attemptLogin((string) $a['email'], 'wrong')['status']);
        }
        self::assertSame('locked', AssociatePortalService::attemptLogin((string) $a['email'], 'Secret12345')['status']);
    }

    public function testThePortalShowsOnlyTheirOwnAndNeverPricesCostsProfitOrCafin(): void
    {
        $a = $this->associate();
        $b = $this->associate();
        $ca = $this->client('Portal Buyer A');
        $cb = $this->client('Portal Buyer B');
        AssociateService::linkClient($ca, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        AssociateService::linkClient($cb, (int) $b['id'], 'code', null, '2026-10-01', $this->admin);
        $oa = $this->order($ca);
        $ob = $this->order($cb);
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
        $portal = new AssociatePortalController();
        $orders = $this->render(fn () => $portal->orders([]));
        self::assertStringContainsString('/associate/orders/' . $oa, $orders);
        self::assertStringNotContainsString('/associate/orders/' . $ob, $orders);
        self::assertStringContainsString('USD 4,500.00', $orders, 'the FOB value');
        foreach (['unit price', 'profit', 'cafin', 'supplier', 'margin', '45.00', '150,000', '250,000'] as $never) {
            self::assertStringNotContainsString($never, strtolower(strip_tags($orders)), "never shown: {$never}");
        }
        $page = $this->render(fn () => $portal->order(['id' => (string) $oa]));
        foreach (['unit price', 'profit', 'cafin', 'supplier', '1,800', '2,700'] as $never) {
            self::assertStringNotContainsString($never, strtolower(strip_tags($page)), "never shown: {$never}");
        }
        self::assertStringContainsString('Buyer paid in full', $page);
        self::assertSame('Order not found.', $this->render(fn () => $portal->order(['id' => (string) $ob])), 'another associate\'s order');
        self::assertSame(404, http_response_code());
        $_GET = ['format' => 'csv'];
        self::assertStringNotContainsString('Portal Buyer B', $this->render(fn () => $portal->commission([])));
        $_GET = [];
        self::assertStringContainsString('Client registration certificate', $this->render(fn () => $portal->certificate(['id' => (string) $ca])));
        self::assertSame('Client not found.', $this->render(fn () => $portal->certificate(['id' => (string) $cb])));
    }

    public function testStaffViewAsIsReadOnly(): void
    {
        $a = $this->associate();
        AssociatePortalService::startViewAs($this->admin, (int) $a['id']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertTrue((AssociateAuth::required())([]));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::assertSame('Read-only: you are viewing this portal as staff.', $this->render(fn () => self::assertFalse((AssociateAuth::required())([]))));
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testAnInvitedOrSuspendedAssociateCannotInviteClients(): void
    {
        $a = $this->associate(['status' => 'invited']);
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
        $_POST = ['company_name' => 'Too Early Ltd', 'country' => 'UK'];
        (new AssociatePortalController())->createInvitation([]);
        self::assertSame('You can invite clients once your account is active.', $this->flash()['message']);
        self::assertSame([], AssociateInvitationRepository::forAssociate((int) $a['id']));
        $this->as($this->md);
        $_POST = ['status' => 'active', 'reason' => 'KYC and agreement done.'];
        (new AssociateAdminController())->changeStatus(['id' => (string) $a['id']]);
        self::assertSame('An associate becomes Active once Accounts have verified their bank details (two people).', $this->flash()['message']);
    }

    // ---------------------------------------------------------------- staff pages, permissions, Zoho wording

    public function testWhoSeesWhatOnTheStaffSide(): void
    {
        $a = $this->associate();
        \App\Repositories\PricingRepository::setAssociateCommission((int) $a['id'], 'fixed', $this->level('Level 3'));
        $client = $this->client('Visible Buyer');
        AssociateService::linkClient($client, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        $sales = $this->createTestUser('Export Executive');
        $this->as($sales);
        $_GET = ['view' => 'list'];
        $list = $this->render(fn () => (new AssociateAdminController())->index([]));
        self::assertStringContainsString(htmlspecialchars((string) $a['name']), $list);
        self::assertStringNotContainsString('Commission terms', $list, 'Sales: who introduced whom, no amounts');
        $show = $this->render(fn () => (new AssociateAdminController())->show(['id' => (string) $a['id']]));
        self::assertStringContainsString('Visible Buyer', $show);
        foreach (['Level 3', 'Bank details', 'Payouts', 'Show full number'] as $never) {
            self::assertStringNotContainsString($never, $show, "Sales never sees: {$never}");
        }
        $this->as($this->ed);
        $show = $this->render(fn () => (new AssociateAdminController())->show(['id' => (string) $a['id']]));
        self::assertStringContainsString('Fixed level: <b>Level 3</b>', $show);
        self::assertStringNotContainsString('Show full number', $show, 'the full bank number is Finance / MD only');
        $role = static fn (string $name): int => (int) Database::connection()->query('SELECT id FROM roles WHERE name = ' . Database::connection()->quote($name))->fetchColumn();
        self::assertFalse(\App\Services\PermissionService::can($sales, $role('Export Executive'), 'manage_associates'));
        self::assertTrue(\App\Services\PermissionService::can($this->finance1, $role('Finance Manager'), 'manage_associate_bank'));
        self::assertFalse(\App\Services\PermissionService::can($this->finance1, $role('Finance Manager'), 'manage_associates'));
    }

    public function testTheZohoBillAndPaymentSayWhatTheyAre(): void
    {
        $a = $this->associate(['name' => 'TEST-Assoc ' . $this->tag]);
        $client = $this->client('Zoho Buyer');
        AssociateService::linkClient($client, (int) $a['id'], 'code', null, '2026-10-01', $this->admin);
        $this->verifiedBank($a);
        $o = $this->order($client);
        IncentiveService::lockIfReady($o, $this->admin);
        $p = array_values(array_filter(IncentiveRepository::payoutsForOrder($o), static fn (array $p): bool => $p['payee_kind'] === 'associate'))[0];
        IncentiveService::markPaid((int) $p['id'], date('Y-m-d'), 2744.0, 56.0, $this->admin, 'bank_transfer', 'UTR777888');
        $fileId = $this->createTestFile();
        \App\Repositories\AssociateDocumentRepository::add((int) $a['id'], 'invoice', $fileId, (int) $p['id'], 'RK/24-25/017', true, null);
        $ref = (string) Database::connection()->query("SELECT buyer_inquiry_ref FROM orders WHERE id = {$o}")->fetchColumn();
        $c = ZohoBooksService::associateCommission(['order_id' => $o, 'buyer_inquiry_ref' => $ref, 'buyer_name' => 'Zoho Buyer ' . $this->tag]);
        self::assertSame("Associate commission — {$a['name']} — for order {$ref} (Zoho Buyer {$this->tag}), 2% on the invoiced FOB of the goods (basis USD 4500.00) · associate invoice RK/24-25/017 · TDS 56.00 deducted", $c['bill_description']);
        self::assertSame("Associate commission for {$a['name']} — order {$ref} — gross ₹2,800.00, TDS u/s 194H ₹56.00, net paid ₹2,744.00 · UTR UTR777888 · against bill RK/24-25/017", $c['payment_description']);
        self::assertSame(['RK/24-25/017', 2800.0, 2744.0, 56.0, 'UTR777888'], [$c['bill_no'], $c['gross_inr'], $c['paid_inr'], $c['tds_inr'], $c['reference']]);
    }

    public function testTheRegisterAndReportOpen(): void
    {
        $a = $this->associate();
        $this->as($this->md);
        $_GET = ['view' => 'list'];
        self::assertStringContainsString('Add an associate', $this->render(fn () => (new AssociateAdminController())->index([])));
        $_GET = [];
        self::assertStringContainsString('When commission is paid', $this->render(fn () => (new AssociateAdminController())->ladder([])));
        self::assertStringContainsString('TDS register', $this->render(fn () => (new AssociateAdminController())->report([])));
        self::assertStringContainsString('Change status', $this->render(fn () => (new AssociateAdminController())->show(['id' => (string) $a['id']])));
        $base = ['associate_tds_pct' => '2', 'associate_bank_wait_days' => '7', 'associate_commission_due_days' => '30', 'associate_payout_run' => 'monthly', 'associate_payout_day' => '40',
            'associate_counting_threshold_inr' => '500000', 'associate_protection_months' => '6', 'associate_gst_pct' => '18', 'associate_claim_hold' => '1', 'associate_portal_commission_months' => '12'];
        $_POST = $base;
        (new AssociateAdminController())->saveLadder([]);
        self::assertSame('Choose the payout day: 1 to 28 for a monthly run, a weekday for a weekly run.', $this->flash()['message']);
        $_POST = ['associate_payout_day' => '10'] + $base;
        (new AssociateAdminController())->saveLadder([]);
        self::assertSame([10, 'monthly'], [\App\Services\AssociateCommission::settings()['day'], \App\Services\AssociateCommission::settings()['run']]);
        // The ED may save TDS but not the commission rules (MD only).
        $this->as($this->ed);
        $_POST = ['associate_payout_day' => '20'] + $base;
        (new AssociateAdminController())->saveLadder([]);
        self::assertSame(10, \App\Services\AssociateCommission::settings()['day']);
        IncentiveRepository::setSetting('associate_payout_day', '5', $this->admin);
    }
}
