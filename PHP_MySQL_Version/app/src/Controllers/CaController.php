<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\FinancialYear;
use App\Helpers\View;
use App\Repositories\CaBankStatementRepository;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Repositories\ZohoSyncLogRepository;
use App\Services\AuthService;
use App\Services\BankStatementCsvParser;
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
        View::render('ca/index', [
            'settlements' => CaRepository::settlementRegister(),
            'usersById' => $this->usersById(),
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
        ], 'layout/base');
    }

    public function setExpenseTds(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        $isTdsApplicable = !empty($_POST['is_tds_applicable']);
        $tdsAmount = $isTdsApplicable && trim((string) ($_POST['tds_amount'] ?? '')) !== ''
            ? (float) $_POST['tds_amount']
            : null;

        CaExpenseRepository::setTds($id, $isTdsApplicable, $tdsAmount, (int) $user['id']);
        Flash::set('success', 'Expense TDS details updated.');
        header('Location: /ca/expenses');
    }

    /**
     * Bank statement lines (Phase 5) — upload a CSV export, then match each
     * line to a revenue leg or an expense. The two pickers only ever list
     * items not already matched to some other line, so the same revenue
     * leg/expense can't accidentally be double-matched.
     */
    public function bankStatement(array $params): void
    {
        $matchedRevenueKeys = CaBankStatementRepository::matchedRevenueKeys();
        $matchedExpenseIds = CaBankStatementRepository::matchedExpenseIds();

        View::render('ca/bank_statement', [
            'lines' => CaBankStatementRepository::all(),
            'unmatchedRevenueLegs' => CaRepository::legsWithInrActualUnmatched($matchedRevenueKeys),
            'unmatchedExpenses' => array_values(array_filter(
                CaExpenseRepository::all(),
                static fn(array $e): bool => !in_array((int) $e['id'], $matchedExpenseIds, true)
            )),
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
        CaBankStatementRepository::matchToExpense($lineId, $expenseId, (int) $user['id']);
        Flash::set('success', 'Bank line matched to the expense.');
        header('Location: /ca/bank-statement');
    }

    public function unmatchBankLine(array $params): void
    {
        CaBankStatementRepository::unmatch((int) $params['id']);
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
}
