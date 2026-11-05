<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderQualityDocumentRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\FileUploadService;

/**
 * Owner decision D-257 Phase 0B (5 Nov 2026): the order page's quality
 * documents checklist — each document a line's category asks for is
 * pending, received or approved, with its file. Internal only (never on
 * the client portal). Port: orderQualityDocumentController.js.
 */
final class OrderQualityDocumentController
{
    public function save(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $back = "Location: /orders/{$orderId}#quality-documents";
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $documentName = trim((string) ($_POST['document_name'] ?? ''));
        $status = (string) ($_POST['status'] ?? '');
        $note = trim((string) ($_POST['note'] ?? ''));
        $doc = OrderQualityDocumentRepository::required($orderId, $categoryId, $documentName);
        if ($doc === null) {
            Flash::set('error', 'This order does not ask for that quality document.');
            header($back);
            return;
        }
        if (!array_key_exists($status, OrderQualityDocumentRepository::STATUSES)) {
            Flash::set('error', $doc['document_name'] . ': choose Pending, Received or Approved.');
            header($back);
            return;
        }
        if (mb_strlen($note) > 255) {
            Flash::set('error', $doc['document_name'] . ': the note can be at most 255 characters.');
            header($back);
            return;
        }
        $hasFile = isset($_FILES['file']) && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($status !== 'pending' && !$hasFile && $doc['file_store_id'] === null) {
            Flash::set('error', $doc['document_name'] . ': attach the document before marking it ' . strtolower(OrderQualityDocumentRepository::STATUSES[$status]) . '.');
            header($back);
            return;
        }
        $userId = (int) AuthService::currentUserId();
        $fileId = null;
        if ($hasFile) {
            try {
                $fileId = FileUploadService::handleUpload('file', 'quality_document', \App\Helpers\OrderStorage::folder($order, 'supplier_po', 'quality-documents'),
                    null, $orderId, $userId, null, null, $doc['document_name']);
            } catch (\Throwable $e) {
                Flash::setErrorFromException($e);
                header($back);
                return;
            }
        }
        OrderQualityDocumentRepository::save($orderId, $doc['category_id'], $doc['document_name'], $status, $fileId, $note !== '' ? $note : null, $userId);
        AuditLogRepository::log($userId, 'QUALITY_DOCUMENT_UPDATED', 'orders', $orderId, $doc['document_name'],
            $doc['status'], $status . ($fileId !== null ? ' (file attached)' : ''));
        Flash::set('success', $doc['document_name'] . ' marked ' . strtolower(OrderQualityDocumentRepository::STATUSES[$status]) . '.');
        header($back);
    }
}
