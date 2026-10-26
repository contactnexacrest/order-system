<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\AuditLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderAssigneeRepository;
use App\Services\BuyerActionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision (2026-10-26): "we don't need mail on each audit
 * step/event/log". AuditLogRepository::log() used to email every logged
 * action (order assignees + every Admin/MD/ED + a catch-all) — 90+ mails
 * for a single order, so the few that needed someone to act were lost in
 * the noise. The audit log is now only a record; mail goes out only where
 * a specific person needs to know or act (NotificationRepository::create()).
 * The client actions that used to reach staff only through that hook now
 * notify the order's own team directly.
 */
final class AuditLogSendsNoEmailTest extends DbTestCase
{
    private string $errorLog;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();
        // With no SMTP configured EmailService writes each attempted mail
        // to the PHP error log — capture it to see what was "sent".
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'mail');
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        parent::tearDown();
    }

    private function mailsAttempted(): int
    {
        return substr_count((string) file_get_contents($this->errorLog), 'Subject:');
    }

    public function testLoggingAnOrderActionSendsNoEmail(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderAssigneeRepository::add($orderId, $this->createTestUser('Export Executive'), null);

        foreach (['ORDER_STAGE_GATE_PASSED', 'DOCUMENT_APPROVED', 'ADVANCE_PAYMENT_CLEARED', 'REVIEWERS_ASSIGNED'] as $actionType) {
            AuditLogRepository::log(null, $actionType, 'orders', $orderId);
        }

        self::assertSame(0, $this->mailsAttempted());
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Repositories/AuditLogRepository.php');
        self::assertDoesNotMatchRegularExpression('/EmailService::|sendPlainText\(|ActionNotificationService|NotificationRepository::create\(\$/', $source, 'the audit log must never trigger mail');
    }

    public function testABuyerAcknowledgingTheOcNotifiesOnlyThatOrdersTeam(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $assigneeId = $this->createTestUser('Export Executive');
        OrderAssigneeRepository::add($orderId, $assigneeId, null);

        $notified = NotificationRepository::notifyOrderTeam($orderId, 'buyer_oc_acknowledged', 'The buyer acknowledged the Order Confirmation.');

        self::assertSame([$assigneeId], $notified);
        self::assertSame(1, $this->mailsAttempted());
    }

    public function testAnUnassignedOrderFallsBackToEveryoneWhoManagesOrders(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());

        $notified = NotificationRepository::notifyOrderTeam($orderId, 'client_payment_reported', 'The buyer reported a payment.');

        self::assertNotEmpty($notified, 'nobody assigned yet — it must still reach someone');
        self::assertSame($notified, array_values(array_unique($notified)));
    }

    public function testTheBuyerActionLinkAcknowledgementNotifiesTheOrderTeam(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $assigneeId = $this->createTestUser('Export Executive');
        OrderAssigneeRepository::add($orderId, $assigneeId, null);
        for ($stage = 1; $stage <= 3; $stage++) {
            \App\Services\StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
        $documentId = $this->createTestDocument($orderId, 'OC', 'sent');
        \App\Repositories\OrderOcAcknowledgmentRepository::recordSent($orderId, $documentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+48 hours')));

        $result = BuyerActionService::acknowledgeOc($orderId);

        self::assertTrue($result['ok'], $result['message']);
        $rows = array_filter(NotificationRepository::forUser($assigneeId), static fn (array $n): bool => $n['type'] === 'buyer_oc_acknowledged');
        self::assertCount(1, $rows);
    }

    public function testTheNotificationItselfIsTheEmailSubjectTrimmedToFit(): void
    {
        self::assertSame('NexaCrest — Amendment AMD-1 needs MD approval.', NotificationRepository::emailSubject('Amendment AMD-1 needs MD approval.'));
        $long = NotificationRepository::emailSubject(str_repeat('x', 200));
        self::assertSame(mb_strlen('NexaCrest — ') + 108, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }
}
