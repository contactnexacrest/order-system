<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\SettingValueValidator;
use App\Middleware\AssociateAuth;
use App\Middleware\ClientAuth;
use App\Middleware\SessionAuth;
use App\Repositories\CompanySettingsRepository;
use App\Services\AuthService;
use App\Services\RememberBrowserService;
use App\Services\SessionTimeouts;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-283 (11 Nov 2026, point 21): the Admin idle timeouts really
 * apply — staff 4 h, buyers 2 h, associates 2 h, staff signed out after 12 h
 * however active; PHP's own session life follows them; a 2-minute warning with
 * "Stay logged in"; "This is my own office computer" for 30 days and "Forget all
 * remembered computers". Parity: sessionTimeoutsD283.test.js.
 */
final class SessionTimeoutsD283Test extends DbTestCase
{
    /** @var array<string, ?string> */
    private array $before = [];

    private const KEYS = ['session_timeout_staff_minutes', 'session_timeout_client_minutes', 'session_timeout_associate_minutes', 'session_absolute_staff_hours'];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        foreach (self::KEYS as $k) {
            $this->before[$k] = CompanySettingsRepository::get($k);
        }
        SessionTimeouts::$current = null;
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $k => $v) {
            CompanySettingsRepository::set($k, $v);
        }
        $_SESSION = [];
        SessionTimeouts::$current = null;
        parent::tearDown();
    }

    public function testTheSeedHasSeparateTimeoutsAndThirtyRememberedDays(): void
    {
        self::assertSame('240', (string) $this->value("SELECT setting_value FROM company_settings WHERE setting_key = 'session_timeout_staff_minutes'"));
        self::assertSame('120', (string) $this->value("SELECT setting_value FROM company_settings WHERE setting_key = 'session_timeout_client_minutes'"));
        self::assertSame('120', (string) $this->value("SELECT setting_value FROM company_settings WHERE setting_key = 'session_timeout_associate_minutes'"));
        self::assertSame('12', (string) $this->value("SELECT setting_value FROM company_settings WHERE setting_key = 'session_absolute_staff_hours'"));
        self::assertSame('7', (string) $this->value("SELECT setting_value FROM company_settings WHERE setting_key = 'login_remember_browser_days'")); // D-369: 7 days
        self::assertNull($this->value("SELECT 1 FROM company_settings WHERE setting_key = 'session_timeout_minutes'"), 'the one shared timeout is gone');
    }

    public function testEachAreaHasItsOwnIdleLimit(): void
    {
        CompanySettingsRepository::set('session_timeout_staff_minutes', '240');
        CompanySettingsRepository::set('session_timeout_client_minutes', '120');
        $now = time();
        self::assertFalse(SessionTimeouts::idleExpired('staff', $now - 3 * 3600, $now), 'staff: 3 hours idle is fine');
        self::assertTrue(SessionTimeouts::idleExpired('staff', $now - 241 * 60, $now));
        self::assertTrue(SessionTimeouts::idleExpired('client', $now - 3 * 3600, $now), 'buyers: 3 hours idle is too long');
        self::assertFalse(SessionTimeouts::idleExpired('client', null, $now), 'no activity yet is not expired');
        self::assertGreaterThanOrEqual(240 * 60, SessionTimeouts::maxIdleSeconds());
        self::assertSame('4 hours', SessionTimeouts::describeMinutes(240));
        self::assertSame('90 minutes', SessionTimeouts::describeMinutes(90));
    }

    public function testPhpKeepsTheSessionAsLongAsTheLongestTimeout(): void
    {
        CompanySettingsRepository::set('session_timeout_staff_minutes', '240');
        CompanySettingsRepository::set('session_timeout_client_minutes', '120');
        CompanySettingsRepository::set('session_timeout_associate_minutes', '300');
        self::assertSame(300 * 60, SessionTimeouts::maxIdleSeconds(), 'the longest of the three, not the host default of about 24 minutes');
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bootstrap.php');
        self::assertStringContainsString('SessionTimeouts::configurePhpSession()', $src, 'bootstrap sets gc_maxlifetime and the cookie before session_start()');
        self::assertStringContainsString("'lifetime' => \$sessionLifetime", $src);
    }

    public function testStaffAreSignedOutAfterTwelveHoursHoweverActive(): void
    {
        CompanySettingsRepository::set('session_absolute_staff_hours', '12');
        $userId = $this->createTestUser('Admin');
        $user = \App\Repositories\UserRepository::findById($userId);
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => (int) $user['session_version'],
                     '_last_activity_ts' => time() - 60, SessionTimeouts::STAFF_LOGIN_AT_KEY => time() - 13 * 3600];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertFalse((SessionAuth::required())([]));
        self::assertNull(AuthService::currentUserId());
        self::assertSame('For security, staff log in again at least every 12 hours. Please log in again.', \App\Helpers\Flash::pull()[0]['message']);
    }

    public function testAnActiveStaffPageCarriesTheWarningTag(): void
    {
        $userId = $this->createTestUser('Admin');
        $user = \App\Repositories\UserRepository::findById($userId);
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => (int) $user['session_version'],
                     '_last_activity_ts' => time() - 60, SessionTimeouts::STAFF_LOGIN_AT_KEY => time() - 3600];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertTrue((SessionAuth::required())([]));
        $tag = SessionTimeouts::metaTag();
        self::assertStringContainsString('<meta name="session-idle" content="' . SessionTimeouts::idleMinutes('staff') * 60 . '"', $tag);
        self::assertStringContainsString('data-keepalive="/keep-alive"', $tag);
        self::assertStringContainsString('data-login="/login"', $tag);
        self::assertStringContainsString('data-warn="120"', $tag);
        self::assertMatchesRegularExpression('/data-absolute-left="(3[0-9]{4})"/', $tag, 'about 11 hours left');
    }

    public function testBuyersAndAssociatesUseTheirOwnTimeout(): void
    {
        CompanySettingsRepository::set('session_timeout_client_minutes', '120');
        CompanySettingsRepository::set('session_timeout_associate_minutes', '120');
        $clientId = $this->createTestClient();
        Database::connection()->prepare('INSERT INTO client_logins (client_id, password_hash) VALUES (:c, :h)')->execute(['c' => $clientId, 'h' => password_hash('x', PASSWORD_DEFAULT)]);
        $_SESSION = ['_client_portal_client_id' => $clientId, '_client_last_activity_ts' => time() - 3 * 3600];
        self::assertFalse((ClientAuth::required())([]), 'a buyer idle for 3 hours is signed out');

        $associateId = \App\Repositories\AssociateRepository::create(['name' => 'D283 Assoc ' . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'd283-' . bin2hex(random_bytes(4)) . '@assoc.test', 'status' => 'active', 'portal_enabled' => 1], $this->createTestUser('Admin'));
        Database::connection()->exec("UPDATE associates SET portal_enabled = 1 WHERE id = {$associateId}");
        $_SESSION = ['_associate_portal_id' => $associateId, '_associate_last_activity_ts' => time() - 3 * 3600];
        self::assertFalse((AssociateAuth::required())([]), 'an associate idle for 3 hours is signed out');
        self::assertNull(\App\Services\AssociatePortalService::currentId());
        $_SESSION = ['_associate_portal_id' => $associateId, '_associate_last_activity_ts' => time() - 60];
        $_SERVER['REQUEST_URI'] = '/associate';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertTrue((AssociateAuth::required())([]), 'one minute idle is fine');
        self::assertStringContainsString('data-keepalive="/associate/keep-alive"', SessionTimeouts::metaTag());
    }

    public function testTheTimeoutSettingsHaveAllowedRanges(): void
    {
        self::assertNull(SettingValueValidator::check('number', '240', 'session_timeout_staff_minutes'));
        self::assertSame('must be a whole number from 5 to 1440 minutes (got "2000")', SettingValueValidator::check('number', '2000', 'session_timeout_staff_minutes'));
        self::assertSame('must be a whole number from 1 to 24 hours (got "0")', SettingValueValidator::check('number', '0', 'session_absolute_staff_hours'));
        self::assertSame('must be a whole number from 0 to 90 days (got "91")', SettingValueValidator::check('number', '91', 'login_remember_browser_days'));
        self::assertNull(SettingValueValidator::check('number', '91', 'some_other_number'), 'other numbers keep the old rule');
    }

    public function testForgetAllRememberedComputers(): void
    {
        $userId = $this->createTestUser('Export Executive');
        $before = CompanySettingsRepository::get('login_remember_browser_days');
        CompanySettingsRepository::set('login_remember_browser_days', '30');
        try {
            RememberBrowserService::remember($userId);
            RememberBrowserService::remember($userId);
            self::assertSame(2, RememberBrowserService::countFor($userId));
            $admin = $this->createTestUser('Admin');
            $_SESSION = ['_auth_user_id' => $admin];
            (new \App\Controllers\UserController())->forgetComputers(['id' => (string) $userId]);
            self::assertSame(0, RememberBrowserService::countFor($userId));
            self::assertSame('2 remembered computers forgotten — the e-mailed login code is asked again at the next login.', \App\Helpers\Flash::pull()[0]['message']);
            self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action_type = 'LOGIN_BROWSERS_FORGOTTEN' AND entity_id = {$userId}"));
        } finally {
            CompanySettingsRepository::set('login_remember_browser_days', $before);
        }
    }

    private function value(string $sql): mixed
    {
        $v = Database::connection()->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }
}
