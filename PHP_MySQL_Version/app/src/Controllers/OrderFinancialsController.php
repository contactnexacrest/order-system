<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\OrderCostEntryRepository;
use App\Repositories\OrderExportBenefitSchemeRepository;
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
        header("Location: /orders/{$orderId}#step-13g-benefits");
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
        header("Location: /orders/{$orderId}#step-13g-benefits");
    }

    /**
     * Owner request (2026-10-27): a wrongly typed claim can be corrected or
     * deleted — reason required, the old and new figures audit-logged, and
     * the CA financial-year lock respected for both the old and new dates.
     *
     * @return array{0: array, 1: array, 2: int, 3: ?int}|null the claim, the user, user id and role id — null once a response was sent
     */
    private function benefitForChange(array $params, string $field): ?array
    {
        $orderId = (int) $params['id'];
        $benefitId = (int) $params['benefitId'];
        $benefit = CaExportBenefitRepository::find($benefitId);
        if (!$benefit || (int) $benefit['order_id'] !== $orderId) {
            http_response_code(404);
            echo 'Benefit claim not found for this order.';
            return null;
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}#step-13g-benefits");
            return null;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow((string) $benefit['claimed_at'], (int) $user['id'], $roleId, 'ca_export_benefits', $benefitId, $field)) {
            header("Location: /orders/{$orderId}#step-13g-benefits");
            return null;
        }
        return [$benefit, $user, (int) $user['id'], $roleId];
    }

    public function correctExportBenefit(array $params): void
    {
        $orderId = (int) $params['id'];
        $found = $this->benefitForChange($params, 'correct');
        if ($found === null) {
            return;
        }
        [$benefit, , $userId, $roleId] = $found;
        $back = "Location: /orders/{$orderId}#step-13g-benefits";

        $claimed = trim((string) ($_POST['claimed_amount'] ?? ''));
        $claimedAt = trim((string) ($_POST['claimed_at'] ?? ''));
        $received = trim((string) ($_POST['received_amount'] ?? ''));
        $receivedAt = trim((string) ($_POST['received_at'] ?? ''));
        $isDate = static fn(string $d): bool => \DateTimeImmutable::createFromFormat('!Y-m-d', $d) !== false;
        if (!is_numeric($claimed) || (float) $claimed <= 0 || !$isDate($claimedAt)) {
            Flash::set('error', 'Enter the claimed amount and the date it was claimed — nothing was changed.');
            header($back);
            return;
        }
        if ($received !== '' && (!is_numeric($received) || (float) $received < 0 || !$isDate($receivedAt))) {
            Flash::set('error', 'Enter a valid received amount and date (or leave the received amount empty if nothing has arrived yet) — nothing was changed.');
            header($back);
            return;
        }
        if (!CaFyLockGuard::allow($claimedAt, $userId, $roleId, 'ca_export_benefits', (int) $benefit['id'], 'correct')
            || ($received !== '' && !CaFyLockGuard::allow($receivedAt, $userId, $roleId, 'ca_export_benefits', (int) $benefit['id'], 'correct'))) {
            header($back);
            return;
        }

        $reference = trim((string) ($_POST['reference_number'] ?? '')) ?: null;
        CaExportBenefitRepository::correct((int) $benefit['id'], $reference, (float) $claimed, $claimedAt, $received !== '' ? (float) $received : null, $received !== '' ? $receivedAt : null);
        $describe = static fn(array $b): string => sprintf(
            'claimed %s on %s (ref %s), received %s',
            number_format((float) $b['claimed_amount'], 2, '.', ''),
            $b['claimed_at'],
            $b['reference_number'] ?? '—',
            $b['received_amount'] !== null ? number_format((float) $b['received_amount'], 2, '.', '') . ' on ' . $b['received_at'] : 'nothing'
        );
        $after = CaExportBenefitRepository::find((int) $benefit['id']);
        AuditLogRepository::log($userId, 'CA_EXPORT_BENEFIT_CORRECTED', 'ca_export_benefits', (int) $benefit['id'], $benefit['scheme_name'], $describe($benefit), $describe($after), trim((string) $_POST['reason']));
        Flash::set('success', "{$benefit['scheme_name']} claim corrected.");
        header($back);
    }

    public function deleteExportBenefit(array $params): void
    {
        $orderId = (int) $params['id'];
        $found = $this->benefitForChange($params, 'delete');
        if ($found === null) {
            return;
        }
        [$benefit, , $userId] = $found;
        // Owner decision 2026-10-29: Zoho Books must match the app. A claim
        // whose receipt is in Books goes only once that journal is removed:
        // clearing the receipt does that on the next sync.
        if (!empty($benefit['zoho_journal_id'])) {
            Flash::set('error', "This {$benefit['scheme_name']} receipt is in Zoho Books. First correct the claim and leave the received amount empty, then run the Zoho sync (it removes the journal there), then delete the claim.");
            header("Location: /orders/{$orderId}#step-13g-benefits");
            return;
        }
        CaExportBenefitRepository::delete((int) $benefit['id']);
        AuditLogRepository::log(
            $userId,
            'CA_EXPORT_BENEFIT_DELETED',
            'ca_export_benefits',
            (int) $benefit['id'],
            $benefit['scheme_name'],
            sprintf('claimed %s on %s (ref %s), received %s', number_format((float) $benefit['claimed_amount'], 2, '.', ''), $benefit['claimed_at'], $benefit['reference_number'] ?? '—', $benefit['received_amount'] !== null ? number_format((float) $benefit['received_amount'], 2, '.', '') : 'nothing'),
            null,
            trim((string) $_POST['reason'])
        );
        Flash::set('success', "{$benefit['scheme_name']} claim deleted.");
        header("Location: /orders/{$orderId}#step-13g-benefits");
    }

    /**
     * Owner decision (2026-10-27): at Step 13g an eligible scheme the company
     * will not claim — or whose money will never come — is skipped with a
     * reason (scheme_name set), or every scheme still open at once
     * (scheme_name empty). action=undo clears one scheme's skip.
     */
    public function skipExportBenefitClaim(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!OrderRepository::find($orderId)) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $back = "Location: /orders/{$orderId}#step-13g-benefits";
        $userId = (int) AuthService::currentUserId();
        $schemeName = trim((string) ($_POST['scheme_name'] ?? ''));
        $state = \App\Services\BenefitSettlementService::state($orderId);
        $bySettlement = array_column($state['schemes'], 'settlement', 'scheme_name');

        if (($_POST['action'] ?? '') === 'undo') {
            if (($bySettlement[$schemeName] ?? null) !== 'skipped') {
                Flash::set('error', 'That scheme is not skipped.');
                header($back);
                return;
            }
            OrderExportBenefitSchemeRepository::setClaimSkipped($orderId, $schemeName, null, $userId);
            AuditLogRepository::log($userId, 'EXPORT_BENEFIT_CLAIM_SKIP_UNDONE', 'orders', $orderId, 'scheme_name', $schemeName, null);
            Flash::set('success', "{$schemeName} is open again in Step 13g.");
            header($back);
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header($back);
            return;
        }
        $open = array_merge($state['unclaimed'], $state['waiting']);
        $targets = $schemeName === '' ? $open : (in_array($schemeName, $open, true) ? [$schemeName] : []);
        if ($targets === []) {
            Flash::set('error', $schemeName === '' ? 'Every eligible scheme is already received or skipped.' : "{$schemeName} is not an eligible scheme still waiting on this order.");
            header($back);
            return;
        }
        foreach ($targets as $name) {
            OrderExportBenefitSchemeRepository::setClaimSkipped($orderId, $name, $reason, $userId);
            AuditLogRepository::log($userId, 'EXPORT_BENEFIT_CLAIM_SKIPPED', 'orders', $orderId, 'scheme_name', null, $name, $reason);
        }
        Flash::set('success', 'Skipped: ' . implode(', ', $targets) . '.');
        header($back);
    }

    /**
     * Eligibility decision for a government export-benefit scheme —
     * separate from (and a precondition of) actually recording a claim
     * above. 'not_eligible' requires a reason, same deliberate
     * "never silently skipped" rule as OrderComplianceTaskRepository's
     * skip_reason.
     */
    public function setExportBenefitSchemeStatus(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();

        if (isset($_POST['schemes']) && is_array($_POST['schemes'])) {
            $this->saveAllExportBenefitSchemes($orderId, (int) $user['id']);
            return;
        }

        $schemeName = trim((string) ($_POST['scheme_name'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? ''));
        if ($schemeName === '' || !array_key_exists($status, \App\Repositories\OrderExportBenefitSchemeRepository::STATUSES) || $status === 'pending') {
            Flash::set('error', 'Select a valid scheme and eligibility status.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($status === 'not_eligible' && $reason === '') {
            Flash::set('error', 'A reason is required to mark a scheme Not Eligible.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }

        \App\Repositories\OrderExportBenefitSchemeRepository::setStatus($orderId, $schemeName, $status, $status === 'not_eligible' ? $reason : null, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED', 'orders', $orderId, 'export_benefit_scheme:' . $schemeName, null, $status, $status === 'not_eligible' ? $reason : null);
        Flash::set('success', "{$schemeName} eligibility updated.");
        header("Location: /orders/{$orderId}#order-financials");
    }

    /**
     * Owner request (2026-10-26): every scheme's eligibility saved in one
     * submit instead of one Save per row. Rows still left Pending are
     * untouched; only rows whose status or reason actually changed are
     * written (and audit-logged). All or nothing — if any row is invalid,
     * nothing is saved and the error names each scheme to fix.
     */
    private function saveAllExportBenefitSchemes(int $orderId, int $userId): void
    {
        $repo = \App\Repositories\OrderExportBenefitSchemeRepository::class;
        $current = [];
        foreach ($repo::forOrder($orderId) as $row) {
            $current[$row['scheme_name']] = $row;
        }

        $changes = [];
        $errors = [];
        foreach ($_POST['schemes'] as $row) {
            $schemeName = trim((string) ($row['scheme_name'] ?? ''));
            $status = trim((string) ($row['status'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            if (!isset($current[$schemeName]) || !array_key_exists($status, $repo::STATUSES)) {
                $errors[] = 'select a valid status for ' . ($schemeName !== '' ? $schemeName : 'every scheme');
                continue;
            }
            if ($status === 'pending') {
                continue;
            }
            if ($status === 'not_eligible' && mb_strlen($reason) < \App\Helpers\ReasonValidator::MIN_LENGTH) {
                $errors[] = "{$schemeName} needs a reason of at least " . \App\Helpers\ReasonValidator::MIN_LENGTH . ' characters to be Not Eligible';
                continue;
            }
            $storedReason = $status === 'not_eligible' ? $reason : null;
            if ($current[$schemeName]['status'] === $status && ($current[$schemeName]['reason'] ?? null) === $storedReason) {
                continue;
            }
            $changes[] = [$schemeName, $status, $storedReason];
        }

        if ($errors !== []) {
            Flash::set('error', 'Nothing saved — ' . implode('; ', $errors) . '.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        foreach ($changes as [$schemeName, $status, $storedReason]) {
            $repo::setStatus($orderId, $schemeName, $status, $storedReason, $userId);
            AuditLogRepository::log($userId, 'EXPORT_BENEFIT_SCHEME_STATUS_UPDATED', 'orders', $orderId, 'export_benefit_scheme:' . $schemeName, $current[$schemeName]['status'], $status, $storedReason);
        }
        Flash::set('success', $changes === []
            ? 'No changes to save.'
            : 'Export benefit eligibility saved for ' . implode(', ', array_column($changes, 0)) . '.');
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
        // Owner decision 2026-10-29: what the cost was paid from (it goes to Zoho Books from there).
        $paidFrom = (string) ($_POST['paid_from'] ?? '');
        if (!array_key_exists($paidFrom, OrderCostEntryRepository::PAID_FROM)) {
            Flash::set('error', 'Choose what the cost was paid from.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }

        $id = OrderCostEntryRepository::create($orderId, $category, $description, (float) $amount, $incurredAt, (int) $user['id'], $paidFrom);
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

        // External QA report N-11: an interest rate is a percentage, so
        // anything above 100 (the report's example: 250%) is never
        // plausible — refused outright, same as the negative-value check
        // above. Interest paid exceeding the loan amount is merely
        // unusual, not impossible (e.g. cumulative interest across
        // renewed years), so that one is only ever a warning, never a
        // block, and is shown after the save below.
        if ($interestRate !== null && $interestRate > 100) {
            Flash::set('error', 'Enter a valid interest rate — it cannot exceed 100%.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        $interestExceedsLoan = $interestPaid !== null && $bankLoanAmount !== null && $interestPaid > $bankLoanAmount;

        $lcFileId = $existing['lc_file_id'] ?? null;
        if (!empty($_FILES['lc_file']['name'])) {
            try {
                $lcFileId = FileUploadService::handleUpload(
                    'lc_file',
                    'order_lc_copy',
                    \App\Helpers\OrderStorage::folder($order, 'lc'),
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
        if ($interestExceedsLoan) {
            Flash::set('warning', 'LC / bank loan details saved — but the interest paid is more than the loan amount itself. Double-check this is correct.');
        } else {
            Flash::set('success', 'LC / bank loan details saved.');
        }
        header("Location: /orders/{$orderId}#order-financials");
    }

    /**
     * Owner decision 2026-10-29: records the money the bank actually paid
     * under the LC — the date, the amount in the order currency, the rupees
     * credited and the bank charges deducted. It goes to Zoho Books as a
     * customer payment applied to the CI, so it can be corrected only until
     * then.
     */
    public function recordLcReceived(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $back = static function (string $type, string $message) use ($orderId): void {
            Flash::set($type, $message);
            header("Location: /orders/{$orderId}#lc-received");
        };
        $lc = OrderLcDetailRepository::find($orderId);
        if (($order['payment_method'] ?? '') !== 'LC' || $lc === null || $lc['lc_confirmed_at'] === null) {
            $back('error', 'Record the LC and confirm it usable (Step 5) first.');
            return;
        }
        if ($lc['lc_zoho_synced_at'] !== null) {
            $back('error', 'This LC payment is already in Zoho Books, so it cannot be changed here. Ask the CA to correct it there.');
            return;
        }
        $currency = (string) ($order['currency_code'] ?? 'INR');
        $receivedAt = trim((string) ($_POST['lc_received_at'] ?? ''));
        $amount = trim((string) ($_POST['lc_received_amount'] ?? ''));
        $inr = trim((string) ($_POST['lc_inr_credited'] ?? ''));
        $charges = trim((string) ($_POST['lc_bank_charges_inr'] ?? '')) ?: '0';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $receivedAt, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $receivedAt > date('Y-m-d')) {
            $back('error', 'Enter the date the bank paid (not in the future).');
            return;
        }
        if (!is_numeric($amount) || (float) $amount <= 0) {
            $back('error', "Enter the amount paid under the LC ({$currency}).");
            return;
        }
        if (!is_numeric($inr) || (float) $inr <= 0) {
            $back('error', 'Enter the rupees credited to the bank account.');
            return;
        }
        if (!is_numeric($charges) || (float) $charges < 0) {
            $back('error', 'Enter the bank charges deducted (₹), or 0.');
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($receivedAt, (int) $user['id'], $roleId, 'order_lc_details', $orderId, 'record')) {
            header("Location: /orders/{$orderId}#lc-received");
            return;
        }
        OrderLcDetailRepository::recordReceived($orderId, $receivedAt, (float) $amount, (float) $inr, (float) $charges, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'ORDER_LC_PAYMENT_RECEIVED', 'order_lc_details', $orderId, 'lc_received_amount', $lc['lc_received_amount'], $amount, "{$currency} {$amount} on {$receivedAt}: ₹{$inr} credited, ₹{$charges} bank charges");
        $back('success', "LC payment recorded: {$currency} {$amount}, ₹{$inr} credited.");
    }

    /**
     * Owner decision 2026-10-29 (CAFIN change confirmed twice): the LC
     * payment's FIRC / eBRC realization proof, like the other legs'.
     */
    public function recordLcFirc(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();
        $reference = trim((string) ($_POST['lc_firc_reference'] ?? ''));
        $back = static function (string $type, string $message) use ($orderId): void {
            Flash::set($type, $message);
            header("Location: /orders/{$orderId}#lc-received");
        };
        if ($reference === '' || mb_strlen($reference) > 100) {
            $back('error', 'Enter the FIRC/eBRC reference.');
            return;
        }
        $lc = OrderLcDetailRepository::find($orderId);
        if ($lc === null || $lc['lc_received_at'] === null) {
            $back('error', 'Record the LC payment itself before entering its FIRC/eBRC reference.');
            return;
        }
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow((string) $lc['lc_received_at'], (int) $user['id'], $roleId, 'order_lc_details', $orderId, 'lc_firc_reference')) {
            header("Location: /orders/{$orderId}#lc-received");
            return;
        }
        $receivedAt = trim((string) ($_POST['lc_firc_received_at'] ?? '')) ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedAt)) {
            $back('error', 'Enter a valid FIRC/eBRC date.');
            return;
        }
        OrderLcDetailRepository::setFirc($orderId, $reference, $receivedAt);
        AuditLogRepository::log((int) $user['id'], 'CA_FIRC_RECORDED', 'order_lc_details', $orderId, 'lc_firc_reference', $lc['lc_firc_reference'], $reference);
        $back('success', 'LC payment FIRC/eBRC reference recorded.');
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
        // Owner decision 2026-10-29: when NexaCrest pays, what it was paid from (the fee goes to Zoho Books from there).
        $paidFrom = (string) ($_POST['paid_from'] ?? '');
        if ($feePayer === 'nexacrest' && $feeAmount !== null && $feeAmount > 0 && !array_key_exists($paidFrom, OrderCostEntryRepository::PAID_FROM)) {
            Flash::set('error', 'Choose what the inspection fee was paid from.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }

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
            OrderCostEntryRepository::setPaidFrom((int) $costEntryId, $paidFrom);
        } elseif ($costEntryId) {
            // A fee already in Zoho Books is removed there first (owner decision 2026-10-29).
            $zohoError = self::removeFromZoho(OrderCostEntryRepository::find((int) $costEntryId) ?? [], (int) $user['id']);
            if ($zohoError !== null) {
                Flash::set('error', $zohoError);
                header("Location: /orders/{$orderId}#order-financials");
                return;
            }
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

    /**
     * Owner decision 2026-10-29: Zoho Books must match the app — a cost
     * entry already in Books is removed there first. Returns why it could
     * not be, or null.
     */
    private static function removeFromZoho(array $entry, int $userId): ?string
    {
        if (empty($entry['zoho_expense_id'])) {
            return null;
        }
        try {
            \App\Services\ZohoBooksService::deleteRecord('expense', (string) $entry['zoho_expense_id']);
        } catch (\Throwable $e) {
            return 'This cost is in Zoho Books and could not be removed there, so nothing was changed: ' . $e->getMessage();
        }
        \App\Repositories\ZohoSyncLogRepository::log('order_cost', 'order', (int) $entry['order_id'], 'cost', 'success', (string) $entry['zoho_expense_id'], 'Removed order cost "' . (OrderCostEntryRepository::CATEGORIES[$entry['category']] ?? $entry['category']) . '" (INR ' . number_format((float) $entry['amount_inr'], 2, '.', '') . ') from Zoho Books: it was removed in the app.', 'manual', $userId);
        return null;
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
        if (!empty($entry['incentive_kind'])) {
            Flash::set('error', 'This is the order\'s team incentive or associate commission. It is managed in Step 12d (Team Incentive), not here.');
            header("Location: /orders/{$orderId}#financials-12d");
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        if (!CaFyLockGuard::allow($entry['incurred_at'], (int) $user['id'], $roleId, 'order_cost_entries', $entryId, 'delete')) {
            header("Location: /orders/{$orderId}");
            return;
        }

        $zohoError = self::removeFromZoho($entry, (int) $user['id']);
        if ($zohoError !== null) {
            Flash::set('error', $zohoError);
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        OrderCostEntryRepository::delete($entryId);
        AuditLogRepository::log((int) $user['id'], 'ORDER_COST_ENTRY_DELETED', 'order_cost_entries', $entryId, null, $entry['category'], null);
        Flash::set('success', !empty($entry['zoho_expense_id']) ? 'Cost entry removed, here and in Zoho Books.' : 'Cost entry removed.');
        header("Location: /orders/{$orderId}#order-financials");
    }

    /**
     * Owner request (2026-10-26, t4): Step 12 can be skipped and marked
     * complete when there is nothing to record (reason required,
     * audit-logged). No cost, benefit or expense is written — the figures
     * stay blank rather than reading as 0. Export-benefit schemes still
     * have to be answered before the order can be marked fully settled.
     */
    public function skipFinancials(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!OrderRepository::find($orderId)) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $reason = trim((string) ($_POST['skip_reason'] ?? ''));
        if (($error = ReasonValidator::check($reason)) !== null) {
            Flash::set('error', $error);
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        $userId = (int) AuthService::currentUserId();
        OrderRepository::markFinancialsSkipped($orderId, $userId, $reason);
        AuditLogRepository::log($userId, 'ORDER_FINANCIALS_SKIPPED', 'orders', $orderId, 'financials_skipped_at', null, date('Y-m-d H:i:s'), $reason);
        Flash::set('success', 'Step 12 marked complete without financial figures — nothing was recorded as 0. You can still add figures later.');
        header("Location: /orders/{$orderId}#order-financials");
    }

    public function undoSkipFinancials(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        OrderRepository::clearFinancialsSkipped($orderId);
        AuditLogRepository::log((int) AuthService::currentUserId(), 'ORDER_FINANCIALS_SKIP_UNDONE', 'orders', $orderId, 'financials_skipped_at', (string) ($order['financials_skipped_at'] ?? ''), null);
        Flash::set('success', 'Step 12 reopened.');
        header("Location: /orders/{$orderId}#order-financials");
    }

    /**
     * Owner request (2026-10-26): Step 12 in guided sub-steps. 12a — the
     * order's costs are marked complete (or reopened to add more).
     */
    public function confirmCosts(array $params): void
    {
        $orderId = (int) $params['id'];
        if (!OrderRepository::find($orderId)) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $userId = (int) AuthService::currentUserId();
        $reopen = ($_POST['action'] ?? '') === 'reopen';
        OrderRepository::setFinancialsCostsConfirmed($orderId, $reopen ? null : $userId);
        AuditLogRepository::log($userId, $reopen ? 'ORDER_COSTS_REOPENED' : 'ORDER_COSTS_CONFIRMED', 'orders', $orderId, 'financials_costs_confirmed_at', null, null);
        Flash::set('success', $reopen ? 'Costs reopened (12a).' : 'Costs marked complete (12a). Next: export benefit eligibility (12b).');
        header("Location: /orders/{$orderId}#order-financials");
    }

    /**
     * 12d — Step 12 marked complete once costs are confirmed (12a) and
     * every scheme's eligibility is answered (12b). Benefit money (12c)
     * can still arrive later and be recorded then.
     */
    public function completeFinancials(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $userId = (int) AuthService::currentUserId();
        if (($_POST['action'] ?? '') === 'reopen') {
            OrderRepository::setFinancialsCompleted($orderId, null);
            AuditLogRepository::log($userId, 'ORDER_FINANCIALS_REOPENED', 'orders', $orderId, 'financials_completed_at', null, null);
            Flash::set('success', 'Step 12 reopened.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        $missing = [];
        if (empty($order['financials_costs_confirmed_at'])) {
            $missing[] = 'mark the costs complete (12a)';
        }
        if (\App\Repositories\OrderExportBenefitSchemeRepository::summaryForOrder($orderId)['pending'] > 0) {
            $missing[] = 'answer every scheme\'s eligibility (12b)';
        }
        if ($missing !== []) {
            Flash::set('error', 'Before completing Step 12: ' . implode(' and ', $missing) . '.');
            header("Location: /orders/{$orderId}#order-financials");
            return;
        }
        OrderRepository::setFinancialsCompleted($orderId, $userId);
        AuditLogRepository::log($userId, 'ORDER_FINANCIALS_COMPLETED', 'orders', $orderId, 'financials_completed_at', null, date('Y-m-d H:i:s'));
        Flash::set('success', 'Step 12 complete. Benefit money still to come can be recorded in 12c whenever it arrives.');
        header("Location: /orders/{$orderId}#order-financials");
    }

    /**
     * 12c — one Save for the benefits table: a row with no claim yet and a
     * claimed amount records the claim; a row whose claim is still waiting
     * and has a received amount records the money. All rows are checked
     * before anything is written.
     */
    public function saveExportBenefits(array $params): void
    {
        $orderId = (int) $params['id'];
        $order = OrderRepository::find($orderId);
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $isDate = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $isAmount = static fn(string $a, bool $allowZero): bool => is_numeric($a) && ($allowZero ? (float) $a >= 0 : (float) $a > 0);
        $fail = static function (string $message) use ($orderId): void {
            Flash::set('error', $message . ' Nothing was saved.');
            header("Location: /orders/{$orderId}#step-13g-benefits");
        };

        $claims = [];
        $receipts = [];
        foreach ((array) ($_POST['benefits'] ?? []) as $row) {
            $benefitId = (int) ($row['benefit_id'] ?? 0);
            $receivedAmount = trim((string) ($row['received_amount'] ?? ''));
            $receivedAt = trim((string) ($row['received_at'] ?? ''));
            if ($benefitId === 0) {
                $claimedAmount = trim((string) ($row['claimed_amount'] ?? ''));
                if ($claimedAmount === '') {
                    continue;
                }
                $scheme = trim((string) ($row['scheme_name'] ?? ''));
                $claimedAt = trim((string) ($row['claimed_at'] ?? ''));
                if ($scheme === '' || !$isAmount($claimedAmount, false) || !$isDate($claimedAt)) {
                    $fail("{$scheme}: enter the claimed amount and the date it was claimed.");
                    return;
                }
                $claims[] = [$scheme, trim((string) ($row['reference_number'] ?? '')) ?: null, (float) $claimedAmount, $claimedAt, $receivedAmount, $receivedAt];
                continue;
            }
            $benefit = CaExportBenefitRepository::find($benefitId);
            if (!$benefit || (int) ($benefit['order_id'] ?? 0) !== $orderId) {
                $fail('A claim on this form does not belong to this order.');
                return;
            }
            if ($receivedAmount !== '' && $benefit['received_amount'] === null) {
                $receipts[] = [$benefitId, $receivedAmount, $receivedAt];
            }
        }
        foreach ([...array_map(static fn($c) => [$c[4], $c[5], $c[0]], $claims), ...array_map(static fn($r) => [$r[1], $r[2], 'A claim'], $receipts)] as [$amount, $at, $label]) {
            if ($amount !== '' && (!$isAmount($amount, true) || !$isDate($at))) {
                $fail("{$label}: enter the received amount and the date it was received.");
                return;
            }
        }
        if ($claims === [] && $receipts === []) {
            Flash::set('success', 'Nothing new to save in 12c.');
            header("Location: /orders/{$orderId}#step-13g-benefits");
            return;
        }
        $payment = \App\Repositories\OrderPaymentStatusRepository::find($orderId);
        if ($claims !== [] && (!in_array($order['status'], ['shipped_docs_sent', 'complete'], true) || empty($payment['balance_remittance_received_at']))) {
            $fail('Claims can be recorded once the documents are sent (13f) and the balance remittance is received.');
            return;
        }
        foreach ($claims as [$scheme, , , $claimedAt, $receivedAmount, $receivedAt]) {
            if (!CaFyLockGuard::allow($claimedAt, (int) $user['id'], $roleId, 'ca_export_benefits', 0, 'record')
                || ($receivedAmount !== '' && !CaFyLockGuard::allow($receivedAt, (int) $user['id'], $roleId, 'ca_export_benefits', 0, 'mark_received'))) {
                header("Location: /orders/{$orderId}#step-13g-benefits");
                return;
            }
        }
        foreach ($receipts as [$benefitId, , $receivedAt]) {
            if (!CaFyLockGuard::allow($receivedAt, (int) $user['id'], $roleId, 'ca_export_benefits', $benefitId, 'mark_received')) {
                header("Location: /orders/{$orderId}#step-13g-benefits");
                return;
            }
        }

        foreach ($claims as [$scheme, $reference, $claimedAmount, $claimedAt, $receivedAmount, $receivedAt]) {
            $id = CaExportBenefitRepository::record($orderId, $scheme, $reference, $claimedAmount, $claimedAt, 'INR', null, (int) $user['id']);
            AuditLogRepository::log((int) $user['id'], 'CA_EXPORT_BENEFIT_RECORDED', 'ca_export_benefits', $id, null, null, $scheme);
            if ($receivedAmount !== '') {
                $receipts[] = [$id, $receivedAmount, $receivedAt];
            }
        }
        foreach ($receipts as [$benefitId, $receivedAmount, $receivedAt]) {
            CaExportBenefitRepository::markReceived($benefitId, (float) $receivedAmount, $receivedAt);
            AuditLogRepository::log((int) $user['id'], 'CA_EXPORT_BENEFIT_RECEIVED', 'ca_export_benefits', $benefitId, null, null, $receivedAmount);
        }
        Flash::set('success', 'Benefits saved — ' . count($claims) . ' claim(s) recorded, ' . count($receipts) . ' receipt(s) recorded.');
        header("Location: /orders/{$orderId}#step-13g-benefits");
    }
}
