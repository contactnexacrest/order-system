<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\ClientRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\ClientPortalService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (AUTH-04/AUTH-05/CP-07 — external QA report cross-verification):
 * login used to check is_active/locked_until BEFORE verifying the
 * password, so an attacker submitting a wrong password for a guessed
 * email could learn — with zero knowledge of the real password — whether
 * that email belongs to a disabled account, a locked account, or no
 * account at all, purely from which status came back. The fix checks the
 * password first: a wrong password always returns the same generic
 * 'invalid_credentials', whatever the account's real state; only a
 * CORRECT password ever reveals 'account_disabled' or 'locked_out'.
 */
final class AccountEnumerationGuardTest extends DbTestCase
{
    private const KNOWN_PASSWORD = 'CorrectHorseBatteryStaple42!';

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testWrongPasswordOnADisabledStaffAccountLooksIdenticalToInvalidCredentials(): void
    {
        $userId = $this->createTestUserWithKnownPassword('Export Executive');
        UserRepository::setActive($userId, false);

        $result = AuthService::attemptLogin($this->emailFor($userId), 'definitely-the-wrong-password');

        self::assertSame('invalid_credentials', $result['status'], 'a wrong password must never reveal that the account is disabled');
    }

    public function testWrongPasswordOnALockedStaffAccountLooksIdenticalToInvalidCredentials(): void
    {
        $userId = $this->createTestUserWithKnownPassword('Export Executive');
        UserRepository::lockUntil($userId, date('Y-m-d H:i:s', time() + 900));

        $result = AuthService::attemptLogin($this->emailFor($userId), 'definitely-the-wrong-password');

        self::assertSame('invalid_credentials', $result['status'], 'a wrong password must never reveal that the account is locked');
    }

    public function testCorrectPasswordOnADisabledStaffAccountStillRevealsDisabled(): void
    {
        $userId = $this->createTestUserWithKnownPassword('Export Executive');
        UserRepository::setActive($userId, false);

        $result = AuthService::attemptLogin($this->emailFor($userId), self::KNOWN_PASSWORD);

        self::assertSame('account_disabled', $result['status'], 'the legitimate holder must still be told once they prove they know the password');
    }

    public function testCorrectPasswordOnALockedStaffAccountStillRevealsLockedOut(): void
    {
        $userId = $this->createTestUserWithKnownPassword('Export Executive');
        UserRepository::lockUntil($userId, date('Y-m-d H:i:s', time() + 900));

        $result = AuthService::attemptLogin($this->emailFor($userId), self::KNOWN_PASSWORD);

        self::assertSame('locked_out', $result['status']);
    }

    public function testAnUnknownEmailAndAWrongPasswordOnARealDisabledAccountReturnTheSameStatus(): void
    {
        $userId = $this->createTestUserWithKnownPassword('Export Executive');
        UserRepository::setActive($userId, false);

        $unknownEmailResult = AuthService::attemptLogin('definitely-not-a-real-user@nexacrest.test', 'anything');
        $realDisabledResult = AuthService::attemptLogin($this->emailFor($userId), 'anything');

        self::assertSame($unknownEmailResult['status'], $realDisabledResult['status']);
        self::assertSame('invalid_credentials', $unknownEmailResult['status']);
    }

    public function testWrongPasswordOnADeactivatedClientPortalLoginLooksIdenticalToInvalidCredentials(): void
    {
        $clientId = $this->createTestClientWithKnownPortalPassword();
        ClientRepository::setActive($clientId, false);

        $result = ClientPortalService::attemptLogin($this->clientEmailFor($clientId), 'definitely-the-wrong-password');

        self::assertSame('invalid_credentials', $result['status'], 'a wrong password must never reveal that the client is disabled');
    }

    public function testCorrectPasswordOnADeactivatedClientPortalLoginStillRevealsDisabled(): void
    {
        $clientId = $this->createTestClientWithKnownPortalPassword();
        ClientRepository::setActive($clientId, false);

        $result = ClientPortalService::attemptLogin($this->clientEmailFor($clientId), self::KNOWN_PASSWORD);

        self::assertSame('account_disabled', $result['status']);
    }

    private function createTestUserWithKnownPassword(string $roleName): int
    {
        $userId = $this->createTestUser($roleName);
        UserRepository::updatePassword($userId, password_hash(self::KNOWN_PASSWORD, PASSWORD_DEFAULT), false);
        return $userId;
    }

    private function emailFor(int $userId): string
    {
        return (string) UserRepository::findById($userId)['email'];
    }

    private function createTestClientWithKnownPortalPassword(): int
    {
        $clientId = $this->createTestClient();
        $email = 'phpunit-client-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        Database::connection()
            ->prepare('UPDATE clients SET email = :email WHERE id = :id')
            ->execute(['email' => $email, 'id' => $clientId]);
        Database::connection()
            ->prepare('INSERT INTO client_logins (client_id, password_hash) VALUES (:cid, :hash)')
            ->execute(['cid' => $clientId, 'hash' => password_hash(self::KNOWN_PASSWORD, PASSWORD_DEFAULT)]);
        return $clientId;
    }

    private function clientEmailFor(int $clientId): string
    {
        $stmt = Database::connection()->prepare('SELECT email FROM clients WHERE id = :id');
        $stmt->execute(['id' => $clientId]);
        return (string) $stmt->fetchColumn();
    }
}
