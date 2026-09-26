<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\FinancialYear;
use App\Helpers\View;
use App\Repositories\CaRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;
use App\Repositories\ZohoSyncLogRepository;
use App\Services\AuthService;
use App\Services\CaSyncService;
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
        $result = CaSyncService::syncPendingRevenue('manual', (int) $user['id']);
        if ($result['failed'] > 0) {
            Flash::set('warning', "Sync finished: {$result['synced']} pushed, {$result['failed']} failed — see the log below for details.");
        } elseif ($result['synced'] > 0) {
            Flash::set('success', "Sync finished: {$result['synced']} settlement leg(s) pushed to Zoho Books.");
        } else {
            Flash::set('success', 'Sync ran — nothing was pending (or Zoho Books isn\'t configured yet). See the log below.');
        }
        header('Location: /ca/zoho-sync');
    }
}
