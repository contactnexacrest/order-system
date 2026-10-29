<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use App\Services\ZohoGuard;

/**
 * Zoho integration Stage 3 (2026-10-29) — what the app pays out, waiting
 * to go to Zoho Books: suppliers' and logistics partners' tax invoices
 * (bills), the payments made to them, and the order's expenses. Same rule
 * as the revenue side: in production, test and sample orders (and test or
 * sample suppliers) never go; in test, only TEST- orders do, and a bill or
 * payment only when its vendor's name starts with TEST- too, so the
 * clean-up script finds everything testing made. Written only by
 * CaSyncService.
 */
final class ZohoPurchaseRepository
{
    /**
     * The vendor behind each bill: the supplier of the bill's supplier PO,
     * or the logistics partner. Joined into every bill and payment query.
     */
    private const BILL_SELECT = "
        SELECT b.*, o.buyer_inquiry_ref, o.company_legal_name AS buyer_name,
               (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1
                OR COALESCE(s.is_test_data, 0) = 1 OR COALESCE(s.is_sample_data, 0) = 1) AS is_test_or_sample,
               COALESCE(s.id, lp.id) AS vendor_id,
               COALESCE(s.supplier_legal_name, lp.partner_name) AS vendor_name,
               COALESCE(s.gstin, lp.gstin) AS vendor_gstin,
               COALESCE(s.pan, lp.pan) AS vendor_pan,
               COALESCE(s.zoho_contact_id, lp.zoho_contact_id) AS vendor_zoho_contact_id
        FROM order_vendor_bills b
        JOIN orders o ON o.id = b.order_id
        JOIN clients c ON c.id = o.client_id
        LEFT JOIN order_supplier_po po ON po.id = b.supplier_po_id
        LEFT JOIN suppliers s ON s.id = po.supplier_id AND b.vendor_kind = 'supplier'
        LEFT JOIN logistics_partners lp ON lp.id = b.logistics_partner_id AND b.vendor_kind = 'logistics'";

    /** @return list<array<string,mixed>> tax invoices not yet in Books (dated on or after $startDate, when given) */
    public static function billsToSync(?string $startDate = null): array
    {
        $rows = Database::connection()->query(self::BILL_SELECT . " WHERE b.doc_kind = 'invoice' AND b.zoho_bill_id IS NULL ORDER BY b.bill_date, b.id")->fetchAll();
        return array_values(array_filter($rows, static fn (array $r): bool => self::allowed($r) && ($startDate === null || (string) $r['bill_date'] >= $startDate)));
    }

