<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Dates;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Repositories\AssociateBankRepository;
use App\Repositories\AssociatePortalRepository;
use App\Repositories\AssociateRepository;
use App\Repositories\IncentiveRepository;
use App\Repositories\PricingRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\VendorBankChangeRepository;
use App\Repositories\VendorDetailsRepository;
use App\Services\AssociateBankConfirmation;
use App\Services\AssociateCodeService;
use App\Services\AssociateCommission;
use App\Services\AssociateKyc;
use App\Services\AuthService;
use App\Services\BankDetailsCheck;
use App\Services\PermissionService;
use App\Services\VendorBankChangeService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions 11 Nov 2026 (confirmed twice), associates:
 * - D-290 (points 6, 7): ID document type + number 5–30 characters; ID proof and address proof separate
 *   required items; app-set file names; 60 days for associates already accepted.
 * - D-291 (point 8): commission rule per associate — standard ladder / own ladder / fixed %; MD only, reason.
 * - D-292 (points 9, 16): "See associate commission rules"; the associate sees only their current %, the
 *   4-step "How it works" and the owner's statuses; payout day (last day of the month, Sunday/holiday rule).
 * - D-293 (points 10, 18): the associate confirms new bank details (last 4 digits + e-mailed code, 7 days);
 *   one verifier other than the enterer, at least 2 checks; MD/ED self-verify with a reason; waits 0 / 15.
 * - D-294: the same rules for supplier bank details through the vendor confirmation link.
 * Node twin: tests/integration/associateOwnerDecisionsNov11.test.js.
 */
