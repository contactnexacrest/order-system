<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\FileStoreController;
use App\Controllers\IncentiveController;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\AssociateRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderRepository;
use App\Services\IncentiveService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 2026-10-30 (incentive follow-ups 10 and 11): the signed
 * associate agreement is kept in the app with its date, and Step 4d records
 * the day the buyer's PO arrived — the agreement's Day 1, so the 10-day rule
 * counts from it. The Node suite has the same test
 * (associateAgreementAndPoDate.test.js).
 */
final class AssociateAgreementAndPoDateTest extends DbTestCase
{
    private int $admin;
    private ?string $mailBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailBefore = CompanySettingsRepository::get('mail_sending_enabled');
        $this->admin = $this->createTestUser('Admin');
        $_SESSION = ['_auth_user_id' => $this->admin];
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', (string) ($this->mailBefore ?? '0'), null);
        $_SESSION = [];
        $_POST = [];
        $_FILES = [];
        parent::tearDown();
    }

    private function associate(array $overrides = []): array
    {
        return $overrides + ['name' => 'PHPUnit Associate', 'based_in' => 'india', 'country' => '', 'currency_code' => 'INR', 'email' => '', 'phone' => '',
            'tax_id' => '', 'agreement_signed_on' => '', 'rate_1_5' => '0.70', 'rate_6_10' => '1.00', 'rate_11_20' => '1.50', 'rate_21_40' => '1.75',
            'rate_41_plus' => '2.00', 'status' => 'active', 'notes' => ''];
    }

    private function associateCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM associates WHERE name = 'PHPUnit Associate'")->fetchColumn();
    }

    public function testStep4dRecordsTheDayTheBuyersPoArrived(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $pdo->prepare("UPDATE order_stages os JOIN stages_master sm ON sm.id = os.stage_id SET os.status = 'in_progress', os.unlocked_at = NOW() WHERE os.order_id = :o AND sm.stage_number = 2")
            ->execute(['o' => $orderId]);
        $pdo->prepare('INSERT INTO order_buyer_po_documents (order_id, file_id) VALUES (:o, :f)')->execute(['o' => $orderId, 'f' => $this->createTestFile($orderId)]);
        CompanySettingsRepository::set('mail_sending_enabled', '0', null);
        $this->createTestDocument($orderId, 'BUYERPO', 'approved');
        $created = substr((string) OrderRepository::find($orderId)['created_at'], 0, 10);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        self::assertMatchesRegularExpression('#<input type="date" name="buyer_po_received_on" required min="' . $created . '" max="' . date('Y-m-d') . '"#', $html);

        foreach (['' => 'no date', date('Y-m-d', strtotime('+1 day')) => 'a future date', date('Y-m-d', strtotime($created . ' -1 day')) => 'before the order'] as $date => $why) {
            $_POST = ['buyers_po_ref' => 'PO-77', 'buyer_po_received_on' => $date];
            (new OrderController())->recordBuyerPo(['id' => (string) $orderId]);
            self::assertStringContainsString("Enter the date the buyer's PO was received", Flash::pull()[0]['message'], "{$why} is refused");
            self::assertNull(OrderRepository::find($orderId)['buyer_po_received_on']);
        }

        $_POST = ['buyers_po_ref' => 'PO-77', 'buyer_po_received_on' => $created];
        (new OrderController())->recordBuyerPo(['id' => (string) $orderId]);
        self::assertSame('success', ($f = Flash::pull()[0])['type'], $f['message']);
        self::assertSame(['PO-77', $created], [OrderRepository::find($orderId)['buyers_po_ref'], OrderRepository::find($orderId)['buyer_po_received_on']]);

        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        self::assertStringContainsString('&middot; received on ' . \App\Helpers\Dates::short($created), (string) ob_get_clean());

        OrderRepository::setBuyersPoRef($orderId, 'PO-77A');
        self::assertSame($created, OrderRepository::find($orderId)['buyer_po_received_on'], 'changing only the reference keeps the date');

        // Correcting it afterwards (owner decision 2026-10-30): with a reason, audit-logged.
        Database::connection()->prepare('UPDATE orders SET created_at = DATE_SUB(created_at, INTERVAL 5 DAY) WHERE id = :o')->execute(['o' => $orderId]);
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        self::assertStringContainsString('action="/orders/' . $orderId . '/buyer-po/received-date"', (string) ob_get_clean());
        $earlier = date('Y-m-d', strtotime('-3 days'));
        $_POST = ['buyer_po_received_on' => $earlier, 'reason' => 'short'];
        (new OrderController())->correctBuyerPoReceivedDate(['id' => (string) $orderId]);
        self::assertSame('error', Flash::pull()[0]['type'], 'a reason of at least 10 characters is needed');
        $_POST = ['buyer_po_received_on' => date('Y-m-d', strtotime('-9 days')), 'reason' => 'The PO email arrived earlier than recorded.'];
        (new OrderController())->correctBuyerPoReceivedDate(['id' => (string) $orderId]);
        self::assertSame('error', Flash::pull()[0]['type'], 'not before the order was created');
        $_POST = ['buyer_po_received_on' => $earlier, 'reason' => 'The PO email arrived earlier than recorded.'];
        (new OrderController())->correctBuyerPoReceivedDate(['id' => (string) $orderId]);
        self::assertSame('success', Flash::pull()[0]['type']);
        self::assertSame($earlier, OrderRepository::find($orderId)['buyer_po_received_on']);
        self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'BUYER_PO_RECEIVED_DATE_CORRECTED' AND entity_id = {$orderId}")->fetchColumn());
    }

    public function testTheTenDaysCountFromTheClientsFirstPoReceived(): void
    {
        $pdo = Database::connection();
        $client = $this->createTestClient();
        $first = $this->createTestOrder($client);
        $second = $this->createTestOrder($spare = $this->createTestClient());
        $pdo->prepare('UPDATE orders SET client_id = :c, sequence_no = 2 WHERE id = :o')->execute(['c' => $client, 'o' => $second]);
        $set = static function (int $orderId, string $created, ?string $po) use ($pdo): void {
            $pdo->prepare('UPDATE orders SET created_at = :c, buyer_po_received_on = :p WHERE id = :o')->execute(['c' => $created . ' 10:00:00', 'p' => $po, 'o' => $orderId]);
        };
        $within = static fn (int $orderId): bool => IncentiveService::withinFirstTenDays(OrderRepository::find($orderId));

        // The illustration in clause 4.6: first PO received 4 March (Day 1), a later one on 13 March (Day 10).
        $set($first, '2026-02-25', '2026-03-04');
        $set($second, '2026-03-12', '2026-03-13');
        self::assertTrue($within($second), 'Day 10 from the first PO received is the same order, though 15 days after the first order was created');
        $set($second, '2026-03-12', '2026-03-14');
        self::assertFalse($within($second), 'Day 11 is a repeat order');

        $set($second, '2026-03-10', null);
        self::assertTrue($within($second), 'an order with no PO date recorded counts from its order date');
        $set($first, '2026-02-25', null);
        self::assertFalse($within($second), 'and so does the first order: 25 February to 10 March is past the 10 days');
    }

    public function testTheSignedAgreementGoesWithItsSigningDate(): void
    {
        $before = $this->associateCount();
        $_POST = $this->associate(['agreement_signed_on' => '2026-10-01']);
        (new IncentiveController())->saveAssociate([]);
        self::assertSame('error', Flash::pull()[0]['type'], 'a signing date needs the signed copy');
        self::assertSame($before, $this->associateCount());

        $tmp = tempnam(sys_get_temp_dir(), 'agreement-');
        file_put_contents($tmp, '%PDF-1.4 signed agreement');
        $_FILES['agreement_file'] = ['name' => 'agreement.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
        try {
            $_POST = $this->associate();
            (new IncentiveController())->saveAssociate([]);
            self::assertStringContainsString('date the agreement was signed', Flash::pull()[0]['message'], 'a signed copy needs its signing date');

            // With both, the upload reaches storage: move_uploaded_file() cannot succeed outside a real
            // HTTP upload, so getting that far (the associate_agreement context resolves) is the proof.
            $_POST = $this->associate(['agreement_signed_on' => '2026-10-01']);
            (new IncentiveController())->saveAssociate([]);
            self::assertStringContainsString('Failed to move the uploaded file', Flash::pull()[0]['message']);
            self::assertSame($before, $this->associateCount(), 'nothing is saved when the file cannot be stored');
        } finally {
            @unlink($tmp);
        }

        $_FILES = [];
        $_POST = $this->associate();
        (new IncentiveController())->saveAssociate([]);
        self::assertSame('success', Flash::pull()[0]['type'], 'an associate without an agreement yet can still be added');
        ob_start();
        (new IncentiveController())->associates([]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('<input type="file" name="agreement_file" accept=".pdf,application/pdf" data-required-if="agreement_signed_on"', $html);
    }

    public function testOnlyThoseWhoManageIncentivesCanDownloadTheSignedAgreement(): void
    {
        $id = AssociateRepository::create(array_map(static fn ($v) => $v === '' ? null : $v, $this->associate(['agreement_signed_on' => '2026-10-01'])), $this->admin);
        $fileId = $this->createTestFile();
        $path = (string) Database::connection()->query('SELECT server_path FROM file_store WHERE id = ' . $fileId)->fetchColumn();
        file_put_contents($path, '%PDF-1.4 signed agreement');
        AssociateRepository::setAgreementFile($id, $fileId);
        try {
            ob_start();
            (new IncentiveController())->downloadAgreement(['id' => (string) $id]);
            self::assertSame('%PDF-1.4 signed agreement', (string) ob_get_clean());
            self::assertSame(1, (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = 'ASSOCIATE_AGREEMENT_DOWNLOADED' AND entity_id = {$id}")->fetchColumn(), 'every download is logged');

            ob_start();
            (new IncentiveController())->associates([]);
            self::assertStringContainsString('<a href="/associates/' . $id . '/agreement" class="small">Signed copy</a>', (string) ob_get_clean());

            $exporter = $this->createTestUser('Export Executive');
            $_SESSION = ['_auth_user_id' => $exporter];
            $role = (int) Database::connection()->query("SELECT id FROM roles WHERE name = 'Export Executive'")->fetchColumn();
            self::assertFalse(PermissionService::can($exporter, $role, 'manage_incentives'));
            ob_start();
            (new FileStoreController())->download(['id' => (string) $fileId]);
            self::assertSame('You do not have permission to view this document.', (string) ob_get_clean(), 'the general file link does not serve it either');
        } finally {
            @unlink($path);
        }
    }
}
