<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\FinancialYear;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\CaBankStatementRepository;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaExportBenefitRepository;
use App\Repositories\CaFyLockRepository;
use App\Repositories\CaRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\LookupRepository;
use App\Repositories\OrderPaymentStatusRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Repositories\ZohoSyncLogRepository;
use App\Services\AuthService;
use App\Services\BankStatementCsvParser;
use App\Services\CaFyLockGuard;
use App\Services\CaSyncService;
use App\Services\PermissionService;
use App\Services\ZohoBooksService;

/**
 * CA / Accounting module — independent of the order-pipeline system. Every
 * route here is gated on ca_module_view (see public_html/index.php), never
 * on manage_orders, so a CA/Accounts-only user can reach this without any
 * order-management access.
 */
final class CaController
{
    private function usersById(): array
    {
        $usersById = [];
        foreach (UserRepository::listActive() as $u) {
            $usersById[(int) $u['id']] = $u['name'];
        }
        return $usersById;
    }

    public function index(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        View::render('ca/index', [
            'settlements' => CaRepository::settlementRegister(),
            'usersById' => $this->usersById(),
            // Point 3 (2026-10-01): zoho-sync and fy-locks are gated on the
            // stricter ca_module_manage, not the plain ca_module_view every
            // other link here needs — hiding their cards for a
            // view-only CA user avoids offering a link that 403s.
            'canManageCa' => PermissionService::can((int) $user['id'], $roleId, 'ca_module_manage'),
        ], 'layout/base');
    }

    /**
     * FY-wise or calendar-year-wise revenue report. Deliberately its own
     * report engine, sharing only the FinancialYear date-bucketing helper
     * with the order-pipeline Reports module — no shared tables, no shared
     * queries (Section 9 of the CA module brief: the two must never
     * intersect).
     */
    public function reports(array $params): void
    {
        $mode = ($_GET['mode'] ?? 'fy') === 'calendar' ? 'calendar' : 'fy';
        $availableFy = CaRepository::availableFinancialYears();
        $availableCalendar = CaRepository::availableCalendarYears();

        $period = trim((string) ($_GET['period'] ?? ''));
        if ($period === '') {
            $period = $mode === 'fy'
                ? ($availableFy[0] ?? FinancialYear::current())
                : (string) ($availableCalendar[0] ?? date('Y'));
        }

        $report = CaRepository::revenueReport($mode, $period);

        View::render('ca/reports', [
            'mode' => $mode,
            'period' => $period,
            'availableFy' => $availableFy,
            'availableCalendar' => $availableCalendar,
            'report' => $report,
            'usersById' => $this->usersById(),
            'gstin' => CompanySettingsRepository::get('gstin'),
            'lutNumber' => CompanySettingsRepository::get('lut_number'),
            'lutValidFy' => CompanySettingsRepository::get('lut_valid_fy'),
        ], 'layout/base');
    }

    /**
     * Zoho Books sync status page — config status, the "Sync Now" button,
     * and the independent zoho_sync_log history. Gated on ca_module_manage
     * (see public_html/index.php), a step up from ca_module_view, since
     * this exposes sync error detail and can call an external API.
     */
    public function zohoSync(array $params): void
    {
        View::render('ca/zoho_sync', [
            'isEnabled' => ZohoBooksService::isEnabled(),
            'pendingCount' => count(CaRepository::legsPendingZohoSync()),
            'log' => ZohoSyncLogRepository::recent(100),
        ], 'layout/base');
    }

    public function runZohoSync(array $params): void
    {
        $user = AuthService::currentUser();
        $result = CaSyncService::runFullSync('manual', (int) $user['id']);
        $revenue = $result['revenue'];
        $expenses = $result['expenses'];

        if ($revenue['failed'] > 0 || $expenses['failed'] > 0) {
            Flash::set('warning', "Sync finished: {$revenue['synced']} payment(s) pushed, {$expenses['imported']} expense(s) imported, " . ($revenue['failed'] + $expenses['failed']) . ' failed — see the log below for details.');
        } elseif ($revenue['synced'] > 0 || $expenses['imported'] > 0) {
            Flash::set('success', "Sync finished: {$revenue['synced']} payment(s) pushed, {$expenses['imported']} expense(s) imported.");
        } else {
            Flash::set('success', 'Sync ran — nothing was pending (or Zoho Books isn\'t configured yet). See the log below.');
        }
        header('Location: /ca/zoho-sync');
    }

