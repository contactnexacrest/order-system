<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\AssociateRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Services\AssociatePortalService;
use App\Services\AuthService;
use App\Services\ClientPortalService;
use App\Services\TwoFactorService;
use App\Tests\Support\DbTestCase;

/**
 * HNT-L04 (QA 13 Nov 2026): signing in again with the password sent a new
 * e-mailed login code every time, without limit — for staff, buyers and
 * associates alike — so whoever knows a password could flood the inbox and
 * get a fresh set of 5 code tries as often as they liked. Now at most
 * 5 codes are sent per account in 15 minutes (a "send a new code" counts
 * too); a successful sign-in starts the count again. The Node suite has
 * the same test (loginCodeSendLimitHntL04.test.js).
 */
final class LoginCodeSendLimitHntL04Test extends DbTestCase
{
    private const PASSWORD = 'Known-Password-123!';

    private ?string $rememberDaysBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_COOKIE = [];
        $this->rememberDaysBefore = CompanySettingsRepository::get('login_remember_browser_days');
        CompanySettingsRepository::set('login_remember_browser_days', '0');
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('login_remember_browser_days', (string) ($this->rememberDaysBefore ?? '7'));
        $_SESSION = [];
        $_COOKIE = [];
        parent::tearDown();
    }

    private function staff(): array
    {
        $id = $this->createTestUser('Export Executive');
        Database::connection()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
            ->execute(['h' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'id' => $id]);
        return UserRepository::findById($id);
    }

    private function buyer(): array
    {
        $email = 'l04-buyer-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientId = $this->createTestClient($email);
        Database::connection()->prepare('INSERT INTO client_logins (client_id, password_hash, force_password_change) VALUES (:c, :h, 0)')
            ->execute(['c' => $clientId, 'h' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        return ['id' => $clientId, 'email' => $email];
    }

    private function associate(): array
    {
        $staff = $this->createTestUser('Admin');
        $id = AssociateRepository::create(['name' => 'L04 Assoc ' . bin2hex(random_bytes(3)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'l04-assoc-' . bin2hex(random_bytes(4)) . '@assoc.test', 'status' => 'active', 'portal_enabled' => 1], $staff);
        AssociateRepository::setFields($id, ['portal_enabled' => 1, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT)]);
        return AssociateRepository::find($id);
    }

    private function associateCodesIssued(int $associateId): int
    {
        $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM associate_tokens WHERE associate_id = :a AND purpose = 'login_code'");
        $stmt->execute(['a' => $associateId]);
        return (int) $stmt->fetchColumn();
    }

    public function testHntL04StaffGetAtMostFiveCodesIn15MinutesBySigningInAgain(): void
    {
        $u = $this->staff();
        for ($i = 1; $i <= TwoFactorService::MAX_CODES_PER_WINDOW; $i++) {
            $_SESSION = [];
            self::assertSame('requires_2fa', AuthService::attemptLogin((string) $u['email'], self::PASSWORD)['status'], "code {$i}");
        }
        $_SESSION = [];
        self::assertSame('too_many_codes', AuthService::attemptLogin((string) $u['email'], self::PASSWORD)['status'], 'the 6th code within 15 minutes is refused');
        self::assertNull(TwoFactorService::pendingUserId(), 'no sign-in waits for a code that was never sent');
    }

    public function testHntL04ResendCountsTowardsTheSameLimitAndTheLatestCodeStillWorks(): void
    {
        $u = $this->staff();
        for ($i = 1; $i <= 4; $i++) {
            $_SESSION = [];
            AuthService::attemptLogin((string) $u['email'], self::PASSWORD);
        }
        $_SESSION['_2fa_pending']['issued_at'] = time() - 61;
        $fifth = TwoFactorService::resend();
        self::assertSame('sent', $fifth['status']);
        $_SESSION['_2fa_pending']['issued_at'] = time() - 61;
        self::assertSame('too_many', TwoFactorService::resend()['status'], 'a 6th code by "send a new code" is refused too');
        self::assertSame('ok', AuthService::completeTwoFactor($fifth['dev_code'])['status'], 'the last code sent still signs in');
    }

    public function testHntL04ASuccessfulSignInStartsTheCountAgain(): void
    {
        $u = $this->staff();
        for ($round = 1; $round <= 3; $round++) {
            for ($i = 1; $i <= 4; $i++) {
                $_SESSION = [];
                $r = AuthService::attemptLogin((string) $u['email'], self::PASSWORD);
                self::assertSame('requires_2fa', $r['status'], "round {$round}, code {$i}");
            }
            self::assertSame('ok', AuthService::completeTwoFactor($r['dev_code'])['status']);
        }
    }

    public function testHntL04BuyersGetAtMostFiveCodes(): void
    {
        $b = $this->buyer();
        for ($i = 1; $i <= 5; $i++) {
            $_SESSION = [];
            self::assertSame('requires_code', ClientPortalService::attemptLogin($b['email'], self::PASSWORD)['status'], "code {$i}");
        }
        $_SESSION = [];
        self::assertSame('too_many_codes', ClientPortalService::attemptLogin($b['email'], self::PASSWORD)['status']);
        self::assertNull(TwoFactorService::pendingUserId('client'));

        // Another buyer is not affected.
        $other = $this->buyer();
        $_SESSION = [];
        $r = ClientPortalService::attemptLogin($other['email'], self::PASSWORD);
        self::assertSame('requires_code', $r['status']);
        self::assertSame('ok', ClientPortalService::completeLogin($r['dev_code'])['status']);
    }

    public function testHntL04AssociatesGetAtMostFiveCodes(): void
    {
        $a = $this->associate();
        for ($i = 1; $i <= 5; $i++) {
            $_SESSION = [];
            $r = AssociatePortalService::attemptLogin((string) $a['email'], self::PASSWORD);
            self::assertSame('code_sent', $r['status'], "code {$i}");
        }
        $_SESSION = [];
        self::assertSame('too_many_codes', AssociatePortalService::attemptLogin((string) $a['email'], self::PASSWORD)['status']);
        self::assertNull(AssociatePortalService::pendingId());
        self::assertSame(5, $this->associateCodesIssued((int) $a['id']), 'exactly 5 codes were issued (and e-mailed)');
    }

    public function testHntL04AssociateSuccessfulSignInStartsTheCountAgain(): void
    {
        $a = $this->associate();
        for ($round = 1; $round <= 2; $round++) {
            for ($i = 1; $i <= 4; $i++) {
                $_SESSION = [];
                $r = AssociatePortalService::attemptLogin((string) $a['email'], self::PASSWORD);
                self::assertSame('code_sent', $r['status'], "round {$round}, code {$i}");
            }
            self::assertTrue(AssociatePortalService::verifyCode($r['code']));
        }
    }

    public function testHntL04TheRefusalMessageIsPlain(): void
    {
        self::assertStringContainsString('at most 5 in 15 minutes', TwoFactorService::TOO_MANY_CODES_MESSAGE);
    }
}
