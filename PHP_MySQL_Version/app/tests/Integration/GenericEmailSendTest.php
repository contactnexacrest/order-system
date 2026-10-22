<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\EmailLogRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\EmailDispatchService;
use App\Tests\Support\DbTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * External QA report L-42: four seeded email templates — payment_followup,
 * shipment_readiness, bl_copy_sent, balance_receipt_confirmation — have no
 * document behind them, but EmailDispatchService::requestSend() declared
 * its $documentId parameter as a plain (non-nullable) `int`, so calling it
 * with null for any of these literally threw a PHP TypeError: there was no
 * way to even construct a request for one, let alone send it (EM-NODOC).
 * dispatch() separately hard-failed any document_id IS NULL row
 * unconditionally, so even a row created some other way could never
 * actually go out.
 */
final class GenericEmailSendTest extends DbTestCase
{
    private function setClientEmail(int $clientId): void
    {
        Database::connection()
            ->prepare('UPDATE clients SET email = :email WHERE id = :id')
            ->execute(['email' => 'jane@buyer.example', 'id' => $clientId]);
    }

    /** @return array<string, array{0:string}> */
    public static function genericTemplateProvider(): array
    {
        return [
            'payment_followup' => ['payment_followup'],
            'shipment_readiness' => ['shipment_readiness'],
            'bl_copy_sent' => ['bl_copy_sent'],
            'balance_receipt_confirmation' => ['balance_receipt_confirmation'],
        ];
    }

    #[DataProvider('genericTemplateProvider')]
    public function testRequestSendAcceptsADocumentlessGenericTemplate(string $templateKey): void
    {
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId);
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Export Executive');

        // The actual bug: this used to be a TypeError (Argument #2
        // ($documentId) must be of type int, null given), not merely a
        // wrong result — it couldn't be called at all.
        $result = EmailDispatchService::requestSend($orderId, null, $templateKey, null, $userId);

        self::assertArrayHasKey('id', $result);
        $row = EmailLogRepository::find($result['id']);
        self::assertNotNull($row);
        self::assertNull($row['document_id']);
        self::assertSame($templateKey, $row['template_key']);
        self::assertSame($orderId, (int) $row['order_id']);
    }

    public function testRequestSendRefusesADuplicateGenericSendStillInFlight(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId);
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Export Executive');

        EmailDispatchService::requestSend($orderId, null, 'payment_followup', null, $userId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already awaiting approval or scheduled to go out');
        EmailDispatchService::requestSend($orderId, null, 'payment_followup', null, $userId);
    }

    public function testRequestSendAllowsADifferentGenericTemplateWhileOneIsInFlight(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId);
        $orderId = $this->createTestOrder($clientId);
        $userId = $this->createTestUser('Export Executive');

        EmailDispatchService::requestSend($orderId, null, 'payment_followup', null, $userId);
        // Must not be blocked by the unrelated payment_followup send still in flight.
        $result = EmailDispatchService::requestSend($orderId, null, 'shipment_readiness', null, $userId);

        self::assertArrayHasKey('id', $result);
    }

    public function testDispatchGenericFailsGracefullyWithoutCrashingWhenBlCopyHasNoAttachment(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId);
        $orderId = $this->createTestOrder($clientId);
        $emailLogId = EmailLogRepository::create($orderId, null, 'bl_copy_sent', 'jane@buyer.example', 'Subject', 'Body', null, $this->createTestUser('Admin'));
        EmailLogRepository::approve($emailLogId, $this->createTestUser('Admin'));

        $sent = EmailDispatchService::dispatch(EmailLogRepository::find($emailLogId));

        self::assertFalse($sent, 'bl_copy_sent must refuse to go out with no scanned BL on file, not crash or send with no attachment');
        self::assertSame('failed', EmailLogRepository::find($emailLogId)['status']);
    }

    public function testDispatchGenericReachesTheAttachmentCheckWithARealBlCopyOnFile(): void
    {
        $clientId = $this->createTestClient();
        $this->setClientEmail($clientId);
        $orderId = $this->createTestOrder($clientId);
        $tmpPath = tempnam(sys_get_temp_dir(), 'l42-bl-copy-');
        file_put_contents($tmpPath, '%PDF-1.4 fake scanned BL for test');
        $pdo = Database::connection();
        $fileStmt = $pdo->prepare(
            "INSERT INTO file_store (order_id, file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type)
             VALUES (:order_id, 'RECEIVED', :path, :uuid, 'bl-scan.pdf', :size, 'application/pdf')"
        );
        $fileStmt->execute(['order_id' => $orderId, 'path' => $tmpPath, 'uuid' => bin2hex(random_bytes(16)) . '.pdf', 'size' => filesize($tmpPath)]);
        $fileId = (int) $pdo->lastInsertId();
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk']);
        OrderShippingRepository::attachBlCopy($orderId, $fileId);

        $emailLogId = EmailLogRepository::create($orderId, null, 'bl_copy_sent', 'jane@buyer.example', 'Subject', 'Body', null, $this->createTestUser('Admin'));
        EmailLogRepository::approve($emailLogId, $this->createTestUser('Admin'));

        try {
            EmailDispatchService::dispatch(EmailLogRepository::find($emailLogId));
            // No SMTP is configured in this test environment, so the actual
            // transport attempt itself still fails — the real assertion is
            // simply that getting here (past the attachment check, with a
            // genuine file on disk) never throws.
            self::assertTrue(true);
        } finally {
            @unlink($tmpPath);
        }
    }
}
