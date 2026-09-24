<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Repositories\OrderCommentRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\FileUploadService;
use App\Services\OrderCommentService;

/** docs/schema.sql Section AI — staff side of the order progress chat. */
final class OrderCommentController
{
    public function post(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $body = trim((string) ($_POST['body'] ?? ''));

        try {
            $order = OrderRepository::find($orderId);
            if (!$order) {
                throw new \RuntimeException("Order {$orderId} not found");
            }
            $subPath = 'clients/' . FileUploadService::sanitizePathSegment((string) $order['client_unique_number'])
                . '/' . FileUploadService::sanitizePathSegment((string) $order['order_reference'])
                . '/comment_attachments';
            $fileIds = FileUploadService::handleMultipleUploads(
                'attachments',
                'order_comment_media',
                $subPath,
                null,
                $orderId,
                (int) $user['id'],
                'Staff',
                'Order Update Attachment'
            );
            OrderCommentService::postAsStaff($orderId, (int) $user['id'], $body !== '' ? $body : null, $fileIds);
            Flash::set('success', 'Posted — the client has been emailed.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }

        header("Location: /orders/{$orderId}#order-updates");
    }

    /** Staff download of a comment attachment — same permission as any other document download. */
    public function downloadAttachment(array $params): void
    {
        $orderId = (int) $params['id'];
        $fileId = (int) $params['fileId'];
        $file = OrderCommentRepository::findAttachmentForOrder($fileId, $orderId);
        if (!$file || !is_file($file['server_path'])) {
            http_response_code(404);
            echo 'File not found.';
            return;
        }
        $safeDownloadName = preg_replace('/[\x00-\x1F\x7F"\/\\\\]/', '', $file['original_filename']) ?? $file['original_filename'];
        header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
        header('Content-Length: ' . filesize($file['server_path']));
        readfile($file['server_path']);
    }
}
