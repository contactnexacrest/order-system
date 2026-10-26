<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderSupplierPoDocumentRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Services\FileUploadService;

/**
 * Owner request (2026-10-26, t4): public, no-login Supplier Action Link
 * for Step 7c — the supplier uploads their signed PO / acknowledgment
 * themselves, the same way the Buyer Action Link works for the buyer.
 * Authorization is the per-PO token
 * (OrderSupplierPoRepository::generateActionToken()/findByValidActionToken()).
 *
 * Deliberately shows nothing about the buyer or any other document: just
 * whose PO it is, a download of that Supplier PO once issued, what has
 * been received so far, and the upload.
 */
final class SupplierActionController
{
    private function poOrExpired(string $token): ?array
    {
        $supplierPo = OrderSupplierPoRepository::findByValidActionToken($token);
        if (!$supplierPo) {
            View::render('supplier_action/link_expired', [], 'layout/bare');
            return null;
        }
        return $supplierPo;
    }

    public function show(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $supplierPo = $this->poOrExpired($token);
        if (!$supplierPo) {
            return;
        }
        View::render('supplier_action/show', [
            'wrapClass' => 'intake-wrap',
            'issuedSupplierPo' => self::issuedSupplierPoDocument((int) $supplierPo['order_id']),
            'token' => $token,
            'supplierPo' => $supplierPo,
            'companyName' => CompanySettingsRepository::get('legal_name') ?: 'NexaCrest',
            'documents' => OrderSupplierPoDocumentRepository::forSupplierPo((int) $supplierPo['id']),
        ], 'layout/bare');
    }

    public function uploadAcknowledgment(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $supplierPo = $this->poOrExpired($token);
        if (!$supplierPo) {
            return;
        }
        $orderId = (int) $supplierPo['order_id'];
        $order = OrderRepository::find($orderId);
        try {
            $fileId = FileUploadService::handleUpload(
                'document',
                'supplier_po_ack',
                \App\Helpers\OrderStorage::forReceived($order, 'supplier_po'),
                null,
                $orderId,
                null,
                null,
                'Supplier (self-uploaded)',
                'Supplier PO acknowledgment (supplier-uploaded)'
            );
            $documentId = OrderSupplierPoDocumentRepository::attach((int) $supplierPo['id'], $fileId);
            AuditLogRepository::log(null, 'SUPPLIER_PO_DOCUMENT_UPLOADED_BY_SUPPLIER', 'order_supplier_po_documents', $documentId, null, null, null, 'Uploaded by the supplier via the Supplier Action Link.');
            NotificationRepository::notifyOrderTeam($orderId, 'supplier_po_ack_uploaded_by_supplier', 'The supplier uploaded their signed PO / acknowledgment for ' . ($supplierPo['supplier_po_reference'] ?? 'the Supplier PO') . ' on order ' . ($order['order_reference'] ?? "#{$orderId}") . ' — check it and confirm the supplier signed (Step 7d).');
            Flash::set('success', 'Thank you — your signed PO / acknowledgment has been received.');
        } catch (\Throwable $e) {
            Flash::set('error', $e->getMessage());
        }
        header("Location: /supplier-action/{$token}");
    }

    /**
     * Owner request (2026-10-26): the supplier downloads the Supplier PO
     * (generated in Step 7b) from the link to sign it — only once issued.
     */
    private static function issuedSupplierPoDocument(int $orderId): ?array
    {
        $latest = \App\Repositories\DocumentRepository::findLatestForOrderAndTypeCode($orderId, 'SUPPO');
        if (!$latest || empty($latest['pdf_file_id']) || !in_array($latest['status'], ['approved', 'sent'], true)) {
            return null;
        }
        return $latest;
    }

    public function downloadSupplierPo(array $params): void
    {
        $supplierPo = $this->poOrExpired((string) ($params['token'] ?? ''));
        if (!$supplierPo) {
            return;
        }
        $document = self::issuedSupplierPoDocument((int) $supplierPo['order_id']);
        \App\Helpers\StoredFileDownload::send($document ? (int) $document['pdf_file_id'] : null);
    }
}
