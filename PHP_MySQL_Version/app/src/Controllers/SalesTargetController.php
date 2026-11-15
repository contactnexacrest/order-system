<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AssociateRepository;
use App\Repositories\SalesTargetRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\SalesTargetService;

/**
 * Owner decision D-378 (15 Nov 2026): sales targets — the Targets page, its settings and the
 * MD / ED's edits. D-379: GET /sales-targets opens at the Department level of the "Sales targets"
 * report (read-only team view of the viewer's departments, views/sales_targets/team.php); the full
 * page and every other route need the All level, which itself needs manage_sales_targets; money figures
 * (₹, margins, average margin per container) also need view_price_buildup
 * (SalesTargetService::canSeeMoney()). Salary is never posted: the calculator on the Targets page
 * runs in the browser only, its inputs have no name and sit outside every form.
 *
 * Routes:
 *   GET  /sales-targets                    index()
 *   GET  /sales-targets/settings           settings()
 *   POST /sales-targets/settings           saveSettings()
 *   POST /sales-targets/person/{id}        savePerson()
 *   POST /sales-targets/associate/{id}     saveAssociate()
 *   POST /sales-targets/credit/{orderId}   moveCredit()
 *
 * Node twin: NODE_MySQL_Version/app/src/controllers/salesTargetController.js
 */
final class SalesTargetController
{
    public function index(array $params): void
    {
        SalesTargetService::sync();
        $settings = SalesTargetService::settings();
        $today = date('Y-m-d');
        $periods = SalesTargetService::recentPeriods((string) $settings['period'], $today, 4);
        $chosen = $periods[0];
        foreach ($periods as $p) {
            if (($_GET['period'] ?? '') === $p['key']) {
                $chosen = $p;
            }
        }
        // Owner decision D-379: below All (Department level), the read-only team view of the viewer's departments —
        // containers only: no Edit, no money, no calculator, no associates, no settings link.
        if (\App\Services\ReportAccess::level('sales_targets') !== \App\Services\ReportAccess::ALL) {
            $team = SalesTargetService::teamData($chosen, $today);
            $me = (int) (AuthService::currentUser()['id'] ?? 0);
            $members = array_flip(\App\Services\ReportAccess::departmentMemberIds($me));
            View::render('sales_targets/team', [
                'periods' => $periods,
                'period' => $chosen,
                'rows' => array_values(array_filter($team['rows'], static fn (array $r): bool => isset($members[(int) $r['user']['id']]))),
            ], 'layout/base');
            return;
        }
        $canMoney = SalesTargetService::canSeeMoney();
        View::render('sales_targets/index', [
            'periods' => $periods,
            // Not 'data': View::capture() extracts with EXTR_SKIP, so a 'data' key would never reach the view.
            'team' => SalesTargetService::teamData($chosen, $today),
            'canMoney' => $canMoney,
            // Printed for the calculator only to holders of view_price_buildup; otherwise typed.
            'avgMargin' => $canMoney ? SalesTargetService::avgMarginPerContainer($today) : null,
        ], 'layout/base');
    }

    public function settings(array $params): void
    {
        $canMoney = SalesTargetService::canSeeMoney();
        View::render('sales_targets/settings', [
            'settings' => SalesTargetService::settings(),
            'company' => $canMoney ? SalesTargetRepository::latestCompanyRow() : null,
            'designations' => SalesTargetRepository::designations(),
            'defaults' => SalesTargetRepository::targetsByScope('designation'),
            'canMoney' => $canMoney,
        ], 'layout/base');
    }

    public function saveSettings(array $params): void
    {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /sales-targets/settings');
            return;
        }
        $canMoney = SalesTargetService::canSeeMoney();
        [$values, $errors] = SalesTargetService::validateSettings($_POST, $canMoney);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: /sales-targets/settings');
            return;
        }
        $changes = SalesTargetService::saveSettings($values, $canMoney, (int) AuthService::currentUserId(), $reason);
        Flash::set('success', $changes === [] ? 'Nothing changed.' : 'Sales target settings saved (' . implode(', ', $changes) . ').');
        header('Location: /sales-targets/settings');
    }

    public function savePerson(array $params): void
    {
        $personId = (int) $params['id'];
        if (UserRepository::findById($personId) === null) {
            Flash::set('error', 'Person not found.');
            header('Location: /sales-targets');
            return;
        }
        $error = SalesTargetService::savePerson($personId, $_POST, (int) AuthService::currentUserId());
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Target saved and marked reviewed today.');
        header('Location: /sales-targets' . self::periodQuery());
    }

    public function saveAssociate(array $params): void
    {
        $associateId = (int) $params['id'];
        if (AssociateRepository::find($associateId) === null) {
            Flash::set('error', 'Associate not found.');
            header('Location: /sales-targets');
            return;
        }
        $error = SalesTargetService::saveAssociate($associateId, $_POST, (int) AuthService::currentUserId());
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Associate target saved.');
        header('Location: /sales-targets' . self::periodQuery());
    }

    public function moveCredit(array $params): void
    {
        $orderId = (int) $params['orderId'];
        if (\App\Repositories\OrderRepository::find($orderId) === null) {
            Flash::set('error', 'Order not found.');
            header('Location: /sales-targets');
            return;
        }
        $error = SalesTargetService::moveCredit($orderId, (string) ($_POST['user_id'] ?? ''), trim((string) ($_POST['reason'] ?? '')), (int) AuthService::currentUserId());
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Target credit moved.');
        header('Location: /sales-targets' . self::periodQuery());
    }

    /** Back to the period the form was sent from (?period=YYYY-MM-DD), when it is one. */
    private static function periodQuery(): string
    {
        $p = (string) ($_POST['period'] ?? '');
        return preg_match('/^\d{4}-\d{2}-01$/', $p) ? '?period=' . $p : '';
    }
}
