<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controllers\OrderController;
use App\Repositories\FileStoreRepository;
use App\Repositories\OrderShippingRepository;
use App\Services\StageGateService;
use App\Tests\Support\DbTestCase;

/**
 * Phase 2 item 15 of the approved remediation plan: "Upload BL copy +
 * record BL number once issued by shipping company, lean UI." The
 * "Confirm BL Issued" form only ever let staff type in the BL number and
 * date — there was no way anywhere in the system to attach the shipping
 * line's actual scanned BL document.
 */
final class BlCopyUploadTest extends DbTestCase
{
    private function unlockThrough(int $orderId, int $throughStage): void
    {
        for ($stage = 1; $stage <= $throughStage; $stage++) {
            StageGateService::passAndUnlockNext($orderId, $stage, null);
        }
    }

    private function render(int $orderId): string
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return ob_get_clean();
    }

    public function testBlIssuedFormOffersAnOptionalScannedCopyUpload(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->unlockThrough($orderId, 6);

        $output = $this->render($orderId);

        self::assertStringContainsString('name="bl_copy"', $output, 'the Confirm BL Issued form must offer a scanned-copy upload field');
        self::assertStringContainsString('enctype="multipart/form-data"', $output);
    }

    public function testOnceBlIssuedWithNoCopyYetThereIsAWayToAttachOneLater(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk']);
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');
        $this->unlockThrough($orderId, 7);

        $output = $this->render($orderId);

        self::assertStringContainsString('/bl-copy', $output, 'a recorded BL with no copy yet must offer a way to attach one afterward');
        self::assertStringNotContainsString('Download scanned BL copy', $output);
    }

    public function testOnceACopyIsAttachedADownloadLinkAppearsInstead(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk']);
        OrderShippingRepository::recordBl($orderId, 'MSCU1234567', '2026-06-15');
        $this->unlockThrough($orderId, 7);
        $fileId = $this->createTestFile($orderId);
        OrderShippingRepository::attachBlCopy($orderId, $fileId);

        $output = $this->render($orderId);

        self::assertStringContainsString("/file-store/{$fileId}/download", $output);
        self::assertStringContainsString('Download scanned BL copy', $output);
    }

    public function testAttachBlCopyRepositoryMethodPersistsTheFileId(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderShippingRepository::upsert($orderId, ['shipping_line' => 'Maersk']);
        $fileId = $this->createTestFile($orderId);

        OrderShippingRepository::attachBlCopy($orderId, $fileId);

        $shipping = OrderShippingRepository::find($orderId);
        self::assertSame($fileId, (int) $shipping['bl_copy_file_id']);
        self::assertNotNull(FileStoreRepository::find($fileId));
    }
}