    /**
     * Expenses imported one-way from Zoho Books (Phase 4). No create/edit
     * path for the expense data itself here — only the local-only TDS
     * annotation, which never pushes back to Zoho.
     */
    public function expenses(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        View::render('ca/expenses', [
            'expenses' => CaExpenseRepository::all(),
            'usersById' => $this->usersById(),
            'canEditTds' => PermissionService::can((int) $user['id'], $roleId, 'inr_actual_edit'),
            'canOverrideFyLock' => CaFyLockGuard::canOverride((int) $user['id'], $roleId),
        ], 'layout/base');
    }

    /** TDS Payable Summary — closes a real gap: the per-expense TDS flag existed but was never rolled up for compliance filing. */
    public function tdsSummary(array $params): void
    {
        View::render('ca/tds_summary', [
            'rows' => CaExpenseRepository::tdsSummary(),
        ], 'layout/base');
    }

    public function setExpenseTds(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();

        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $expense = CaExpenseRepository::find($id);
        if (!CaFyLockGuard::allow($expense !== null ? $expense['expense_date'] : null, (int) $user['id'], $roleId, 'ca_expenses', $id, 'tds')) {
            header('Location: /ca/expenses');
            return;
        }

        $isTdsApplicable = !empty($_POST['is_tds_applicable']);
        $tdsAmount = $isTdsApplicable && trim((string) ($_POST['tds_amount'] ?? '')) !== ''
            ? (float) $_POST['tds_amount']
            : null;

        CaExpenseRepository::setTds($id, $isTdsApplicable, $tdsAmount, (int) $user['id']);
        Flash::set('success', 'Expense TDS details updated.');
        header('Location: /ca/expenses');
    }

    /**
     * Point 2 follow-up — links (or, given a blank reference, unlinks) an
     * imported expense to the specific order it was actually incurred
     * for (e.g. ECGC insurance or a third-party inspection fee paid for
     * one shipment). Zoho Books has no concept of this app's order IDs,
     * so this is always a manual, local-only step — never pushed back to
     * Zoho, exactly like recordExportBenefit()'s order-reference lookup.
     */
    public function linkExpenseToOrder(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();

        $expense = CaExpenseRepository::find($id);
        if (!$expense) {
            Flash::set('error', 'Expense not found.');
            header('Location: /ca/expenses');
            return;
        }

        $orderRef = trim((string) ($_POST['order_reference'] ?? ''));
        $orderId = null;
        if ($orderRef !== '') {
            $orderId = OrderRepository::findIdByReference($orderRef);
            if ($orderId === null) {
                Flash::set('error', "No order found with reference \"{$orderRef}\" — the expense was not linked. Leave the field blank to unlink.");
                header('Location: /ca/expenses');
                return;
            }
        }

        CaExpenseRepository::linkToOrder($id, $orderId);
        AuditLogRepository::log(
            (int) $user['id'],
            $orderId !== null ? 'CA_EXPENSE_LINKED_TO_ORDER' : 'CA_EXPENSE_UNLINKED_FROM_ORDER',
            'ca_expenses',
            $id,
            'order_id',
            $expense['order_id'] !== null ? (string) $expense['order_id'] : null,
            $orderId !== null ? (string) $orderId : null
        );
        Flash::set('success', $orderId !== null ? "Expense linked to {$orderRef}." : 'Expense unlinked from its order.');
        header('Location: /ca/expenses');
    }

    /**
     * Point 2 follow-up — the one switch that controls whether this
     * order's linked government export benefits/expenses can ever be
     * assembled into a document at all, even an internal-only one. Off
     * by default for every order; gated on ca_internal_doc_manage (NOT
     * ca_module_view/inr_actual_edit) — Admin/MD/ED and Super Admin only,
     * unless explicitly granted to someone else via Roles & Permissions.
     * The document itself, once generated, can never reach a client —
     * see DocumentGenerationService::generateCaInternalAnnexure()'s
     * docblock for the structural (not just permission-based) reason why.
     */
    public function toggleInternalDoc(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();

        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header('Location: /orders');
            return;
        }

        $enabled = !empty($_POST['enabled']);
        OrderRepository::setCaInternalDocEnabled($orderId, $enabled);

