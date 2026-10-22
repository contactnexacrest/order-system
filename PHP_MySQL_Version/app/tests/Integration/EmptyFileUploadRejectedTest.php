<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\FileUploadService;
use App\Tests\Support\DbTestCase;

/**
 * External QA report R-10: "A 0-byte file is accepted as the buyer's
 * signed PO (Buyer Action Link upload)." FileUploadService::storeOne()
 * only ever checked size against the upper bound (too large) — never a
 * lower one — so an empty file passed straight through to
 * move_uploaded_file() and got stored as a real, unusable "signed PO".
 */
final class EmptyFileUploadRejectedTest extends DbTestCase
{
    public function testA0ByteFileIsRejectedBeforeTryingToMoveIt(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'empty-upload-test-');
        file_put_contents($tmpPath, ''); // deliberately empty
        $_FILES['bl_copy'] = [
            'name'     => 'empty.pdf',
            'type'     => 'application/pdf',
            'tmp_name' => $tmpPath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 0,
        ];

        try {
            FileUploadService::handleUpload('bl_copy', 'bl_copy', 'test/empty-upload', null, null, null);
            self::fail('an empty file must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('empty', $e->getMessage());
        } finally {
            unset($_FILES['bl_copy']);
            @unlink($tmpPath);
        }
    }

    public function testANonEmptyFileStillPassesTheSizeCheck(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nonempty-upload-test-');
        file_put_contents($tmpPath, '%PDF-1.4 fake pdf content for test');
        $_FILES['bl_copy'] = [
            'name'     => 'bl-scan.pdf',
            'type'     => 'application/pdf',
            'tmp_name' => $tmpPath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmpPath),
        ];

        try {
            FileUploadService::handleUpload('bl_copy', 'bl_copy', 'test/nonempty-upload', null, null, null);
            self::fail('move_uploaded_file() cannot succeed outside a real HTTP upload, so handleUpload() must throw here — the point is which error it throws');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('empty', $e->getMessage());
            self::assertStringContainsString('Failed to move the uploaded file', $e->getMessage());
        } finally {
            unset($_FILES['bl_copy']);
            @unlink($tmpPath);
        }
    }
}