final class AssociateOwnerDecisionsNov11Test extends DbTestCase
{
    private static ?Router $router = null;
    private int $staff;
    private string $tag;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__nov11-routes-build__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler();
            self::$router = $router;
        }
        return self::$router;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::router();
        $this->tag = strtoupper(bin2hex(random_bytes(3)));
        $this->staff = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$this->staff}");
        foreach (['bank_details_check_people' => 'one', 'associate_bank_wait_first_days' => '0', 'associate_bank_wait_change_days' => '15',
            'associate_payout_run' => 'monthly', 'associate_payout_day' => 'last', 'associate_payout_holiday_rule' => 'before'] as $k => $v) {
            IncentiveRepository::setSetting($k, $v, $this->staff);
        }
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    /** @return array{code: int, body: string, flash: ?string, type: ?string} */
    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_GET = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $all = Flash::pull();
        $flash = end($all) ?: null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null, 'type' => $flash['type'] ?? null];
    }

    private function asUser(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
    }

    private function asAssociate(array $a): void
    {
        $_SESSION = ['_associate_portal_id' => (int) $a['id']];
    }

    private function associate(array $extra = []): array
    {
        $id = AssociateRepository::create($extra + ['name' => 'Nov11 Associate ' . $this->tag . ' ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'associate-' . bin2hex(random_bytes(4)) . '@email.com', 'status' => 'active', 'portal_enabled' => 1], $this->staff);
        Database::connection()->exec("UPDATE associates SET portal_enabled = 1 WHERE id = {$id}");
        AssociateCodeService::issue($id, 'N' . $this->tag . substr((string) $id, -2));
        return AssociateRepository::find($id);
    }

    private const BANK = ['holder_name' => 'Demo Associate', 'account_number' => '501002345678', 'ifsc' => 'HDFC0001234', 'swift' => '', 'bank_name' => 'HDFC Bank',
        'bank_branch_address' => '', 'intermediary_bank' => '', 'account_currency' => 'INR'];

    private function bankStatus(int $bankId): array
    {
        return Database::connection()->query("SELECT status, cancelled_how FROM associate_bank_accounts WHERE id = {$bankId}")->fetch(\PDO::FETCH_NUM);
    }

    /** A request waiting for confirmation, with a known code ("123456"). */
    private function awaiting(array $a, ?int $enteredBy, string $number = '501002345678'): int
    {
        $id = AssociateBankRepository::request((int) $a['id'], ['account_number' => $number] + self::BANK, $enteredBy === null, $enteredBy);
        AssociateBankConfirmation::start($id, $a, $enteredBy === null, $enteredBy);
        AssociateBankRepository::setCode($id, hash('sha256', '123456'), date('Y-m-d H:i:s', strtotime('+10 minutes')));
        return $id;
    }

    // ---------------------------------------------------------------- D-290: KYC

    public function testIdDocumentTypeAndNumberAndSeparateIdAndAddressProof(): void
    {
        self::assertSame(['AB-12/345 X', null], AssociateKyc::normaliseReference('identity', ' ab-12/345  x '));
        self::assertSame(['Z1234', null], AssociateKyc::normaliseReference('identity', 'z1234'));
        $msg = 'The ID document number should be 5 to 30 letters, digits, dashes (-), slashes (/) or spaces.';
        self::assertSame([null, $msg], AssociateKyc::normaliseReference('identity', 'Z123'));
        self::assertSame([null, $msg], AssociateKyc::normaliseReference('identity', str_repeat('A', 31)));
        self::assertSame([null, $msg], AssociateKyc::normaliseReference('identity', 'AB#12345'));
        // PAN and Aadhaar keep their exact formats.
        self::assertSame(['ABCDE1234F', null], AssociateKyc::normaliseReference('pan', 'abcde1234f'));
        self::assertSame(['XXXX XXXX 1234', null], AssociateKyc::normaliseReference('aadhaar', '1234'));
        self::assertSame('[A-Za-z0-9 \/\-]{5,30}', AssociateKyc::REFERENCE_RULES['identity'][0]);

        self::assertSame([null, null, 'Choose the type of ID document: Passport, National ID card, Residence permit or Driving licence.'], AssociateKyc::checkUpload('identity', 'Z1234567', ''));
        self::assertSame([null, null, "Enter the ID document's number (5 to 30 letters, digits, dashes, slashes or spaces)."], AssociateKyc::checkUpload('identity', '', 'residence_permit'));
        self::assertSame(['RP-2026/0001', 'residence_permit', null], AssociateKyc::checkUpload('identity', 'rp-2026/0001', 'residence_permit'));
        self::assertSame(['Passport', 'National ID card', 'Residence permit', 'Driving licence'], array_values(AssociateKyc::ID_DOC_TYPES));

        $india = $this->associate();
        $abroad = $this->associate(['based_in' => 'abroad', 'currency_code' => 'USD']);
        $keys = static fn (array $a): array => array_map(static fn (array $i): string => $i['key'], AssociateKyc::requiredItems($a));
        self::assertSame(['pan', 'id', 'address', 'bank', 'agreement'], $keys($india));
        self::assertSame(['id', 'address', 'bank', 'agreement'], $keys($abroad));

        // App-set file names (the same document may be uploaded under each — two records).
        self::assertSame('ID proof — Demo Associate — 11 Nov 2026.pdf', AssociateKyc::appFileName('identity', 'Demo Associate', 'scan.PDF', '2026-11-11'));
        self::assertSame('ID proof — Demo Associate — 11 Nov 2026.jpg', AssociateKyc::appFileName('aadhaar', 'Demo Associate', 'a.jpg', '2026-11-11'));
        self::assertSame('Address proof — Demo Associate — 11 Nov 2026.png', AssociateKyc::appFileName('address', 'Demo / Associate', 'bill.png', '2026-11-11'));
        self::assertNull(AssociateKyc::appFileName('pan', 'Demo Associate', 'pan.pdf'));

        // The portal refuses an ID document without its type, before any file is stored.
        $this->asAssociate($india);
        self::assertSame('Choose the type of ID document: Passport, National ID card, Residence permit or Driving licence.',
            $this->call('POST', '/associate/documents', ['kind' => 'identity', 'reference' => 'Z1234567'])['flash']);
        $page = $this->call('GET', '/associate/profile')['body'];
        self::assertStringContainsString('name="id_doc_type"', $page);
        self::assertStringContainsString('Address proof', $page);
        self::assertStringNotContainsString('Use the same file', $page, 'no "use the same file" button (owner)');
    }

    public function testAssociatesAlreadyAcceptedGet60DaysForTheAddressProof(): void
    {
        $a = $this->associate();
        $this->completeAssociateKyc((int) $a['id']);
        Database::connection()->exec("DELETE FROM associate_documents WHERE associate_id = {$a['id']} AND kind = 'address'");
        self::assertSame(['Address proof'], AssociateKyc::missing($a));
        self::assertSame(['Address proof'], AssociateKyc::missingForPayout($a, '2026-11-11'), 'without a grace date the payout waits');
        Database::connection()->exec("UPDATE associates SET address_proof_due_on = '2027-01-10' WHERE id = {$a['id']}");
        $a = AssociateRepository::find((int) $a['id']);
        self::assertSame([], AssociateKyc::missingForPayout($a, '2027-01-10'), 'within the 60 days payouts are not held');
        self::assertSame(['Address proof'], AssociateKyc::missingForPayout($a, '2027-01-11'));
        self::assertSame('Address proof needed by 10 Jan 2027 — after that, commission payouts wait until it is accepted.', AssociateKyc::addressGraceNote($a, '2026-11-11'));
        self::assertSame(60, AssociateKyc::ADDRESS_PROOF_GRACE_DAYS);
    }

    // ---------------------------------------------------------------- D-291 / D-292: commission rule and what is shown

    public function testOwnLadderOverridesTheStandardLadderAndTheMdSetsItWithAReason(): void
    {
        $levels = array_values(array_filter(PricingRepository::levels(true), static fn (array $l): bool => $l['kind'] === 'pct'));
        self::assertGreaterThanOrEqual(2, count($levels), 'the seed has commission levels');
        [$low, $high] = [$levels[0], $levels[1]];
        $a = $this->associate();
        $md = $this->createTestUser('Managing Director');

        // Without a reason nothing changes.
        $this->asUser($md);
        $post = ['rule' => 'own_ladder', 'from' => ['1', '3'], 'to' => ['2', ''], 'level_id' => [(string) $high['id'], (string) $low['id']]];
        self::assertSame('Give the reason for the change (10 to 300 characters) — it is kept in the Audit Log.', $this->call('POST', "/associates/{$a['id']}/commission-rule", $post)['flash']);
        self::assertSame('ladder', AssociateRepository::find((int) $a['id'])['commission_mode']);

        $r = $this->call('POST', "/associates/{$a['id']}/commission-rule", $post + ['reason' => 'Strong partner in UAE — agreed 10 Nov']);
        self::assertStringStartsWith('Commission rule saved: Own ladder: 1–2: ' . $high['name'] . '; 3–onward: ' . $low['name'], (string) $r['flash']);
        $a = AssociateRepository::find((int) $a['id']);
        self::assertSame('own_ladder', $a['commission_mode']);
        self::assertSame('Strong partner in UAE — agreed 10 Nov', $a['commission_rule_reason']);
        $audit = Database::connection()->query("SELECT reason, new_value FROM audit_log WHERE action_type = 'ASSOCIATE_COMMISSION_RULE_CHANGED' AND entity_id = {$a['id']} ORDER BY id DESC LIMIT 1")->fetch();
        self::assertSame('Strong partner in UAE — agreed 10 Nov', $audit['reason']);

        [$level, $count] = AssociateCommission::levelForAssociate($a, null);
        self::assertSame([(int) $high['id'], 0], [(int) $level['id'], $count], 'the own ladder decides the next order');
        $p = AssociateCommission::ruleProgress($a);
        self::assertSame(['Own ladder', 0, 2, 2], [$p['rule'], $p['count'], $p['of'], $p['more']]);
        self::assertSame(AssociateCommission::rateLabel($low), $p['next_rate']);
        self::assertSame(['Orders 1–2', 'Orders 3 onwards'], array_column($p['steps'], 'label'));

        // A user without manage_pricing cannot reach the rule page.
        $this->asUser($this->createTestUser('Finance Manager'));
        self::assertStringNotContainsString('Save the rule', $this->call('GET', "/associates/{$a['id']}/commission-rule")['body']);

        // Remove own ladder: back to the standard ladder; its steps are gone.
        $this->asUser($md);
        $this->call('POST', "/associates/{$a['id']}/commission-rule", ['rule' => 'ladder', 'reason' => 'Back to the standard ladder.']);
        self::assertSame([], PricingRepository::ownLadder((int) $a['id']));
        self::assertSame('ladder', AssociateRepository::find((int) $a['id'])['commission_mode']);
    }

    public function testWhoSeesTheRuleAndWhatTheAssociateSees(): void
    {
        $pdo = Database::connection();
        $roles = $pdo->query("SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
                               WHERE p.permission_key = 'view_associate_commission_rules' ORDER BY r.name")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['Admin', 'Executive Director', 'Finance Director', 'Finance Manager', 'Managing Director'], $roles);
        self::assertSame('See associate commission rules', $pdo->query("SELECT name FROM permissions WHERE permission_key = 'view_associate_commission_rules'")->fetchColumn());

        $a = $this->associate();
        // Staff with the permission: the rule and progress; without it: "Set by the MD."
        $this->asUser($this->createTestUser('Finance Manager'));
        $page = $this->call('GET', "/associates/{$a['id']}")['body'];
        self::assertStringContainsString('data-commission-rule', $page);
        self::assertStringContainsString('Orders counted so far', $page);
        $this->asUser($this->createTestUser('Export Executive'));
        $page = $this->call('GET', "/associates/{$a['id']}")['body'];
        self::assertStringContainsString('Set by the MD.', $page);
        self::assertStringNotContainsString('Orders counted so far', $page);

        // The associate: only the current %, never levels, ladder or counts.
        [$level] = AssociateCommission::levelForAssociate($a, null);
        $this->asAssociate($a);
        $profile = $this->call('GET', '/associate/profile')['body'];
        self::assertStringContainsString('>' . AssociateCommission::rateLabel($level) . '</span> <b>of the FOB value of each order from the clients you introduced</b>', $profile);
        self::assertStringContainsString('How it works', $profile);
        self::assertStringContainsString('It is worked out on the <b>FOB value on the Commercial Invoice</b>.', $profile);
        self::assertStringContainsString('in our <b>monthly payment on the last day of the month</b>, in Indian rupees to your verified bank account.', $profile);
        self::assertStringNotContainsString((string) $level['name'], $profile);
        self::assertStringNotContainsString('goods value', $profile);
        $this->asAssociate($a);
        $commission = $this->call('GET', '/associate/commission')['body'];
        self::assertStringContainsString('of the FOB value of each order from the clients you introduced', $commission);
        self::assertStringContainsString('<th>Your %</th>', $commission);

        // The owner's statuses.
        self::assertSame('Not earned yet — buyer still paying', AssociatePortalRepository::NOT_EARNED_LABEL);
        self::assertSame('Paid on 30 Nov 2026 (SWIFT FT2611300001)', AssociatePortalRepository::payoutLabel(['status' => 'paid', 'paid_on' => '2026-11-30', 'paid_reference' => 'SWIFT FT2611300001'], false));
        self::assertSame('Earned — will be paid on 31 Dec 2026', AssociatePortalRepository::payoutLabel(['status' => 'due', 'due_on' => '2026-12-28', 'payout_run_on' => '2026-12-31'], false));
        self::assertSame('On hold — a buyer claim on this order is open', AssociatePortalRepository::payoutLabel(['status' => 'due', 'payout_run_on' => '2026-12-31'], true));
        self::assertSame('On hold — a buyer claim on this order is open', AssociatePortalRepository::payoutLabel(['status' => 'held', 'hold_reason' => \App\Services\AssociatePayoutService::CLAIM_HOLD_REASON], false));
    }

    public function testPayoutDayLastDayOfTheMonthAndSundayOrHolidayMove(): void
    {
        $set = AssociateCommission::settings();
        self::assertSame([true, 'before'], [$set['last_day'], $set['holiday_rule']], 'seed default: last day of the month, working day before');
        self::assertSame('2026-11-30', AssociateCommission::payoutRunOn('2026-11-15', $set));
        self::assertSame('2027-02-27', AssociateCommission::payoutRunOn('2027-02-01', $set), '28 Feb 2027 is a Sunday → the Saturday before');
        self::assertSame('2027-01-30', AssociateCommission::payoutRunOn('2027-01-10', $set), '31 Jan 2027 is a Sunday');
        self::assertSame('2027-02-27', AssociateCommission::payoutRunOn('2027-01-31', $set), 'the day before is before the due date → next run');
        self::assertSame('2027-02-01', AssociateCommission::payoutRunOn('2027-01-10', ['holiday_rule' => 'after'] + $set));
        $pdo = Database::connection();
        $had = (bool) $pdo->query("SELECT 1 FROM company_holidays WHERE holiday_date = '2027-03-31'")->fetchColumn();
        $pdo->exec("INSERT IGNORE INTO company_holidays (holiday_date, description) VALUES ('2027-03-31', 'Nov11 test holiday')");
        try {
            self::assertSame('2027-03-30', AssociateCommission::payoutRunOn('2027-03-10', $set), 'a Holiday Calendar day → the working day before');
            self::assertSame('2027-04-01', AssociateCommission::payoutRunOn('2027-03-10', ['holiday_rule' => 'after'] + $set));
        } finally {
            if (!$had) {
                $pdo->exec("DELETE FROM company_holidays WHERE holiday_date = '2027-03-31'");
            }
        }
        self::assertSame('monthly payment on the last day of the month', AssociateCommission::payDayText($set));
        self::assertSame('monthly payment on the 5th', AssociateCommission::payDayText(['run' => 'monthly', 'day' => 5, 'last_day' => false]));
        self::assertSame('weekly payment every Friday', AssociateCommission::payDayText(['run' => 'weekly', 'day' => 5, 'last_day' => false]));
        self::assertSame('2026-12-04', AssociateCommission::payoutRunOn('2026-12-01', ['run' => 'weekly', 'day' => 5, 'last_day' => false, 'holiday_rule' => 'before']));

        // The settings page: a change of the payment run needs a reason; the two hidden settings are editable.
        $md = $this->createTestUser('Managing Director');
        $this->asUser($md);
        $form = $this->call('GET', '/associates/ladder')['body'];
        self::assertStringContainsString('<option value="last" selected>Last day of the month</option>', $form);
        self::assertStringContainsString('name="associate_code_changes_per_year"', $form);
        self::assertStringContainsString('name="associate_upgrade_after_orders"', $form);
        self::assertStringContainsString('name="bank_details_check_people"', $form);
        $fields = ['associate_tds_pct' => '2', 'associate_commission_due_days' => '30', 'associate_payout_run' => 'monthly', 'associate_payout_day' => '20', 'associate_payout_holiday_rule' => 'after',
            'associate_counting_threshold_inr' => '500000', 'associate_protection_months' => '6', 'associate_gst_pct' => '18', 'associate_portal_commission_months' => '12',
            'associate_code_changes_per_year' => '4', 'associate_upgrade_after_orders' => '5', 'bank_details_check_people' => 'one',
            'associate_bank_wait_first_days' => '0', 'associate_bank_wait_change_days' => '15'];
        self::assertSame('Give the reason for changing the payment run (10 to 300 characters) — it is kept in the Audit Log.', $this->call('POST', '/associates/ladder', $fields)['flash']);
        self::assertStringStartsWith('Saved.', (string) $this->call('POST', '/associates/ladder', $fields + ['payout_reason' => 'Finance pays vendors on the 20th.'])['flash']);
        $s = IncentiveRepository::settings();
        self::assertSame(['20', 'after', '4', '5'], [$s['associate_payout_day'], $s['associate_payout_holiday_rule'], $s['associate_code_changes_per_year'], $s['associate_upgrade_after_orders']]);
        self::assertSame('Finance pays vendors on the 20th.', Database::connection()->query("SELECT reason FROM audit_log WHERE action_type = 'ASSOCIATE_SETTING_CHANGED' AND field_name = 'associate_payout_day' ORDER BY id DESC LIMIT 1")->fetchColumn());
        self::assertSame('Enter TDS as 0–30 % and each bank waiting time as 0–90 days.', $this->call('POST', '/associates/ladder', ['associate_bank_wait_change_days' => '91'] + $fields)['flash']);
        IncentiveRepository::setSetting('associate_payout_day', 'last', $md);
        IncentiveRepository::setSetting('associate_payout_holiday_rule', 'before', $md);
        IncentiveRepository::setSetting('associate_code_changes_per_year', '3', $md);
        IncentiveRepository::setSetting('associate_upgrade_after_orders', '3', $md);
    }

    // ---------------------------------------------------------------- D-293: the associate confirms, one person verifies

    public function testTheAssociateConfirmsBeforeStaffVerifyAndOnePersonVerifies(): void
    {
        $a = $this->associate();
        $id = $this->awaiting($a, null);
        self::assertSame(['awaiting_confirmation', null], $this->bankStatus($id));
        self::assertNotNull(AssociateBankConfirmation::open((int) $a['id']));
        $confirmBy = (string) Database::connection()->query("SELECT confirm_by FROM associate_bank_accounts WHERE id = {$id}")->fetchColumn();
        self::assertSame(date('Y-m-d', strtotime('+7 days')), substr($confirmBy, 0, 10), '7 days to confirm');

        // A bar on every portal page; the confirm page shows current vs new.
        $this->asAssociate($a);
        self::assertStringContainsString('New bank details are waiting for your confirmation.', $this->call('GET', '/associate')['body']);
        $this->asAssociate($a);
        $page = $this->call('GET', '/associate/bank/confirm')['body'];
        self::assertStringContainsString('Please confirm your new bank details', $page);
        self::assertStringContainsString('••••••••5678', $page);
        self::assertStringContainsString('This is not me — cancel it', $page);

        // Staff cannot verify before the confirmation.
        $this->asUser($this->staff);
        self::assertSame('Verify is available once the associate confirms the new details.',
            $this->call('POST', "/associates/{$a['id']}/bank/{$id}/verify", ['check_bank_proof' => '1', 'check_phone' => '1'])['flash']);

        self::assertSame('The last 4 digits do not match the new account number — check them and type them again.', AssociateBankConfirmation::confirm($a, $id, '0000', '123456', true));
        self::assertSame('Tick "These are my bank details" to confirm.', AssociateBankConfirmation::confirm($a, $id, '5678', '123456', false));
        self::assertSame('The code is not right — check the e-mail we sent you, or ask for a new code.', AssociateBankConfirmation::confirm($a, $id, '5678', '111111', true));
        self::assertSame(1, (int) Database::connection()->query("SELECT confirm_code_attempts FROM associate_bank_accounts WHERE id = {$id}")->fetchColumn());
        $this->asAssociate($a);
        $r = $this->call('POST', '/associate/bank/confirm', ['last4' => '5678', 'code' => '123456', 'mine' => '1']);
        self::assertStringStartsWith('Thank you — confirmed. NexaCrest will now check the new details.', (string) $r['flash']);
        self::assertSame('pending', $this->bankStatus($id)[0]);

        // One person, at least two checks; the test payment needs its reference.
        $this->asUser($this->staff);
        self::assertSame('Tick at least two of the ways you checked the bank details.', $this->call('POST', "/associates/{$a['id']}/bank/{$id}/verify", ['check_bank_proof' => '1'])['flash']);
        self::assertSame('Enter the bank reference (UTR / SWIFT) of the test payment.', $this->call('POST', "/associates/{$a['id']}/bank/{$id}/verify", ['check_bank_proof' => '1', 'check_test_payment' => '1'])['flash']);
        $r = $this->call('POST', "/associates/{$a['id']}/bank/{$id}/verify", ['check_bank_proof' => '1', 'check_test_payment' => '1', 'test_payment_ref' => 'UTR 412345678901', 'verify_note' => 'Spoke to them at 3 pm']);
        self::assertSame('Verified. Usable from ' . Dates::short(date('Y-m-d')) . '; the associate sees it in their portal (the e-mail could not be sent).' /* D-280: "e-mailed" only when it was; the test run has no mail server */, $r['flash'], 'a first account: 0 days');
        $row = AssociateBankRepository::find($id);
        self::assertSame([1, 0, 1, 'UTR 412345678901', 'Spoke to them at 3 pm'], [(int) $row['check_bank_proof'], (int) $row['check_phone'], (int) $row['check_test_payment'], $row['test_payment_ref'], $row['verify_note']]);
        self::assertSame('bank proof, test payment (ref UTR 412345678901)', BankDetailsCheck::checksText($row));

        // A change of account entered by staff: the enterer cannot verify (Admin has no self-approval rule).
        $second = $this->awaiting($a, $this->staff, '600070008000');
        AssociateBankConfirmation::confirm($a, $second, '8000', '123456', true);
        $this->asUser($this->staff);
        self::assertSame('Someone else must verify details you entered.', $this->call('POST', "/associates/{$a['id']}/bank/{$second}/verify", ['check_bank_proof' => '1', 'check_phone' => '1'])['flash']);

        // The MD may verify their own entry, with a reason (Admin › Self-approval rules › Bank details, seeded MD and ED).
        self::assertSame(['Executive Director', 'Managing Director'], Database::connection()->query(
            "SELECT r.name FROM self_approval_rules s JOIN roles r ON r.id = s.role_id WHERE s.rule_key = 'bank_details' ORDER BY r.name")->fetchAll(\PDO::FETCH_COLUMN));
        $md = $this->createTestUser('Managing Director');
        $third = $this->awaiting($a, $md, '700080009000');
        self::assertSame(['cancelled', 'replaced'], $this->bankStatus($second), 'newer details replace the ones still open');
        AssociateBankConfirmation::confirm($a, $third, '9000', '123456', true);
        $this->asUser($md);
        self::assertSame('You entered these details yourself — give the reason you verify them (at least 10 characters).',
            $this->call('POST', "/associates/{$a['id']}/bank/{$third}/verify", ['check_bank_proof' => '1', 'check_phone' => '1'])['flash']);
        $r = $this->call('POST', "/associates/{$a['id']}/bank/{$third}/verify", ['check_bank_proof' => '1', 'check_phone' => '1', 'self_reason' => 'Only finance person is on leave until 20 Nov']);
        self::assertSame('Verified. Usable from ' . Dates::short(date('Y-m-d', strtotime('+15 days'))) . '; the associate sees it in their portal (the e-mail could not be sent).' /* D-280: "e-mailed" only when it was; the test run has no mail server */, $r['flash'], 'a change of account: 15 days');
        self::assertSame('Only finance person is on leave until 20 Nov', AssociateBankRepository::find($third)['self_verify_reason']);
        self::assertSame($id, (int) AssociateBankRepository::usable((int) $a['id'])['id'], 'the old account stays in use during the 15 days');

        // The two-people setting: Verify needs a first check by someone else.
        IncentiveRepository::setSetting('bank_details_check_people', 'two', $this->staff);
        try {
            $fourth = $this->awaiting($a, null, '800090001000');
            AssociateBankConfirmation::confirm($a, $fourth, '1000', '123456', true);
            $this->asUser($this->staff);
            self::assertSame('Record the first check before verifying.', $this->call('POST', "/associates/{$a['id']}/bank/{$fourth}/verify")['flash']);
            self::assertSame('First check recorded. A different person now verifies it.', $this->call('POST', "/associates/{$a['id']}/bank/{$fourth}/check", ['check_bank_proof' => '1', 'check_phone' => '1'])['flash']);
            self::assertSame('A different person must verify — you already acted on this request.', $this->call('POST', "/associates/{$a['id']}/bank/{$fourth}/verify")['flash']);
        } finally {
            IncentiveRepository::setSetting('bank_details_check_people', 'one', $this->staff);
        }
    }

    public function testThisIsNotMeCancelsAndAlertsTheMdAndFinanceAndSevenDaysCancel(): void
    {
        $a = $this->associate();
        $md = $this->createTestUser('Managing Director');
        $fm = $this->createTestUser('Finance Manager');
        $id = $this->awaiting($a, $this->staff);
        $this->asAssociate($a);
        $r = $this->call('POST', '/associate/bank/not-me');
        self::assertSame(AssociateBankConfirmation::NOT_ME_MESSAGE, $r['flash']);
        self::assertSame('Cancelled. Your bank details have not changed. We have told our Managing Director and Finance team. If someone contacted you about your bank details, please reply in Messages.', AssociateBankConfirmation::NOT_ME_MESSAGE);
        self::assertSame(['cancelled', 'not_me'], $this->bankStatus($id));
        foreach ([$md, $fm] as $u) {
            self::assertStringContainsString("Associate {$a['name']} clicked \"This is not me — cancel it\"", (string) Database::connection()->query(
                "SELECT message FROM notifications WHERE user_id = {$u} AND type = 'bank_change_refused' ORDER BY id DESC LIMIT 1")->fetchColumn());
        }
        $this->asAssociate($a);
        self::assertStringNotContainsString('data-bank-confirm-bar', $this->call('GET', '/associate')['body']);

        // No answer in 7 days: cancelled by itself; the old account stays.
        $late = $this->awaiting($a, null, '900010002000');
        Database::connection()->exec("UPDATE associate_bank_accounts SET confirm_by = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$late}");
        self::assertNull(AssociateBankConfirmation::open((int) $a['id']));
        self::assertGreaterThanOrEqual(1, AssociateBankConfirmation::expireOverdue());
        self::assertSame(['cancelled', 'expired'], $this->bankStatus($late));
    }

    // ---------------------------------------------------------------- D-294: suppliers confirm through the link

    public function testSupplierBankDetailsAreConfirmedThroughTheLinkThenVerified(): void
    {
        $supplierId = SupplierRepository::create(['supplier_legal_name' => 'Nov11 Stone Supplier ' . $this->tag, 'types' => ['Quarry Owner'], 'email' => 'accounts@email.com'], $this->staff);
        $bank = ['bank_account_name' => 'Nov11 Stone Supplier', 'bank_name' => 'State Bank of India', 'bank_branch' => 'Main', 'bank_ifsc' => 'SBIN0001234', 'bank_account_type' => 'Current',
            'bank_upi_id' => null, 'bank_account_number' => '112233445566'];
        $r = VendorBankChangeService::propose('supplier', $supplierId, $bank, 'staff', $this->staff, 'accounts@email.com');
        self::assertSame('accounts@email.com', $r['emailed_to']);
        self::assertFalse(VendorDetailsRepository::bankDetails(SupplierRepository::find($supplierId))['has_bank'], 'the account in use does not change yet');
        Database::connection()->prepare('UPDATE vendor_bank_changes SET confirm_token_hash = :h WHERE id = :id')->execute(['h' => hash('sha256', 'nov11-token-' . $this->tag), 'id' => $r['id']]);

        $this->asUser($this->staff);
        self::assertSame('Verify is available once they confirm the new details (link sent to their registered e-mail).',
            $this->call('POST', "/suppliers/{$supplierId}/bank-changes/{$r['id']}/verify", ['check_bank_proof' => '1', 'check_phone' => '1'])['flash']);

        $_SESSION = [];
        $page = $this->call('GET', '/vendor-bank-confirm/nov11-token-' . $this->tag)['body'];
        self::assertStringContainsString('Please confirm your new bank details', $page);
        self::assertStringContainsString('••••5566', $page);
        self::assertStringNotContainsString('112233445566', $page);
        self::assertStringContainsString('This is not us — cancel it', $page);
        $c = VendorBankChangeRepository::find($r['id']);
        self::assertSame('The last 4 digits do not match the new account number — check them and type them again.', VendorBankChangeService::confirm($c, '1111', true));
        $body = $this->call('POST', '/vendor-bank-confirm/nov11-token-' . $this->tag, ['last4' => '5566', 'ours' => '1'])['body'];
        self::assertStringContainsString('Thank you — confirmed', $body);
        self::assertSame('pending', VendorBankChangeRepository::find($r['id'])['status']);

        // The enterer may not verify; another person with two checks does, and a first account is used at once.
        $this->asUser($this->staff);
        self::assertSame('Someone else must verify details you entered.', $this->call('POST', "/suppliers/{$supplierId}/bank-changes/{$r['id']}/verify", ['check_bank_proof' => '1', 'check_phone' => '1'])['flash']);
        $other = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$other}");
        $this->asUser($other);
        self::assertSame('Bank details verified. They are used from the date shown; until then payments go to the account on file.',
            $this->call('POST', "/suppliers/{$supplierId}/bank-changes/{$r['id']}/verify", ['check_bank_proof' => '1', 'check_phone' => '1'])['flash']);
        self::assertSame('applied', VendorBankChangeRepository::find($r['id'])['status']);
        $onFile = VendorDetailsRepository::bankDetails(SupplierRepository::find($supplierId));
        self::assertSame(['112233445566', 'SBIN0001234'], [$onFile['bank_account_number'], $onFile['bank_ifsc']]);
        self::assertNotNull($onFile['bank_verified_at']);

        // A change of account: "This is not us" cancels it and alerts the MD and Finance; the account on file stays.
        $md = $this->createTestUser('Managing Director');
        $r2 = VendorBankChangeService::propose('supplier', $supplierId, ['bank_account_number' => '998877665544'] + $bank, 'vendor', null, 'accounts@email.com');
        self::assertTrue(VendorBankChangeService::notUs(VendorBankChangeRepository::find($r2['id'])));
        self::assertSame(['cancelled', 'not_me'], array_values(Database::connection()->query("SELECT status, cancelled_how FROM vendor_bank_changes WHERE id = {$r2['id']}")->fetch(\PDO::FETCH_NUM)));
        self::assertStringContainsString('clicked "This is not us — cancel it"', (string) Database::connection()->query(
            "SELECT message FROM notifications WHERE user_id = {$md} AND type = 'bank_change_refused' ORDER BY id DESC LIMIT 1")->fetchColumn());
        self::assertSame('112233445566', VendorDetailsRepository::bankDetails(SupplierRepository::find($supplierId))['bank_account_number']);

        // Not confirmed in 7 days: cancelled.
        $r3 = VendorBankChangeService::propose('supplier', $supplierId, ['bank_account_number' => '556677889900'] + $bank, 'staff', $this->staff, 'accounts@email.com');
        Database::connection()->exec("UPDATE vendor_bank_changes SET confirm_by = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = {$r3['id']}");
        self::assertGreaterThanOrEqual(1, VendorBankChangeService::daily()['expired']);
        self::assertSame('cancelled', VendorBankChangeRepository::find($r3['id'])['status']);
    }
}
