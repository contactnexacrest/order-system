<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\ReportAccessRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\ReportAccess;

/**
 * Owner decision D-379 (15 Nov 2026): Users & roles › Roles and permissions › <role> › Reports tab
 * (the level of each report for the role, with the reason for the change), "See Reports as this
 * role" (the Reports page drawn as the role sees it, read-only), "Reset to defaults", and Edit user ›
 * "Extra report access for this person". Every route needs manage_report_access (seeded: MD, ED).
 * Audit: REPORT_ACCESS_CHANGED (one row per changed report, old → new), USER_REPORT_EXTRA_ADDED /
 * USER_REPORT_EXTRA_REMOVED.
 *
 * Routes:
 *   GET  /admin/roles/{id}/reports          roleTab()
 *   POST /admin/roles/{id}/reports          saveRole()
 *   POST /admin/roles/{id}/reports/reset    resetRole()
 *   GET  /admin/roles/{id}/reports/preview  preview()
 *   POST /users/{id}/report-extras          addUserExtra()
 *   POST /users/{id}/report-extras/remove   removeUserExtra()
 *
 * Node twin: NODE_MySQL_Version/app/src/controllers/reportAccessController.js
 */
final class ReportAccessController
{
    private function me(): int
    {
        return (int) (AuthService::currentUser()['id'] ?? 0);
    }

    /** @return array<string,mixed>|null the role of the URL, or null after sending the person back */
    private function roleOr404(array $params): ?array
    {
        $role = RoleRepository::find((int) ($params['id'] ?? 0));
        if (!$role) {
            Flash::set('error', 'Role not found.');
            header('Location: /admin/permissions');
            return null;
        }
        return $role;
    }

    public function roleTab(array $params): void
    {
        $role = $this->roleOr404($params);
        if ($role === null) {
            return;
        }
        $roleId = (int) $role['id'];
        $perms = array_flip(ReportAccessRepository::rolePermissionKeys($roleId));
        $can = static fn (string $perm): bool => isset($perms[$perm]);
        $levels = ReportAccess::roleLevels($roleId);
        $sections = [];
        foreach (ReportAccess::bySection() as $section => $keys) {
            foreach ($keys as $key) {
                $level = $levels[$key];
                $sections[$section][] = [
                    'key' => $key,
                    'label' => ReportAccess::REPORTS[$key]['label'],
                    'level' => $level,
                    'needs' => ReportAccess::needsText($key),
                    // D-379 cosmetic follow-up: the "Needs" column, one plain-word entry per need (✓ / ⚠).
                    'needsItems' => ReportAccess::needsItems($can, $key, $level),
                    // "missing for this role": the level set here opens nothing without these permissions.
                    'missing' => $level === ReportAccess::OFF ? [] : ReportAccess::missingPermissions($can, $key, $level),
                ];
            }
        }
        View::render('permission_admin/role_reports', [
            'role' => $role,
            'sections' => $sections,
            'defaults' => ReportAccess::defaultsForRoleName((string) $role['name']),
        ], 'layout/base');
    }

    public function saveRole(array $params): void
    {
        $role = $this->roleOr404($params);
        if ($role === null) {
            return;
        }
        $back = '/admin/roles/' . (int) $role['id'] . '/reports';
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: ' . $back);
            return;
        }
        $posted = is_array($_POST['levels'] ?? null) ? $_POST['levels'] : [];
        $levels = [];
        foreach ($posted as $key => $level) {
            if (is_string($key) && is_string($level) && ReportAccess::isReport($key) && ReportAccess::isLevel($level)) {
                $levels[$key] = $level;
            }
        }
        $changed = ReportAccess::saveRoleLevels((int) $role['id'], $levels, $this->me(), $reason);
        Flash::set('success', $changed === []
            ? 'Nothing changed for ' . $role['name'] . '.'
            : 'Saved the report access for ' . $role['name'] . ': ' . count($changed) . ' report' . (count($changed) === 1 ? '' : 's') . ' changed.');
        header('Location: ' . $back);
    }

    public function resetRole(array $params): void
    {
        $role = $this->roleOr404($params);
        if ($role === null) {
            return;
        }
        $back = '/admin/roles/' . (int) $role['id'] . '/reports';
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: ' . $back);
            return;
        }
        $changed = ReportAccess::resetRole((int) $role['id'], (string) $role['name'], $this->me(), $reason);
        Flash::set('success', $changed === []
            ? $role['name'] . ' already has the default report access.'
            : 'Reset ' . $role['name'] . ' to the default report access: ' . count($changed) . ' report' . (count($changed) === 1 ? '' : 's') . ' changed.');
        header('Location: ' . $back);
    }

    /** "See Reports as this role": the Reports page with the role's own levels and permissions, read-only. */
    public function preview(array $params): void
    {
        $role = $this->roleOr404($params);
        if ($role === null) {
            return;
        }
        ReportAccess::previewAsRole((int) $role['id']);
        try {
            $data = ReportController::indexData('', $role);
        } finally {
            ReportAccess::previewAsRole(null); // the menus of the page stay the signed-in person's
        }
        View::render('reports/index', $data, 'layout/base');
    }

    public function addUserExtra(array $params): void
    {
        $userId = (int) ($params['id'] ?? 0);
        $target = UserRepository::findById($userId);
        if (!$target) {
            Flash::set('error', 'User not found.');
            header('Location: /users');
            return;
        }
        $error = ReportAccess::addUserExtra(
            $userId,
            $target['role_id'] !== null ? (int) $target['role_id'] : null,
            (string) ($_POST['report_key'] ?? ''),
            (string) ($_POST['level'] ?? ''),
            (string) ($_POST['reason'] ?? ''),
            $this->me()
        );
        Flash::set($error === null ? 'success' : 'error', $error ?? 'Extra report access saved.');
        header('Location: /users/' . $userId . '/edit#report-extras');
    }

    public function removeUserExtra(array $params): void
    {
        $userId = (int) ($params['id'] ?? 0);
        $removed = ReportAccess::removeUserExtra($userId, (string) ($_POST['report_key'] ?? ''), $this->me());
        Flash::set($removed ? 'success' : 'error', $removed ? 'Extra report access removed.' : 'That extra report access was already removed.');
        header('Location: /users/' . $userId . '/edit#report-extras');
    }
}
