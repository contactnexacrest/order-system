<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decisions (2026-10-27): whoever asks NexaCrest for money sends a
 * bill — the supplier (Step 7e advance, Step 10a balance) and the logistics
 * partners (CHA, transport, freight forwarder). One row per bill, then the
 * payment against it. docs/schema.sql Section CE.
 */
final class OrderVendorBillRepository
{
    public const SUPPLIER_LEGS = ['advance' => 'Advance', 'balance' => 'Balance'];

    public const LOGISTICS_SERVICES = [
        'cha'           => 'CHA charges',
        'transport'     => 'Transport',
        'cha_transport' => 'CHA + Transport (one bill)',
        'ocean_freight' => 'Ocean freight',
        'other'         => 'Other',
    ];

    public const PAYMENT_MODES = ['NEFT / RTGS', 'IMPS', 'UPI', 'Cheque'];

    /** @param array<string,mixed> $data */
    public static function create(int $orderId, string $vendorKind, array $data, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO order_vendor_bills (order_id, vendor_kind, supplier_po_id, logistics_partner_id, bill_for, bill_no, bill_date,
                taxable_inr, gst_inr, total_inr, note, bill_file_id, created_by)
             VALUES (:order_id, :vendor_kind, :supplier_po_id, :logistics_partner_id, :bill_for, :bill_no, :bill_date,
                :taxable_inr, :gst_inr, :total_inr, :note, :bill_file_id, :created_by)'
        )->execute([
            'order_id'             => $orderId,
            'vendor_kind'          => $vendorKind,
            'supplier_po_id'       => $data['supplier_po_id'] ?? null,
            'logistics_partner_id' => $data['logistics_partner_id'] ?? null,
            'bill_for'             => $data['bill_for'],
            'bill_no'              => $data['bill_no'],
            'bill_date'            => $data['bill_date'],
            'taxable_inr'          => $data['taxable_inr'],
            'gst_inr'              => $data['gst_inr'],
            'total_inr'            => round((float) $data['taxable_inr'] + (float) $data['gst_inr'], 2),
            'note'                 => $data['note'] ?? null,
            'bill_file_id'         => $data['bill_file_id'] ?? null,
            'created_by'           => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_vendor_bills WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> oldest first, with the logistics partner's name joined in */
    public static function forOrder(int $orderId, ?string $vendorKind = null): array
    {
        $sql = 'SELECT b.*, lp.partner_name, lp.service_type AS partner_service_type
                FROM order_vendor_bills b
                LEFT JOIN logistics_partners lp ON lp.id = b.logistics_partner_id
                WHERE b.order_id = :order_id';
        $params = ['order_id' => $orderId];
        if ($vendorKind !== null) {
            $sql .= ' AND b.vendor_kind = :kind';
            $params['kind'] = $vendorKind;
        }
        $stmt = Database::connection()->prepare($sql . ' ORDER BY b.bill_date, b.id');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function markPaid(int $id, string $paidAt, float $amount, string $mode, string $reference, int $userId): void
    {
        Database::connection()->prepare(
            'UPDATE order_vendor_bills SET paid_at = :paid_at, paid_amount_inr = :amount, payment_mode = :mode, payment_ref = :ref, paid_by = :user_id
             WHERE id = :id AND paid_at IS NULL'
        )->execute(['paid_at' => $paidAt, 'amount' => $amount, 'mode' => $mode, 'ref' => $reference, 'user_id' => $userId, 'id' => $id]);
    }

    /** Only an unpaid bill can be removed — a paid one is part of the money record. */
    public static function deleteUnpaid(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM order_vendor_bills WHERE id = :id AND paid_at IS NULL');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** @return array{0: float, 1: string|null} [total paid, latest paid date] on one supplier leg */
    public static function supplierLegPaid(int $supplierPoId, string $leg): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(paid_amount_inr), 0) AS paid, MAX(paid_at) AS last_paid
             FROM order_vendor_bills WHERE vendor_kind = 'supplier' AND supplier_po_id = :po AND bill_for = :leg AND paid_at IS NOT NULL"
        );
        $stmt->execute(['po' => $supplierPoId, 'leg' => $leg]);
        $row = $stmt->fetch();
        return [(float) $row['paid'], $row['last_paid'] !== null ? (string) $row['last_paid'] : null];
    }

    /** @return array<int, array<string,mixed>> every unpaid bill (supplier or logistics) on the order */
    public static function unpaidForOrder(int $orderId): array
    {
        return array_values(array_filter(self::forOrder($orderId), static fn (array $b): bool => $b['paid_at'] === null));
    }
}