        AuditLogRepository::log(
            (int) $user['id'],
            $enabled ? 'CA_INTERNAL_DOC_ENABLED' : 'CA_INTERNAL_DOC_DISABLED',
            'orders',
            $orderId,
            'ca_internal_doc_enabled',
            (string) (int) !empty($order['ca_internal_doc_enabled']),
            (string) (int) $enabled
        );

        Flash::set('success', $enabled
            ? 'Internal CA financial annexure enabled for this order — it can now be generated below.'
            : 'Internal CA financial annexure disabled for this order.');
        header("Location: /orders/{$orderId}");
    }

    /**
     * Generates (or regenerates) the internal-only CA Financial Annexure
     * for one order — refused unless toggleInternalDoc() has already
     * turned it on for this specific order, so generation is never
     * possible from a stale form left open after someone else disabled
     * it. Same permission as the toggle; viewing/downloading the result
     * afterwards only needs ca_module_view (see DocumentController::
     * download()'s CAFIN-specific check) since it shows nothing beyond
     * what that permission already exposes on this order's own page.
     */
    public function generateInternalDoc(array $params): void
    {
        $orderId = (int) $params['id'];
        $user = AuthService::currentUser();

        $order = OrderRepository::find($orderId);
        if (!$order) {
            Flash::set('error', 'Order not found.');
            header('Location: /orders');
            return;
        }
        if (empty($order['ca_internal_doc_enabled'])) {
            Flash::set('error', 'The internal CA financial annexure is not enabled for this order yet — enable it first.');
            header("Location: /orders/{$orderId}");
            return;
        }

        $result = \App\Services\DocumentGenerationService::generateCaInternalAnnexure($orderId, (int) $user['id']);

        AuditLogRepository::log(
            (int) $user['id'],
            'CA_INTERNAL_DOC_GENERATED',
            'orders',
            $orderId,
            'document_reference',
            null,
            $result['document_reference']
        );

        Flash::set('success', "Internal CA financial annexure generated: {$result['document_reference']}.");
        header("Location: /orders/{$orderId}");
    }

    /**
     * CA / Accounting module (Phase 8) — government export benefit/
     * incentive claims (RODTEP + whatever else Admin adds to
     * dropdown_options('export_benefit_scheme')). Unlike ca_expenses this
     * is money owed TO the company, entered locally since there's no Zoho
     * Books import for it.
     */
    public function exportBenefits(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        View::render('ca/export_benefits', [
            'benefits' => CaExportBenefitRepository::all(),
            'schemeOptions' => LookupRepository::dropdownOptions('export_benefit_scheme'),
            'totalClaimed' => CaExportBenefitRepository::totalClaimed(),
            'totalReceived' => CaExportBenefitRepository::totalReceived(),
            'canOverrideFyLock' => CaFyLockGuard::canOverride((int) $user['id'], $roleId),
        ], 'layout/base');
    }

    public function recordExportBenefit(array $params): void
    {
        $user = AuthService::currentUser();

        $schemeName = trim((string) ($_POST['scheme_name'] ?? ''));
        $validSchemes = array_column(LookupRepository::dropdownOptions('export_benefit_scheme'), 'option_value');
        if (!in_array($schemeName, $validSchemes, true)) {
            Flash::set('error', 'Choose a valid scheme.');
            header('Location: /ca/export-benefits');
            return;
        }

        $claimedAmount = trim((string) ($_POST['claimed_amount'] ?? ''));
        if ($claimedAmount === '' || !is_numeric($claimedAmount) || (float) $claimedAmount <= 0) {
            Flash::set('error', 'Enter a valid claimed amount.');
            header('Location: /ca/export-benefits');
            return;
        }

        $claimedAt = trim((string) ($_POST['claimed_at'] ?? ''));
        if ($claimedAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $claimedAt)) {
            Flash::set('error', 'Enter a valid claim date.');
            header('Location: /ca/export-benefits');
            return;
        }

        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        if (!CaFyLockGuard::allow($claimedAt, (int) $user['id'], $roleId, 'ca_export_benefits', 0, 'record')) {
            header('Location: /ca/export-benefits');
            return;
        }

        $orderId = null;
        $orderRef = trim((string) ($_POST['order_reference'] ?? ''));
        if ($orderRef !== '') {
            $orderId = OrderRepository::findIdByReference($orderRef);
            if ($orderId === null) {
                Flash::set('error', "No order found with reference \"{$orderRef}\" — the claim was not recorded. Leave the field blank if this benefit isn't tied to one order.");
                header('Location: /ca/export-benefits');
                return;
            }
        }

        $referenceNumber = trim((string) ($_POST['reference_number'] ?? '')) ?: null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $currencyCode = trim((string) ($_POST['currency_code'] ?? '')) ?: 'INR';

        $id = CaExportBenefitRepository::record($orderId, $schemeName, $referenceNumber, (float) $claimedAmount, $claimedAt, $currencyCode, $notes, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'CA_EXPORT_BENEFIT_RECORDED', 'ca_export_benefits', $id, null, null, $schemeName);
        Flash::set('success', "{$schemeName} claim recorded.");
        header('Location: /ca/export-benefits');
    }

    public function markExportBenefitReceived(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;

        $benefit = CaExportBenefitRepository::find($id);
        if (!$benefit) {
            http_response_code(404);
            echo 'Claim not found.';
            return;
        }

        $receivedAmount = trim((string) ($_POST['received_amount'] ?? ''));
        if ($receivedAmount === '' || !is_numeric($receivedAmount) || (float) $receivedAmount < 0) {
            Flash::set('error', 'Enter a valid received amount.');
            header('Location: /ca/export-benefits');
            return;
        }
        $receivedAt = trim((string) ($_POST['received_at'] ?? ''));
        if ($receivedAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedAt)) {
            Flash::set('error', 'Enter a valid received date.');
            header('Location: /ca/export-benefits');
            return;
        }

        if (!CaFyLockGuard::allow($receivedAt, (int) $user['id'], $roleId, 'ca_export_benefits', $id, 'mark_received')) {
            header('Location: /ca/export-benefits');
            return;
        }

        CaExportBenefitRepository::markReceived($id, (float) $receivedAmount, $receivedAt);
        AuditLogRepository::log((int) $user['id'], 'CA_EXPORT_BENEFIT_RECEIVED', 'ca_export_benefits', $id, 'received_amount', null, $receivedAmount);
        Flash::set('success', 'Marked as received.');
        header('Location: /ca/export-benefits');
    }

    /**
     * Bank statement lines (Phase 5) — upload a CSV export, then match each
     * line to a revenue leg or an expense. The two pickers only ever list
     * items not already matched to some other line, so the same revenue
     * leg/expense can't accidentally be double-matched.
     */
    public function bankStatement(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $canOverride = CaFyLockGuard::canOverride((int) $user['id'], $roleId);

        $matchedRevenueKeys = CaBankStatementRepository::matchedRevenueKeys();
        $matchedExpenseIds = CaBankStatementRepository::matchedExpenseIds();

        // Phase 6: a leg/expense whose own date falls in a locked financial
        // year is left off the matching pickers entirely — matching it now
        // would be a new entry against a year the CA has already closed,
        // exactly the kind of backdated change the lock exists to prevent.
        // Phase 7: unless the viewer holds ca_fy_lock_override, in which
        // case they can still see (and, on submit, log an override for)
        // a locked-FY item.
        $unmatchedRevenueLegs = array_values(array_filter(
            CaRepository::legsWithInrActualUnmatched($matchedRevenueKeys),
            static fn(array $leg): bool => $canOverride || CaFyLockRepository::lockMessageForDate($leg['cleared_at']) === null
        ));
        $unmatchedExpenses = array_values(array_filter(
            CaExpenseRepository::all(),
            static fn(array $e): bool => !in_array((int) $e['id'], $matchedExpenseIds, true)
                && ($canOverride || CaFyLockRepository::lockMessageForDate($e['expense_date']) === null)
        ));

        View::render('ca/bank_statement', [
            'lines' => CaBankStatementRepository::all(),
            'unmatchedRevenueLegs' => $unmatchedRevenueLegs,
            'unmatchedExpenses' => $unmatchedExpenses,
            'canOverrideFyLock' => $canOverride,
        ], 'layout/base');
    }

    public function uploadBankStatement(array $params): void
    {
        $user = AuthService::currentUser();
        if (empty($_FILES['statement']) || $_FILES['statement']['error'] !== UPLOAD_ERR_OK) {
            Flash::set('error', 'No file was uploaded, or the upload failed.');
            header('Location: /ca/bank-statement');
            return;
        }
        $extension = strtolower((string) pathinfo($_FILES['statement']['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            Flash::set('error', 'Only .csv files are supported — export your bank statement as CSV first.');
            header('Location: /ca/bank-statement');
            return;
        }

        try {
            $parsed = BankStatementCsvParser::parse($_FILES['statement']['tmp_name']);
        } catch (\RuntimeException $e) {
            Flash::set('error', 'Could not read the file: ' . $e->getMessage());
            header('Location: /ca/bank-statement');
            return;
        }

        $imported = 0;
        $duplicates = 0;
        foreach ($parsed['rows'] as $row) {
            $hash = hash('sha256', implode('|', [$row['date'], (string) $row['description'], (string) $row['reference'], (string) $row['credit'], (string) $row['debit']]));
            $inserted = CaBankStatementRepository::insertLine($hash, $row['date'], $row['description'], $row['reference'], $row['credit'], $row['debit'], (int) $user['id']);
            if ($inserted) {
                $imported++;
            } else {
                $duplicates++;
            }
        }

        Flash::set('success', "Imported {$imported} line(s). {$duplicates} already-imported duplicate(s) skipped, {$parsed['skipped']} unparseable row(s) skipped.");
        header('Location: /ca/bank-statement');
    }

    public function matchBankLineToRevenue(array $params): void
    {
        $lineId = (int) $params['id'];
        $user = AuthService::currentUser();
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $leg = (string) ($_POST['leg'] ?? '');
        if ($orderId <= 0 || !in_array($leg, ['advance', 'balance', 'freight'], true)) {
            Flash::set('error', 'Choose a revenue leg to match.');
            header('Location: /ca/bank-statement');
            return;
        }

        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $ops = OrderPaymentStatusRepository::find($orderId) ?? [];
        $clearedAt = $ops[$leg . '_cleared_at'] ?? null;
        if (!CaFyLockGuard::allow($clearedAt, (int) $user['id'], $roleId, 'order_payment_status', $orderId, "{$leg}_bank_match")) {
            header('Location: /ca/bank-statement');
            return;
        }

        CaBankStatementRepository::matchToRevenue($lineId, $orderId, $leg, (int) $user['id']);
        Flash::set('success', 'Bank line matched to the settlement leg.');
        header('Location: /ca/bank-statement');
    }

    public function matchBankLineToExpense(array $params): void
    {
        $lineId = (int) $params['id'];
        $user = AuthService::currentUser();
        $expenseId = (int) ($_POST['expense_id'] ?? 0);
        if ($expenseId <= 0) {
            Flash::set('error', 'Choose an expense to match.');
            header('Location: /ca/bank-statement');
            return;
        }

        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $expense = CaExpenseRepository::find($expenseId);
        if (!CaFyLockGuard::allow($expense !== null ? $expense['expense_date'] : null, (int) $user['id'], $roleId, 'ca_expenses', $expenseId, 'bank_match')) {
            header('Location: /ca/bank-statement');
            return;
        }

        CaBankStatementRepository::matchToExpense($lineId, $expenseId, (int) $user['id']);
        Flash::set('success', 'Bank line matched to the expense.');
        header('Location: /ca/bank-statement');
    }

    public function unmatchBankLine(array $params): void
    {
        $lineId = (int) $params['id'];
        $user = AuthService::currentUser();
        $roleId = $user['role_id'] !== null ? (int) $user['role_id'] : null;
        $line = CaBankStatementRepository::find($lineId);
        $allowed = true;
        if ($line !== null) {
            if ($line['matched_order_id'] !== null && $line['matched_leg'] !== null) {
                $ops = OrderPaymentStatusRepository::find((int) $line['matched_order_id']) ?? [];
                $allowed = CaFyLockGuard::allow($ops[$line['matched_leg'] . '_cleared_at'] ?? null, (int) $user['id'], $roleId, 'order_payment_status', (int) $line['matched_order_id'], "{$line['matched_leg']}_bank_unmatch");
            } elseif ($line['matched_expense_id'] !== null) {
                $expense = CaExpenseRepository::find((int) $line['matched_expense_id']);
                $allowed = CaFyLockGuard::allow($expense !== null ? $expense['expense_date'] : null, (int) $user['id'], $roleId, 'ca_expenses', (int) $line['matched_expense_id'], 'bank_unmatch');
            }
        }
        if (!$allowed) {
            header('Location: /ca/bank-statement');
            return;
        }

        CaBankStatementRepository::unmatch($lineId);
        Flash::set('success', 'Match removed.');
        header('Location: /ca/bank-statement');
    }

    /**
     * All-time reconciliation summary: bank statement totals vs. recorded
     * revenue/expense totals, plus what's still unmatched on each side.
     * Deliberately all-time rather than period-filtered for now — see
     * ca-05-bank-reconciliation.md.
     */
    public function reconciliation(array $params): void
    {
        $bankTotals = CaBankStatementRepository::totals();
        $matchedRevenueKeys = CaBankStatementRepository::matchedRevenueKeys();
        $matchedExpenseIds = CaBankStatementRepository::matchedExpenseIds();

        View::render('ca/reconciliation', [
            'bankTotals' => $bankTotals,
            'totalRevenue' => CaRepository::totalInrActualAll(),
            'totalExpenses' => CaExpenseRepository::totalAll(),
            'unmatchedRevenueLegs' => CaRepository::legsWithInrActualUnmatched($matchedRevenueKeys),
            'unmatchedExpenses' => array_values(array_filter(
                CaExpenseRepository::all(),
                static fn(array $e): bool => !in_array((int) $e['id'], $matchedExpenseIds, true)
            )),
            'unmatchedBankLines' => array_values(array_filter(
                CaBankStatementRepository::all(),
                static fn(array $l): bool => $l['matched_order_id'] === null && $l['matched_expense_id'] === null
            )),
        ], 'layout/base');
    }

    /**
     * Year-end financial year lock (Phase 6) — gated on ca_module_manage,
     * the same admin tier as the Zoho Books sync page, since locking a year
     * is an administrative action with a wider blast radius than routine
     * CA data entry.
     */
    public function fyLocks(array $params): void
    {
        $years = array_values(array_unique(array_merge(
            CaRepository::availableFinancialYears(),
            CaExpenseRepository::availableFinancialYears(),
            [FinancialYear::current()]
        )));
        rsort($years);

        $lockedYears = CaFyLockRepository::lockedYears();

        // FY-Close Readiness (closes a real gap: locking previously showed
        // nothing about whether a year was actually ready to close) — only
        // computed for still-open years, since a locked year's readiness is
        // moot.
        $readinessByYear = [];
        foreach ($years as $fy) {
            if (!in_array($fy, $lockedYears, true)) {
                $readinessByYear[$fy] = CaRepository::fyReadiness($fy);
            }
        }

        View::render('ca/fy_locks', [
            'years' => $years,
            'lockedYears' => $lockedYears,
            'readinessByYear' => $readinessByYear,
            'history' => CaFyLockRepository::history(),
            'recentOverrides' => AuditLogRepository::search(actionType: 'CA_FY_LOCK_OVERRIDDEN', limit: 20),
            'usersById' => $this->usersById(),
        ], 'layout/base');
    }

    public function lockFinancialYear(array $params): void
    {
        $user = AuthService::currentUser();
        $fy = trim((string) ($_POST['financial_year'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $fy)) {
            Flash::set('error', 'Choose a valid financial year to lock.');
            header('Location: /ca/fy-locks');
            return;
        }
        if (CaFyLockRepository::isLocked($fy)) {
            Flash::set('error', "FY {$fy} is already locked.");
            header('Location: /ca/fy-locks');
            return;
        }
        CaFyLockRepository::lock($fy, (int) $user['id']);
        Flash::set('success', "FY {$fy} locked. No CA data entry against that year will be accepted until it's reopened.");
        header('Location: /ca/fy-locks');
    }

    public function unlockFinancialYear(array $params): void
    {
        $user = AuthService::currentUser();
        $fy = trim((string) ($_POST['financial_year'] ?? ''));
        $reason = trim((string) ($_POST['unlock_reason'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $fy) || $reason === '') {
            Flash::set('error', 'Enter a reason for reopening this financial year.');
            header('Location: /ca/fy-locks');
            return;
        }
        if (!CaFyLockRepository::isLocked($fy)) {
            Flash::set('error', "FY {$fy} isn't currently locked.");
            header('Location: /ca/fy-locks');
            return;
        }
        CaFyLockRepository::unlock($fy, (int) $user['id'], $reason);
        Flash::set('success', "FY {$fy} reopened for CA data entry.");
        header('Location: /ca/fy-locks');
    }
}
