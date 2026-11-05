<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\Input;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DivisionRepository;
use App\Services\AuthService;
use App\Services\DivisionService;

/**
 * Owner decision D-257 (5 Nov 2026): divisions.
 *  - POST /divisions/choose — the header switcher (company level, two or more active divisions).
 *  - Admin › Divisions (manage_divisions; the MD): modules, the division's own
 *    settings, the pre-flight checklist and Activate. Export cannot be deactivated.
 *  - The Division of a user / a department (shown only with two or more active divisions).
 * Port: divisionController.js.
 */
final class DivisionController
{
    public const SETTING_SCOPES = ['company' => 'Company setting', 'pricing' => 'Pricing setting', 'incentive' => 'Incentive setting'];

    public function choose(array $params): void
    {
        if (DivisionService::showSwitcher()) {
            DivisionService::choose(Input::scalarString($_POST['division'] ?? 'all'));
        }
        $back = (string) ($_POST['back'] ?? '/');
        header('Location: ' . (preg_match('#^/[^/]#', $back) === 1 || $back === '/' ? $back : '/'));
    }

    public function index(array $params): void
    {
        $rows = [];
        foreach (DivisionService::all() as $d) {
            $rows[] = $d + ['counts' => DivisionRepository::counts((int) $d['id'])];
        }
        View::render('divisions/index', ['divisions' => $rows], 'layout/base');
    }

    public function show(array $params): void
    {
        $division = DivisionService::find((int) $params['id']);
        if ($division === null) {
            http_response_code(404);
            echo 'Division not found.';
            return;
        }
        $id = (int) $division['id'];
        View::render('divisions/show', [
            'division' => $division,
            'modules' => DivisionRepository::modules($id),
            'settings' => DivisionRepository::settings($id),
            'counts' => DivisionRepository::counts($id),
            'preflight' => $division['status'] === 'planned' ? DivisionRepository::preflight($id) : [],
            'orderRefFormat' => self::numberFormat($id),
            'starterDepartments' => DivisionRepository::STARTER_DEPARTMENTS[$division['code']] ?? [],
            'staffChoices' => $division['status'] === 'planned' ? DivisionRepository::staffChoices($id) : [],
        ], 'layout/base');
    }

    /** The order number format this division uses (its own, else the company one; non-Export numbers start with the code). */
    public static function numberFormat(int $divisionId): string
    {
        $own = null;
        foreach (DivisionRepository::settings($divisionId) as $row) {
            if ($row['scope'] === 'company' && $row['setting_key'] === 'order_ref_format') {
                $own = (string) $row['setting_value'];
            }
        }
        $company = (string) (\App\Repositories\CompanySettingsRepository::companyValue('order_ref_format') ?: \App\Services\ReferenceNumberService::DEFAULT_ORDER_REF_FORMAT);
        $division = DivisionService::find($divisionId);
        if ($own !== null) {
            return $own;
        }
        return $divisionId === DivisionService::EXPORT_ID ? $company : ($division['code'] ?? '') . '/' . $company;
    }

    public function saveModules(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        if (DivisionService::find($id) === null) {
            http_response_code(404);
            return;
        }
        $keys = array_values(array_intersect(array_keys(DivisionService::MODULES), array_map('strval', (array) ($_POST['modules'] ?? []))));
        DivisionRepository::saveModules($id, $keys, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'DIVISION_MODULES_SAVED', 'divisions', $id, 'modules', null, implode(', ', $keys));
        Flash::set('success', 'Modules saved.');
        header("Location: /admin/divisions/{$id}");
    }

    public function saveSetting(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        $scope = (string) ($_POST['scope'] ?? '');
        $key = Input::scalarString($_POST['setting_key'] ?? '');
        $value = Input::scalarString($_POST['setting_value'] ?? '');
        $error = match (true) {
            DivisionService::find($id) === null => 'Division not found.',
            !isset(self::SETTING_SCOPES[$scope]) => 'Choose which kind of setting this is.',
            preg_match('/^[a-z][a-z0-9_:.\-]{1,99}$/i', $key) !== 1 => 'Enter the setting name exactly as on its settings page (e.g. order_ref_format).',
            mb_strlen($value) > 2000 => 'The value is too long (2,000 characters at most).',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /admin/divisions/{$id}");
            return;
        }
        DivisionRepository::setSetting($id, $scope, $key, $value, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'DIVISION_SETTING_SAVED', 'divisions', $id, $scope . ':' . $key, null, $value);
        Flash::set('success', "Setting \"{$key}\" saved for this division.");
        header("Location: /admin/divisions/{$id}");
    }

    public function deleteSetting(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        $scope = (string) ($_POST['scope'] ?? '');
        $key = Input::scalarString($_POST['setting_key'] ?? '');
        DivisionRepository::deleteSetting($id, $scope, $key);
        AuditLogRepository::log((int) $user['id'], 'DIVISION_SETTING_REMOVED', 'divisions', $id, $scope . ':' . $key, null, null);
        Flash::set('success', "Setting \"{$key}\" removed: the company value applies again.");
        header("Location: /admin/divisions/{$id}");
    }

