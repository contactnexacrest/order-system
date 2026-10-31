<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\Input;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * Owner decision D-214 (2026-10-31), phase 1: Admin → Departments &
 * designations (each designation tied to its own role, i.e. permission set),
 * and a person's department memberships on their user page — the only
 * day-to-day change. Port: departmentAdminController.js.
 */
final class DepartmentAdminController
{
    private const BACK = 'Location: /admin/departments';

    public function index(array $params): void
    {
        $designations = DepartmentRepository::designations();
        $byDepartment = [];
        foreach ($designations as $d) {
            $byDepartment[(int) $d['department_id']][] = $d;
        }
        View::render('departments/admin', [
            'departments' => DepartmentRepository::departments(),
            'byDepartment' => $byDepartment,
            'levels' => DepartmentRepository::LEVELS,
            'roles' => RoleRepository::all(),
        ], 'layout/base');
    }

    public function createDepartment(array $params): void
    {
        $user = AuthService::currentUser();
        $name = Input::scalarString($_POST['name'] ?? '');
        $code = strtoupper(Input::scalarString($_POST['code'] ?? ''));
        $color = (string) ($_POST['color'] ?? '#3a4140');
        $error = match (true) {
            mb_strlen($name) < 2 || mb_strlen($name) > 100 => 'Enter the department name (2 to 100 characters).',
            preg_match('/^[A-Z][A-Z0-9_]{1,19}$/', $code) !== 1 => 'Enter a short code: 2 to 20 capital letters, digits or _ (e.g. QUALITY).',
            preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1 => 'Choose a colour.',
            DepartmentRepository::departmentNameTaken($name) => "A department named \"{$name}\" already exists.",
            DepartmentRepository::departmentCodeTaken($code) => "The code {$code} is already used.",
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header(self::BACK);
            return;
        }
        $id = DepartmentRepository::createDepartment($code, $name, $color);
        AuditLogRepository::log((int) $user['id'], 'DEPARTMENT_CREATED', 'departments', $id, 'name', null, $name);
        Flash::set('success', "Department \"{$name}\" created. Add its designations below.");
        header(self::BACK);
    }

