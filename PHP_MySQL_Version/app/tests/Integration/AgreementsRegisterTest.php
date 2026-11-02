<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AgreementController;
use App\Repositories\AgreementRepository;
use App\Services\AgreementAlertService;
use App\Services\DocumentDataAssembler;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2 Nov 2026 (point 4): the Agreements register — clients, suppliers, logistics
 * partners, associates and staff; status; reminders 30 / 7 days before expiry and on the day, once
 * each; staff agreements only for manage_agreements; the client and associate agreements kept on
 * their own pages are listed too, and the client footer on documents is unchanged.
 * Node twin: agreementsRegister.test.js.
 */
final class AgreementsRegisterTest extends DbTestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function supplier(string $name = 'Register Test Quarry'): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO suppliers (supplier_legal_name, is_active) VALUES (:n, 1)')
            ->execute(['n' => $name . ' ' . bin2hex(random_bytes(3))]);
        return (int) $pdo->lastInsertId();
    }

    private function agreement(string $type, int $partyId, ?string $expires, string $agreementType = 'Supply Agreement (Quarry/Factory)', string $signed = '2026-01-10'): int
    {
        return AgreementRepository::create([
            'party_type' => $type, 'party_id' => $partyId, 'agreement_type' => $agreementType, 'signed_on' => $signed,
            'expires_on' => $expires, 'file_id' => $this->createTestFile(),
        ], $this->createTestUser('Admin'));
    }

    private function asUser(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        \App\Services\PermissionService::resetCache();
    }

    private function render(string $action, array $get = [], array $params = []): string
    {
        $_GET = $get;
        ob_start();
        (new AgreementController())->{$action}($params);
        return (string) ob_get_clean();
    }

    public function testStatusFollowsTheDates(): void
    {
        $today = '2026-11-02';
        self::assertSame('active', AgreementRepository::statusOf(['expires_on' => '2027-01-01'], $today));
        self::assertSame('expiring', AgreementRepository::statusOf(['expires_on' => '2026-12-02'], $today), 'exactly 30 days is "expiring soon"');
        self::assertSame('expiring', AgreementRepository::statusOf(['expires_on' => '2026-11-02'], $today), 'the expiry day itself');
        self::assertSame('expired', AgreementRepository::statusOf(['expires_on' => '2026-11-01'], $today));
        self::assertSame('no_expiry', AgreementRepository::statusOf(['expires_on' => null], $today));
        self::assertSame('terminated', AgreementRepository::statusOf(['expires_on' => '2027-01-01', 'terminated_on' => '2026-10-01'], $today));
        self::assertSame('renewed', AgreementRepository::statusOf(['expires_on' => '2026-11-10', 'renewed' => 1], $today));
    }

    public function testAddEditRenewAndTerminate(): void
    {
        $supplierId = $this->supplier();
        $id = $this->agreement('supplier', $supplierId, '2026-11-20');
        $row = AgreementRepository::forParty('supplier', $supplierId, '2026-11-02')[0];
        self::assertSame('expiring', $row['status']);
        self::assertSame('register', $row['source']);

        // Edit (through the controller, no new file needed).
        $this->asUser($this->createTestUser('Admin'));
        $_POST = ['agreement_type' => 'Supply Agreement (Quarry/Factory)', 'signed_on' => '2026-01-10', 'expires_on' => '2027-06-30', 'title' => 'Granite 2026–2027'];
        $this->render('save', [], ['id' => (string) $id]);
        self::assertSame('2027-06-30', AgreementRepository::find($id)['expires_on']);
        self::assertSame('Granite 2026–2027', AgreementRepository::find($id)['title']);

        // Renew: a new row linked to the old one; the old one shows "Renewed (replaced)".
        $newId = AgreementRepository::create(['party_type' => 'supplier', 'party_id' => $supplierId, 'agreement_type' => 'Supply Agreement (Quarry/Factory)', 'signed_on' => '2026-10-30', 'expires_on' => '2028-06-30', 'renews_agreement_id' => $id], $this->createTestUser('Admin'));
        $byId = array_column(AgreementRepository::forParty('supplier', $supplierId), null, 'id');
        self::assertSame('renewed', $byId[$id]['status']);
        self::assertSame('active', $byId[$newId]['status']);

        // Terminate.
        $_POST = ['terminated_on' => '2026-11-02', 'terminated_reason' => 'Supplier closed the quarry'];
        $this->render('terminate', [], ['id' => (string) $newId]);
        self::assertSame('2026-11-02', AgreementRepository::find($newId)['terminated_on']);
        self::assertSame('terminated', array_column(AgreementRepository::forParty('supplier', $supplierId), null, 'id')[$newId]['status']);
    }

    public function testTheFormIsCheckedOnTheServer(): void
    {
        [, $errors] = AgreementController::validate(['party_type' => 'supplier', 'party_id_supplier' => '999999999', 'agreement_type' => 'Nonsense', 'signed_on' => '2099-01-01', 'expires_on' => '2098-01-01'], true);
        $text = implode(' ', $errors);
        self::assertStringContainsString('Choose the supplier from the list.', $text);
        self::assertStringContainsString('Choose the type of agreement.', $text);
        self::assertStringContainsString('The signing date cannot be in the future.', $text);
        self::assertStringContainsString('The agreement must expire after it starts', $text);

        $supplierId = $this->supplier();
        [$data, $errors] = AgreementController::validate(['party_type' => 'supplier', 'party_id_supplier' => (string) $supplierId, 'agreement_type' => 'Commercial NDA', 'signed_on' => '2026-10-01', 'expires_on' => ''], true);
        self::assertSame([], $errors);
        self::assertNull($data['expires_on'], 'an empty expiry date means no expiry');

        // A new agreement needs its signed copy.
        $this->asUser($this->createTestUser('Admin'));
        $_POST = ['party_type' => 'supplier', 'party_id_supplier' => (string) $supplierId, 'agreement_type' => 'Commercial NDA', 'signed_on' => '2026-10-01'];
        $this->render('save');
        self::assertSame([], AgreementRepository::forParty('supplier', $supplierId));
        self::assertStringContainsString('Attach the signed copy', (string) ($_SESSION['_flash']['error'] ?? json_encode($_SESSION)));
    }

    public function testRemindersGoOutAt30And7DaysAndOnTheDayOnceEach(): void
    {
        $supplierId = $this->supplier('Reminder Quarry');
        $this->agreement('supplier', $supplierId, '2026-12-02');
        $count = static fn (): int => (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE type = 'agreement_expiry' AND message LIKE '%Reminder Quarry%'")->fetchColumn();

        AgreementAlertService::run('2026-11-01'); // 31 days left — nothing yet
        self::assertSame(0, $count());
        AgreementAlertService::run('2026-11-02'); // 30 days
        $after30 = $count();
        self::assertGreaterThan(0, $after30);
        AgreementAlertService::run('2026-11-02'); // re-run the same day
        AgreementAlertService::run('2026-11-10'); // still within 30, above 7
        self::assertSame($after30, $count(), 'the 30-day reminder is sent once');
        AgreementAlertService::run('2026-11-25'); // 7 days
        self::assertSame(2 * $after30, $count());
        AgreementAlertService::run('2026-12-02'); // the day
        self::assertSame(3 * $after30, $count());
        $last = (string) Database::connection()->query("SELECT message FROM notifications WHERE type = 'agreement_expiry' AND message LIKE '%Reminder Quarry%' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringContainsString('expires TODAY (2026-12-02)', $last);
        AgreementAlertService::run('2026-12-03'); // expired — no more
        self::assertSame(3 * $after30, $count());
    }

    public function testATerminatedAgreementGetsNoReminder(): void
    {
        $supplierId = $this->supplier('Ended Quarry');
        $id = $this->agreement('supplier', $supplierId, '2026-11-09');
        AgreementRepository::terminate($id, '2026-10-01', 'Ended early by agreement');
        AgreementAlertService::run('2026-11-02');
        self::assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE type = 'agreement_expiry' AND message LIKE '%Ended Quarry%'")->fetchColumn());
    }

    public function testStaffAgreementsOnlyForManageAgreements(): void
    {
        $staffId = $this->createTestUser('Export Executive');
        $staffName = (string) Database::connection()->query('SELECT name FROM users WHERE id = ' . $staffId)->fetchColumn();
        Database::connection()->prepare('UPDATE users SET name = :n WHERE id = :id')->execute(['n' => 'Staff NDA Person ' . $staffId, 'id' => $staffId]);
        $id = $this->agreement('staff', $staffId, null, 'Staff NDA');

        $this->asUser($this->createTestUser('Managing Director'));
        $md = $this->render('index', ['view' => 'list']);
        self::assertStringContainsString('Staff NDA Person ' . $staffId, $md);

        $this->asUser($this->createTestUser('Finance Director')); // view_agreements, not manage
        $viewer = $this->render('index', ['view' => 'list']);
        self::assertStringContainsString('Agreements', $viewer);
        self::assertStringNotContainsString('Staff NDA Person ' . $staffId, $viewer);
        self::assertStringNotContainsString('/agreements/new', $viewer, 'a viewer cannot add');
        ob_start();
        (new AgreementController())->download(['id' => (string) $id]);
        $out = (string) ob_get_clean();
        self::assertSame(403, http_response_code());
        self::assertStringContainsString('You do not have permission', $out);
        http_response_code(200);
        unset($staffName);
    }

    public function testClientAndAssociateAgreementsAreListedAndTheFooterIsUnchanged(): void
    {
        $clientId = $this->createTestClient();
        Database::connection()->prepare(
            "UPDATE clients SET company_legal_name = 'Footer Client Ltd', agreement_file_path = '/tmp/x.pdf', agreement_file_original_name = 'signed.pdf',
                    agreement_uploaded_at = '2026-01-05 10:00:00', agreement_expiry_date = '2026-11-20', agreement_force_expired = 0,
                    agreement_footer_text = 'Subject to the Sales & Supply Agreement dated 5 Jan 2026' WHERE id = :id"
        )->execute(['id' => $clientId]);
        $assocId = \App\Repositories\AssociateRepository::create(['name' => 'Register Associate', 'based_in' => 'india', 'currency_code' => 'INR', 'status' => 'active'], $this->createTestUser('Admin'));
        Database::connection()->prepare("UPDATE associates SET agreement_file_id = :f, agreement_signed_on = '2026-02-01', agreement_ends_on = '2027-02-01' WHERE id = :id")
            ->execute(['f' => $this->createTestFile(), 'id' => $assocId]);

        $client = AgreementRepository::forParty('client', $clientId, '2026-11-02');
        self::assertCount(1, $client);
        self::assertSame('client', $client[0]['source']);
        self::assertSame('expiring', $client[0]['status']);
        $assoc = AgreementRepository::forParty('associate', $assocId, '2026-11-02');
        self::assertSame('Associate Agreement', $assoc[0]['agreement_type']);
        self::assertSame('active', $assoc[0]['status']);

        // The client footer on documents still comes from the client record, as before.
        $orderId = $this->createTestOrder($clientId);
        $data = DocumentDataAssembler::assemble($orderId, 'QT');
        self::assertStringContainsString('Subject to the Sales & Supply Agreement dated 5 Jan 2026', (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // The client agreement now gets reminders too.
        AgreementAlertService::run('2026-11-13'); // 7 days
        self::assertGreaterThan(0, (int) Database::connection()->query("SELECT COUNT(*) FROM notifications WHERE type = 'agreement_expiry' AND message LIKE '%Footer Client Ltd%'")->fetchColumn());
    }

    public function testFiltersAndThePartyCard(): void
    {
        $supplierId = $this->supplier('Filter Quarry');
        $this->agreement('supplier', $supplierId, date('Y-m-d', strtotime('+10 days')));
        $this->agreement('supplier', $supplierId, date('Y-m-d', strtotime('+200 days')), 'Commercial NDA');
        $this->asUser($this->createTestUser('Admin'));
        $within = $this->render('index', ['view' => 'list', 'within' => '30', 'q' => 'Filter Quarry']);
        self::assertSame(1, substr_count($within, 'Supply Agreement (Quarry/Factory)</td>') + substr_count($within, "Supply Agreement (Quarry/Factory)<br>"));
        self::assertStringNotContainsString('Commercial NDA<', preg_replace('#<select.*?</select>#s', '', $within) ?? $within);
        $nda = $this->render('index', ['view' => 'list', 'type' => 'Commercial NDA', 'q' => 'Filter Quarry']);
        self::assertStringContainsString('Commercial NDA', preg_replace('#<select.*?</select>#s', '', $nda) ?? $nda);

        // The supplier page card lists both, with "Add agreement".
        $__partyType = 'supplier';
        $__partyId = $supplierId;
        ob_start();
        require dirname(__DIR__, 2) . '/views/agreements/_party_card.php';
        $card = (string) ob_get_clean();
        self::assertStringContainsString('Add agreement', $card);
        self::assertSame(2, substr_count($card, '<tr>') - 1);
    }
}
