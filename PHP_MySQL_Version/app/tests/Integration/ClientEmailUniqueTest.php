<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Helpers\Flash;
use App\Repositories\ClientRepository;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 5, R5-02 (owner decision 2026-10-28): two clients may not
 * share an email — the client portal logs in by email. Refused on create,
 * duplicate and edit, case- and space-insensitively; a client keeps its
 * own email when edited.
 */
final class ClientEmailUniqueTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = ['_auth_user_id' => $this->createTestUser('Admin')];
        Flash::pull();
    }

    protected function tearDown(): void
    {
        Flash::pull();
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    private function uniqueEmail(): string
    {
        return 'unique-' . bin2hex(random_bytes(4)) . '@example.test';
    }

    private function clientCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    }

    private function post(array $fields): void
    {
        $_POST = $fields + ['billing_address' => '1 Test Street', 'consignee_same_as_buyer' => '1', 'notify_party_same_as_consignee' => '1'];
    }

    public function testCreatingASecondClientWithTheSameEmailIsRefused(): void
    {
        $email = $this->uniqueEmail();
        $this->createTestClient($email);
        $before = $this->clientCount();

        $this->post(['company_legal_name' => 'Second Buyer Ltd', 'email' => '  ' . strtoupper($email) . ' ']);
        (new ClientController())->store([]);

        self::assertSame($before, $this->clientCount());
        self::assertStringContainsString('already uses the email', Flash::pull()[0]['message']);
    }

    public function testADifferentEmailIsAccepted(): void
    {
        $this->createTestClient($this->uniqueEmail());
        $before = $this->clientCount();

        $this->post(['company_legal_name' => 'Third Buyer Ltd', 'email' => $this->uniqueEmail()]);
        (new ClientController())->store([]);

        self::assertSame($before + 1, $this->clientCount());
    }

    public function testEditingAClientOntoAnotherClientsEmailIsRefusedButKeepingItsOwnIsFine(): void
    {
        $takenEmail = $this->uniqueEmail();
        $this->createTestClient($takenEmail);
        $ownEmail = $this->uniqueEmail();
        $clientId = $this->createTestClient($ownEmail);

        $this->post(['company_legal_name' => 'Edited Buyer Ltd', 'email' => $takenEmail]);
        (new ClientController())->update(['id' => (string) $clientId]);
        self::assertSame($ownEmail, ClientRepository::find($clientId)['email']);
        self::assertStringContainsString('already uses the email', Flash::pull()[0]['message']);

        $this->post(['company_legal_name' => 'Edited Buyer Ltd', 'email' => $ownEmail]);
        (new ClientController())->update(['id' => (string) $clientId]);
        self::assertSame('Edited Buyer Ltd', ClientRepository::find($clientId)['company_legal_name']);
    }
}
