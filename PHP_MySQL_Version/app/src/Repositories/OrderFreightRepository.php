<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderFreightRepository
{
    public static function upsert(int $orderId, array $data): void
    {
        // gst_treatment is deliberately never written here (dropped along
        // with its dead admin dropdown): the FDN is always a pure cost
        // reimbursement, never a taxable service supply, so there is
        // nothing left for this column to configure. The column itself is
        // left in the schema rather than dropped, same as
        // freight_cleared_at/freight_cleared_by above — removing a column
        // is a real migration, not a code change.
        Database::connection()->prepare(
            'INSERT INTO order_freight
                (order_id, confirmed_freight_rate, insurance_amount, freight_forwarder_name, freight_forwarder_contact, freight_forwarder_partner_id)
             VALUES (:order_id, :rate, :insurance, :forwarder_name, :forwarder_contact, :forwarder_partner_id)
             ON DUPLICATE KEY UPDATE
                freight_forwarder_partner_id = VALUES(freight_forwarder_partner_id),
                confirmed_freight_rate = VALUES(confirmed_freight_rate),
                insurance_amount = VALUES(insurance_amount),
                freight_forwarder_name = VALUES(freight_forwarder_name),
                freight_forwarder_contact = VALUES(freight_forwarder_contact)'
        )->execute([
            'order_id'          => $orderId,
            'rate'              => $data['confirmed_freight_rate'] ?: null,
            'insurance'         => $data['insurance_amount'] ?: null,
            'forwarder_name'    => $data['freight_forwarder_name'] ?? null,
            'forwarder_contact' => $data['freight_forwarder_contact'] ?? null,
            'forwarder_partner_id' => $data['freight_forwarder_partner_id'] ?? null,
        ]);
    }

    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_freight WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function setFdnDocumentId(int $orderId, int $documentId): void
    {
        Database::connection()->prepare(
            'UPDATE order_freight SET fdn_document_id = :document_id WHERE order_id = :order_id'
        )->execute(['document_id' => $documentId, 'order_id' => $orderId]);
    }

    /**
     * NOT the canonical "freight payment cleared" flag — that's
     * order_payment_status.freight_cleared_at (OrderPaymentStatusRepository
     * ::markFreightCleared()), which is what the Stage 6->7 gate and the
     * CI's Section B payment settlement both read (matching how advance/
     * balance clearing lives on order_payment_status, not on the order
     * itself). This column exists from the original Phase A schema design
     * and is kept for a future per-shipment freight-reconciliation view,
     * but the controller does not write it — left here rather than
     * dropped, since removing a column is a real migration, not a code
     * change, and no code currently depends on it being NULL vs set.
     */
    public static function markCleared(int $orderId, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE order_freight SET freight_cleared_at = NOW(), freight_cleared_by = :user_id WHERE order_id = :order_id'
        )->execute(['user_id' => $userId, 'order_id' => $orderId]);
    }
}
