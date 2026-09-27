<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Services\ClientPortalService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 (CP-08 — external QA report cross-verification): clients.email has
 * no uniqueness constraint, so two different client records can end up
 * sharing the same address (a data-entry mistake, or an edit). Before this
 * fix, ClientLoginRepository::findByEmail() picked one of the matches with
 * LIMIT 1 — an arbitrary tie-break that would silently authenticate
 * whoever logs in with that email as whichever client won the tie,
 * regardless of which client's own password was actually supplied.
 */
final class ClientLoginEmailAmbiguityTest extends DbTestCase
{
    private const PASSWORD_A = 'Client-A-Password-1!';
    private const PASSWORD_B = 'Client-B-Password-2!';

    protected function setUp(): void
    {
        parent::setUp();
        // A successful login calls session_regenerate_id(), which PHP warns
        // about without an actual active session — CLI PHPUnit never
        // starts one on its own.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    public function testASharedEmailRefusesLoginForEitherClientsPassword(): void
    {
        $sharedEmail = 'shared-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientA = $this->createClientWithPortalLogin($sharedEmail, self::PASSWORD_A);
        $clientB = $this->createClientWithPortalLogin($sharedEmail, self::PASSWORD_B);

        $resultA = ClientPortalService::attemptLogin($sharedEmail, self::PASSWORD_A);
        $resultB = ClientPortalService::attemptLogin($sharedEmail, self::PASSWORD_B);

        self::assertSame('invalid_credentials', $resultA['status'], "client A's own correct password must not authenticate while the email is ambiguous");
        self::assertSame('invalid_credentials', $resultB['status'], "client B's own correct password must not authenticate while the email is ambiguous");
    }

    public function testOnceTheEmailIsMadeUniqueThatClientCanLogInNormallyAgain(): void
    {
        $sharedEmail = 'shared-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientA = $this->createClientWithPortalLogin($sharedEmail, self::PASSWORD_A);
        $clientB = $this->createClientWithPortalLogin($sharedEmail, self::PASSWORD_B);

        // Staff resolves the duplicate by giving B a distinct email.
        $distinctEmail = 'distinct-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        Database::connection()
            ->prepare('UPDATE clients SET email = :email WHERE id = :id')
            ->execute(['email' => $distinctEmail, 'id' => $clientB]);

        $result = ClientPortalService::attemptLogin($distinctEmail, self::PASSWORD_B);

        self::assertSame('ok', $result['status'], 'once no longer ambiguous, the client can log in normally again');
    }

    private function createClientWithPortalLogin(string $email, string $password): int
    {
        $clientId = $this->createTestClient();
        $pdo = Database::connection();
        $pdo->prepare('UPDATE clients SET email = :email WHERE id = :id')
            ->execute(['email' => $email, 'id' => $clientId]);
        $pdo->prepare('INSERT INTO client_logins (client_id, password_hash, force_password_change) VALUES (:cid, :hash, 0)')
            ->execute(['cid' => $clientId, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        return $clientId;
    }
}
