<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\OrderStorage;
use App\Repositories\AuditLogRepository;
use App\Repositories\LogisticsPartnerRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderSupplierPoRepository;
use App\Repositories\OrderVendorBillRepository;
use App\Services\AuthService;
use App\Services\CaFyLockGuard;
use App\Services\FileUploadService;
use App\Services\StageGateService;

/**
 * Owner decisions (2026-10-27): bill first, then pay. The supplier's bills
 * (Step 7e advance, Step 10a balance) and the logistics partners' bills
 * (CHA, transport, freight forwarder — Group C, every incoterm). On CFR/CIF
 * orders a logistics bill can be paid only after the buyer's Debit Note
 * payment has cleared (Step 9d). docs/schema.sql Section CE.
 */
final class VendorBillController
{
    /** Anchors on the order page each kind of bill returns to. */
    private const ANCHORS = ['advance' => 'step-7e-supplier-advance-paid', 'balance' => 'step-10a-supplier-balance-paid', 'logistics' => 'logistics-bills'];

    private function back(int $orderId, string $anchor, string $type, string $message): void
    {
        Flash::set($type, $message);
        header("Location: /orders/{$orderId}#{$anchor}");
    }

    /**
     * The bill fields both kinds share: number, date, taxable value, GST and the bill itself.
     *
     * @return array<string,mixed>|string the cleaned fields, or an error message
     */
    private function billFields(): array|string
    {
        $billNo = trim((string) ($_POST['bill_no'] ?? ''));
        if ($billNo === '') {
            return 'Enter the bill / invoice number.';
        }
        $billDate = trim((string) ($_POST['bill_date'] ?? ''));
        if (!self::validDate($billDate) || $billDate > date('Y-m-d')) {
            return 'Enter the bill date (not in the future).';
        }
        $taxable = trim((string) ($_POST['taxable_inr'] ?? ''));
        if (!is_numeric($taxable) || (float) $taxable <= 0) {
            return 'Enter the taxable value of the bill (₹).';
        }
        $gst = trim((string) ($_POST['gst_inr'] ?? ''));
        if ($gst === '') {
            $gst = '0';
        }
        if (!is_numeric($gst) || (float) $gst < 0) {
            return 'Enter the GST on the bill (₹), or 0.';
        }
        if (empty($_FILES['bill_file']['name'])) {
            return 'Attach the bill / invoice.';
        }
        return [
            'bill_no' => mb_substr($billNo, 0, 100),
            'bill_date' => $billDate,
            'taxable_inr' => round((float) $taxable, 2),
            'gst_inr' => round((float) $gst, 2),
            'note' => trim((string) ($_POST['note'] ?? '')) !== '' ? mb_substr(trim((string) $_POST['note']), 0, 500) : null,
        ];
    }

