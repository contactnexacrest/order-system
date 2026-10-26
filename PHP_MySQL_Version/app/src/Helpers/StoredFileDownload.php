<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Repositories\FileStoreRepository;

/**
 * Streams one file_store file as a download, or a plain 404. Used by the
 * no-login Buyer/Supplier Action Link pages for the one document each may
 * download (owner request 2026-10-26).
 */
final class StoredFileDownload
{
    public static function send(?int $fileId): void
    {
        $file = $fileId ? FileStoreRepository::find($fileId) : null;
        if (!$file || !is_file($file['server_path'])) {
            http_response_code(404);
            echo 'Document not found.';
            return;
        }
        $safeDownloadName = str_replace(['/', '\\'], '-', $file['original_filename']);
        $safeDownloadName = preg_replace('/[\x00-\x1F\x7F"]/', '', $safeDownloadName) ?? $safeDownloadName;
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        header('Content-Length: ' . (string) $file['file_size_bytes']);
        readfile($file['server_path']);
    }
}
