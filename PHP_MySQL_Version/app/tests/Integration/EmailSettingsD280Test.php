<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\EmailSettingsController;
use App\Helpers\Flash;
use App\Repositories\AssociateRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\NotificationRepository;
use App\Services\AssociatePortalService;
use App\Services\AssociateService;
use App\Services\EmailDispatchService;
use App\Services\EmailPolicy;
use App\Services\MailSenderService;
use App\Services\StaffRouting;
use App\Services\TwoFactorService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions D-280 / D-281 / D-282 (11 Nov 2026, points 1, 2, 3, 5, 22):
 * Admin › E-mail settings (MUST / SHOULD / OPTIONAL per audience), testing mode,
 * truthful "e-mailed" wording with a copyable link, department routing of staff
 * e-mails, company-level compliance owners, the associate's forgot-password and
 * the invite e-mail with the permanent login page. Parity: emailSettingsD280.test.js.
 */
final class EmailSettingsD280Test extends DbTestCase
{
    private ?string $testingBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $this->testingBefore = CompanySettingsRepository::get('email_testing_mode');
        CompanySettingsRepository::set('email_testing_mode', '0');
        EmailPolicy::resetCache();
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('email_testing_mode', (string) ($this->testingBefore ?? '0'));
        foreach (['staff_counterparty|staff', 'buyer_documents|associate', 'buyer_links|buyer'] as $key) {
            [$k, $a] = explode('|', $key);
            EmailPolicy::setSwitchForTest($k, $a, EmailPolicy::tier($k, $a) === 'should');
        }
        Database::connection()->exec('UPDATE compliance_alert_owners SET responsible_user_id = NULL, backup_user_id = NULL');
        $_SESSION = [];
        parent::tearDown();
    }

    // ---- the catalogue and the seed ----

    public function testTheMustEmailsAreTheOnesTheOwnerNamed(): void
    {
        foreach (['login_code' => ['staff', 'associate', 'buyer'], 'set_password_invite' => ['associate', 'buyer'], 'forgot_password' => ['staff', 'associate', 'buyer'],
                  'bank_details_changed' => ['associate'], 'backup_code_alert' => ['staff'], 'quote_verification_code' => ['buyer'],
                  // D-293 / D-303 (owner 12 Nov 2026): the bank-details confirmations are listed as MUST rows too.
                  'bank_confirm_code' => ['associate'], 'vendor_bank_confirm' => ['supplier']] as $kind => $audiences) {
            foreach ($audiences as $a) {
                self::assertTrue(EmailPolicy::isMust($kind, $a), "{$kind} / {$a} is MUST");
            }
        }
        self::assertTrue(!empty(EmailPolicy::KINDS['set_password_invite']['access_critical']), 'invites are flagged access-critical');
        // Only one OPTIONAL: the associate copy of buyer documents.
        $optional = [];
        foreach (EmailPolicy::KINDS as $kind => $def) {
            foreach ($def['audiences'] as $a => $tier) {
                if ($tier === 'optional') {
                    $optional[] = "{$kind}|{$a}";
                }
            }
        }
        self::assertSame(['buyer_documents|associate'], $optional);
    }

    public function testTheBankDetailsConfirmationEmailsAreListedAsMustRowsAndSentUnderThatKind(): void
    {
        // D-293 / D-303: the page lists them; the senders tag their e-mail with the same kind.
        $html = $this->renderEmailSettingsPage();
        self::assertStringContainsString('data-email-kind="bank_confirm_code"', $html);
        self::assertStringContainsString('data-email-kind="vendor_bank_confirm"', $html);
        self::assertTrue(EmailPolicy::switchOn('bank_confirm_code', 'associate'), 'MUST: always on');
        self::assertTrue(EmailPolicy::switchOn('vendor_bank_confirm', 'supplier'), 'MUST: always on');
        $src = dirname(__DIR__, 2) . '/src/Services/';
        self::assertStringContainsString("true, 'bank_confirm_code', 'associate')", (string) file_get_contents($src . 'AssociateBankConfirmation.php'));
        self::assertStringContainsString("true, 'vendor_bank_confirm', 'supplier')", (string) file_get_contents($src . 'VendorBankChangeService.php'));
    }

    public function testTheSeedStartsWithShouldOnOptionalOffAndTestingModeOff(): void
    {
        self::assertSame(1, (int) $this->value("SELECT is_enabled FROM email_settings WHERE email_kind = 'staff_counterparty' AND audience = 'staff'"));
        self::assertSame(0, (int) $this->value("SELECT is_enabled FROM email_settings WHERE email_kind = 'buyer_documents' AND audience = 'associate'"));
        self::assertNull($this->value("SELECT 1 FROM email_settings WHERE email_kind = 'login_code'"), 'MUST e-mails have no switch row');
        self::assertNull($this->value("SELECT 1 FROM company_settings WHERE setting_key = 'mail_sending_enabled'"), 'the old master switch is gone');
        self::assertNotNull($this->value("SELECT 1 FROM company_settings WHERE setting_key = 'email_testing_mode'"));
    }

    // ---- testing mode and the switches ----

    public function testTestingModeHoldsOnlyBuyerAndSupplierEmailsThatAreNotMust(): void
    {
        CompanySettingsRepository::set('email_testing_mode', '1');
        self::assertSame('testing mode holds e-mails to buyers and suppliers', EmailPolicy::holdReason('buyer_links', 'buyer'));
        self::assertSame('testing mode holds e-mails to buyers and suppliers', EmailPolicy::holdReason('supplier_links', 'supplier'));
        self::assertNull(EmailPolicy::holdReason('staff_counterparty', 'staff'), 'staff e-mails still go out');
        self::assertNull(EmailPolicy::holdReason('associate_commission', 'associate'), 'associate e-mails still go out');
        self::assertNull(EmailPolicy::holdReason('set_password_invite', 'buyer'), 'a MUST e-mail to a buyer still goes out');
        self::assertNull(EmailPolicy::holdReason('quote_verification_code', 'buyer'));
        self::assertFalse(EmailDispatchService::mailSendingEnabled(), 'buyer documents are held, so approval counts as sent');
    }

    public function testASwitchedOffEmailIsHeldWithItsOwnReason(): void
    {
        EmailPolicy::setSwitchForTest('staff_counterparty', 'staff', false);
        self::assertSame('this e-mail is switched off in Admin › E-mail settings', EmailPolicy::holdReason('staff_counterparty', 'staff'));
        self::assertFalse(MailSenderService::send('someone@nexacrest.test', 'S', 'B', [], false, 'staff_counterparty', 'staff'));
        self::assertSame('held', MailSenderService::$lastOutcome);
    }

    public function testTheOptionalAssociateCopyIsOffUntilSwitchedOn(): void
    {
        self::assertSame('this e-mail is switched off in Admin › E-mail settings', EmailPolicy::holdReason('buyer_documents', 'associate'));
        EmailPolicy::setSwitchForTest('buyer_documents', 'associate', true);
        self::assertNull(EmailPolicy::holdReason('buyer_documents', 'associate'));
        $body = EmailDispatchService::associateCopyBody(['name' => 'Agent One'], ['company_legal_name' => 'Buyer Co'], ['order_reference' => 'NX-1'], 'Please find attached.');
        self::assertSame("Hello Agent One,\n\nFor your information, this is a copy of the e-mail NexaCrest sent to your client Buyer Co (order NX-1).\n\n---\n\nPlease find attached.", $body);
    }

    public function testANotificationEmailFollowsTheSwitchAndMustIgnoresTheOptOut(): void
    {
        $userId = $this->createTestUser('Export Executive');
        self::assertNotNull(NotificationRepository::emailNotification($userId, 'Hello', 'buyer_po_uploaded_by_client'));
        EmailPolicy::setSwitchForTest('staff_counterparty', 'staff', false);
        self::assertNull(NotificationRepository::emailNotification($userId, 'Hello', 'buyer_po_uploaded_by_client'), 'switched off in Admin');
        Database::connection()->exec("UPDATE users SET email_action_notifications_enabled = 0 WHERE id = {$userId}");
        self::assertNotNull(NotificationRepository::emailNotification($userId, 'Security', 'backup_code_used'), 'a MUST alert ignores the personal opt-out');
    }

    // ---- truthful wording, with the link to copy ----

    public function testTheLinkMessageNeverSaysEmailedWhenItWasNot(): void
    {
        self::assertSame('PI-stage form link e-mailed to b@x.test. If they did not get it, copy the link and send it yourself.',
            EmailDispatchService::linkMailOutcome('PI-stage form link', 'b@x.test', true, 'L'));
        self::assertSame('PI-stage form link generated — not e-mailed: no client e-mail on file. Copy the link and send it yourself.',
            EmailDispatchService::linkMailOutcome('PI-stage form link', null, false, 'L'));
        EmailPolicy::setSwitchForTest('buyer_links', 'buyer', false);
        self::assertSame('PI-stage form link generated — not e-mailed to b@x.test: this e-mail is switched off in Admin › E-mail settings. Copy the link and send it yourself.',
            EmailDispatchService::linkMailOutcome('PI-stage form link', 'b@x.test', false, 'L'));
        self::assertSame('Supplier Action link generated — not e-mailed: no supplier e-mail on file. Copy the link and send it yourself.',
            EmailDispatchService::linkMailOutcome('Supplier Action link', null, false, 'L', 'supplier_links', 'supplier'));
    }

    public function testAFlashCanCarryALinkToCopy(): void
    {
        Flash::set('success', 'Made.', 'https://example.test/x');
        self::assertSame([['type' => 'success', 'message' => 'Made.', 'copy_link' => 'https://example.test/x']], Flash::pull());
    }

    // ---- associates: invite, forgot password, login page ----

    public function testTheInviteGivesTheLoginPageAndAcceptingShowsTheLinkToCopy(): void
    {
        $a = ['name' => 'Agent One', 'email' => 'agent@assoc.test'];
        $body = AssociateService::inviteBody($a, 'https://app.test/associate/set-password/abc');
        self::assertStringContainsString("Set your password within 72 hours (the link works once):\nhttps://app.test/associate/set-password/abc", $body);
        self::assertStringContainsString("After that, log in any time at:\n" . AssociateService::loginUrl(), $body);
        self::assertStringEndsWith('/associate/login', AssociateService::loginUrl());

        $id = $this->associate();
        $link = AssociateService::invitePortal($id, $this->createTestUser('Admin'));
        self::assertStringContainsString('/associate/set-password/', $link);
        // No mail server in the test environment: the screen must say it was NOT sent.
        self::assertFalse(AssociateService::$lastInviteSent);
        self::assertStringStartsWith('The "set your password" e-mail could NOT be sent to x@y.test', AssociateService::inviteOutcome('x@y.test'));
    }

    public function testTheAssociateForgotPasswordGivesAOneUseLinkOnlyForARealPortalAccount(): void
    {
        $id = $this->associate();
        $a = AssociateRepository::find($id);
        self::assertNull(AssociatePortalService::sendResetLink('nobody-' . bin2hex(random_bytes(3)) . '@assoc.test'));
        $link = AssociatePortalService::sendResetLink((string) $a['email']);
        self::assertNotNull($link);
        $token = substr((string) $link, strrpos((string) $link, '/') + 1);
        self::assertSame($id, (int) AssociatePortalService::associateForSetPassword($token)['id']);
        self::assertTrue(AssociatePortalService::setPassword($token, 'Reset1234567'));
        self::assertNull(AssociatePortalService::associateForSetPassword($token), 'the link works once');
        self::assertStringContainsString('If you did not ask for this, you can ignore this e-mail', AssociatePortalService::resetBody($a, 'L'));
    }

    public function testTheBuyerLoginCodeHasItsOwnWording(): void
    {
        self::assertStringNotContainsString('administrator', TwoFactorService::CLIENT_CODE_BODY);
        self::assertStringContainsString('order portal', TwoFactorService::CLIENT_CODE_BODY);
        self::assertNotNull($this->value("SELECT 1 FROM email_templates WHERE template_key = 'client_login_code'"));
        self::assertStringContainsString('{login_link}', (string) $this->value("SELECT body FROM email_templates WHERE template_key = 'client_portal_invite'"));
    }

    // ---- department routing ----

    public function testOrderEventsEmailOnlyTheDepartmentOnTheOrder(): void
    {
        $finance = $this->member('Accounts Executive');
        $sales = $this->member('Export Executive');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$finance, $sales]);
        self::assertSame('FIN', StaffRouting::departmentFor('client_payment_reported'));
        $route = StaffRouting::forOrder($orderId, 'FIN');
        self::assertSame('order', $route['level']);
        self::assertSame([$finance], $route['email'], 'only the Finance person on the order, not the Sales one');
        self::assertNull($route['note']);
    }

    public function testWithNobodyFromTheDepartmentTheManagerIsToldToAssignSomeone(): void
    {
        $manager = $this->member('Logistics Manager');
        $orderId = $this->createTestOrder($this->createTestClient(), 'FOB', 'Standard — New Buyer', 'USD', null, null, [$this->member('Export Executive')]);
        $route = StaffRouting::forOrder($orderId, 'LOG');
        self::assertSame('manager', $route['level']);
        self::assertContains($manager, $route['email']);
        self::assertSame('Nobody from Logistics is assigned to this order — please assign someone.', $route['note']);

        NotificationRepository::notifyOrderTeam($orderId, 'inspection_failed', 'Inspection failed on the order.');
        self::assertSame('Inspection failed on the order. Nobody from Logistics is assigned to this order — please assign someone.',
            $this->value("SELECT message FROM notifications WHERE user_id = {$manager} AND related_order_id = {$orderId} ORDER BY id DESC LIMIT 1"));
    }

    public function testWithNoManagerEitherTheMdIsEmailedAndRoutineEventsSkipMdEdAndCoordinator(): void
    {
        $code = 'T' . strtoupper(bin2hex(random_bytes(3)));
        Database::connection()->prepare('INSERT INTO departments (code, name) VALUES (:c, :n)')->execute(['c' => $code, 'n' => 'Test Dept ' . $code]);
        $orderId = $this->createTestOrder($this->createTestClient());
        $route = StaffRouting::forOrder($orderId, $code);
        self::assertSame('md', $route['level']);
        self::assertSame(StaffRouting::managingDirectors(), $route['email']);

        self::assertTrue(StaffRouting::isRoutineInAppOnly($this->createTestUser('Managing Director')));
        self::assertTrue(StaffRouting::isRoutineInAppOnly($this->createTestUser('Executive Director')));
        self::assertTrue(StaffRouting::isRoutineInAppOnly($this->createTestUser('Order Coordinator')));
        self::assertFalse(StaffRouting::isRoutineInAppOnly($this->createTestUser('Export Executive')));
    }

    public function testCompanyAlertsGoToTheResponsiblePersonAndTheBackup(): void
    {
        $responsible = $this->createTestUser('Export Executive');
        $backup = $this->createTestUser('Accounts Executive');
        $admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $admin];
        $_POST = ['reason' => 'Name who looks after the LUT', 'on' => $this->currentSwitches(),
                  'owners' => ['lut' => ['responsible' => (string) $responsible, 'backup' => (string) $backup]]];
        (new EmailSettingsController())->save([]);
        self::assertSame([$responsible, $backup], StaffRouting::companyItem('lut'));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action_type = 'COMPLIANCE_ALERT_OWNER_CHANGED' AND field_name = 'lut' AND user_id = {$admin}"));
        Database::connection()->exec("UPDATE users SET is_active = 0 WHERE id = {$responsible}");
        self::assertSame([$backup], StaffRouting::companyItem('lut'), 'an inactive person is skipped');
    }

    public function testTheSettingsPageNeedsAReasonAndSavesTestingModeAndSwitches(): void
    {
        $admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $admin];
        $_POST = ['reason' => '', 'testing_mode' => '1', 'on' => []];
        (new EmailSettingsController())->save([]);
        self::assertSame('error', Flash::pull()[0]['type']);
        self::assertFalse(EmailPolicy::testingMode(), 'nothing saved without a reason');

        $on = $this->currentSwitches();
        unset($on['staff_counterparty|staff']);
        $_POST = ['reason' => 'Testing the buyer e-mails this week', 'testing_mode' => '1', 'on' => $on];
        (new EmailSettingsController())->save([]);
        $flash = Flash::pull()[0];
        self::assertSame('success', $flash['type']);
        self::assertStringContainsString('Testing mode (hold e-mails to buyers and suppliers): on', $flash['message']);
        self::assertTrue(EmailPolicy::testingMode());
        self::assertFalse(EmailPolicy::switchOn('staff_counterparty', 'staff'));
        self::assertTrue(EmailPolicy::switchOn('login_code', 'staff'), 'a MUST switch cannot be turned off');
    }

    // ---- helpers ----

    /** @return array<string,string> every switch that is on now, as the form posts it */
    private function renderEmailSettingsPage(): string
    {
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        ob_start();
        (new \App\Controllers\EmailSettingsController())->index([]);
        return (string) ob_get_clean();
    }

    private function currentSwitches(): array
    {
        $on = [];
        foreach (EmailPolicy::matrix() as $kind => $cells) {
            foreach ($cells as $aud => $cell) {
                if ($cell['tier'] !== 'must' && $cell['on']) {
                    $on["{$kind}|{$aud}"] = '1';
                }
            }
        }
        return $on;
    }

    /** A user with the role and a current membership of that role's designation. */
    private function member(string $roleName): int
    {
        $userId = $this->createTestUser($roleName);
        Database::connection()->prepare(
            'INSERT INTO user_department_memberships (user_id, designation_id, starts_on, is_default)
             SELECT :u, dd.id, CURDATE(), 1 FROM department_designations dd JOIN roles r ON r.id = dd.role_id WHERE r.name = :r LIMIT 1'
        )->execute(['u' => $userId, 'r' => $roleName]);
        return $userId;
    }

    private function associate(): int
    {
        $id = AssociateRepository::create(['name' => 'D280 Assoc ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'd280-' . bin2hex(random_bytes(4)) . '@assoc.test', 'status' => 'active', 'portal_enabled' => 1], $this->createTestUser('Admin'));
        Database::connection()->exec("UPDATE associates SET portal_enabled = 1 WHERE id = {$id}");
        return $id;
    }

    private function value(string $sql): mixed
    {
        $v = Database::connection()->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }
}
