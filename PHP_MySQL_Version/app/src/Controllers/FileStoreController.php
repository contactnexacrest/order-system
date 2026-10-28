<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\FileStoreRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\OrderAccessService;
use App\Services\PermissionService;

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

        // QA Round 5, R5-21: this route used to serve any file by its id.
        // A removed file stays removed, an order's file needs the same
        // access as the order page (assignment / view_all_orders, archived
        // rule), and CAFIN needs view_cafin — the same gates as everywhere
        // else these files are shown.
        if ((int) ($file['is_active'] ?? 1) !== 1) {
            http_response_code(404);
            echo 'This file has been removed.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!empty($file['order_id'])) {
            $order = OrderRepository::find((int) $file['order_id']);
            $denied = $order ? OrderAccessService::denialReason($order, (int) $user['id'], $roleId) : null;
            if ($denied !== null) {
                http_response_code(403);
                echo $denied;
                return;
            }
        }
        if (FileStoreRepository::documentTypeCodeFor($fileId) === 'CAFIN' && !PermissionService::can((int) $user['id'], $roleId, 'view_cafin')) {
            http_response_code(403);
            echo 'You do not have permission to view this document.';
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
