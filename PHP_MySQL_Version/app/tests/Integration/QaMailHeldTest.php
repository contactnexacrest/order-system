<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\EmailLogRepository;
use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;

/**
 * QA MTX-003 (D-280): a send held by testing mode (or its switch in Admin ›
 * E-mail settings) is not a failure. The log records it as held with the
 * reason (not 'failed'), the screen says why (not "check the mail
 * configuration"), and — as D-280 says — staff send it outside the app or
 * submit it again once the hold is lifted; it is never sent later by itself.
 * Node twin: qaMailHeld.test.js.
 */
final class QaMailHeldTest extends DbTestCase
{
    private const REASON = 'testing mode holds e-mails to buyers and suppliers';

    private function order(): int
    {
        $clientId = $this->createTestClient();
        Database::connection()->prepare('UPDATE clients SET email = :e WHERE id = :id')->execute(['e' => 'buyer-' . bin2hex(random_bytes(4)) . '@email.com', 'id' => $clientId]);
        return $this->createTestOrder($clientId);
    }

    /** Level 1 by one person, Level 2 by another, then "Send now". */
    private function requestApproveSendNow(int $orderId, ?int $documentId, string $template): array
    {
        $requester = $this->createTestUser('Export Executive');
        $approver = $this->createTestUser('Admin');
        $r = EmailDispatchService::requestSend($orderId, $documentId, $template, null, $requester);
        if (!$r['dispatched']) {
            EmailDispatchService::approveSend($r['id'], $approver);
        }
        $sent = EmailDispatchService::sendNowOverride($r['id'], $approver);
        return [$r['id'], $sent];
    }

    public function testMtx003AGenericSendHeldByTestingModeIsLoggedAsHeldNotFailed(): void
    {
        $orderId = $this->order();
        $this->withDocumentMailsHeld(function () use ($orderId): void {
            [$id, $sent] = $this->requestApproveSendNow($orderId, null, 'payment_followup');
            self::assertFalse($sent);
            self::assertSame(self::REASON, EmailDispatchService::$lastHoldReason);
            $row = EmailLogRepository::find($id);
            self::assertNotSame('failed', $row['status']);
            self::assertSame('cancelled', $row['status']);
            self::assertNull($row['cancelled_by'], 'held by the system, not cancelled by a person');
            self::assertSame('Held, not sent: ' . self::REASON . '. Send it outside the app, or submit it again once that changes.', $row['cancellation_reason']);
            self::assertStringStartsWith('Not e-mailed: ' . self::REASON . '.', EmailDispatchService::heldMessage((string) EmailDispatchService::$lastHoldReason));
            // Not "in flight": the same e-mail can be submitted again once testing mode is off.
            self::assertArrayHasKey('id', EmailDispatchService::requestSend($orderId, null, 'payment_followup', null, $this->createTestUser('Export Executive')));
        });
    }

    public function testMtx003ADocumentSendHeldByTestingModeIsLoggedAsHeldNotFailed(): void
    {
        $orderId = $this->order();
        $fileId = $this->createTestFile($orderId);
        file_put_contents((string) Database::connection()->query("SELECT server_path FROM file_store WHERE id = {$fileId}")->fetchColumn(), '%PDF-1.4 test');
        $docId = $this->createTestDocument($orderId, 'PI', 'approved', $fileId);
        $this->withDocumentMailsHeld(function () use ($orderId, $docId): void {
            [$id, $sent] = $this->requestApproveSendNow($orderId, $docId, 'send_pi');
            self::assertFalse($sent);
            self::assertSame(self::REASON, EmailDispatchService::$lastHoldReason);
            $row = EmailLogRepository::find($id);
            self::assertSame('cancelled', $row['status']);
            self::assertStringStartsWith('Held, not sent: ' . self::REASON, (string) $row['cancellation_reason']);
        });
    }

    public function testMtx003AnImmediateSendWithTheQueueOffReportsTheHold(): void
    {
        $orderId = $this->order();
        \App\Repositories\CompanySettingsRepository::set('mail_approval_queue_enabled', '0', null);
        try {
            $this->withDocumentMailsHeld(function () use ($orderId): void {
                $r = EmailDispatchService::requestSend($orderId, null, 'shipment_readiness', null, $this->createTestUser('Export Executive'));
                self::assertTrue($r['dispatched']);
                self::assertFalse($r['sent']);
                self::assertSame(self::REASON, $r['held']);
            });
        } finally {
            \App\Repositories\CompanySettingsRepository::set('mail_approval_queue_enabled', '1', null);
        }
    }
}