    public static function findBill(int $billId): ?array
    {
        $stmt = Database::connection()->prepare(self::BILL_SELECT . ' WHERE b.id = :id');
        $stmt->execute(['id' => $billId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The Books bill a payment counts towards: its own bill when that is a
     * tax invoice, or — for a payment on a proforma — the tax invoice that
     * replaced the proforma. Null while that bill is not in Books yet.
     */
    public static function targetBillFor(array $bill): ?array
    {
        if ($bill['doc_kind'] === 'invoice') {
            return $bill['zoho_bill_id'] !== null ? $bill : null;
        }
        $stmt = Database::connection()->prepare(self::BILL_SELECT . " WHERE b.replaces_bill_id = :id AND b.doc_kind = 'invoice' AND b.zoho_bill_id IS NOT NULL LIMIT 1");
        $stmt->execute(['id' => (int) $bill['id']]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> payments to vendors not yet in Books (paid on or after $startDate, when given), each with its bill */
    public static function paymentsToSync(?string $startDate = null): array
    {
        return array_values(array_filter(self::payments('p.zoho_payment_id IS NULL'), static fn (array $p): bool => $startDate === null || (string) $p['payment']['paid_at'] >= $startDate));
    }

    /** @return list<array<string,mixed>> payments in Books but not yet applied to a bill (advances on a proforma) */
    public static function heldPayments(): array
    {
        return self::payments('p.zoho_payment_id IS NOT NULL AND p.zoho_applied_at IS NULL');
    }

    /** @return list<array<string,mixed>> */
    private static function payments(string $where): array
    {
        $rows = Database::connection()->query("SELECT p.* FROM order_vendor_bill_payments p WHERE {$where} ORDER BY p.paid_at, p.id")->fetchAll();
        $out = [];
        foreach ($rows as $payment) {
            $bill = self::findBill((int) $payment['bill_id']);
            if ($bill !== null && self::allowed($bill)) {
                $out[] = ['payment' => $payment, 'bill' => $bill];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> order expenses not yet in Books (dated on or after $startDate, when given) */
    public static function expensesToSync(?string $startDate = null): array
    {
        $rows = Database::connection()->query(
            'SELECT e.*, o.buyer_inquiry_ref, o.company_legal_name AS buyer_name,
                    (o.is_test_data = 1 OR o.is_sample_data = 1 OR c.is_test_data = 1 OR c.is_sample_data = 1) AS is_test_or_sample
             FROM order_expenses e
             JOIN orders o ON o.id = e.order_id
             JOIN clients c ON c.id = o.client_id
             WHERE e.zoho_expense_id IS NULL
             ORDER BY e.expense_date, e.id'
        )->fetchAll();
        return array_values(array_filter($rows, static fn (array $r): bool => ($startDate === null || (string) $r['expense_date'] >= $startDate)
            && (ZohoGuard::isProduction()
                ? (int) $r['is_test_or_sample'] !== 1
                : str_starts_with((string) $r['buyer_name'], ZohoGuard::TEST_PREFIX))));
    }

    /** Zoho expense ids this app created — the expense import must not bring them back. */
    public static function isPushedExpense(string $zohoExpenseId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM order_expenses WHERE zoho_expense_id = :id');
        $stmt->execute(['id' => $zohoExpenseId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function setVendorContactId(string $vendorKind, int $vendorId, string $contactId): void
    {
        $table = $vendorKind === 'supplier' ? 'suppliers' : 'logistics_partners';
        Database::connection()->prepare("UPDATE {$table} SET zoho_contact_id = :zoho WHERE id = :id")->execute(['zoho' => $contactId, 'id' => $vendorId]);
    }

    public static function setBillSynced(int $billId, string $zohoBillId): void
    {
        Database::connection()->prepare('UPDATE order_vendor_bills SET zoho_bill_id = :zoho, zoho_synced_at = NOW() WHERE id = :id')->execute(['zoho' => $zohoBillId, 'id' => $billId]);
    }

    public static function setPaymentSynced(int $paymentId, string $zohoPaymentId, bool $applied): void
    {
        Database::connection()->prepare('UPDATE order_vendor_bill_payments SET zoho_payment_id = :zoho, zoho_synced_at = NOW(), zoho_applied_at = ' . ($applied ? 'NOW()' : 'NULL') . ' WHERE id = :id')
            ->execute(['zoho' => $zohoPaymentId, 'id' => $paymentId]);
    }

    public static function setPaymentApplied(int $paymentId): void
    {
        Database::connection()->prepare('UPDATE order_vendor_bill_payments SET zoho_applied_at = NOW() WHERE id = :id')->execute(['id' => $paymentId]);
    }

    public static function setExpenseSynced(int $expenseId, string $zohoExpenseId): void
    {
        Database::connection()->prepare('UPDATE order_expenses SET zoho_expense_id = :zoho, zoho_synced_at = NOW() WHERE id = :id')->execute(['zoho' => $zohoExpenseId, 'id' => $expenseId]);
    }

    /** The clean-up script removed this record from Books: forget it here too. */
    public static function forgetZohoRecord(string $kind, string $zohoId): void
    {
        $sql = [
            'contact' => ['UPDATE suppliers SET zoho_contact_id = NULL WHERE zoho_contact_id = :id', 'UPDATE logistics_partners SET zoho_contact_id = NULL WHERE zoho_contact_id = :id'],
            'bill' => ['UPDATE order_vendor_bills SET zoho_bill_id = NULL, zoho_synced_at = NULL WHERE zoho_bill_id = :id'],
            'vendorpayment' => ['UPDATE order_vendor_bill_payments SET zoho_payment_id = NULL, zoho_synced_at = NULL, zoho_applied_at = NULL WHERE zoho_payment_id = :id'],
            'expense' => ['UPDATE order_expenses SET zoho_expense_id = NULL, zoho_synced_at = NULL WHERE zoho_expense_id = :id'],
        ][$kind] ?? [];
        foreach ($sql as $statement) {
            Database::connection()->prepare($statement)->execute(['id' => $zohoId]);
        }
    }

    /** @return list<array{kind: string, id: string, label: string}> every Books record this database created on the purchase side (clean-up --from-sync-log) */
    public static function pushedRecords(): array
    {
        $pdo = Database::connection();
        $out = [];
        foreach ($pdo->query('SELECT p.zoho_payment_id, p.payment_ref, p.amount_inr FROM order_vendor_bill_payments p WHERE p.zoho_payment_id IS NOT NULL')->fetchAll() as $r) {
            $out[] = ['kind' => 'vendorpayment', 'id' => (string) $r['zoho_payment_id'], 'label' => "vendor payment {$r['payment_ref']} INR {$r['amount_inr']} (created by this database)"];
        }
        foreach ($pdo->query('SELECT zoho_expense_id, details, amount_inr FROM order_expenses WHERE zoho_expense_id IS NOT NULL')->fetchAll() as $r) {
            $out[] = ['kind' => 'expense', 'id' => (string) $r['zoho_expense_id'], 'label' => "expense {$r['details']} INR {$r['amount_inr']} (created by this database)"];
        }
        foreach ($pdo->query('SELECT zoho_bill_id, bill_no, total_inr FROM order_vendor_bills WHERE zoho_bill_id IS NOT NULL')->fetchAll() as $r) {
            $out[] = ['kind' => 'bill', 'id' => (string) $r['zoho_bill_id'], 'label' => "bill {$r['bill_no']} INR {$r['total_inr']} (created by this database)"];
        }
        foreach ($pdo->query('SELECT supplier_legal_name AS name, zoho_contact_id FROM suppliers WHERE zoho_contact_id IS NOT NULL UNION ALL SELECT partner_name, zoho_contact_id FROM logistics_partners WHERE zoho_contact_id IS NOT NULL')->fetchAll() as $r) {
            $out[] = ['kind' => 'contact', 'id' => (string) $r['zoho_contact_id'], 'label' => "vendor {$r['name']} (created by this database)"];
        }
        return $out;
    }

    private static function allowed(array $row): bool
    {
        if (ZohoGuard::isProduction()) {
            return (int) $row['is_test_or_sample'] !== 1;
        }
        return str_starts_with((string) $row['buyer_name'], ZohoGuard::TEST_PREFIX)
            && str_starts_with((string) ($row['vendor_name'] ?? ''), ZohoGuard::TEST_PREFIX);
    }
}
