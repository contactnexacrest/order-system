<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\FinancialYear;
use App\Helpers\View;
use App\Repositories\CaExpenseRepository;
use App\Repositories\CaRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Repositories\ZohoSyncLogRepository;
use App\Services\AuthService;
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
}
