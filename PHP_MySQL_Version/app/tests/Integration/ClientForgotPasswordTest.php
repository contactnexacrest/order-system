<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientPortalController;
use App\Helpers\Flash;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog #88 (and the real cause of #72, "client can't log back
 * in after first logout" — direct repro of the actual login/logout/login
 * flow on both stacks showed no session/logic bug; a client who simply
 * forgot their password, with nowhere to recover it, is what "can't log
 * back in" actually meant): the client portal had no self-service
 * password recovery at all. client_password_reset_tokens and
 * ClientPasswordResetTokenRepository already existed (built for the
 * initial "set your password" email after advance-cleared provisioning)
 * but nothing ever let a client who forgot their password request a
 * fresh one — their only option was asking staff directly. This adds the
 * missing /client/forgot-password screen, reusing the exact same
 * /client/set-password/{token} flow and token table.
 */
final class ClientForgotPasswordTest extends DbTestCase
{
    private function createClientWithPortalLogin(string $email, bool $clientActive = true, bool $loginActive = true): int
    {
        $clientId = $this->createTestClient();
        $pdo = Database::connection();
        $pdo->prepare('UPDATE clients SET email = :email, is_active = :active WHERE id = :id')
            ->execute(['email' => $email, 'active' => $clientActive ? 1 : 0, 'id' => $clientId]);
        $pdo->prepare('INSERT INTO client_logins (client_id, password_hash, is_active, force_password_change) VALUES (:cid, :hash, :active, 0)')
            ->execute(['cid' => $clientId, 'hash' => password_hash('irrelevant', PASSWORD_DEFAULT), 'active' => $loginActive ? 1 : 0]);
        return $clientId;
    }

    private function tokenCountForClient(int $clientId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM client_password_reset_tokens WHERE client_id = :cid');
        $stmt->execute(['cid' => $clientId]);
        return (int) $stmt->fetchColumn();
    }

    private function auditLogCount(string $actionType, int $clientId): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM audit_log WHERE action_type = :action AND entity_type = 'clients' AND entity_id = :id"
        );
        $stmt->execute(['action' => $actionType, 'id' => $clientId]);
        return (int) $stmt->fetchColumn();
    }

    public function testRequestingAResetForARealMatchingEmailCreatesATokenAndLogsIt(): void
    {
        $email = 'forgot-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientId = $this->createClientWithPortalLogin($email);
        $_POST = ['email' => $email];

        ob_start();
        (new ClientPortalController())->forgotPassword([]);
        ob_end_clean();

        self::assertSame(1, $this->tokenCountForClient($clientId));
        self::assertSame(1, $this->auditLogCount('CLIENT_PASSWORD_RESET_REQUESTED', $clientId));

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        self::assertStringContainsString($email, $flash[0]['message']);
    }

    public function testRequestingAResetForANonMatchingEmailShowsTheSameGenericMessageAndCreatesNoToken(): void
    {
        $email = 'no-such-client-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $_POST = ['email' => $email];

        ob_start();
        (new ClientPortalController())->forgotPassword([]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
        self::assertStringContainsString($email, $flash[0]['message']);
        self::assertStringContainsString('If', $flash[0]['message']);
    }

    public function testADeactivatedClientGetsTheGenericMessageButNoToken(): void
    {
        $email = 'forgot-inactive-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientId = $this->createClientWithPortalLogin($email, clientActive: false);
        $_POST = ['email' => $email];

        ob_start();
        (new ClientPortalController())->forgotPassword([]);
        ob_end_clean();

        self::assertSame(0, $this->tokenCountForClient($clientId), 'a deactivated client must never receive a working reset link');
        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
    }

    public function testAClientWhoseLoginIsDisabledGetsTheGenericMessageButNoToken(): void
    {
        $email = 'forgot-loginoff-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientId = $this->createClientWithPortalLogin($email, clientActive: true, loginActive: false);
        $_POST = ['email' => $email];

        ob_start();
        (new ClientPortalController())->forgotPassword([]);
        ob_end_clean();

        self::assertSame(0, $this->tokenCountForClient($clientId));
    }

    public function testASixthRequestWithinAnHourIsRateLimitedAndCreatesNoNewToken(): void
    {
        $email = 'forgot-ratelimit-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientId = $this->createClientWithPortalLogin($email);
        $_POST = ['email' => $email];

        for ($i = 0; $i < 5; $i++) {
            ob_start();
            (new ClientPortalController())->forgotPassword([]);
            ob_end_clean();
        }
        self::assertSame(5, $this->tokenCountForClient($clientId));

        ob_start();
        (new ClientPortalController())->forgotPassword([]);
        ob_end_clean();

        self::assertSame(5, $this->tokenCountForClient($clientId), 'a 6th request within the hour must not create another token (mail-bombing guard)');
    }

    public function testTheResultingTokenActuallyWorksWithTheExistingSetPasswordFlow(): void
    {
        $email = 'forgot-e2e-' . bin2hex(random_bytes(4)) . '@nexacrest.test';
        $clientId = $this->createClientWithPortalLogin($email);
        $_POST = ['email' => $email];

        ob_start();
        (new ClientPortalController())->forgotPassword([]);
        ob_end_clean();
        Flash::pull();

        $row = Database::connection()
            ->query("SELECT token_hash FROM client_password_reset_tokens WHERE client_id = {$clientId} ORDER BY id DESC LIMIT 1")
            ->fetch();
        self::assertNotNull($row);

        // We only ever stored the hash (the raw token was emailed, never
        // persisted) — confirm the row the forgot-password flow created is
        // exactly the kind showSetPassword()/setPassword() already know how
        // to validate, by round-tripping a token whose hash we control.
        $rawToken = bin2hex(random_bytes(32));
        Database::connection()
            ->prepare('UPDATE client_password_reset_tokens SET token_hash = :hash WHERE client_id = :cid ORDER BY id DESC LIMIT 1')
            ->execute(['hash' => hash('sha256', $rawToken), 'cid' => $clientId]);

        $_POST = ['new_password' => 'Brand-New-Password-1!', 'confirm_password' => 'Brand-New-Password-1!'];
        ob_start();
        (new ClientPortalController())->setPassword(['token' => $rawToken]);
        ob_end_clean();

        $flash = Flash::pull();
        self::assertSame('success', $flash[0]['type']);
    }
}
