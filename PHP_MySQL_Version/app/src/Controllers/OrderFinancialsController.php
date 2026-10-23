<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderInspectionFeeRepository;
use App\Repositories\OrderLcDetailRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\CaFyLockGuard;
use App\Services\FileUploadService;

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
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $payment = \App\Repositories\OrderPaymentStatusRepository::find($orderId);
        if (!in_array($order['status'], ['shipped_docs_sent', 'complete'], true) || empty($payment['balance_remittance_received_at'])) {
            Flash::set('error', 'Government export benefits only apply once the order is complete and the CI (balance) remittance has been received.');
            header("Location: /orders/{$orderId}");
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

    /**
     * Export process steps 5-1a/5-2a/5-3a: when a client opts for a
     * Letter of Credit, record the final LC document + LC number against
     * the order; if a bank loan was taken against that LC, record the
     * loan amount/interest rate/interest paid, and keep that interest in
     * sync with a single order_cost_entries row (category
     * bank_loan_interest) — the same expense ledger every other order
     * cost already uses, rather than a second, parallel place to look.
     */
    public function saveLcDetails(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $existing = OrderLcDetailRepository::find($orderId);

        $lcNumber = trim((string) ($_POST['lc_number'] ?? '')) ?: null;

        foreach (['bank_loan_amount' => 'bank loan amount', 'bank_loan_interest_rate' => 'interest rate', 'bank_loan_interest_paid' => 'interest paid'] as $field => $label) {
            $raw = trim((string) ($_POST[$field] ?? ''));
            if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0)) {
                Flash::set('error', "Enter a valid {$label} (or leave it blank).");
                header("Location: /orders/{$orderId}#order-financials");
                return;
            }
        }
        $bankLoanAmount = trim((string) ($_POST['bank_loan_amount'] ?? '')) !== '' ? (float) $_POST['bank_loan_amount'] : null;
        $interestRate = trim((string) ($_POST['bank_loan_interest_rate'] ?? '')) !== '' ? (float) $_POST['bank_loan_interest_rate'] : null;
        $interestPaid = trim((string) ($_POST['bank_loan_interest_paid'] ?? '')) !== '' ? (float) $_POST['bank_loan_interest_paid'] : null;

        $lcFileId = $existing['lc_file_id'] ?? null;
        if (!empty($_FILES['lc_file']['name'])) {
            try {
                $lcFileId = FileUploadService::handleUpload(
                    'lc_file',
                    'order_lc_copy',
                    'clients/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['client_unique_number']) . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order['order_reference']) . '/lc',
                    null,
                    $orderId,
                    (int) $user['id'],
                    null,
                    null,
                    'Letter of Credit'
                );
            } catch (\Throwable $e) {
                Flash::set('error', 'LC/loan details were not saved — the LC file upload failed: ' . Flash::describeException($e, 'upload failed — check the server error log.'));
                header("Location: /orders/{$orderId}#order-financials");
                return;
            }
        }

        // Keep the single bank_loan_interest cost entry in sync: create it
        // the first time an interest amount is entered, update it on every
        // subsequent edit, and remove it if the amount is cleared back out
        // — this order's expense ledger should never show a stale figure
        // or a duplicate row for the same loan.
        $interestCostEntryId = $existing['interest_cost_entry_id'] ?? null;
        $staleCostEntryIdToDelete = null;
        if ($interestPaid !== null && $interestPaid > 0) {
            if ($interestCostEntryId) {
                OrderCostEntryRepository::updateAmount($interestCostEntryId, $interestPaid, 'Bank loan interest (LC order)');
            } else {
                $interestCostEntryId = OrderCostEntryRepository::create(
                    $orderId,
                    'bank_loan_interest',
                    'Bank loan interest (LC order)',
                    $interestPaid,
                    date('Y-m-d'),
                    (int) $user['id']
                );
            }
        } elseif ($interestCostEntryId) {
            // order_lc_details.interest_cost_entry_id FK must stop pointing
            // at this row BEFORE it's deleted, not after — the upsert below
            // clears it to null first; only once that's committed is it
            // safe to delete the now-unreferenced order_cost_entries row.
            $staleCostEntryIdToDelete = $interestCostEntryId;
            $interestCostEntryId = null;
        }

        OrderLcDetailRepository::upsert($orderId, [
            'lc_number' => $lcNumber,
            'lc_file_id' => $lcFileId,
            'bank_loan_amount' => $bankLoanAmount,
            'bank_loan_interest_rate' => $interestRate,
            'bank_loan_interest_paid' => $interestPaid,
            'interest_cost_entry_id' => $interestCostEntryId,
            'recorded_by' => (int) $user['id'],
        ]);
        if ($staleCostEntryIdToDelete !== null) {
            OrderCostEntryRepository::delete($staleCostEntryIdToDelete);
        }
        AuditLogRepository::log((int) $user['id'], 'ORDER_LC_DETAILS_SAVED', 'order_lc_details', $orderId, null, null, $lcNumber);
        Flash::set('success', 'LC / bank loan details saved.');
        header("Location: /orders/{$orderId}#order-financials");
    }

    /**
     * Export process step 8a: who pays the 3rd-party pre-shipment
     * inspection fee for this order. When NexaCrest pays, the amount is
     * kept in sync with a single order_cost_entries row (category
     * inspection_fee), same pattern as the LC bank loan interest sync
     * above — if the client pays instead (or no amount is given), any
     * previously-synced expense entry is removed, since NexaCrest no
     * longer incurred that cost.
     */
    public function saveInspectionFee(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!OrderRepository::find($orderId)) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $existing = OrderInspectionFeeRepository::find($orderId);

        $feePayer = trim((string) ($_POST['fee_payer'] ?? ''));
        if (!in_array($feePayer, ['nexacrest', 'client'], true)) {
            Flash::set('error', 'Select who pays the inspection fee.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        $rawAmount = trim((string) ($_POST['fee_amount'] ?? ''));
        if ($rawAmount !== '' && (!is_numeric($rawAmount) || (float) $rawAmount < 0)) {
            Flash::set('error', 'Enter a valid fee amount (or leave it blank).');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        $feeAmount = $rawAmount !== '' ? (float) $rawAmount : null;

        $costEntryId = $existing['cost_entry_id'] ?? null;
        $staleCostEntryIdToDelete = null;
        if ($feePayer === 'nexacrest' && $feeAmount !== null && $feeAmount > 0) {
            if ($costEntryId) {
                OrderCostEntryRepository::updateAmount($costEntryId, $feeAmount, 'Pre-shipment inspection fee');
            } else {
                $costEntryId = OrderCostEntryRepository::create(
                    $orderId,
                    'inspection_fee',
                    'Pre-shipment inspection fee',
                    $feeAmount,
                    date('Y-m-d'),
                    (int) $user['id']
                );
            }
        } elseif ($costEntryId) {
            // order_inspection_fees.cost_entry_id FK must stop pointing at
            // this row BEFORE it's deleted, not after — same ordering as
            // the LC interest sync above.
            $staleCostEntryIdToDelete = $costEntryId;
            $costEntryId = null;
        }

        OrderInspectionFeeRepository::upsert($orderId, [
            'fee_payer' => $feePayer,
            'fee_amount' => $feeAmount,
            'cost_entry_id' => $costEntryId,
            'recorded_by' => (int) $user['id'],
        ]);
        if ($staleCostEntryIdToDelete !== null) {
            OrderCostEntryRepository::delete($staleCostEntryIdToDelete);
        }
        AuditLogRepository::log((int) $user['id'], 'ORDER_INSPECTION_FEE_SAVED', 'order_inspection_fees', $orderId, 'fee_payer', null, $feePayer);
        Flash::set('success', 'Inspection fee details saved.');
        header("Location: /orders/{$orderId}#order-financials");
    }

    /**
     * docs/schema.sql Section BW — export process step 11a. The
     * distance-to-percentage mapping is a business judgement call
     * (5/7/10/15%), not a fixed formula, so staff chooses the
     * percentage themselves; this just computes and records the
     * resulting amount against the order's own FOB value and keeps it
     * synced into order_cost_entries.
     */
    public function saveTravelExpenses(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $existing = \App\Repositories\OrderTravelExpenseRepository::find($orderId);

        $selectedPct = trim((string) ($_POST['expense_pct'] ?? ''));
        $rawPct = $selectedPct === 'other' ? trim((string) ($_POST['expense_pct_other'] ?? '')) : $selectedPct;
        if ($rawPct === '' || !is_numeric($rawPct) || (float) $rawPct <= 0 || (float) $rawPct > 100) {
            Flash::set('error', 'Enter a valid travel expense percentage (greater than 0, up to 100).');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        $expensePct = (float) $rawPct;

        $rawDistance = trim((string) ($_POST['factory_distance_km'] ?? ''));
        if ($rawDistance !== '' && (!is_numeric($rawDistance) || (float) $rawDistance < 0)) {
            Flash::set('error', 'Enter a valid factory distance in km (or leave it blank).');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        $factoryDistanceKm = $rawDistance !== '' ? (float) $rawDistance : null;

        $fobTotal = \App\Repositories\OrderProductRepository::totalFobValue($orderId);

        /**
         * External QA report N-03: this used to write pct% of the FOB
         * value — which is in the order's OWN currency (e.g. USD) —
         * straight into order_cost_entries.amount_inr with no
         * conversion, understating the cost by the exchange rate on
         * every foreign-currency order. Converts at the same rate
         * OrderProfitabilityService::revenueInInr() falls back to for an
         * order with no per-leg actual yet (advance_exchange_rate ??
         * balance_exchange_rate) — the best "the order's exchange rate"
         * available, since advance/balance each carry their own rate
         * rather than one shared order-wide rate (Batch 3 #3). Refuses
         * rather than silently store a wrong figure if neither is known
         * yet.
         */
        $currencyCode = $order['currency_code'] ?? 'INR';
        if ($currencyCode === 'INR') {
            $rate = 1.0;
        } else {
            $payment = \App\Repositories\OrderPaymentStatusRepository::find($orderId);
            $rate = $payment['advance_exchange_rate'] ?? $payment['balance_exchange_rate'] ?? null;
            if ($rate === null) {
                Flash::set('error', "Travel expenses need this order's exchange rate — record the advance or balance payment's exchange rate first.");
                header("Location: /orders/{$orderId}#order-financials");
                return;
            }
            $rate = (float) $rate;
        }
        $expenseAmount = round($expensePct / 100 * $fobTotal * $rate, 2);

        $costEntryId = $existing['cost_entry_id'] ?? null;
        if ($costEntryId) {
            OrderCostEntryRepository::updateAmount($costEntryId, $expenseAmount, 'Travel expenses (factory visits)');
        } else {
            $costEntryId = OrderCostEntryRepository::create(
                $orderId,
                'travel_expenses',
                'Travel expenses (factory visits)',
                $expenseAmount,
                date('Y-m-d'),
                (int) $user['id']
            );
        }

        \App\Repositories\OrderTravelExpenseRepository::upsert($orderId, [
            'factory_distance_km' => $factoryDistanceKm,
            'expense_pct' => $expensePct,
            'expense_amount' => $expenseAmount,
            'cost_entry_id' => $costEntryId,
            'recorded_by' => (int) $user['id'],
        ]);
        AuditLogRepository::log((int) $user['id'], 'ORDER_TRAVEL_EXPENSE_SAVED', 'order_travel_expenses', $orderId, 'expense_pct', null, (string) $expensePct);
        Flash::set('success', 'Travel expenses saved.');
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
