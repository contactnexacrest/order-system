<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\SettingsController;
use App\Repositories\CompanySettingsRepository;
use App\Services\MailRedirectService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section AS — mail redirect/CC, independent of Test Mode
 * (see TestModeEmailGateTest for that feature's own equivalent tests).
 * Covers: MailRedirectService's resolve/CC logic (including the security-
 * email carve-out, same shape as TestModeService's), and
 * SettingsController::update()'s Super-Admin-only gate on
 * requires_super_admin fields (mail_cc_emails, mail_default_cc_email).
 */
final class MailRedirectCcTest extends DbTestCase
{
    private const KEYS = ['mail_redirect_enabled', 'mail_redirect_address', 'mail_cc_emails', 'mail_default_cc_email'];
    private array $originalValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        foreach (self::KEYS as $key) {
            $this->originalValues[$key] = (string) CompanySettingsRepository::get($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalValues as $key => $value) {
            CompanySettingsRepository::set($key, $value, null);
        }
        parent::tearDown();
    }

    public function testResolveRecipientPassesThroughWhenRedirectIsOff(): void
    {
        CompanySettingsRepository::set('mail_redirect_enabled', '0');
        CompanySettingsRepository::set('mail_redirect_address', 'qa-inbox@nexacrest.example');

        $result = MailRedirectService::resolveRecipient('buyer@real-company.example', false);

        self::assertSame('buyer@real-company.example', $result);
    }

    public function testResolveRecipientRedirectsWhenEnabledWithAValidAddress(): void
    {
        CompanySettingsRepository::set('mail_redirect_enabled', '1');
        CompanySettingsRepository::set('mail_redirect_address', 'qa-inbox@nexacrest.example');

        $result = MailRedirectService::resolveRecipient('buyer@real-company.example', false);

        self::assertSame('qa-inbox@nexacrest.example', $result);
    }

    public function testResolveRecipientFallsBackToOriginalWhenEnabledButAddressIsBlank(): void
    {
        CompanySettingsRepository::set('mail_redirect_enabled', '1');
        CompanySettingsRepository::set('mail_redirect_address', '');

        $result = MailRedirectService::resolveRecipient('buyer@real-company.example', false);

        self::assertSame('buyer@real-company.example', $result, 'an unconfigured redirect must never silently drop the real recipient');
    }

    public function testSecurityEmailAlwaysBypassesRedirectRegardlessOfSettings(): void
    {
        CompanySettingsRepository::set('mail_redirect_enabled', '1');
        CompanySettingsRepository::set('mail_redirect_address', 'qa-inbox@nexacrest.example');

        $result = MailRedirectService::resolveRecipient('staff@nexacrest.example', true);

        self::assertSame('staff@nexacrest.example', $result, 'staff 2FA/password-reset emails must never be redirected');
    }

    public function testCcListCombinesCommaSeparatedListAndDefaultCcDeduplicated(): void
    {
        CompanySettingsRepository::set('mail_cc_emails', 'a@example.com, b@example.com ,a@example.com');
        CompanySettingsRepository::set('mail_default_cc_email', 'default@example.com');

        $result = MailRedirectService::ccList(false);

        sort($result);
        self::assertSame(['a@example.com', 'b@example.com', 'default@example.com'], $result);
    }

    public function testCcListIgnoresInvalidAddressesAndIsEmptyWhenUnset(): void
    {
        CompanySettingsRepository::set('mail_cc_emails', '');
        CompanySettingsRepository::set('mail_default_cc_email', '');

        self::assertSame([], MailRedirectService::ccList(false));
    }

    public function testCcListIsEmptyForASecurityEmailRegardlessOfConfiguredAddresses(): void
    {
        CompanySettingsRepository::set('mail_cc_emails', 'a@example.com');
        CompanySettingsRepository::set('mail_default_cc_email', 'default@example.com');

        self::assertSame([], MailRedirectService::ccList(true), 'security emails must never be CC\'d to a third party');
    }

    public function testNonSuperAdminCannotChangeMailCcEmails(): void
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        CompanySettingsRepository::set('mail_cc_emails', 'original@example.com');

        $_POST = [
            'settings' => ['mail_cc_emails' => 'attacker@example.com'],
            'reason' => 'phpunit super-admin-gate regression check',
        ];
        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();

        self::assertSame('original@example.com', CompanySettingsRepository::get('mail_cc_emails'));
    }

    public function testSuperAdminCanChangeMailCcEmails(): void
    {
        $userId = $this->createTestUser('Admin');
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $userId]);
        $_SESSION['_auth_user_id'] = $userId;
        CompanySettingsRepository::set('mail_cc_emails', 'original@example.com');

        $_POST = [
            'settings' => ['mail_cc_emails' => 'new@example.com'],
            'reason' => 'phpunit super-admin-gate regression check',
        ];
        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();

        self::assertSame('new@example.com', CompanySettingsRepository::get('mail_cc_emails'));
    }

    public function testEmailListValueRejectsAnInvalidAddressInTheList(): void
    {
        $userId = $this->createTestUser('Admin');
        Database::connection()->prepare('UPDATE users SET is_super_admin = 1 WHERE id = :id')->execute(['id' => $userId]);
        $_SESSION['_auth_user_id'] = $userId;
        CompanySettingsRepository::set('mail_cc_emails', 'original@example.com');

        $_POST = [
            'settings' => ['mail_cc_emails' => 'valid@example.com, not-an-email'],
            'reason' => 'phpunit email_list validation regression check',
        ];
        ob_start();
        (new SettingsController())->update([]);
        ob_end_clean();

        self::assertSame('original@example.com', CompanySettingsRepository::get('mail_cc_emails'));
    }
}
