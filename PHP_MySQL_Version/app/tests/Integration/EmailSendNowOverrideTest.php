<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\EmailLogRepository;
use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;

/**
 * 90-item backlog Phase 4: the cron script's own docblock anticipated a
 * "future 'send now' button" — an approver who cleared Level-2 approval
 * before a deferred send's scheduled_at had no way to actually make it
 * go out immediately; it still sat waiting for scheduled_at and the next
 * cron tick. sendNowOverride() shares dispatch()'s exact code path, so a
 * forced send can never diverge from what the cron would have done.
 * Also covers awaitingDispatch() now sorting most-recent-first.
 */
final class EmailSendNowOverrideTest extends DbTestCase
{
    public function testSendNowOverrideRefusesARowStillAwaitingApproval(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', null);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', '2030-01-01 00:00:00', 1);
        // Deliberately not approved — still 'pending_approval'.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only an already-approved send still waiting to go out can be sent now.');

        EmailDispatchService::sendNowOverride($emailLogId, 1);
    }

    public function testSendNowOverrideDispatchesAnApprovedRowImmediately(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $fileId = $this->createTestFile($orderId, $clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $fileId);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', '2030-01-01 00:00:00', 1);
        EmailLogRepository::approve($emailLogId, 1);

        EmailDispatchService::sendNowOverride($emailLogId, 1);

        $row = EmailLogRepository::find($emailLogId);
        self::assertNotSame('approved', $row['status'], 'a forced send must claim the row off "approved" immediately, not wait for scheduled_at (2099) or the cron');

        $log = AuditLogRepository::search(null, null, null, 'EMAIL_SEND_FORCED_NOW', null, null, 5);
        self::assertNotEmpty($log, 'the forced-now override must be audit-logged');
    }

    public function testSendNowOverrideRefusesARowAlreadySent(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $fileId = $this->createTestFile($orderId, $clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $fileId);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);
        EmailLogRepository::approve($emailLogId, 1);
        EmailDispatchService::dispatch(EmailLogRepository::find($emailLogId));

        $this->expectException(\RuntimeException::class);
        EmailDispatchService::sendNowOverride($emailLogId, 1);
    }

    public function testAwaitingDispatchSortsMostRecentFirst(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentIdA = $this->createTestDocument($orderId, 'QT', 'approved', null);
        $olderId = EmailLogRepository::create($orderId, $documentIdA, 'send_qt', 'buyer@example.test', 'Subject A', 'Body', null, 1);
        Database::connection()->prepare('UPDATE email_log SET created_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = :id')->execute(['id' => $olderId]);

        $documentIdB = $this->createTestDocument($orderId, 'PI', 'approved', null);
        $newerId = EmailLogRepository::create($orderId, $documentIdB, 'send_pi', 'buyer@example.test', 'Subject B', 'Body', null, 1);

        $rows = EmailLogRepository::awaitingDispatch();
        $ids = array_column($rows, 'id');
        $posNewer = array_search($newerId, $ids, true);
        $posOlder = array_search($olderId, $ids, true);

        self::assertNotFalse($posNewer);
        self::assertNotFalse($posOlder);
        self::assertLessThan($posOlder, $posNewer, 'the most recently requested send must appear before the older one');
    }
}
