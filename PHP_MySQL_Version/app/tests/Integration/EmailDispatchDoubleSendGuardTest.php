<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\EmailLogRepository;
use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;

/**
 * QA-5 EML-06 (external QA report cross-verification): an overlapping run
 * of dispatch_deferred_emails.php — a slow run still going when the next
 * cPanel cron tick fires, or an in-process scheduler racing an OS cron
 * entry pointed at the same script — could pull the same 'approved'
 * email_log row via dueForSend() twice and send it twice. Fixed by making
 * EmailDispatchService::dispatch() atomically claim the row
 * (EmailLogRepository::claimForSend(): UPDATE ... WHERE status =
 * 'approved') before doing anything else, so only one of two racing
 * attempts can ever proceed.
 */
final class EmailDispatchDoubleSendGuardTest extends DbTestCase
{
    public function testClaimForSendWinsOnceAndLosesOnASecondAttempt(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', null);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);
        EmailLogRepository::approve($emailLogId, 1);

        self::assertTrue(EmailLogRepository::claimForSend($emailLogId), 'the first claim (simulating the first of two overlapping runs) must win');
        self::assertSame('sending', EmailLogRepository::find($emailLogId)['status']);

        self::assertFalse(EmailLogRepository::claimForSend($emailLogId), 'a second claim attempt (the overlapping run) must lose — the row is no longer approved');
    }

    public function testClaimForSendRefusesARowThatWasNeverApproved(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', null);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);
        // Deliberately not approved — still 'pending_approval'.

        self::assertFalse(EmailLogRepository::claimForSend($emailLogId));
        self::assertSame('pending_approval', EmailLogRepository::find($emailLogId)['status']);
    }

    /**
     * The exact shape of the original vulnerability: dispatch() called
     * twice on the row a dueForSend() query returned to two overlapping
     * runs before either had updated it. The second call must be a
     * complete no-op — no second mail-send attempt, no second mutation of
     * the row at all — whatever the first call's own outcome was.
     */
    public function testDispatchCalledTwiceOnTheSameApprovedRowOnlyEverActsOnce(): void
    {
        $clientId = $this->createTestClient();
        $orderId = $this->createTestOrder($clientId);
        $fileId = $this->createTestFile($orderId, $clientId);

        $documentId = $this->createTestDocument($orderId, 'QT', 'approved', $fileId);
        $emailLogId = EmailLogRepository::create($orderId, $documentId, 'send_qt', 'buyer@example.test', 'Subject', 'Body', null, 1);
        EmailLogRepository::approve($emailLogId, 1);

        // First call: whatever real-world outcome (sent or failed — SMTP
        // may not be configured in this environment), it must move the
        // row off 'approved' via the atomic claim.
        EmailDispatchService::dispatch(EmailLogRepository::find($emailLogId));
        $afterFirstCall = EmailLogRepository::find($emailLogId);
        self::assertNotSame('approved', $afterFirstCall['status'], 'the first call must have claimed the row');

        // Second call: simulates the overlapping run reaching this same
        // row from its own dueForSend() snapshot, taken before the first
        // call's claim landed.
        $secondResult = EmailDispatchService::dispatch($afterFirstCall);
        $afterSecondCall = EmailLogRepository::find($emailLogId);

        self::assertFalse($secondResult, 'the second, racing call must be refused');
        self::assertSame($afterFirstCall, $afterSecondCall, 'the second call must mutate nothing — not even flip a still-"sending" row to "failed"');
    }
}
