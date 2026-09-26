<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\FinancialYear;
use App\Helpers\View;
use App\Repositories\CaRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\UserRepository;

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
}
