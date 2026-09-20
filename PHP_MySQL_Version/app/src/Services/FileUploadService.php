<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Repositories\FileStoreRepository;

/**
 * Handles a real uploaded file (spec Section 3 / Section 14 "FILE UPLOADS":
 * type validation, size limits, UUID naming, outside web root). Validation
 * limits come from file_upload_contexts — never hardcoded per call site.
 *
 * Only two upload contexts are wired to an actual screen in this delivery:
 * amendment_signed_copy and dispute_document (Phase D scope). The others
 * seeded in file_upload_contexts (draft_bl, fumigation_cert, ...) are
 * reserved for a future phase's upload screens and this service works for
 * them unchanged whenever that's built — it has no idea what a "draft BL"
 * is, same judgment call as CompanySettingsRepository.
 */
final class FileUploadService
{
    /**
     * @param string $formFieldName e.g. 'signed_copy' — the <input type="file" name="...">
     * @throws \RuntimeException on missing file, disallowed extension, or oversize
     */
    public static function handleUpload(
        string $formFieldName,
        string $contextKey,
        string $subPath,
        ?int $clientId,
        ?int $orderId,
        int $uploadedBy,
        ?string $originalFilenameOverride = null,
        ?string $receivedFrom = null,
        ?string $documentTypeLabel = null
    ): int {
        if (empty($_FILES[$formFieldName]) || $_FILES[$formFieldName]['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('No file was uploaded, or the upload failed.');
        }
        $upload = $_FILES[$formFieldName];

        $context = self::findContext($contextKey);
        if (!$context) {
            throw new \RuntimeException("Unknown upload context: {$contextKey}");
        }

        $originalName = $originalFilenameOverride ?? $upload['name'];
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = array_map('trim', explode(',', strtolower($context['allowed_extensions'])));
        if (!in_array($extension, $allowed, true)) {
            throw new \RuntimeException("File type .{$extension} is not allowed for this upload (allowed: {$context['allowed_extensions']}).");
        }

        if ((int) $upload['size'] > (int) $context['max_size_bytes']) {
            $maxMb = round(((int) $context['max_size_bytes']) / 1048576, 1);
            throw new \RuntimeException("File is too large — maximum is {$maxMb} MB for this upload.");
        }

        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $targetDir = "{$storageBase}/{$subPath}";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $uuidFilename = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = "{$targetDir}/{$uuidFilename}";

        if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Failed to move the uploaded file into storage.');
        }

        return FileStoreRepository::insertReceived(
            $clientId,
            $orderId,
            $targetPath,
            $uuidFilename,
            $originalName,
            (int) filesize($targetPath),
            $upload['type'] ?? 'application/octet-stream',
            $uploadedBy,
            $receivedFrom,
            $documentTypeLabel
        );
    }

    private static function findContext(string $contextKey): ?array
    {
        $stmt = \App\Config\Database::connection()->prepare(
            'SELECT * FROM file_upload_contexts WHERE context_key = :key'
        );
        $stmt->execute(['key' => $contextKey]);
        return $stmt->fetch() ?: null;
    }
}