    public function activate(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        $division = DivisionService::find($id);
        if ($division === null || $division['status'] !== 'planned') {
            Flash::set('error', 'Only a planned division can be activated.');
            header("Location: /admin/divisions/{$id}");
            return;
        }
        $missing = array_filter(DivisionRepository::preflight($id), static fn (array $i): bool => !$i['ok']);
        if ($missing !== [] || ($_POST['confirm'] ?? '') !== '1') {
            Flash::set('error', $missing !== [] ? 'Finish the checklist first: ' . implode('; ', array_column($missing, 'label')) . '.' : 'Tick the box to confirm.');
            header("Location: /admin/divisions/{$id}");
            return;
        }
        DivisionRepository::setStatus($id, 'active', (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'DIVISION_ACTIVATED', 'divisions', $id, 'status', 'planned', 'active');
        Flash::set('success', "{$division['name']} is now active. Company-level people now see the division switcher.");
        header("Location: /admin/divisions/{$id}");
    }

    public function deactivate(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        $division = DivisionService::find($id);
        $counts = $division !== null ? DivisionRepository::counts($id) : ['orders' => 0];
        $error = match (true) {
            $division === null => 'Division not found.',
            $id === DivisionService::EXPORT_ID => 'Export is the company\'s main division and always stays active.',
            $division['status'] !== 'active' => 'This division is not active.',
            $counts['orders'] > 0 => 'This division already has orders, so it stays active.',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /admin/divisions/{$id}");
            return;
        }
        DivisionRepository::setStatus($id, 'planned', (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'DIVISION_DEACTIVATED', 'divisions', $id, 'status', 'active', 'planned');
        Flash::set('success', "{$division['name']} is back to planned.");
        header("Location: /admin/divisions/{$id}");
    }

    /** D-257 Phase 2: creates a planned division's starting departments (Domestic Trade: Sales, Purchase, Dispatch). */
    public function starterDepartments(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        $division = DivisionService::find($id);
        if ($division === null || $id === DivisionService::EXPORT_ID) {
            Flash::set('error', 'Division not found.');
            header('Location: /admin/divisions');
            return;
        }
        $made = DivisionRepository::createStarterDepartments($id);
        AuditLogRepository::log((int) $user['id'], 'DIVISION_STARTER_DEPARTMENTS', 'divisions', $id, 'departments', null, (string) $made);
        Flash::set('success', $made > 0 ? "{$made} departments created for {$division['name']}. Add their designations in Admin › Departments." : 'Its starting departments already exist.');
        header("Location: /admin/divisions/{$id}");
    }

    /**
     * D-257 Phase 2: puts a staff member into a division from Admin › Divisions —
     * the way to staff a planned division before it is activated (the user's own
     * Division field only appears once two divisions are active).
     */
    public function addStaff(array $params): void
    {
        $id = (int) $params['id'];
        $user = AuthService::currentUser();
        $division = DivisionService::find($id);
        $staffId = (int) ($_POST['user_id'] ?? 0);
        $staff = $staffId > 0 ? \App\Repositories\UserRepository::findById($staffId) : null;
        $error = match (true) {
            $division === null => 'Division not found.',
            $staff === null || (int) ($staff['is_active'] ?? 0) !== 1 => 'Choose an active staff member.',
            $staff['division_id'] === null => 'This person works at company level (sees every division); their division is not changed here.',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header("Location: /admin/divisions/{$id}");
            return;
        }
        DivisionRepository::setUserDivision($staffId, $id);
        AuditLogRepository::log((int) $user['id'], 'USER_DIVISION_SET', 'users', $staffId, 'division_id', (string) $staff['division_id'], (string) $id);
        Flash::set('success', "{$staff['name']} now works in {$division['name']}.");
        header("Location: /admin/divisions/{$id}");
    }

    public function setUserDivision(array $params): void
    {
        $userId = (int) $params['id'];
        $user = AuthService::currentUser();
        $raw = (string) ($_POST['division_id'] ?? '');
        $divisionId = $raw === '' ? null : (int) $raw;
        if (!DivisionService::isMulti() || ($divisionId !== null && DivisionService::find($divisionId) === null)) {
            Flash::set('error', 'Choose a division.');
            header("Location: /users/{$userId}/edit");
            return;
        }
        DivisionRepository::setUserDivision($userId, $divisionId);
        AuditLogRepository::log((int) $user['id'], 'USER_DIVISION_SET', 'users', $userId, 'division_id', null, $divisionId === null ? 'company level' : (string) $divisionId);
        Flash::set('success', 'Division saved.');
        header("Location: /users/{$userId}/edit");
    }

    public function setDepartmentDivision(array $params): void
    {
        $departmentId = (int) $params['id'];
        $user = AuthService::currentUser();
        $raw = (string) ($_POST['division_id'] ?? '');
        $divisionId = $raw === '' ? null : (int) $raw;
        if (!DivisionService::isMulti() || ($divisionId !== null && DivisionService::find($divisionId) === null)) {
            Flash::set('error', 'Choose a division.');
            header('Location: /admin/departments');
            return;
        }
        DivisionRepository::setDepartmentDivision($departmentId, $divisionId);
        AuditLogRepository::log((int) $user['id'], 'DEPARTMENT_DIVISION_SET', 'departments', $departmentId, 'division_id', null, $divisionId === null ? 'company level' : (string) $divisionId);
        Flash::set('success', 'Division saved.');
        header('Location: /admin/departments');
    }
}
