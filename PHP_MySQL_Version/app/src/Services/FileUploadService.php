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
        ?int $uploadedBy,
        ?string $originalFilenameOverride = null,
        ?string $receivedFrom = null,
        ?string $documentTypeLabel = null
    ): int {
        if (empty($_FILES[$formFieldName]) || $_FILES[$formFieldName]['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('No file was uploaded, or the upload failed.');
        }
        $upload = $_FILES[$formFieldName];
        $originalName = $originalFilenameOverride ?? $upload['name'];

        return self::storeOne(
            $upload['tmp_name'],
            $upload['size'],
            $originalName,
            $upload['type'] ?? 'application/octet-stream',
            $contextKey,
            $subPath,
            $clientId,
            $orderId,
            $uploadedBy,
            $receivedFrom,
            $documentTypeLabel
        );
    }

    /**
     * Multiple files under one array-style field (`name="attachments[]"`),
     * e.g. the order progress chat's image/video attachments. Every file
     * must individually pass the same context validation as handleUpload();
     * an empty array (no files chosen) is fine and simply returns [].
     *
     * @return int[] file_store ids, in the same order as the uploaded files
     * @throws \RuntimeException on the first invalid file (nothing is left
     *         partially stored — the caller runs this before creating the
     *         parent row it would attach to)
     */
    public static function handleMultipleUploads(
        string $formFieldName,
        string $contextKey,
        string $subPath,
        ?int $clientId,
        ?int $orderId,
        ?int $uploadedBy,
        ?string $receivedFrom = null,
        ?string $documentTypeLabel = null
    ): array {
        if (empty($_FILES[$formFieldName]) || empty($_FILES[$formFieldName]['name'])) {
            return [];
        }
        $field = $_FILES[$formFieldName];
        $count = is_array($field['name']) ? count($field['name']) : 0;

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            if ($field['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue; // an empty extra <input> slot, not a real selection
            }
            if ($field['error'][$i] !== UPLOAD_ERR_OK) {
                throw new \RuntimeException("Upload failed for \"{$field['name'][$i]}\".");
            }
            $ids[] = self::storeOne(
                $field['tmp_name'][$i],
                $field['size'][$i],
                $field['name'][$i],
                $field['type'][$i] ?? 'application/octet-stream',
                $contextKey,
                $subPath,
                $clientId,
                $orderId,
                $uploadedBy,
                $receivedFrom,
                $documentTypeLabel
            );
        }
        return $ids;
    }

    private static function storeOne(
        string $tmpName,
        int $size,
        string $originalName,
        string $mimeType,
        string $contextKey,
        string $subPath,
        ?int $clientId,
        ?int $orderId,
        ?int $uploadedBy,
        ?string $receivedFrom,
        ?string $documentTypeLabel
    ): int {
        $context = self::findContext($contextKey);
        if (!$context) {
            throw new \RuntimeException("Unknown upload context: {$contextKey}");
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = array_map('trim', explode(',', strtolower($context['allowed_extensions'])));
        if (!in_array($extension, $allowed, true)) {
            throw new \RuntimeException("File type .{$extension} is not allowed for this upload (allowed: {$context['allowed_extensions']}).");
        }

        if ($size > (int) $context['max_size_bytes']) {
            $maxMb = round(((int) $context['max_size_bytes']) / 1048576, 1);
            throw new \RuntimeException("File \"{$originalName}\" is too large — maximum is {$maxMb} MB for this upload.");
        }

        $storageBase = rtrim(Env::get('STORAGE_BASE_PATH', ''), '/');
        $targetDir = "{$storageBase}/{$subPath}";
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $uuidFilename = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = "{$targetDir}/{$uuidFilename}";

        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new \RuntimeException('Failed to move the uploaded file into storage.');
        }

        return FileStoreRepository::insertReceived(
            $clientId,
            $orderId,
            $targetPath,
            $uuidFilename,
            $originalName,
            (int) filesize($targetPath),
            $mimeType,
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

    /** Same sanitization DocumentGenerationService uses for its own storage folder names — kept in sync so every caller building a storage subPath does it identically. */
    public static function sanitizePathSegment(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? 'x';
    }
}
