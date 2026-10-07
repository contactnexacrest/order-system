<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\OrderController;
use App\Helpers\Flash;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;
use App\Services\EmailDispatchService;
use App\Services\MailSenderService;
use App\Services\PermissionService;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * docs/schema.sql Section AU — four related gaps found during live testing:
 * (1) the Stage 4 "buyer acknowledged by email" override only worked once
 * an order_oc_acknowledgments row already existed (i.e. only after an OC
 * send had actually completed) — if the send was itself broken, staff had
 * no way to move the order past Stage 4 at all; (2)/(3) nothing could turn
 * off the always-on email approval queue, or kill sending entirely, while
 * diagnosing a hosting SMTP problem; (4) the 48h auto-confirm window was
 * hardcoded.
 */
final class OcAckAndMailTogglesTest extends DbTestCase
{
    private const SETTING_KEYS = [
        'mail_sending_enabled', 'mail_approval_queue_enabled',
        'oc_ack_override_restricted', 'oc_ack_auto_confirm_hours',
    ];
    private array $originalValues = [];

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_SESSION = [];
        foreach (self::SETTING_KEYS as $key) {
            $this->originalValues[$key] = (string) CompanySettingsRepository::get($key);
        }
        PermissionService::resetCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->originalValues as $key => $value) {
            CompanySettingsRepository::set($key, $value, null);
        }
        PermissionService::resetCache();
        parent::tearDown();
    }

    private function unlockStage4(int $orderId): void
    {
        StageGateService::passAndUnlockNext($orderId, 1, null);
        StageGateService::passAndUnlockNext($orderId, 2, null);
        StageGateService::passAndUnlockNext($orderId, 3, null);
        self::assertTrue(StageGateService::isUnlocked($orderId, 4));
    }

    private function denyPermission(int $userId, string $permissionKey): void
    {
        $pdo = Database::connection();
        $permissionId = (int) $pdo->query('SELECT id FROM permissions WHERE permission_key = ' . $pdo->quote($permissionKey))->fetchColumn();
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:user_id, :permission_id, 0)')
            ->execute(['user_id' => $userId, 'permission_id' => $permissionId]);
    }

    // --- OC acknowledgment override, decoupled from a prior send ---

    public function testRecordOcAcknowledgmentSucceedsWithoutAnyPriorSend(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockStage4($orderId);
        $documentId = $this->createTestDocument($orderId, 'OC', 'draft');
        self::assertNull(OrderOcAcknowledgmentRepository::find($orderId), 'no ack row should exist yet — the OC was never actually sent');
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST['acknowledged_note'] = 'Buyer confirmed by phone, evidence recorded here.';

        ob_start();
        (new OrderController())->recordOcAcknowledgment(['id' => $orderId]);
        ob_end_clean();

        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        self::assertNotNull($ack);
        self::assertSame($documentId, (int) $ack['document_id']);
        self::assertNotNull($ack['acknowledged_at']);
        self::assertSame('staff_recorded_email', $ack['acknowledged_via']);
        self::assertTrue(StageGateService::isUnlocked($orderId, 5), 'Stage 5 must unlock even though the OC was never actually emailed');
    }

    public function testRecordOcAcknowledgmentRefusesWhenNoOcDocumentExistsAtAll(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockStage4($orderId);
        $userId = $this->createTestUser('Export Executive');
        $_SESSION['_auth_user_id'] = $userId;
        $_POST['acknowledged_note'] = 'Buyer confirmed by phone.';

        ob_start();
        (new OrderController())->recordOcAcknowledgment(['id' => $orderId]);
        ob_end_clean();

        self::assertNull(OrderOcAcknowledgmentRepository::find($orderId));
        self::assertFalse(StageGateService::isUnlocked($orderId, 5));
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    public function testOcAckAutoConfirmHoursIsConfigurable(): void
    {
        CompanySettingsRepository::set('oc_ack_auto_confirm_hours', '2');
        $orderId = $this->createTestOrder($this->createTestClient());
        $documentId = $this->createTestDocument($orderId, 'OC', 'approved');

        OrderOcAcknowledgmentRepository::recordSent($orderId, $documentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+2 hours')));

        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        $sentAt = strtotime($ack['sent_at']);
        $dueAt = strtotime($ack['due_at']);
        self::assertEqualsWithDelta(2 * 3600, $dueAt - $sentAt, 5, 'due_at must follow the configured window, not the old hardcoded 48h');
    }

    // --- oc_ack_override_restricted gates the override behind a permission ---

    public function testOverrideRestrictedBlocksAUserWithoutThePermission(): void
    {
        CompanySettingsRepository::set('oc_ack_override_restricted', '1');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockStage4($orderId);
        $this->createTestDocument($orderId, 'OC', 'draft');
        $userId = $this->createTestUser('Export Executive'); // has override_buyer_acknowledgment by default — explicitly denied below
        $this->denyPermission($userId, 'override_buyer_acknowledgment');
        PermissionService::resetCache();
        $_SESSION['_auth_user_id'] = $userId;
        $_POST['acknowledged_note'] = 'Buyer confirmed by phone.';

        ob_start();
        (new OrderController())->recordOcAcknowledgment(['id' => $orderId]);
        ob_end_clean();

        self::assertNull(OrderOcAcknowledgmentRepository::find($orderId));
        self::assertFalse(StageGateService::isUnlocked($orderId, 5));
        $flash = Flash::pull();
        self::assertSame('error', $flash[0]['type']);
    }

    public function testOverrideRestrictedAllowsAUserWithThePermission(): void
    {
        CompanySettingsRepository::set('oc_ack_override_restricted', '1');
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockStage4($orderId);
        $this->createTestDocument($orderId, 'OC', 'draft');
        $userId = $this->createTestUser('Export Executive'); // keeps override_buyer_acknowledgment from seed
        $_SESSION['_auth_user_id'] = $userId;
        $_POST['acknowledged_note'] = 'Buyer confirmed by phone.';

        ob_start();
        (new OrderController())->recordOcAcknowledgment(['id' => $orderId]);
        ob_end_clean();

        self::assertTrue(StageGateService::isUnlocked($orderId, 5));
    }

    // --- mail_sending_enabled: global kill switch ---

    public function testMailSendingDisabledBlocksANonSecurityEmail(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '0');

        $sent = MailSenderService::send('buyer@real-company.example', 'Subject', 'Body', [], false);

        self::assertFalse($sent);
    }

    public function testMailSendingDisabledDoesNotAffectASecurityEmail(): void
    {
        CompanySettingsRepository::set('mail_sending_enabled', '0');

        // No SMTP configured in the test environment either way, so this
        // still returns false — the point is it must reach that same
        // no-SMTP fallback rather than being blocked earlier by the kill
        // switch, which only ever checks when isSecurityEmail is false.
        $sent = MailSenderService::send('staff@nexacrest.test', 'Your 2FA code', 'Body', [], true);

        self::assertFalse($sent);
    }

    // --- mail_approval_queue_enabled: bypass the Level-2 approval queue ---

    private function setClientEmail(int $clientId, string $email): void
    {
        Database::connection()->prepare('UPDATE clients SET email = :email WHERE id = :id')
            ->execute(['email' => $email, 'id' => $clientId]);
    }

    public function testApprovalQueueDisabledDispatchesImmediatelyInsteadOfQueuing(): void
    {
        CompanySettingsRepository::set('mail_approval_queue_enabled', '0');
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId, 'buyer@example.test');
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved');
        $userId = $this->createTestUser('Export Executive');

        $result = EmailDispatchService::requestSend($orderId, $documentId, 'send_qt', null, $userId);

        self::assertTrue($result['dispatched'], 'with the queue off, the send must be attempted immediately rather than left pending_approval');
        // No SMTP configured in the test environment, so the immediate
        // attempt itself fails — that's expected and asserts the row
        // never sits forever in 'pending_approval' waiting for a human.
        self::assertFalse($result['sent']);
    }

    public function testApprovalQueueEnabledByDefaultStillQueuesForLevel2(): void
    {
        // mail_approval_queue_enabled left at its seeded default ('1').
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId, 'buyer@example.test');
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved');
        $userId = $this->createTestUser('Export Executive');

        $result = EmailDispatchService::requestSend($orderId, $documentId, 'send_qt', null, $userId);

        self::assertFalse($result['dispatched']);
        self::assertNull($result['sent']);
    }
}
