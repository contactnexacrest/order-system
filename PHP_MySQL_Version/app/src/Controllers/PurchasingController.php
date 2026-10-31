<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\Input;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderProductRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PurchasingRepository;
use App\Repositories\SupplierRepository;
use App\Services\AuthService;
use App\Services\OrderAccessService;
use App\Services\PermissionService;
use App\Services\PurchasingService;

/**
 * Owner decision D-214 (2026-10-31), phase 3: Procurement. Sales raises a
 * purchase requirement on the order (no prices); Procurement records
 * supplier quotes, compares and awards (above the approval limit it waits
 * for an approver), and records receipt and the quality check. Port:
 * purchasingController.js.
 */
final class PurchasingController
{
    private const QC_RESULTS = ['passed' => 'Passed', 'passed_with_remarks' => 'Passed with remarks', 'failed' => 'Failed'];

    private static function isDate(string $v): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function back(int $id): void
    {
        header("Location: /procurement/{$id}");
    }

    /** @return array<string,mixed>|null the requirement, or null after answering 404 (missing or on an order this person cannot see) */
    private static function requirement(array $params): ?array
    {
        $r = PurchasingRepository::find((int) $params['id']);
        if ($r === null || !OrderAccessService::currentUserMaySeeOrderId((int) $r['order_id'])) {
            http_response_code(404);
            echo 'Purchase requirement not found.';
            return null;
        }
        return $r;
    }

