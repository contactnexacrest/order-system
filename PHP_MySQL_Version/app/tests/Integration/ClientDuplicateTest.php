<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\ClientController;
use App\Repositories\ClientRepository;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog Phase 6: a "Duplicate" action for a CLIENT — re-onboard
 * a near-identical but genuinely distinct entity (a sister company, a
 * corrected re-entry) under a brand-new Buyer Inquiry Ref
 * (client_unique_number), never the source's own. The source's
 * `duplicate_of_client_id` column already existed in the schema but
 * nothing wrote or read it before this. duplicateStore() refuses the
 * save unless at least one field was actually changed from the source —
 * an exact copy serves no purpose the original record doesn't already.
 */
final class ClientDuplicateTest extends DbTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        $this->userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $this->userId;
    }

    private function createSourceClient(): array
    {
        $pdo = Database::connection();
        $uniqueNumber = 'NC/SC/TEST/' . bin2hex(random_bytes(4));
        $pdo->prepare(
            "INSERT INTO clients (client_unique_number, company_legal_name, billing_address, contact_person, email, phone, country_of_destination, coo_type, consignee_same_as_buyer, notify_party_same_as_consignee, created_by)
             VALUES (:unique_number, 'Acme Exports Ltd', '1 Test Street, Testville', 'Jane Buyer', 'jane@example.test', '+1 555 0000', 'United Kingdom', 'To Be Confirmed', 1, 1, :created_by)"
        )->execute(['unique_number' => $uniqueNumber, 'created_by' => $this->userId]);
        $id = (int) $pdo->lastInsertId();
        return ClientRepository::find($id);
    }

    public function testDuplicateForm404sForAMissingClient(): void
    {
        ob_start();
        (new ClientController())->duplicateForm(['id' => '999999999']);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    public function testDuplicateFormPrefillsFromTheSourceClient(): void
    {
        $source = $this->createSourceClient();

        ob_start();
        (new ClientController())->duplicateForm(['id' => (string) $source['id']]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Acme Exports Ltd', $html);
        self::assertStringContainsString('jane@example.test', $html);
        self::assertStringContainsString('Duplicate Client', $html);
        self::assertStringContainsString("/clients/{$source['id']}/duplicate", $html);
    }

    public function testDuplicateStoreRefusesAnExactCopyWithNoChanges(): void
    {
        $source = $this->createSourceClient();
        $_POST = [
            'company_legal_name' => $source['company_legal_name'],
            'billing_address' => $source['billing_address'],
            'contact_person' => $source['contact_person'],
            'email' => $source['email'],
            'phone' => $source['phone'],
            'country_of_destination' => $source['country_of_destination'],
            'coo_type' => $source['coo_type'],
            'consignee_same_as_buyer' => '1',
            'notify_party_same_as_consignee' => '1',
        ];
        $countBefore = (int) Database::connection()->query('SELECT COUNT(*) FROM clients')->fetchColumn();

        ob_start();
        (new ClientController())->duplicateStore(['id' => (string) $source['id']]);
        ob_end_clean();

        $countAfter = (int) Database::connection()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        self::assertSame($countBefore, $countAfter, 'an exact, unchanged duplicate must never be saved');
    }

    public function testDuplicateStoreSucceedsWhenAtLeastOneFieldDiffers(): void
    {
        $source = $this->createSourceClient();
        $_POST = [
            'company_legal_name' => 'Acme Exports (Europe) Ltd', // the changed field
            'billing_address' => $source['billing_address'],
            'contact_person' => $source['contact_person'],
            // QA Round 5, R5-02: a duplicate needs its own email too.
            'email' => 'europe-' . bin2hex(random_bytes(3)) . '@example.test',
            'phone' => $source['phone'],
            'country_of_destination' => $source['country_of_destination'],
            'coo_type' => $source['coo_type'],
            'consignee_same_as_buyer' => '1',
            'notify_party_same_as_consignee' => '1',
        ];

        ob_start();
        (new ClientController())->duplicateStore(['id' => (string) $source['id']]);
        ob_end_clean();

        $newId = (int) Database::connection()->query('SELECT MAX(id) FROM clients')->fetchColumn();
        $new = ClientRepository::find($newId);

        self::assertSame('Acme Exports (Europe) Ltd', $new['company_legal_name']);
        self::assertSame((int) $source['id'], (int) $new['duplicate_of_client_id']);
        self::assertNotSame($source['client_unique_number'], $new['client_unique_number'], 'the duplicate must get its own, brand-new Buyer Inquiry Ref');
    }

    public function testDuplicateStoreLogsTheAuditTrail(): void
    {
        $source = $this->createSourceClient();
        $_POST = [
            'company_legal_name' => $source['company_legal_name'],
            'billing_address' => 'A genuinely different address, 2 New Road',
            'consignee_same_as_buyer' => '1',
            'notify_party_same_as_consignee' => '1',
        ];

        ob_start();
        (new ClientController())->duplicateStore(['id' => (string) $source['id']]);
        ob_end_clean();

        $newId = (int) Database::connection()->query('SELECT MAX(id) FROM clients')->fetchColumn();
        $stmt = Database::connection()->prepare(
            "SELECT * FROM audit_log WHERE action_type = 'CLIENT_DUPLICATED' AND entity_type = 'clients' AND entity_id = :id"
        );
        $stmt->execute(['id' => $newId]);
        $log = $stmt->fetch();

        self::assertNotFalse($log, 'creating a duplicate must be audit-logged');
        self::assertSame((string) $source['id'], $log['new_value']);
    }

    public function testDuplicateStore404sForAMissingClient(): void
    {
        $_POST = ['company_legal_name' => 'X', 'billing_address' => 'Y'];

        ob_start();
        (new ClientController())->duplicateStore(['id' => '999999999']);
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    public function testDuplicateStoreStillRequiresCompanyNameAndBillingAddress(): void
    {
        $source = $this->createSourceClient();
        $_POST = ['company_legal_name' => '', 'billing_address' => ''];
        $countBefore = (int) Database::connection()->query('SELECT COUNT(*) FROM clients')->fetchColumn();

        ob_start();
        (new ClientController())->duplicateStore(['id' => (string) $source['id']]);
        ob_end_clean();

        $countAfter = (int) Database::connection()->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        self::assertSame($countBefore, $countAfter);
    }

    public function testFindDuplicatesOfReturnsEveryClientDuplicatedFromIt(): void
    {
        $source = $this->createSourceClient();
        $_POST = ['company_legal_name' => 'Acme Exports (Branch 2)', 'billing_address' => $source['billing_address'], 'consignee_same_as_buyer' => '1', 'notify_party_same_as_consignee' => '1'];
        ob_start();
        (new ClientController())->duplicateStore(['id' => (string) $source['id']]);
        ob_end_clean();

        $duplicates = ClientRepository::findDuplicatesOf((int) $source['id']);

        self::assertCount(1, $duplicates);
        self::assertSame('Acme Exports (Branch 2)', $duplicates[0]['company_legal_name']);
    }

    public function testShowPageLinksBothDirectionsOfTheDuplicateRelationship(): void
    {
        $source = $this->createSourceClient();
        $_POST = ['company_legal_name' => 'Acme Exports (Branch 3)', 'billing_address' => $source['billing_address'], 'consignee_same_as_buyer' => '1', 'notify_party_same_as_consignee' => '1'];
        ob_start();
        (new ClientController())->duplicateStore(['id' => (string) $source['id']]);
        ob_end_clean();
        $newId = (int) Database::connection()->query('SELECT MAX(id) FROM clients')->fetchColumn();

        ob_start();
        (new ClientController())->show(['id' => (string) $source['id']]);
        $sourceHtml = (string) ob_get_clean();
        self::assertStringContainsString('Duplicated into', $sourceHtml);
        self::assertStringContainsString('Acme Exports (Branch 3)', $sourceHtml);

        ob_start();
        (new ClientController())->show(['id' => (string) $newId]);
        $newHtml = (string) ob_get_clean();
        self::assertStringContainsString('Duplicated from', $newHtml);
        self::assertStringContainsString('Acme Exports Ltd', $newHtml);
    }
}