    public function updateDepartment(array $params): void
    {
        $user = AuthService::currentUser();
        $dept = DepartmentRepository::findDepartment((int) $params['id']);
        if ($dept === null) {
            http_response_code(404);
            echo 'Department not found.';
            return;
        }
        $name = Input::scalarString($_POST['name'] ?? '');
        $color = (string) ($_POST['color'] ?? $dept['color']);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1 || DepartmentRepository::departmentNameTaken($name, (int) $dept['id'])) {
            Flash::set('error', 'Enter a unique department name (2 to 100 characters) and a colour.');
            header(self::BACK);
            return;
        }
        DepartmentRepository::updateDepartment((int) $dept['id'], $name, $color, !empty($_POST['is_active']), !empty($_POST['sees_whole_company']));
        AuditLogRepository::log((int) $user['id'], 'DEPARTMENT_UPDATED', 'departments', (int) $dept['id'], null,
            "{$dept['name']} (" . ($dept['is_active'] ? 'active' : 'inactive') . ')', "{$name} (" . (!empty($_POST['is_active']) ? 'active' : 'inactive') . ')');
        Flash::set('success', "Department \"{$name}\" saved.");
        header(self::BACK);
    }

    /** A new designation; its role is a new one named after it, or an existing role chosen from the list. */
    public function createDesignation(array $params): void
    {
        $user = AuthService::currentUser();
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $dept = DepartmentRepository::findDepartment($departmentId);
        $title = Input::scalarString($_POST['title'] ?? '');
        $level = (string) ($_POST['level'] ?? '');
        $roleChoice = (string) ($_POST['role_id'] ?? 'new');
        $error = match (true) {
            $dept === null => 'Choose the department.',
            mb_strlen($title) < 2 || mb_strlen($title) > 100 => 'Enter the designation (2 to 100 characters), e.g. Quality Manager.',
            !array_key_exists($level, DepartmentRepository::LEVELS) => 'Choose the level.',
            DepartmentRepository::designationTitleTaken($departmentId, $title) => "\"{$title}\" already exists in {$dept['name']}.",
            $roleChoice === 'new' && RoleRepository::findByName($title) !== null => "A role named \"{$title}\" already exists — choose it from the list instead of a new one.",
            $roleChoice !== 'new' && RoleRepository::find((int) $roleChoice) === null => 'Choose the role.',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header(self::BACK);
            return;
        }
        $roleId = $roleChoice === 'new' ? RoleRepository::create($title, $dept['name'] . ' — ' . DepartmentRepository::LEVELS[$level]) : (int) $roleChoice;
        if ($roleChoice === 'new') {
            AuditLogRepository::log((int) $user['id'], 'ROLE_CREATED', 'roles', $roleId, 'name', null, $title);
        }
        $id = DepartmentRepository::createDesignation($departmentId, $title, $level, $roleId);
        AuditLogRepository::log((int) $user['id'], 'DESIGNATION_CREATED', 'department_designations', $id, 'title', null, "{$title} · {$dept['name']}");
        Flash::set('success', "\"{$title}\" added to {$dept['name']}." . ($roleChoice === 'new' ? ' Its role has no permissions yet — set them with "Permissions".' : ''));
        header(self::BACK);
    }

    public function updateDesignation(array $params): void
    {
        $user = AuthService::currentUser();
        $d = DepartmentRepository::findDesignation((int) $params['id']);
        if ($d === null) {
            http_response_code(404);
            echo 'Designation not found.';
            return;
        }
        $title = Input::scalarString($_POST['title'] ?? '');
        $level = (string) ($_POST['level'] ?? '');
        $limitRaw = trim((string) ($_POST['approval_limit_inr'] ?? ''));
        $error = match (true) {
            mb_strlen($title) < 2 || mb_strlen($title) > 100 => 'Enter the designation (2 to 100 characters).',
            !array_key_exists($level, DepartmentRepository::LEVELS) => 'Choose the level.',
            DepartmentRepository::designationTitleTaken((int) $d['department_id'], $title, (int) $d['id']) => "\"{$title}\" already exists in {$d['department_name']}.",
            $limitRaw !== '' && (!is_numeric($limitRaw) || (float) $limitRaw < 0) => 'Enter the approval limit in rupees, or leave it empty for none.',
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header(self::BACK);
            return;
        }
        DepartmentRepository::updateDesignation((int) $d['id'], $title, $level, !empty($_POST['is_active']), $limitRaw === '' ? null : round((float) $limitRaw, 2));
        AuditLogRepository::log((int) $user['id'], 'DESIGNATION_UPDATED', 'department_designations', (int) $d['id'], null,
            "{$d['title']} · {$d['level']} · limit " . ($d['approval_limit_inr'] ?? '—'), "{$title} · {$level} · limit " . ($limitRaw === '' ? '—' : $limitRaw));
        Flash::set('success', "\"{$title}\" saved.");
        header(self::BACK);
    }

    // ---- a person's memberships (on the user's page) ----

    public function addMembership(array $params): void
    {
        $user = AuthService::currentUser();
        $target = UserRepository::findById((int) $params['id']);
        $back = 'Location: /users/' . (int) $params['id'] . '/edit#departments';
        if ($target === null) {
            http_response_code(404);
            echo 'User not found.';
            return;
        }
        if (self::protectedAccount($target)) {
            return;
        }
        $designation = DepartmentRepository::findDesignation((int) ($_POST['designation_id'] ?? 0));
        $startsOn = trim((string) ($_POST['starts_on'] ?? '')) ?: date('Y-m-d');
        $error = match (true) {
            $designation === null || (int) $designation['is_active'] !== 1 => 'Choose the department and designation.',
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $startsOn, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) => 'Enter the date the membership starts.',
            DepartmentRepository::hasOpenMembership((int) $target['id'], (int) $designation['id']) => "{$target['name']} is already {$designation['title']} in {$designation['department_name']}.",
            default => null,
        };
        if ($error !== null) {
            Flash::set('error', $error);
            header($back);
            return;
        }
        $id = DepartmentRepository::addMembership((int) $target['id'], (int) $designation['id'], $startsOn, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'DEPARTMENT_MEMBERSHIP_ADDED', 'user_department_memberships', $id, 'user_id', null,
            "{$target['name']}: {$designation['title']} · {$designation['department_name']} from {$startsOn}");
        Flash::set('success', "{$target['name']} is now {$designation['title']} in {$designation['department_name']} (from {$startsOn}).");
        header($back);
    }

    public function endMembership(array $params): void
    {
        $user = AuthService::currentUser();
        $m = DepartmentRepository::findMembership((int) $params['membershipId']);
        $back = 'Location: /users/' . (int) $params['id'] . '/edit#departments';
        if ($m === null || (int) $m['user_id'] !== (int) $params['id']) {
            http_response_code(404);
            echo 'Membership not found.';
            return;
        }
        if (self::protectedAccount(UserRepository::findById((int) $m['user_id']) ?? [])) {
            return;
        }
        $endsOn = trim((string) ($_POST['ends_on'] ?? '')) ?: date('Y-m-d');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $endsOn, $d) !== 1 || !checkdate((int) $d[2], (int) $d[3], (int) $d[1]) || $endsOn < (string) $m['starts_on']) {
            Flash::set('error', 'Enter the last day of the membership (not before it started).');
            header($back);
            return;
        }
        DepartmentRepository::endMembership((int) $m['id'], (int) $user['id'], $endsOn);
        AuditLogRepository::log((int) $user['id'], 'DEPARTMENT_MEMBERSHIP_ENDED', 'user_department_memberships', (int) $m['id'], 'ends_on', null, $endsOn,
            "{$m['designation_title']} · {$m['department_name']}");
        Flash::set('success', "Membership {$m['designation_title']} · {$m['department_name']} ends on {$endsOn}.");
        header($back);
    }

    /** A protected founder account's details (memberships included) never change through the application. @param array<string,mixed> $target */
    private static function protectedAccount(array $target): bool
    {
        if ((int) ($target['is_protected_account'] ?? 0) !== 1) {
            return false;
        }
        Flash::set('error', 'This is a protected founder account — its details can never be changed through the application, by anyone, including other Super Admins.');
        header('Location: /users');
        return true;
    }
}
