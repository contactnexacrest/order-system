<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\LookupRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

/**
 * The Roles & Permissions screen. Covers: the per-user grant-only
 * permission override (original scope — see PermissionRepository::
 * grantOverride()/removeGrantOverride(), which only ever ADD an extra
 * permission, never take one away), full role CRUD (create/rename/delete
 * a role — delete blocked for the system role and for any role still
 * assigned to a user), full permission CRUD (create/rename/delete a
 * permission definition — delete blocked for a system permission, i.e.
 * one of the ~25 keys the app's own routes check by string literal, and
 * for any permission still granted to a role or user), and editing which
 * permissions a role has (role_permissions) — previously the matrix was
 * read-only by design; that's the actual gap this closes.
 */
final class PermissionAdminController
{
    private const MIN_REASON_LENGTH = 10;
    private const PERMISSION_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

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

    // --- Role CRUD ---

    public function roleCreate(array $params): void
    {
        $user = AuthService::currentUser();
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? '')) ?: null;

        if ($name === '') {
            Flash::set('error', 'Role name is required.');
            header('Location: /admin/permissions');
            return;
        }
        if (RoleRepository::findByName($name)) {
            Flash::set('error', "A role named \"{$name}\" already exists.");
            header('Location: /admin/permissions');
            return;
        }

        $id = RoleRepository::create($name, $description);
        AuditLogRepository::log((int) $user['id'], 'ROLE_CREATED', 'roles', $id, 'name', null, $name);
        Flash::set('success', "Role \"{$name}\" created — give it permissions from the matrix below, then assign it to users from Users.");
        header('Location: /admin/permissions');
    }

    public function roleEditForm(array $params): void
    {
        $roleId = (int) $params['id'];
        $role = RoleRepository::find($roleId);
        if (!$role) {
            Flash::set('error', 'Role not found.');
            header('Location: /admin/permissions');
            return;
        }
        View::render('permission_admin/role_edit', ['role' => $role], 'layout/base');
    }

    public function roleUpdate(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = (int) $params['id'];
        $role = RoleRepository::find($roleId);
        if (!$role) {
            Flash::set('error', 'Role not found.');
            header('Location: /admin/permissions');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? '')) ?: null;
        if ($name === '') {
            Flash::set('error', 'Role name is required.');
            header("Location: /admin/roles/{$roleId}/edit");
            return;
        }
        $existing = RoleRepository::findByName($name);
        if ($existing && (int) $existing['id'] !== $roleId) {
            Flash::set('error', "A role named \"{$name}\" already exists.");
            header("Location: /admin/roles/{$roleId}/edit");
            return;
        }

        RoleRepository::update($roleId, $name, $description);
        if ($name !== $role['name']) {
            AuditLogRepository::log((int) $user['id'], 'ROLE_UPDATED', 'roles', $roleId, 'name', $role['name'], $name);
        }
        Flash::set('success', "Role \"{$name}\" updated.");
        header('Location: /admin/permissions');
    }

    public function roleDelete(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = (int) $params['id'];
        $role = RoleRepository::find($roleId);
        if (!$role) {
            Flash::set('error', 'Role not found.');
            header('Location: /admin/permissions');
            return;
        }
        if ((int) $role['is_system_role'] === 1) {
            Flash::set('error', "\"{$role['name']}\" is a system role and can never be deleted.");
            header('Location: /admin/permissions');
            return;
        }
        $usersOnRole = RoleRepository::userCount($roleId);
        if ($usersOnRole > 0) {
            Flash::set('error', "Can't delete \"{$role['name']}\" — {$usersOnRole} user(s) still have this role. Reassign them first (Users → Edit).");
            header('Location: /admin/permissions');
            return;
        }

        RoleRepository::delete($roleId);
        AuditLogRepository::log((int) $user['id'], 'ROLE_DELETED', 'roles', $roleId, 'name', $role['name'], null);
        Flash::set('success', "Role \"{$role['name']}\" deleted.");
        header('Location: /admin/permissions');
    }

    // --- Edit which permissions a role has (the matrix, made editable) ---

    public function rolePermissionsForm(array $params): void
    {
        $roleId = (int) $params['id'];
        $role = RoleRepository::find($roleId);
        if (!$role) {
            Flash::set('error', 'Role not found.');
            header('Location: /admin/permissions');
            return;
        }
        View::render('permission_admin/role_permissions', [
            'role' => $role,
            'allPermissions' => PermissionRepository::all(),
            'enabledIds' => PermissionRepository::enabledForRole($roleId),
        ], 'layout/base');
    }

    public function rolePermissionsUpdate(array $params): void
    {
        $user = AuthService::currentUser();
        $roleId = (int) $params['id'];
        $role = RoleRepository::find($roleId);
        if (!$role) {
            Flash::set('error', 'Role not found.');
            header('Location: /admin/permissions');
            return;
        }
        $permissionIds = array_values(array_filter(array_map(
            static fn($v): int => (int) $v,
            (array) ($_POST['permission_ids'] ?? [])
        ), static fn(int $v): bool => $v > 0));

        PermissionRepository::setForRole($roleId, $permissionIds);
        AuditLogRepository::log((int) $user['id'], 'ROLE_PERMISSIONS_UPDATED', 'roles', $roleId, 'permission_count', null, (string) count($permissionIds));
        Flash::set('success', "Permissions updated for \"{$role['name']}\".");
        header('Location: /admin/permissions');
    }

    // --- Permission definition CRUD ---

    public function permissionCreate(array $params): void
    {
        $user = AuthService::currentUser();
        $permissionKey = strtolower(trim((string) ($_POST['permission_key'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? '')) ?: null;
        $category = trim((string) ($_POST['category'] ?? '')) ?: null;

        if ($permissionKey === '' || $name === '') {
            Flash::set('error', 'Permission key and name are required.');
            header('Location: /admin/permissions');
            return;
        }
        if (!preg_match(self::PERMISSION_KEY_PATTERN, $permissionKey)) {
            Flash::set('error', 'Permission key must be lowercase letters, digits, and underscores only, starting with a letter (e.g. "manage_widgets").');
            header('Location: /admin/permissions');
            return;
        }
        if (PermissionRepository::findByKey($permissionKey)) {
            Flash::set('error', "A permission with the key \"{$permissionKey}\" already exists.");
            header('Location: /admin/permissions');
            return;
        }

        $id = PermissionRepository::create($permissionKey, $name, $description, $category);
        AuditLogRepository::log((int) $user['id'], 'PERMISSION_CREATED', 'permissions', $id, 'permission_key', null, $permissionKey);
        Flash::set('success', "Permission \"{$name}\" created. It won't do anything until a developer adds a check for \"{$permissionKey}\" in code — grant it to a role from the matrix below in the meantime.");
        header('Location: /admin/permissions');
    }

    public function permissionEditForm(array $params): void
    {
        $permissionId = (int) $params['id'];
        $permission = PermissionRepository::find($permissionId);
        if (!$permission) {
            Flash::set('error', 'Permission not found.');
            header('Location: /admin/permissions');
            return;
        }
        View::render('permission_admin/permission_edit', ['permission' => $permission], 'layout/base');
    }

    public function permissionUpdate(array $params): void
    {
        $user = AuthService::currentUser();
        $permissionId = (int) $params['id'];
        $permission = PermissionRepository::find($permissionId);
        if (!$permission) {
            Flash::set('error', 'Permission not found.');
            header('Location: /admin/permissions');
            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? '')) ?: null;
        $category = trim((string) ($_POST['category'] ?? '')) ?: null;
        if ($name === '') {
            Flash::set('error', 'Permission name is required.');
            header("Location: /admin/permission-definitions/{$permissionId}/edit");
            return;
        }

        PermissionRepository::update($permissionId, $name, $description, $category);
        if ($name !== $permission['name']) {
            AuditLogRepository::log((int) $user['id'], 'PERMISSION_UPDATED', 'permissions', $permissionId, 'name', $permission['name'], $name);
        }
        Flash::set('success', "Permission \"{$name}\" updated.");
        header('Location: /admin/permissions');
    }

    public function permissionDelete(array $params): void
    {
        $user = AuthService::currentUser();
        $permissionId = (int) $params['id'];
        $permission = PermissionRepository::find($permissionId);
        if (!$permission) {
            Flash::set('error', 'Permission not found.');
            header('Location: /admin/permissions');
            return;
        }
        if ((int) $permission['is_system_permission'] === 1) {
            Flash::set('error', "\"{$permission['name']}\" is a system permission the application code depends on and can never be deleted.");
            header('Location: /admin/permissions');
            return;
        }
        $usage = PermissionRepository::usageCount($permissionId);
        if ($usage > 0) {
            Flash::set('error', "Can't delete \"{$permission['name']}\" — it's still granted to {$usage} role/user override(s). Remove those grants first.");
            header('Location: /admin/permissions');
            return;
        }

        PermissionRepository::deletePermission($permissionId);
        AuditLogRepository::log((int) $user['id'], 'PERMISSION_DELETED', 'permissions', $permissionId, 'permission_key', $permission['permission_key'], null);
        Flash::set('success', "Permission \"{$permission['name']}\" deleted.");
        header('Location: /admin/permissions');
    }
}
