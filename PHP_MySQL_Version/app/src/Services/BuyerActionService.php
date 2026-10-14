<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;
use App\Repositories\OrderBuyerPoDocumentRepository;
use App\Repositories\OrderOcAcknowledgmentRepository;

/**
 * 90-item backlog #75: the OC-acknowledgment and Buyer-PO-upload write
 * paths used to live only inside ClientPortalController, reachable only
 * from a logged-in portal session. This extracts the order-id-scoped core
 * of each (neither ever actually needed the client's own id — only the
 * order's) so both the session-gated client-portal route
 * (ClientPortalController::acknowledgeOc()/uploadBuyerPo()) and the new
 * no-login, tokenized BuyerActionController route can call the exact same
 * logic rather than maintaining two copies of it.
 */
final class BuyerActionService
{
    /** @return array{ok: bool, message: string} */
    public static function acknowledgeOc(int $orderId): array
    {
        $ack = OrderOcAcknowledgmentRepository::find($orderId);
        if (!$ack || $ack['acknowledged_at'] !== null) {
            return ['ok' => false, 'message' => 'There is nothing awaiting your acknowledgement on this order.'];
        }
        if (!StageGateService::isUnlocked($orderId, 4)) {
            return ['ok' => false, 'message' => 'This order is not currently awaiting acknowledgement.'];
        }

        if (!OrderOcAcknowledgmentRepository::markAcknowledged($orderId, 'client_portal', null, null)) {
            // QA-5 EML-06: lost the race to another acknowledgment path
            // (staff-recorded, or the 48h auto-confirm cron) between this
            // action's own pre-check above and this write.
            return ['ok' => true, 'message' => 'This order has already been acknowledged and is moving to production.'];
        }
        StageGateService::passAndUnlockNext($orderId, 4, null);
        return ['ok' => true, 'message' => 'Thank you — your acknowledgement has been recorded and your order is moving to production.'];
    }

    /** @return array{ok: bool, message: string} */
    public static function uploadBuyerPo(array $order): array
    {
        $orderId = (int) $order['id'];
        try {
            $fileId = FileUploadService::handleUpload(
                'document',
                'buyer_po_copy',
                'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/buyer_po',
                (int) $order['client_id'],
                $orderId,
                null,
                null,
                'Client (self-uploaded)',
                'Buyer PO copy (client-uploaded)'
            );
            OrderBuyerPoDocumentRepository::attach($orderId, $fileId);
            AuditLogRepository::log(null, 'BUYER_PO_UPLOADED_BY_CLIENT', 'orders', $orderId, null, null, null, 'Buyer PO copy uploaded by the client via the client portal.');
            return ['ok' => true, 'message' => 'Your Buyer PO copy has been uploaded.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
