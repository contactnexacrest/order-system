<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\CaFyLockGuard;

/**
 * Order-page-embedded financial actions (docs/schema.sql Section AP) —
 * recording a government export benefit claim or an "other order cost"
 * directly against a specific order, without leaving its page or typing
 * the order reference by hand (unlike the general CA module's own
 * /ca/export-benefits screen). Every action here requires
 * manage_order_financials — deliberately stricter than the general
 * ca_module_view/inr_actual_edit permissions (see docs/seed.sql), since
 * this surfaces per-order margin data.
 */
final class OrderFinancialsController
{
    public function addExportBenefit(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!OrderRepository::find($orderId)) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        $schemeName = trim((string) ($_POST['scheme_name'] ?? ''));
        if ($schemeName === '') {
            Flash::set('error', 'Select a scheme.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $claimedAmount = trim((string) ($_POST['claimed_amount'] ?? ''));
        if ($claimedAmount === '' || !is_numeric($claimedAmount) || (float) $claimedAmount <= 0) {
            Flash::set('error', 'Enter a valid claimed amount.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $claimedAt = trim((string) ($_POST['claimed_at'] ?? ''));
        if ($claimedAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $claimedAt)) {
            Flash::set('error', 'Enter a valid claim date.');
            header("Location: /orders/{$orderId}");
            return;
        }

        if (!CaFyLockGuard::allow($claimedAt, (int) $user['id'], $roleId, 'ca_export_benefits', 0, 'record')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        $referenceNumber = trim((string) ($_POST['reference_number'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $currencyCode = trim((string) ($_POST['currency_code'] ?? '')) ?: 'INR';

        $id = CaExportBenefitRepository::record($orderId, $schemeName, $referenceNumber, (float) $claimedAmount, $claimedAt, $currencyCode, $notes, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'CA_EXPORT_BENEFIT_RECORDED', 'ca_export_benefits', $id, null, null, $schemeName);
        Flash::set('success', "{$schemeName} claim recorded for this order.");
        header("Location: /orders/{$orderId}#order-financials");
    }

    public function markExportBenefitReceived(array $params): void
    {
        $orderId = (int) $params['id'];
        $benefitId = (int) $params['benefitId'];
        $benefit = CaExportBenefitRepository::find($benefitId);
        if (!$benefit || (int) ($benefit['order_id'] ?? 0) !== $orderId) {
            http_response_code(404);
            echo 'Claim not found for this order.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        $receivedAmount = trim((string) ($_POST['received_amount'] ?? ''));
        if ($receivedAmount === '' || !is_numeric($receivedAmount) || (float) $receivedAmount < 0) {
            Flash::set('error', 'Enter a valid received amount.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $receivedAt = trim((string) ($_POST['received_at'] ?? ''));
        if ($receivedAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedAt)) {
            Flash::set('error', 'Enter a valid received date.');
            header("Location: /orders/{$orderId}");
            return;
        }

        if (!CaFyLockGuard::allow($receivedAt, (int) $user['id'], $roleId, 'ca_export_benefits', $benefitId, 'mark_received')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        CaExportBenefitRepository::markReceived($benefitId, (float) $receivedAmount, $receivedAt);
        AuditLogRepository::log((int) $user['id'], 'CA_EXPORT_BENEFIT_RECEIVED', 'ca_export_benefits', $benefitId, null, null, $receivedAmount);
        Flash::set('success', 'Marked as received.');
        header("Location: /orders/{$orderId}#order-financials");
    }

    public function addCostEntry(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!OrderRepository::find($orderId)) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        $category = trim((string) ($_POST['category'] ?? ''));
        if (!array_key_exists($category, OrderCostEntryRepository::CATEGORIES)) {
            Flash::set('error', 'Select a valid cost category.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $amount = trim((string) ($_POST['amount_inr'] ?? ''));
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
            Flash::set('error', 'Enter a valid amount.');
            header("Location: /orders/{$orderId}");
            return;
        }
        $incurredAt = trim((string) ($_POST['incurred_at'] ?? '')) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $incurredAt)) {
            Flash::set('error', 'Enter a valid date.');
            header("Location: /orders/{$orderId}");
            return;
        }

        if (!CaFyLockGuard::allow($incurredAt, (int) $user['id'], $roleId, 'order_cost_entries', 0, 'record')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        $description = trim((string) ($_POST['description'] ?? '')) ?: null;

        $id = OrderCostEntryRepository::create($orderId, $category, $description, (float) $amount, $incurredAt, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_COST_ENTRY_RECORDED', 'order_cost_entries', $id, null, null, $category);
        Flash::set('success', 'Cost entry recorded.');
        header("Location: /orders/{$orderId}#order-financials");
    }

    public function deleteCostEntry(array $params): void
    {
        $orderId = (int) $params['id'];
        $entryId = (int) $params['entryId'];
        $entry = OrderCostEntryRepository::find($entryId);
        if (!$entry || (int) $entry['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Cost entry not found for this order.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        if (!CaFyLockGuard::allow($entry['incurred_at'], (int) $user['id'], $roleId, 'order_cost_entries', $entryId, 'delete')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        OrderCostEntryRepository::delete($entryId);
        AuditLogRepository::log((int) $user['id'], 'ORDER_COST_ENTRY_DELETED', 'order_cost_entries', $entryId, null, $entry['category'], null);
        Flash::set('success', 'Cost entry removed.');
        header("Location: /orders/{$orderId}#order-financials");
    }
}