    /** Sales: what the order needs, from one of its product lines or described freely. */
    public function raise(array $params): void
    {
        $user = AuthService::currentUser();
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if ($order === null) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $back = "Location: /orders/{$orderId}#purchase-requirements";
        $productId = (int) ($_POST['order_product_id'] ?? 0);
        $product = $productId > 0 ? OrderProductRepository::find($productId) : null;
        if ($productId > 0 && ($product === null || (int) $product['order_id'] !== $orderId)) {
            Flash::set('error', 'Choose a product line of this order, or describe what is needed.');
            header($back);
            return;
        }
        $description = Input::scalarString($_POST['description'] ?? '') ?: (string) ($product['description'] ?? '');
        $quantityRaw = trim((string) ($_POST['quantity'] ?? ''));
        $quantity = $quantityRaw !== '' ? $quantityRaw : (string) ($product['quantity'] ?? '');
        $neededBy = trim((string) ($_POST['needed_by'] ?? ''));
        $error = match (true) {
            mb_strlen($description) < 2 || mb_strlen($description) > 500 => 'Describe what is needed (2 to 500 characters).',
            !is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 999999999 => 'Enter the quantity needed (more than 0).',
            !self::isDate($neededBy) || $neededBy < date('Y-m-d') => 'Enter the date it is needed by (today or later).',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header($back);
            return;
        }
        $spec = trim((string) ($_POST['specification'] ?? '')) ?: trim(implode(' · ', array_filter([(string) ($product['material'] ?? ''), (string) ($product['finish'] ?? ''), (string) ($product['dimensions'] ?? '')]))) ?: null;
        $unit = Input::scalarString($_POST['unit'] ?? '') ?: ($product['unit'] ?? null);
        $id = PurchasingRepository::create($orderId, $product !== null ? $productId : null, $description, $spec !== null ? mb_substr($spec, 0, 1000) : null,
            round((float) $quantity, 3), $unit !== null ? mb_substr((string) $unit, 0, 20) : null, $neededBy, (int) $user['id'], trim((string) ($_POST['note'] ?? '')) ?: null);
        AuditLogRepository::log((int) $user['id'], 'PURCHASE_REQUIREMENT_RAISED', 'purchase_requirements', $id, null, null,
            "{$order['order_reference']}: {$description} × " . rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.') . ($unit ? " {$unit}" : '') . " by {$neededBy}");
        Flash::set('success', "Purchase requirement PR-{$id} raised for Procurement: {$description}, needed by {$neededBy}.");
        header($back);
    }

    /** Procurement's queue: everything open, earliest need first (only orders this person can see). */
    public function queue(array $params): void
    {
        // The Kanban UI template shows Procurement as a board, one column per status.
        if (\App\Services\UiTemplateService::showsBoard(AuthService::currentUser()['ui_template_preference_id'] ?? null)) {
            // Owner hard rule 2026-11-01: the same status filter as the list — a chosen status shows its column only (all of it).
            $status = (string) ($_GET['status'] ?? '');
            $status = array_key_exists($status, PurchasingRepository::STATUSES) ? $status : '';
            $board = PurchasingRepository::board();
            $source = $status === '' ? $board['rows'] : PurchasingRepository::queue($status);
            $rows = array_values(array_filter($source, static fn (array $r): bool => OrderAccessService::currentUserMaySeeOrderId((int) $r['order_id'])));
            $columns = [];
            foreach (PurchasingRepository::STATUSES as $key => $label) {
                if ($status === '' ? $key !== 'cancelled' : $key === $status) {
                    $columns[$key] = ['label' => $label, 'rows' => array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === $key))];
                }
            }
            View::render('procurement/queue_kanban', ['columns' => $columns, 'cancelled' => $board['cancelled'], 'statuses' => PurchasingRepository::STATUSES, 'status' => $status], 'layout/base');
            return;
        }
        $status = (string) ($_GET['status'] ?? '');
        $rows = PurchasingRepository::queue(array_key_exists($status, PurchasingRepository::STATUSES) ? $status : null);
        $rows = array_values(array_filter($rows, static fn (array $r): bool => OrderAccessService::currentUserMaySeeOrderId((int) $r['order_id'])));
        View::render('procurement/queue', ['rows' => $rows, 'statuses' => PurchasingRepository::STATUSES, 'status' => $status,
            // Owner hard rule 2026-11-01: a Kanban user who switched to the list can switch back.
            'kanbanUser' => \App\Services\UiTemplateService::resolve(AuthService::currentUser()['ui_template_preference_id'] ?? null) === 'kanban'], 'layout/base');
    }

    public function show(array $params): void
    {
        $r = self::requirement($params);
        if ($r === null) {
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $awardValue = null;
        if ($r['awarded_quote_id'] !== null) {
            $awardValue = PurchasingService::awardValue(['unit_price_inr' => $r['awarded_unit_price_inr'], 'gst_percent' => $r['awarded_gst_percent']], (float) $r['quantity']);
        }
        View::render('procurement/show', [
            'r' => $r,
            'quotes' => PurchasingRepository::quotes((int) $r['id']),
            'suppliers' => SupplierRepository::all(),
            'statuses' => PurchasingRepository::STATUSES,
            'qcResults' => self::QC_RESULTS,
            'awardValue' => $awardValue,
            'canWork' => PermissionService::can((int) $user['id'], $roleId, 'manage_purchasing'),
            'approvalRefusal' => $r['status'] === 'awarded' && $awardValue !== null ? PurchasingService::approvalRefusal((int) $user['id'], $roleId, $r, $awardValue) : null,
            'myLimit' => PurchasingService::limitFor((int) $user['id']),
        ], 'layout/base');
    }

    public function addQuote(array $params): void
    {
        $r = self::requirement($params);
        if ($r === null) {
            return;
        }
        $user = AuthService::currentUser();
        $supplier = SupplierRepository::find((int) ($_POST['supplier_id'] ?? 0));
        $price = trim((string) ($_POST['unit_price_inr'] ?? ''));
        $gst = trim((string) ($_POST['gst_percent'] ?? '0')) ?: '0';
        $lead = trim((string) ($_POST['lead_time_days'] ?? ''));
        $validUntil = trim((string) ($_POST['valid_until'] ?? ''));
        $error = match (true) {
            !in_array($r['status'], ['open', 'quoted'], true) => 'Quotes are recorded only before the purchase is awarded.',
            $supplier === null || (int) $supplier['is_active'] !== 1 => 'Choose the supplier.',
            !is_numeric($price) || (float) $price <= 0 || (float) $price > 999999999 => 'Enter the unit price in rupees (more than 0).',
            !is_numeric($gst) || (float) $gst < 0 || (float) $gst > 28 => 'Enter the GST % (0 to 28).',
            !ctype_digit($lead) || (int) $lead > 365 => 'Enter the lead time in days (0 to 365).',
            $validUntil !== '' && (!self::isDate($validUntil) || $validUntil < date('Y-m-d')) => 'The quote must be valid until today or later (or leave it empty).',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            self::back((int) $r['id']);
            return;
        }
        $id = PurchasingRepository::addQuote((int) $r['id'], (int) $supplier['id'], round((float) $price, 2), round((float) $gst, 2), (int) $lead,
            $validUntil ?: null, Input::scalarString($_POST['quote_reference'] ?? '') ?: null, trim((string) ($_POST['note'] ?? '')) ?: null, (int) $user['id']);
        if ($r['status'] === 'open') {
            PurchasingRepository::setStatus((int) $r['id'], 'quoted');
        }
        AuditLogRepository::log((int) $user['id'], 'PURCHASE_QUOTE_RECORDED', 'purchase_quotes', $id, null, null,
            "PR-{$r['id']}: {$supplier['supplier_legal_name']} ₹" . number_format((float) $price, 2) . " + {$gst}% GST, {$lead} days");
        Flash::set('success', "Quote from {$supplier['supplier_legal_name']} recorded.");
        self::back((int) $r['id']);
    }

    public function award(array $params): void
    {
        $r = self::requirement($params);
        if ($r === null) {
            return;
        }
        $user = AuthService::currentUser();
        $quote = PurchasingRepository::findQuote((int) ($_POST['quote_id'] ?? 0));
        if (!in_array($r['status'], ['quoted', 'awarded'], true) || $quote === null || (int) $quote['requirement_id'] !== (int) $r['id']) {
            Flash::set('error', 'Choose one of this requirement\'s quotes (before it is approved).');
            self::back((int) $r['id']);
            return;
        }
        $value = PurchasingService::awardValue($quote, (float) $r['quantity']);
        $needsApproval = PurchasingService::needsApproval((int) $user['id'], $value);
        PurchasingRepository::award((int) $r['id'], (int) $quote['id'], (int) $user['id'], $needsApproval);
        AuditLogRepository::log((int) $user['id'], 'PURCHASE_AWARDED', 'purchase_requirements', (int) $r['id'], 'awarded_quote_id', $r['awarded_quote_id'] !== null ? (string) $r['awarded_quote_id'] : null,
            "{$quote['supplier_legal_name']} ₹" . number_format($value, 2) . ($needsApproval ? ' (waiting for approval)' : ''));
        Flash::set('success', "Awarded to {$quote['supplier_legal_name']} for ₹" . number_format($value, 2) . '. '
            . ($needsApproval ? 'It is above your approval limit and waits for an approver.' : 'Approved — within your approval limit.'));
        self::back((int) $r['id']);
    }

    public function approve(array $params): void
    {
        $r = self::requirement($params);
        if ($r === null) {
            return;
        }
        $user = AuthService::currentUser();
        if ($r['status'] !== 'awarded' || $r['awarded_quote_id'] === null) {
            Flash::set('error', 'Only an award waiting for approval can be approved.');
            self::back((int) $r['id']);
            return;
        }
        $value = PurchasingService::awardValue(['unit_price_inr' => $r['awarded_unit_price_inr'], 'gst_percent' => $r['awarded_gst_percent']], (float) $r['quantity']);
        $refusal = PurchasingService::approvalRefusal((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null, $r, $value);
        if ($refusal !== null) {
            Flash::set('error', $refusal);
            self::back((int) $r['id']);
            return;
        }
        PurchasingRepository::approve((int) $r['id'], (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PURCHASE_APPROVED', 'purchase_requirements', (int) $r['id'], null, null, "{$r['awarded_supplier_name']} ₹" . number_format($value, 2));
        Flash::set('success', "Purchase PR-{$r['id']} approved: {$r['awarded_supplier_name']}, ₹" . number_format($value, 2) . '.');
        self::back((int) $r['id']);
    }

    public function receive(array $params): void
    {
        $r = self::requirement($params);
        if ($r === null) {
            return;
        }
        $user = AuthService::currentUser();
        $qty = trim((string) ($_POST['received_quantity'] ?? ''));
        $on = trim((string) ($_POST['received_on'] ?? ''));
        $qc = (string) ($_POST['qc_result'] ?? '');
        $note = trim((string) ($_POST['qc_note'] ?? ''));
        $error = match (true) {
            $r['status'] !== 'approved' => 'Receipt is recorded once the purchase is approved.',
            !is_numeric($qty) || (float) $qty <= 0 || (float) $qty > 999999999 => 'Enter the quantity received (more than 0).',
            !self::isDate($on) || $on > date('Y-m-d') => 'Enter the date it was received (not in the future).',
            !array_key_exists($qc, self::QC_RESULTS) => 'Choose the quality check result.',
            $qc !== 'passed' && mb_strlen($note) < 5 => 'Say what the quality check found (at least 5 characters).',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            self::back((int) $r['id']);
            return;
        }
        PurchasingRepository::receive((int) $r['id'], round((float) $qty, 3), $on, $qc, $note !== '' ? mb_substr($note, 0, 500) : null, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'PURCHASE_RECEIVED', 'purchase_requirements', (int) $r['id'], 'qc_result', null,
            rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.') . " received {$on}, QC " . self::QC_RESULTS[$qc]);
        Flash::set('success', "Receipt recorded for PR-{$r['id']}: quality check " . strtolower(self::QC_RESULTS[$qc]) . '.');
        self::back((int) $r['id']);
    }

    public function cancel(array $params): void
    {
        $r = self::requirement($params);
        if ($r === null) {
            return;
        }
        $user = AuthService::currentUser();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (in_array($r['status'], ['received', 'cancelled'], true) || mb_strlen($reason) < \App\Helpers\ReasonValidator::MIN_LENGTH) {
            Flash::set('error', 'Give the reason for cancelling (at least ' . \App\Helpers\ReasonValidator::MIN_LENGTH . ' characters); a received purchase cannot be cancelled.');
            self::back((int) $r['id']);
            return;
        }
        PurchasingRepository::setStatus((int) $r['id'], 'cancelled');
        AuditLogRepository::log((int) $user['id'], 'PURCHASE_REQUIREMENT_CANCELLED', 'purchase_requirements', (int) $r['id'], 'status', (string) $r['status'], 'cancelled', $reason);
        Flash::set('success', "PR-{$r['id']} cancelled.");
        self::back((int) $r['id']);
    }

    public function suppliers(array $params): void
    {
        View::render('procurement/suppliers', ['rows' => PurchasingRepository::supplierPerformance()], 'layout/base');
    }
}
