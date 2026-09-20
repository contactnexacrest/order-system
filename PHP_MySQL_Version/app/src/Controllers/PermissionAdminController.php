<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * Per-user permission overrides — grant-only, per the explicit decision:
 * this screen only ever ADDS an extra permission to a specific user beyond
 * what their role gives them; it never takes away a permission their role
 * grants. The underlying user_permissions.is_enabled column and
 * PermissionRepository::effectivePermissions() also support a force-disable
 * direction (is_enabled = 0), but nothing on this screen ever creates one —
 * see PermissionRepository::grantOverride()/removeGrantOverride().
 *
 * Also shows the role -> permission matrix read-only, for visibility.
 * Deliberately NOT a bulk role-matrix editor: an unrestricted "flip any
 * role's any permission" tool is the same anti-pattern the Phase E admin
 * overrides README section already argued against for raw column edits —
 * roles are seed-managed today; this screen's job is the narrower,
 * explicitly-requested per-user addition on top of that.
 */
final class PermissionAdminController
{
    private const MIN_REASON_LENGTH = 10;

    public function index(array $params): void
    {
        View::render('permission_admin/index', [
            'roleMatrix' => PermissionRepository::roleMatrix(),
            'allPermissions' => PermissionRepository::all(),
            'allUsers' => UserRepository::listAllForAdmin(),
            'roles' => LookupRepository::roles(),
            'overrides' => PermissionRepository::activeGrantOverrides(),
            'minReasonLength' => self::MIN_REASON_LENGTH,
        ], 'layout/base');
    }

    public function grantOverride(array $params): void
    {
        $user = AuthService::currentUser();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $permissionId = (int) ($_POST['permission_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($userId <= 0 || $permissionId <= 0) {
            Flash::set('error', 'Pick a user and a permission.');
            header('Location: /admin/permissions');
            return;
        }
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            Flash::set('error', 'Reason must be at least ' . self::MIN_REASON_LENGTH . ' characters — describe why this extra permission is needed.');
            header('Location: /admin/permissions');
            return;
        }

        $id = PermissionRepository::grantOverride($userId, $permissionId, (int) $user['id'], $reason);
        AuditLogRepository::log((int) $user['id'], 'USER_PERMISSION_GRANTED', 'user_permissions', $id, 'permission_id', null, (string) $permissionId, $reason);
        Flash::set('success', 'Extra permission granted.');
        header('Location: /admin/permissions');
    }

    public function removeOverride(array $params): void
    {
        $user = AuthService::currentUser();
        $id = (int) ($params['id'] ?? 0);
        PermissionRepository::removeGrantOverride($id);
        AuditLogRepository::log((int) $user['id'], 'USER_PERMISSION_REVOKED', 'user_permissions', $id);
        Flash::set('success', 'Extra permission removed.');
        header('Location: /admin/permissions');
    }
}