    private static function validDate(string $date): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public function addSupplierBill(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        $leg = (string) ($_POST['bill_for'] ?? '');
        $anchor = self::ANCHORS[$leg] ?? self::ANCHORS['advance'];
        $supplierPo = $order ? OrderSupplierPoRepository::findLatestForOrder($orderId) : null;
        if (!$order || !$supplierPo || !StageGateService::isReachedOrPassed($orderId, 5)) {
            $this->back($orderId, $anchor, 'error', 'Save the Supplier PO terms (Step 7a) before adding the supplier\'s bill.');
            return;
        }
        if (!isset(OrderVendorBillRepository::SUPPLIER_LEGS[$leg])) {
            $this->back($orderId, $anchor, 'error', 'Choose whether this bill is for the advance or the balance.');
            return;
        }
        $fields = $this->billFields();
        if (is_string($fields)) {
            $this->back($orderId, $anchor, 'error', $fields);
            return;
        }
        $user = AuthService::currentUser();
        try {
            $fileId = FileUploadService::handleUpload('bill_file', 'vendor_bill', OrderStorage::forReceived($order, 'supplier_po'), (int) $order['client_id'], $orderId, (int) $user['id'], null, (string) ($supplierPo['supplier_legal_name'] ?? 'Supplier'), 'Supplier bill / invoice');
        } catch (\Throwable $e) {
            $this->back($orderId, $anchor, 'error', $e->getMessage());
            return;
        }
        $billId = OrderVendorBillRepository::create($orderId, 'supplier', $fields + ['supplier_po_id' => (int) $supplierPo['id'], 'bill_for' => $leg, 'bill_file_id' => $fileId], (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'SUPPLIER_BILL_ADDED', 'order_vendor_bills', $billId, 'bill_no', null, $fields['bill_no'], OrderVendorBillRepository::SUPPLIER_LEGS[$leg] . ' bill ₹' . number_format($fields['taxable_inr'] + $fields['gst_inr'], 2));
        $this->back($orderId, $anchor, 'success', 'Supplier bill ' . $fields['bill_no'] . ' added — mark it paid once the payment goes out.');
    }

    public function addLogisticsBill(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        $anchor = self::ANCHORS['logistics'];
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $partner = LogisticsPartnerRepository::find((int) ($_POST['logistics_partner_id'] ?? 0));
        if (!$partner || empty($partner['is_active'])) {
            $this->back($orderId, $anchor, 'error', 'Choose the logistics partner from the directory.');
            return;
        }
        $service = (string) ($_POST['bill_for'] ?? '');
        if (!isset(OrderVendorBillRepository::LOGISTICS_SERVICES[$service])) {
            $this->back($orderId, $anchor, 'error', 'Choose what the bill is for.');
            return;
        }
        $fields = $this->billFields();
        if (is_string($fields)) {
            $this->back($orderId, $anchor, 'error', $fields);
            return;
        }
        $user = AuthService::currentUser();
        try {
            $fileId = FileUploadService::handleUpload('bill_file', 'vendor_bill', OrderStorage::forReceived($order, 'freight'), (int) $order['client_id'], $orderId, (int) $user['id'], null, (string) $partner['partner_name'], 'Logistics partner bill');
        } catch (\Throwable $e) {
            $this->back($orderId, $anchor, 'error', $e->getMessage());
            return;
        }
        $billId = OrderVendorBillRepository::create($orderId, 'logistics', $fields + ['logistics_partner_id' => (int) $partner['id'], 'bill_for' => $service, 'bill_file_id' => $fileId], (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'LOGISTICS_BILL_ADDED', 'order_vendor_bills', $billId, 'bill_no', null, $fields['bill_no'], $partner['partner_name'] . ' — ' . OrderVendorBillRepository::LOGISTICS_SERVICES[$service] . ' ₹' . number_format($fields['taxable_inr'] + $fields['gst_inr'], 2));
        $this->back($orderId, $anchor, 'success', 'Bill ' . $fields['bill_no'] . ' from ' . $partner['partner_name'] . ' added.');
    }

    /**
     * Why a logistics bill can't be paid yet, or null when it can: on a
     * CFR/CIF order, only once the buyer's Debit Note payment has cleared (9d).
     */
    public static function logisticsPaymentBlockedReason(array $order): ?string
    {
        if (strtoupper((string) ($order['incoterm_code'] ?? 'FOB')) === 'FOB') {
            return null;
        }
        $payment = OrderPaymentStatusRepository::find((int) $order['id']);
        return !empty($payment['freight_cleared_at'])
            ? null
            : 'Logistics bills on this ' . strtoupper((string) $order['incoterm_code']) . ' order can be paid once the buyer\'s Debit Note payment has cleared (Step 9d).';
    }

    public function payBill(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        $bill = OrderVendorBillRepository::find((int) $params['billId']);
        if (!$order || !$bill || (int) $bill['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Bill not found.';
            return;
        }
        $anchor = $bill['vendor_kind'] === 'supplier' ? self::ANCHORS[$bill['bill_for']] ?? self::ANCHORS['advance'] : self::ANCHORS['logistics'];
        if ($bill['paid_at'] !== null) {
            $this->back($orderId, $anchor, 'error', 'Bill ' . $bill['bill_no'] . ' is already marked paid.');
            return;
        }
        $supplierPo = null;
        if ($bill['vendor_kind'] === 'supplier') {
            $supplierPo = OrderSupplierPoRepository::findLatestForOrder($orderId);
            if (!$supplierPo || (int) $supplierPo['id'] !== (int) $bill['supplier_po_id']) {
                $this->back($orderId, $anchor, 'error', 'This bill belongs to an earlier Supplier PO version — add it again against the current Supplier PO.');
                return;
            }
            // Owner report (2026-10-27): the balance waits for the advance whenever the terms plan one.
            if ($bill['bill_for'] === 'balance' && (float) ($supplierPo['advance_amount_inr'] ?? 0) > 0 && empty($supplierPo['advance_paid_at'])) {
                $this->back($orderId, $anchor, 'error', 'Pay the supplier advance (Step 7e) first — the Supplier PO terms plan an advance of ₹' . number_format((float) $supplierPo['advance_amount_inr'], 2) . '.');
                return;
            }
        } elseif (($blocked = self::logisticsPaymentBlockedReason($order)) !== null) {
            $this->back($orderId, $anchor, 'error', $blocked);
            return;
        }

        $paidAt = trim((string) ($_POST['paid_at'] ?? ''));
        if (!self::validDate($paidAt) || $paidAt > date('Y-m-d')) {
            $this->back($orderId, $anchor, 'error', 'Enter the date it was paid (not in the future).');
            return;
        }
        $amount = trim((string) ($_POST['paid_amount_inr'] ?? ''));
        if (!is_numeric($amount) || (float) $amount <= 0) {
            $this->back($orderId, $anchor, 'error', 'Enter the amount paid (₹).');
            return;
        }
        $mode = (string) ($_POST['payment_mode'] ?? '');
        if (!in_array($mode, OrderVendorBillRepository::PAYMENT_MODES, true)) {
            $this->back($orderId, $anchor, 'error', 'Choose how it was paid.');
            return;
        }
        $reference = trim((string) ($_POST['payment_ref'] ?? ''));
        if ($reference === '') {
            $this->back($orderId, $anchor, 'error', 'Enter the UTR / payment reference.');
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($paidAt, (int) $user['id'], $roleId, 'order_vendor_bills', (int) $bill['id'], 'mark_paid')) {
            header("Location: /orders/{$orderId}#{$anchor}");
            return;
        }

        OrderVendorBillRepository::markPaid((int) $bill['id'], $paidAt, round((float) $amount, 2), $mode, mb_substr($reference, 0, 100), (int) $user['id']);
        if ($supplierPo !== null) {
            // The Supplier PO's own advance / balance paid figures (Steps
            // 7e / 10a, reports, the ledger) follow the bills paid on that leg.
            [$legPaid, $lastPaid] = OrderVendorBillRepository::supplierLegPaid((int) $supplierPo['id'], (string) $bill['bill_for']);
            if ($bill['bill_for'] === 'advance') {
                OrderSupplierPoRepository::markAdvancePaid((int) $supplierPo['id'], (string) $lastPaid, (int) $user['id'], $legPaid);
            } else {
                OrderSupplierPoRepository::markBalancePaid((int) $supplierPo['id'], (string) $lastPaid, (int) $user['id'], $legPaid);
            }
            AuditLogRepository::log((int) $user['id'], $bill['bill_for'] === 'advance' ? 'SUPPLIER_ADVANCE_PAID' : 'SUPPLIER_BALANCE_PAID', 'order_supplier_po', (int) $supplierPo['id'], $bill['bill_for'] . '_paid_date', null, $paidAt, 'Against bill ' . $bill['bill_no'] . ' — ' . $mode . ' ' . $reference);
        }
        AuditLogRepository::log((int) $user['id'], 'VENDOR_BILL_PAID', 'order_vendor_bills', (int) $bill['id'], 'paid_at', null, $paidAt, '₹' . number_format((float) $amount, 2) . ' — ' . $mode . ' ' . $reference);
        $this->back($orderId, $anchor, 'success', 'Payment of ₹' . number_format((float) $amount, 2) . ' against bill ' . $bill['bill_no'] . ' recorded.');
    }

    public function deleteBill(array $params): void
    {
        $orderId = (int) $params['id'];
        $bill = OrderVendorBillRepository::find((int) $params['billId']);
        if (!$bill || (int) $bill['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Bill not found.';
            return;
        }
        $anchor = $bill['vendor_kind'] === 'supplier' ? self::ANCHORS[$bill['bill_for']] ?? self::ANCHORS['advance'] : self::ANCHORS['logistics'];
        if (!OrderVendorBillRepository::deleteUnpaid((int) $bill['id'])) {
            $this->back($orderId, $anchor, 'error', 'A paid bill stays on record and cannot be removed.');
            return;
        }
        AuditLogRepository::log((int) AuthService::currentUserId(), 'VENDOR_BILL_REMOVED', 'order_vendor_bills', (int) $bill['id'], 'bill_no', $bill['bill_no'], null, 'Unpaid bill removed.');
        $this->back($orderId, $anchor, 'success', 'Bill ' . $bill['bill_no'] . ' removed.');
    }
}
