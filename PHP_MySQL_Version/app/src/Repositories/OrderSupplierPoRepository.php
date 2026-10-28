<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class OrderSupplierPoRepository
{
    /**
     * 90-item backlog "standalone order" follow-on: resolves the
     * supplier's identity details ONCE, at Supplier PO creation — never
     * re-read from `suppliers` again. Mirrors OrderRepository's own
     * resolveSnapshot() for incoterm/currency/port/preset. A supplier can
     * hold several types, so the ONE type that applies to this order is
     * passed in by the caller and recorded here.
     *
     * @return array<string,mixed>
     */
    private static function resolveSupplierSnapshot(int $supplierId, ?string $supplierType): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT supplier_legal_name, address, city, state, gstin, pan, contact_person, contact_person_phone,
                    contact_person_whatsapp, phone, email, whatsapp_number
             FROM suppliers WHERE id = :id'
        );
        $stmt->execute(['id' => $supplierId]);
        $supplier = $stmt->fetch() ?: [];

        return [
            'supplier_legal_name'              => $supplier['supplier_legal_name'] ?? null,
            'supplier_address'                 => $supplier['address'] ?? null,
            'supplier_city'                    => $supplier['city'] ?? null,
            'supplier_state'                   => $supplier['state'] ?? null,
            'supplier_gstin'                   => $supplier['gstin'] ?? null,
            'supplier_pan'                     => $supplier['pan'] ?? null,
            'supplier_contact_person'          => $supplier['contact_person'] ?? null,
            'supplier_contact_person_phone'    => $supplier['contact_person_phone'] ?? null,
            'supplier_contact_person_whatsapp' => $supplier['contact_person_whatsapp'] ?? null,
            'supplier_phone'                   => $supplier['phone'] ?? null,
            'supplier_email'                   => $supplier['email'] ?? null,
            'supplier_whatsapp_number'         => $supplier['whatsapp_number'] ?? null,
            'supplier_type'                    => $supplierType,
        ];
    }

    public static function create(int $orderId, int $supplierId, string $supplierPoReference, array $data, string $status = 'issued'): int
    {
        $pdo = Database::connection();
        $snapshot = self::resolveSupplierSnapshot($supplierId, $data['supplier_type'] ?? null);
        $stmt = $pdo->prepare(
            'INSERT INTO order_supplier_po
                (order_id, supplier_id, supplier_legal_name, supplier_address, supplier_city, supplier_state,
                 supplier_gstin, supplier_pan, supplier_contact_person, supplier_contact_person_phone,
                 supplier_contact_person_whatsapp, supplier_phone, supplier_email, supplier_whatsapp_number, supplier_type,
                 supplier_po_reference, material_stone_type, grade, surface_finish,
                 dimensions, dimensional_tolerance, quantity, unit, colour_reference, special_requirements,
                 unit_price_inr, basic_value_inr, gst_rate_pct, gst_amount_inr, total_payable_inr,
                 advance_pct, advance_amount_inr, balance_amount_inr,
                 delivery_location, required_delivery_date, packing_requirement, status)
             VALUES
                (:order_id, :supplier_id, :supplier_legal_name, :supplier_address, :supplier_city, :supplier_state,
                 :supplier_gstin, :supplier_pan, :supplier_contact_person, :supplier_contact_person_phone,
                 :supplier_contact_person_whatsapp, :supplier_phone, :supplier_email, :supplier_whatsapp_number, :supplier_type,
                 :ref, :material, :grade, :finish,
                 :dimensions, :tolerance, :quantity, :unit, :colour, :special,
                 :unit_price, :basic_value, :gst_rate, :gst_amount, :total_payable,
                 :advance_pct, :advance_amount, :balance_amount,
                 :delivery_location, :required_delivery_date, :packing_requirement, :status)'
        );
        $stmt->execute([
            'order_id'    => $orderId,
            'supplier_id' => $supplierId,
        ] + $snapshot + [
            'ref'         => $supplierPoReference,
            'status'      => $status,
            'material'    => $data['material_stone_type'] ?? null,
            'grade'       => $data['grade'] ?? 'Grade A',
            'finish'      => $data['surface_finish'] ?? null,
            'dimensions'  => $data['dimensions'] ?? null,
            'tolerance'   => $data['dimensional_tolerance'] ?? null,
            'quantity'    => $data['quantity'] ?: null,
            'unit'        => $data['unit'] ?? null,
            'colour'      => $data['colour_reference'] ?? null,
            'special'     => $data['special_requirements'] ?? null,
            'unit_price'  => $data['unit_price_inr'] ?: null,
            'basic_value' => $data['basic_value_inr'] ?: null,
            // Owner decision (2026-10-28): 0% GST is kept as 0, only a blank stays NULL.
            'gst_rate'    => ($data['gst_rate_pct'] ?? '') !== '' ? $data['gst_rate_pct'] : null,
            'gst_amount'  => ($data['gst_amount_inr'] ?? '') !== '' ? $data['gst_amount_inr'] : null,
            'total_payable' => $data['total_payable_inr'] ?: null,
            // Owner decision (2026-10-27): 0% advance is kept as 0, only a blank stays NULL.
            'advance_pct' => ($data['advance_pct'] ?? '') !== '' ? $data['advance_pct'] : null,
            'advance_amount' => ($data['advance_amount_inr'] ?? '') !== '' ? $data['advance_amount_inr'] : null,
            'balance_amount' => $data['balance_amount_inr'] ?: null,
            'delivery_location' => $data['delivery_location'] ?? null,
            'required_delivery_date' => $data['required_delivery_date'] ?: null,
            'packing_requirement' => $data['packing_requirement'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * 90-item backlog "standalone order" follow-on: every field this
     * returns, supplier identity included, now lives on the row itself —
     * no JOIN against `suppliers` needed or performed.
     */
    public static function findLatestForOrder(int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM order_supplier_po WHERE order_id = :order_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetch() ?: null;
    }

    public static function markSigned(int $id): void
    {
        Database::connection()->prepare(
            "UPDATE order_supplier_po SET status = 'signed', signed_at = NOW() WHERE id = :id"
        )->execute(['id' => $id]);
    }

    // External QA report R-11: the amount actually paid is now captured
    // alongside the date — it can differ from the PO's own planned
    // advance_amount_inr/balance_amount_inr (a partial payment, a
    // bank-charge deduction, a rate adjustment).
    public static function markAdvancePaid(int $id, string $date, int $userId, float $amount): void
    {
        Database::connection()->prepare(
            'UPDATE order_supplier_po SET advance_paid_at = :date, advance_paid_by = :user_id, advance_paid_amount_inr = :amount WHERE id = :id'
        )->execute(['date' => $date, 'user_id' => $userId, 'amount' => $amount, 'id' => $id]);
    }

    public static function markBalancePaid(int $id, string $date, int $userId, float $amount): void
    {
        Database::connection()->prepare(
            'UPDATE order_supplier_po SET balance_paid_at = :date, balance_paid_by = :user_id, balance_paid_amount_inr = :amount WHERE id = :id'
        )->execute(['date' => $date, 'user_id' => $userId, 'amount' => $amount, 'id' => $id]);
    }

    /**
     * @return array<int,array<string,mixed>> every supplier PO leg
     *   (advance/balance) paid on the given date, for the "today's
     *   supplier payments" report. External QA report R-11: amount is
     *   the actually-captured paid amount, falling back to the PO's
     *   planned amount for any row paid before that was captured.
     */
    public static function paidOnDate(string $date): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT sp.id, sp.order_id, sp.supplier_po_reference,
                    COALESCE(sp.advance_paid_amount_inr, sp.advance_amount_inr) AS advance_amount_inr, sp.advance_paid_at,
                    COALESCE(sp.balance_paid_amount_inr, sp.balance_amount_inr) AS balance_amount_inr, sp.balance_paid_at,
                    sp.supplier_legal_name, o.order_reference
             FROM order_supplier_po sp
             JOIN orders o ON o.id = sp.order_id
             WHERE sp.advance_paid_at = :date OR sp.balance_paid_at = :date2"
        );
        $stmt->execute(['date' => $date, 'date2' => $date]);
        return $stmt->fetchAll();
    }

    /**
     * Owner request (2026-10-26, t4): a no-login Supplier Action Link (like
     * the Buyer Action Link) so the supplier can upload their signed PO /
     * acknowledgment themselves. Only the sha256 hash authorizes; the
     * encrypted copy lets staff re-copy the live link from the order page.
     */
    public static function generateActionToken(int $id, int $ttlDays = 60): string
    {
        $rawToken = bin2hex(random_bytes(32));
        Database::connection()
            ->prepare('UPDATE order_supplier_po SET supplier_action_token_hash = :hash, supplier_action_token_plain = :plain, supplier_action_token_expires_at = :expires WHERE id = :id')
            ->execute([
                'hash'    => hash('sha256', $rawToken),
                'plain'   => \App\Services\EncryptionService::encrypt($rawToken),
                'expires' => date('Y-m-d H:i:s', time() + ($ttlDays * 86400)),
                'id'      => $id,
            ]);
        return $rawToken;
    }

    /** The public /supplier-action/{token} route's sole authorization check — the latest PO of a live order only. */
    public static function findByValidActionToken(string $rawToken): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT sp.* FROM order_supplier_po sp
               JOIN orders o ON o.id = sp.order_id
              WHERE sp.supplier_action_token_hash = :hash AND sp.supplier_action_token_expires_at > NOW()
                AND o.status IN ('active', 'disputed')
                AND sp.id = (SELECT MAX(id) FROM order_supplier_po WHERE order_id = sp.order_id)"
        );
        $stmt->execute(['hash' => hash('sha256', $rawToken)]);
        return $stmt->fetch() ?: null;
    }

    /** Owner request (2026-10-26, t4): the supplier did not acknowledge the PO — staff record why (Step 7c). */
    public static function markAckSkipped(int $id, int $userId, string $reason): void
    {
        Database::connection()->prepare(
            'UPDATE order_supplier_po SET ack_skipped_at = NOW(), ack_skipped_by = :user_id, ack_skip_reason = :reason WHERE id = :id'
        )->execute(['user_id' => $userId, 'reason' => $reason, 'id' => $id]);
    }

    public static function isAckSkipped(int $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT ack_skipped_at FROM order_supplier_po WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return !empty($stmt->fetchColumn());
    }
}
