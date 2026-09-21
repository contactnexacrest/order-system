<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\FileStoreRepository;

/**
 * Real gap this closes: every "received" upload (dispute_document,
 * amendment_signed_copy, buyer_approval, and now buyer_po_copy/
 * supplier_po_ack) had a working upload path but no download path at
 * all anywhere in the app — staff could attach evidence but never
 * retrieve it again. One generic, permission-gated download route for
 * any file_store row, mirroring DocumentController::download()'s
 * header handling exactly.
 */
final class FileStoreController
{
    public function download(array $params): void
    {
        $fileId = (int) $params['id'];
        $file = FileStoreRepository::find($fileId);
        if (!$file || !is_file($file['server_path'])) {
            http_response_code(404);
            echo 'File is missing from storage.';
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
