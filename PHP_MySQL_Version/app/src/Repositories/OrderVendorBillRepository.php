<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * Owner decisions (2026-10-27): whoever asks NexaCrest for money sends a
 * bill — the supplier (Step 7e advance, Step 10a balance) and the logistics
 * partners (CHA, transport, freight forwarder). One row per bill, then the
 * payments against it. docs/schema.sql Sections CE and CH.
 *
 * Owner decision (2026-10-28, Option B): a bill is a Proforma (an advance
 * request — asks for requested_amount_inr, not a cost) or a Tax invoice (the
 * real bill — the cost). A tax invoice can replace an earlier proforma from
 * the same vendor, so what was paid on the proforma counts towards it. A
 * bill can take several payments (order_vendor_bill_payments).
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

    /** Owner decision (2026-10-27): a logistics bill is an Advance (payable any time) or the Final bill (on CFR/CIF, after Step 9d). */
    public const LOGISTICS_PAYMENT_KINDS = ['advance' => 'Advance', 'final' => 'Final'];

    /** Owner decision (2026-10-28, Option B). */
    public const DOC_KINDS = ['proforma' => 'Proforma', 'invoice' => 'Tax invoice'];

    public const PAYMENT_MODES = ['NEFT / RTGS', 'IMPS', 'UPI', 'Cheque'];

    /** @param array<string,mixed> $data */
    public static function create(int $orderId, string $vendorKind, array $data, int $userId): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO order_vendor_bills (order_id, vendor_kind, supplier_po_id, logistics_partner_id, bill_for, payment_kind, doc_kind, requested_amount_inr, replaces_bill_id,
                bill_no, bill_date, taxable_inr, gst_inr, gst_reverse_charge, total_inr, note, bill_file_id, created_by)
             VALUES (:order_id, :vendor_kind, :supplier_po_id, :logistics_partner_id, :bill_for, :payment_kind, :doc_kind, :requested_amount_inr, :replaces_bill_id,
                :bill_no, :bill_date, :taxable_inr, :gst_inr, :gst_reverse_charge, :total_inr, :note, :bill_file_id, :created_by)'
        )->execute([
            'order_id'             => $orderId,
            'vendor_kind'          => $vendorKind,
            'supplier_po_id'       => $data['supplier_po_id'] ?? null,
            'logistics_partner_id' => $data['logistics_partner_id'] ?? null,
            'bill_for'             => $data['bill_for'],
            'payment_kind'         => $data['payment_kind'] ?? 'final',
            'doc_kind'             => $data['doc_kind'] ?? 'invoice',
            'requested_amount_inr' => $data['requested_amount_inr'] ?? null,
            'replaces_bill_id'     => $data['replaces_bill_id'] ?? null,
            'bill_no'              => $data['bill_no'],
            'bill_date'            => $data['bill_date'],
            'taxable_inr'          => $data['taxable_inr'],
            'gst_inr'              => $data['gst_inr'],
            'gst_reverse_charge'   => (int) ($data['gst_reverse_charge'] ?? 0),
            'total_inr'            => round((float) $data['taxable_inr'] + (float) $data['gst_inr'], 2),
            'note'                 => $data['note'] ?? null,
            'bill_file_id'         => $data['bill_file_id'] ?? null,
            'created_by'           => $userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        self::refreshOrder($orderId);
        return $id;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM order_vendor_bills WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        foreach (self::forOrder((int) $row['order_id']) as $bill) {
            if ((int) $bill['id'] === $id) {
                return $bill;
            }
        }
        return null;
    }

    /**
     * Oldest first, with the logistics partner's name joined in, each bill's
     * payments, and its worked-out figures:
     *  - payments / own_paid: the payments made against this bill itself;
     *  - credited: own_paid, plus — for a tax invoice — what was paid on the
     *    proforma(s) it replaces;
     *  - amount_due: a proforma's requested amount, a tax invoice's total;
     *  - remaining: what is still owed on it (0 once a proforma is replaced);
     *  - replaced_by: the tax invoice's bill no., when a proforma is replaced;
     *  - cost_inr: what it adds to the order's cost — a tax invoice's total,
     *    or what was paid on a proforma not (yet) replaced by an invoice.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function forOrder(int $orderId, ?string $vendorKind = null): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT b.*, lp.partner_name, lp.service_type AS partner_service_type
             FROM order_vendor_bills b
             LEFT JOIN logistics_partners lp ON lp.id = b.logistics_partner_id
             WHERE b.order_id = :order_id
             ORDER BY b.bill_date, b.id'
        );
        $stmt->execute(['order_id' => $orderId]);
        $bills = self::enrich($stmt->fetchAll(), self::paymentsForOrder($orderId));
        return $vendorKind === null ? $bills : array_values(array_filter($bills, static fn (array $b): bool => $b['vendor_kind'] === $vendorKind));
    }

    /** @return array<int, array<int, array<string,mixed>>> payments by bill id, oldest first */
    private static function paymentsForOrder(int $orderId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.* FROM order_vendor_bill_payments p
             JOIN order_vendor_bills b ON b.id = p.bill_id
             WHERE b.order_id = :order_id
             ORDER BY p.paid_at, p.id'
        );
        $stmt->execute(['order_id' => $orderId]);
        $byBill = [];
        foreach ($stmt->fetchAll() as $p) {
            $byBill[(int) $p['bill_id']][] = $p;
        }
        return $byBill;
    }

    /**
     * @param array<int, array<string,mixed>> $bills
     * @param array<int, array<int, array<string,mixed>>> $paymentsByBill
     * @return array<int, array<string,mixed>>
     */
    private static function enrich(array $bills, array $paymentsByBill): array
    {
        $byId = [];
        foreach ($bills as $b) {
            $b['doc_kind'] = $b['doc_kind'] ?? 'invoice';
            $b['payments'] = $paymentsByBill[(int) $b['id']] ?? [];
            $b['own_paid'] = round(array_sum(array_map(static fn (array $p): float => (float) $p['amount_inr'], $b['payments'])), 2);
            $byId[(int) $b['id']] = $b;
        }
        $replacedBy = [];
        foreach ($byId as $b) {
            if ($b['doc_kind'] === 'invoice' && !empty($b['replaces_bill_id']) && isset($byId[(int) $b['replaces_bill_id']])) {
                $replacedBy[(int) $b['replaces_bill_id']] = $b;
            }
        }
        foreach ($byId as $id => $b) {
            $replacing = $replacedBy[$id] ?? null;
            $b['replaced_by'] = $replacing !== null ? (string) $replacing['bill_no'] : null;
            $b['replaced_by_id'] = $replacing !== null ? (int) $replacing['id'] : null;
            $b['replaces_bill_no'] = !empty($b['replaces_bill_id']) && isset($byId[(int) $b['replaces_bill_id']]) ? (string) $byId[(int) $b['replaces_bill_id']]['bill_no'] : null;
            $b['carried_payments'] = [];
            $b['carried_paid'] = 0.0;
            $credited = $b['own_paid'];
            if ($b['doc_kind'] === 'invoice' && $b['replaces_bill_no'] !== null) {
                $proforma = $byId[(int) $b['replaces_bill_id']];
                $b['carried_payments'] = $proforma['payments'];
                $b['carried_paid'] = (float) $proforma['own_paid'];
                $credited += $proforma['own_paid'];
            }
            $b['credited'] = round($credited, 2);
            $b['amount_due'] = $b['doc_kind'] === 'proforma' ? (float) ($b['requested_amount_inr'] ?? $b['total_inr']) : (float) $b['total_inr'];
            $b['remaining'] = $replacing !== null ? 0.0 : round(max(0.0, $b['amount_due'] - $b['credited']), 2);
            $b['settled'] = $b['remaining'] <= 0.004;
            $b['cost_inr'] = $b['doc_kind'] === 'invoice' ? (float) $b['total_inr'] : ($replacing !== null ? 0.0 : $b['own_paid']);
            $byId[$id] = $b;
        }
        return array_values($byId);
    }

    /**
     * Owner request (3 Nov 2026): every payment form shows, read-only, what is to be paid now and how
     * the app worked it out — "To pay now: ₹458,884.30 (bill ₹655,549.00 − advance paid on proforma
     * PI-7 ₹196,664.70)" — so staff can cross-check it with what the vendor asks for.
     *
     * @param array<string,mixed> $b an enriched bill
     * @return array{amount:float,working:string}
     */
    public static function toPayNow(array $b): array
    {
        $m = static fn (float $v): string => '₹' . number_format($v, 2);
        $isProforma = ($b['doc_kind'] ?? 'invoice') === 'proforma';
        $base = $isProforma && $b['requested_amount_inr'] !== null ? 'asked now on the proforma' : ($isProforma ? 'proforma total' : 'bill total');
        $parts = [$base . ' ' . $m((float) $b['amount_due'])];
        if ((float) ($b['carried_paid'] ?? 0) > 0.004) {
            $parts[] = 'advance paid on proforma ' . $b['replaces_bill_no'] . ' ' . $m((float) $b['carried_paid']);
        }
        if ((float) $b['own_paid'] > 0.004) {
            $parts[] = 'already paid on this bill ' . $m((float) $b['own_paid']);
        }
        return ['amount' => (float) $b['remaining'], 'working' => implode(' − ', $parts)];
    }

    /**
     * Owner request (3 Nov 2026): the supplier step's own "To pay now", worked out from the Supplier PO
     * and what was already paid on its bills. Advance (7e): planned advance − paid on the advance leg.
     * Balance (10a): PO total − advance paid − balance already paid. Null when there is nothing planned.
     *
     * @param array<int,array<string,mixed>> $bills enriched bills of the order
     * @param array<string,mixed> $supplierPo
     * @return array{amount:float,working:string}|null
     */
    public static function supplierLegToPayNow(array $bills, array $supplierPo, string $leg): ?array
    {
        $paid = ['advance' => 0.0, 'balance' => 0.0];
        foreach ($bills as $b) {
            if (($b['vendor_kind'] ?? '') !== 'supplier' || (int) $b['supplier_po_id'] !== (int) $supplierPo['id']) {
                continue;
            }
            foreach ($b['payments'] as $p) {
                $paid[($p['leg'] ?? '') === 'balance' ? 'balance' : 'advance'] += (float) $p['amount_inr'];
            }
        }
        $m = static fn (float $v): string => '₹' . number_format($v, 2);
        if ($leg === 'advance') {
            $planned = (float) ($supplierPo['advance_amount_inr'] ?? 0);
            if ($planned <= 0) {
                return null;
            }
            return ['amount' => round(max(0.0, $planned - $paid['advance']), 2), 'working' => 'planned advance ' . $m($planned) . ' − already paid ' . $m(round($paid['advance'], 2))];
        }
        $total = (float) ($supplierPo['total_payable_inr'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        return ['amount' => round(max(0.0, $total - $paid['advance'] - $paid['balance']), 2),
            'working' => 'PO total ' . $m($total) . ' − advance paid ' . $m(round($paid['advance'], 2)) . ' − balance already paid ' . $m(round($paid['balance'], 2))];
    }

    /**
     * The "Total cost" row under a bills table. Owner report (3 Nov 2026): "Paid" showed only the
     * payments on the bills listed, so a tax invoice that replaced a proforma listed elsewhere (the
     * 7e advance proforma, shown in 7e, its invoice in 10a) lost the advance paid on it — ₹4,58,884.30
     * shown where ₹6,55,549.00 was paid. Paid now counts every payment towards the listed bills: each
     * bill's own payments, plus what was paid on a replaced proforma that is not itself in the list
     * (when it is, its own payments are already counted — never twice). Due is what is still owed.
     *
     * @param array<int,array<string,mixed>> $bills enriched bills (forOrder)
     * @return array{cost:float,paid:float,due:float}
     */
    public static function totalsFor(array $bills): array
    {
        $listed = [];
        foreach ($bills as $b) {
            $listed[(int) $b['id']] = true;
        }
        $cost = $paid = $due = 0.0;
        foreach ($bills as $b) {
            $cost += (float) $b['cost_inr'];
            $paid += (float) $b['own_paid'];
            if (!empty($b['replaces_bill_id']) && !isset($listed[(int) $b['replaces_bill_id']])) {
                $paid += (float) ($b['carried_paid'] ?? 0.0);
            }
            $due += (float) $b['remaining'];
        }
        return ['cost' => round($cost, 2), 'paid' => round($paid, 2), 'due' => $due > 0.004 ? round($due, 2) : 0.0];
    }

    /**
     * Records one payment against a bill. $leg is the supplier step it was
     * paid from ('advance' — Step 7e, 'balance' — Step 10a); null for a
     * logistics bill.
     */
    public static function addPayment(int $billId, ?string $leg, string $paidAt, float $amount, string $mode, string $reference, int $userId): void
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO order_vendor_bill_payments (bill_id, leg, paid_at, amount_inr, payment_mode, payment_ref, paid_by)
             VALUES (:bill_id, :leg, :paid_at, :amount, :mode, :ref, :user_id)'
        )->execute(['bill_id' => $billId, 'leg' => $leg, 'paid_at' => $paidAt, 'amount' => $amount, 'mode' => $mode, 'ref' => $reference, 'user_id' => $userId]);
        $stmt = $pdo->prepare('SELECT order_id FROM order_vendor_bills WHERE id = :id');
        $stmt->execute(['id' => $billId]);
        self::refreshOrder((int) $stmt->fetchColumn());
    }

    /**
     * Keeps each bill's summary columns in step with its payments: paid_at is
     * the day it was settled (NULL while anything is still owed),
     * paid_amount_inr the total paid on the bill itself, payment_mode /
     * payment_ref / paid_by the latest payment's.
     */
    public static function refreshOrder(int $orderId): void
    {
        $pdo = Database::connection();
        $update = $pdo->prepare(
            'UPDATE order_vendor_bills SET paid_at = :paid_at, paid_amount_inr = :paid, payment_mode = :mode, payment_ref = :ref, paid_by = :paid_by WHERE id = :id'
        );
        $bills = self::forOrder($orderId);
        $billNo = [];
        foreach ($bills as $b) {
            $billNo[(int) $b['id']] = $b;
        }
        foreach ($bills as $b) {
            $last = $b['payments'] !== [] ? $b['payments'][count($b['payments']) - 1] : null;
            $settledOn = null;
            if ($b['settled']) {
                $dates = array_map(static fn (array $p): string => (string) $p['paid_at'], array_merge($b['payments'], $b['carried_payments']));
                if ($b['replaced_by_id'] !== null) {
                    $dates[] = (string) $billNo[$b['replaced_by_id']]['bill_date'];
                }
                $settledOn = $dates !== [] ? max($dates) : null;
            }
            $update->execute([
                'paid_at' => $settledOn,
                'paid' => $b['own_paid'] > 0 ? $b['own_paid'] : null,
                'mode' => $last['payment_mode'] ?? null,
                'ref' => $last['payment_ref'] ?? null,
                'paid_by' => $last['paid_by'] ?? null,
                'id' => (int) $b['id'],
            ]);
        }
    }

    /** Only a bill with no payment on it can be removed — a paid one is part of the money record. */
    public static function deleteUnpaid(int $id): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT order_id FROM order_vendor_bills WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $orderId = $stmt->fetchColumn();
        $stmt = $pdo->prepare('DELETE FROM order_vendor_bills WHERE id = :id AND NOT EXISTS (SELECT 1 FROM order_vendor_bill_payments p WHERE p.bill_id = :id2)');
        $stmt->execute(['id' => $id, 'id2' => $id]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        self::refreshOrder((int) $orderId);
        return true;
    }

    /** @return array{0: float, 1: string|null} [total paid, latest paid date] on one supplier leg (the step the payments were made from) */
    public static function supplierLegPaid(int $supplierPoId, string $leg): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT COALESCE(SUM(p.amount_inr), 0) AS paid, MAX(p.paid_at) AS last_paid
             FROM order_vendor_bill_payments p
             JOIN order_vendor_bills b ON b.id = p.bill_id
             WHERE b.vendor_kind = 'supplier' AND b.supplier_po_id = :po AND p.leg = :leg"
        );
        $stmt->execute(['po' => $supplierPoId, 'leg' => $leg]);
        $row = $stmt->fetch();
        return [(float) $row['paid'], $row['last_paid'] !== null ? (string) $row['last_paid'] : null];
    }

    /**
     * The proformas a new tax invoice from the same vendor can replace — not
     * already replaced.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function replaceableProformas(int $orderId, string $vendorKind): array
    {
        return array_values(array_filter(
            self::forOrder($orderId, $vendorKind),
            static fn (array $b): bool => $b['doc_kind'] === 'proforma' && $b['replaced_by'] === null
        ));
    }

    /** @return array<int, array<string,mixed>> every bill (supplier or logistics) on the order that still has something owed */
    public static function unpaidForOrder(int $orderId): array
    {
        return array_values(array_filter(self::forOrder($orderId), static fn (array $b): bool => !$b['settled']));
    }
}
