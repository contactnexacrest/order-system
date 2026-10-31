<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section BT — export process step 8a: who pays the
 * 3rd-party pre-shipment inspection fee for an order, and how much.
 * At most one row per order, created lazily the first time it's
 * recorded — most orders never need this tracked at all (most goods
 * either don't need 3rd-party inspection, or the client pays directly).
 */
final class OrderInspectionFeeRepository
{
    public static function find(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_inspection_fees WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{fee_payer:string, fee_amount:?float, agency_name?:?string, agency_invoice_no?:?string, cost_entry_id:?int, recorded_by:?int} $fields
     */
    public static function upsert(int $orderId, array $fields): void
    {
        Database::connection()->prepare(
            'INSERT INTO order_inspection_fees (order_id, fee_payer, fee_amount, agency_name, agency_invoice_no, cost_entry_id, recorded_by)
             VALUES (:order_id, :fee_payer, :fee_amount, :agency_name, :agency_invoice_no, :cost_entry_id, :recorded_by)
             ON DUPLICATE KEY UPDATE
                fee_payer = VALUES(fee_payer),
                fee_amount = VALUES(fee_amount),
                agency_name = VALUES(agency_name),
                agency_invoice_no = VALUES(agency_invoice_no),
                cost_entry_id = VALUES(cost_entry_id),
                recorded_by = VALUES(recorded_by)'
        )->execute([
            'order_id' => $orderId,
            'fee_payer' => $fields['fee_payer'],
            'fee_amount' => $fields['fee_amount'],
            'agency_name' => $fields['agency_name'] ?? null,
            'agency_invoice_no' => $fields['agency_invoice_no'] ?? null,
            'cost_entry_id' => $fields['cost_entry_id'],
            'recorded_by' => $fields['recorded_by'],
        ]);
    }
}
