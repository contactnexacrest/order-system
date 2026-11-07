<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\BackupCodeService;
use App\Services\ClientPortalService;
use App\Services\RememberBrowserService;
use App\Services\TwoFactorService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-265 (7 Nov 2026): a 6-digit code e-mailed at every login for every
 * staff member and every buyer (valid 10 minutes, 5 tries, a new code at most once a
 * minute); emergency backup codes for the accounts that run the company; "Remember
 * this browser" for staff only, off by default. The Node suite has the same test
 * (loginCodeD265.test.js) with the same expectations.
 */
final class LoginCodeD265Test extends DbTestCase
{
    private const PASSWORD = 'Known-Password-123!';

    protected function setUp(): void
    {
        parent::setUp();
        // A real session, so signing in can rotate its id as on a live request.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_COOKIE = [];
        CompanySettingsRepository::set('login_remember_browser_days', '0');
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('login_remember_browser_days', '0');
        $_COOKIE = [];
        parent::tearDown();
    }

    private function staff(string $role): array
    {
        $id = $this->createTestUser($role);
        Database::connection()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
            ->execute(['h' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'id' => $id]);
        return UserRepository::findById($id);
    }

    private function buyer(): array
    {
        $email = 'd265-buyer-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientId = $this->createTestClient($email);
        Database::connection()->prepare('INSERT INTO client_logins (client_id, password_hash, force_password_change) VALUES (:c, :h, 0)')
            ->execute(['c' => $clientId, 'h' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        return ['id' => $clientId, 'email' => $email];
    }

    private function audits(string $action, int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM audit_log WHERE action_type = :a AND user_id = :u');
        $stmt->execute(['a' => $action, 'u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function testEveryStaffRoleNeedsTheEmailedCodeEvenWithTwoFactorSwitchedOff(): void
    {
        foreach (['Admin', 'Managing Director', 'Executive Director', 'Export Executive', 'Accounts Executive', 'Logistics Executive', 'Viewer / Auditor', 'CA / Chartered Accountant'] as $role) {
            $_SESSION = [];
            $u = $this->staff($role); // two_fa_enabled = 0 on the row: no longer matters
            $r = AuthService::attemptLogin((string) $u['email'], self::PASSWORD);
            self::assertSame('requires_2fa', $r['status'], $role);
            self::assertSame('email', $r['method'], $role);
            self::assertMatchesRegularExpression('/^\d{6}$/', $r['dev_code'], $role);
            self::assertNull(AuthService::currentUserId(), "{$role}: not signed in before the code");
            self::assertSame('ok', AuthService::completeTwoFactor($r['dev_code'])['status'], $role);
            self::assertSame((int) $u['id'], AuthService::currentUserId(), $role);
        }
    }

    public function testAWrongCodeFiveTriesAndExpiryAllRefuse(): void
    {
        $u = $this->staff('Export Executive');
        $r = AuthService::attemptLogin((string) $u['email'], self::PASSWORD);
        $wrong = $r['dev_code'] === '000000' ? '111111' : '000000';
        for ($i = 1; $i <= 4; $i++) {
            self::assertSame('invalid_code', AuthService::completeTwoFactor($wrong)['status']);
            self::assertSame((int) $u['id'], TwoFactorService::pendingUserId(), "still waiting after try {$i}");
        }
        self::assertSame('invalid_code', AuthService::completeTwoFactor($wrong)['status']);
        self::assertNull(TwoFactorService::pendingUserId(), 'five wrong tries end the sign-in');
        self::assertSame('no_pending_2fa', AuthService::completeTwoFactor($r['dev_code'])['status'], 'the right code no longer works');

        $r = AuthService::attemptLogin((string) $u['email'], self::PASSWORD);
        $_SESSION['_2fa_pending']['expires_at'] = time() - 1;
        self::assertSame('invalid_code', AuthService::completeTwoFactor($r['dev_code'])['status'], 'an expired code is refused');
        self::assertNull(AuthService::currentUserId());
    }

    public function testTheCodeIsValidForTenMinutes(): void
    {
        $u = $this->staff('Export Executive');
        AuthService::attemptLogin((string) $u['email'], self::PASSWORD);
        $p = $_SESSION['_2fa_pending'];
        self::assertSame(600, $p['expires_at'] - $p['issued_at']);
    }

    public function testANewCodeAtMostOnceAMinute(): void
    {
        $u = $this->staff('Export Executive');
        $first = AuthService::attemptLogin((string) $u['email'], self::PASSWORD)['dev_code'];
        $soon = TwoFactorService::resend();
        self::assertSame('too_soon', $soon['status']);
        self::assertGreaterThan(0, $soon['wait']);

        $_SESSION['_2fa_pending']['issued_at'] = time() - 61;
        $again = TwoFactorService::resend();
        self::assertSame('sent', $again['status']);
        self::assertMatchesRegularExpression('/^\d{6}$/', $again['dev_code']);
        self::assertSame(0, $_SESSION['_2fa_pending']['attempts'], 'a new code starts a fresh 5 tries');
        if ($again['dev_code'] !== $first) {
            self::assertSame('invalid_code', AuthService::completeTwoFactor($first)['status'], 'the old code stops working');
        }
        self::assertSame('ok', AuthService::completeTwoFactor($again['dev_code'])['status']);
    }

    public function testBuyersNeedTheEmailedCodeToo(): void
    {
        $b = $this->buyer();
        $r = ClientPortalService::attemptLogin($b['email'], self::PASSWORD);
        self::assertSame('requires_code', $r['status']);
        self::assertNull(ClientPortalService::currentClientId(), 'the password alone does not open the portal');
        self::assertSame('invalid_code', ClientPortalService::completeLogin($r['dev_code'] === '000000' ? '111111' : '000000')['status']);
        self::assertSame('ok', ClientPortalService::completeLogin($r['dev_code'])['status']);
        self::assertSame($b['id'], ClientPortalService::currentClientId());

        $_SESSION = [];
        ClientPortalService::attemptLogin($b['email'], self::PASSWORD);
        self::assertSame('too_soon', TwoFactorService::resend('client')['status']);
        self::assertNull(TwoFactorService::pendingUserId('staff'), 'a buyer sign-in never touches the staff slot');
    }

    public function testBackupCodesOnlyForCompanyRunnersHashedOneTimeAndReplaced(): void
    {
        $md = $this->staff('Managing Director');
        $exec = $this->staff('Export Executive');
        self::assertTrue(BackupCodeService::eligible((int) $md['id']));
        self::assertFalse(BackupCodeService::eligible((int) $exec['id']));

        $codes = BackupCodeService::generate((int) $md['id']);
        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $c) {
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $c);
        }
        $stored = Database::connection()->query('SELECT code_hash FROM two_fa_backup_codes WHERE user_id = ' . (int) $md['id'])->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(10, $stored);
        foreach ($stored as $h) {
            self::assertNotContains($h, $codes, 'never stored in plain text');
            self::assertNotContains(BackupCodeService::normalise($h), array_map([BackupCodeService::class, 'normalise'], $codes));
        }
        self::assertSame(1, $this->audits('BACKUP_CODES_GENERATED', (int) $md['id']));

        // Signs in with one (lower case, no dash is fine), once only.
        AuthService::attemptLogin((string) $md['email'], self::PASSWORD);
        self::assertSame('ok', AuthService::completeWithBackupCode(strtolower(str_replace('-', '', $codes[0])))['status']);
        self::assertSame((int) $md['id'], AuthService::currentUserId());
        self::assertSame(9, BackupCodeService::remaining((int) $md['id']));
        self::assertSame(1, $this->audits('LOGIN_BACKUP_CODE_USED', (int) $md['id']));
        $notified = (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE type = 'backup_code_used' AND message LIKE " . Database::connection()->quote('%' . $md['email'] . '%'))->fetchColumn();
        self::assertGreaterThan(0, $notified, 'the company-settings holders are told');

        $_SESSION = [];
        AuthService::attemptLogin((string) $md['email'], self::PASSWORD);
        self::assertSame('invalid_code', AuthService::completeWithBackupCode($codes[0])['status'], 'a used code never works again');

        // A new set replaces the old one.
        $new = BackupCodeService::generate((int) $md['id']);
        self::assertSame(10, BackupCodeService::remaining((int) $md['id']));
        self::assertSame('invalid_code', AuthService::completeWithBackupCode($codes[1])['status'], 'the old set stops working');
        self::assertSame('ok', AuthService::completeWithBackupCode($new[0])['status']);

        // Someone outside that circle cannot use the backup form at all.
        $_SESSION = [];
        AuthService::attemptLogin((string) $exec['email'], self::PASSWORD);
        self::assertSame('not_allowed', AuthService::completeWithBackupCode($new[1])['status']);
        self::assertNull(AuthService::currentUserId());
    }

    public function testRememberThisBrowserIsOffByDefaultAndForgottenOnPasswordChange(): void
    {
        $u = $this->staff('Export Executive');
        self::assertSame(0, RememberBrowserService::days());
        $r = AuthService::attemptLogin((string) $u['email'], self::PASSWORD);
        AuthService::completeTwoFactor($r['dev_code'], true);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM login_remembered_browsers WHERE user_id = ' . (int) $u['id'])->fetchColumn(), 'off: nothing remembered even when ticked');
        self::assertArrayNotHasKey(RememberBrowserService::COOKIE, $_COOKIE);

        CompanySettingsRepository::set('login_remember_browser_days', '7');
        $_SESSION = [];
        $r = AuthService::attemptLogin((string) $u['email'], self::PASSWORD);
        self::assertSame('requires_2fa', $r['status'], 'a browser not yet remembered still needs the code');
        AuthService::completeTwoFactor($r['dev_code'], true);
        $cookie = (string) $_COOKIE[RememberBrowserService::COOKIE];
        $row = Database::connection()->query('SELECT * FROM login_remembered_browsers WHERE user_id = ' . (int) $u['id'])->fetch();
        self::assertNotFalse($row);
        self::assertStringNotContainsString($row['validator_hash'], $cookie, 'only a hash of the secret is kept');
        self::assertEqualsWithDelta(time() + 7 * 86400, strtotime((string) $row['expires_at']), 5);

        $_SESSION = [];
        self::assertSame('ok', AuthService::attemptLogin((string) $u['email'], self::PASSWORD)['status'], 'remembered: no code');
        self::assertSame((int) $u['id'], AuthService::currentUserId());

        $_COOKIE[RememberBrowserService::COOKIE] = $cookie . 'x';
        $_SESSION = [];
        self::assertSame('requires_2fa', AuthService::attemptLogin((string) $u['email'], self::PASSWORD)['status'], 'a tampered cookie is ignored');

        $_COOKIE[RememberBrowserService::COOKIE] = $cookie;
        $other = $this->staff('Export Executive');
        $_SESSION = [];
        self::assertSame('requires_2fa', AuthService::attemptLogin((string) $other['email'], self::PASSWORD)['status'], 'another person on the same browser still needs the code');

        UserRepository::updatePassword((int) $u['id'], password_hash(self::PASSWORD, PASSWORD_DEFAULT));
        $_SESSION = [];
        self::assertSame('requires_2fa', AuthService::attemptLogin((string) $u['email'], self::PASSWORD)['status'], 'a password change forgets the browser');
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM login_remembered_browsers WHERE user_id = ' . (int) $u['id'])->fetchColumn());

        CompanySettingsRepository::set('login_remember_browser_days', '0');
        self::assertFalse(RememberBrowserService::isRemembered(UserRepository::findById((int) $u['id']), $cookie), 'switching the setting off ends every remembered browser');
    }

    public function testTheCodeEmailIsSecurityMailWithTheTenMinuteWording(): void
    {
        $body = (string) Database::connection()->query("SELECT body FROM email_templates WHERE template_key = 'two_factor_code'")->fetchColumn();
        self::assertStringContainsString('This code expires in 10 minutes.', $body);
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/TwoFactorService.php');
        self::assertStringContainsString("EmailService::sendPlainText(\$destination, \$rendered['subject'], \$rendered['body'], true);", $src, 'sent even when business e-mail is switched off');
    }
}
