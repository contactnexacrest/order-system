'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const permissionRepository = require('../repositories/permissionRepository');
const roleRepository = require('../repositories/roleRepository');
const userRepository = require('../repositories/userRepository');
const lookupRepository = require('../repositories/lookupRepository');

/**
 * Admin\PermissionAdminController — the Roles & Permissions screen.
 * Covers: the per-user grant-only permission override (original scope),
 * full role CRUD (create/rename/delete a role — delete blocked for the
 * system role and for any role still assigned to a user), full permission
 * CRUD (create/rename/delete a permission definition — delete blocked for
 * a system permission, i.e. one of the ~25 keys the app's own routes
 * check by string literal, and for any permission still granted to a
 * role or user), and editing which permissions a role has (role_permissions)
 * — previously the matrix was read-only by design; that's the actual gap
 * this closes.
 */

const MIN_REASON_LENGTH = 10;
const PERMISSION_KEY_PATTERN = /^[a-z][a-z0-9_]*$/;

async function index(req, res) {
  const [roleMatrix, allPermissions, allUsers, roles, overrides] = await Promise.all([
    permissionRepository.roleMatrix(),
    permissionRepository.all(),
    userRepository.listAllForAdmin(),
    lookupRepository.roles(),
    permissionRepository.activeGrantOverrides(),
  ]);
  res.renderView('permission_admin/index', {
    roleMatrix, allPermissions, allUsers, roles, overrides, minReasonLength: MIN_REASON_LENGTH,
  }, 'layout/base');
}

async function grantOverride(req, res) {
  const user = req.user;
  const userId = parseInt(req.body.user_id, 10) || 0;
  const permissionId = parseInt(req.body.permission_id, 10) || 0;
  const reason = String(req.body.reason || '').trim();

  if (userId <= 0 || permissionId <= 0) {
    flash.set(req, 'error', 'Pick a user and a permission.');
    res.redirect('/admin/permissions');
    return;
  }
  if (reason.length < MIN_REASON_LENGTH) {
    flash.set(req, 'error', `Reason must be at least ${MIN_REASON_LENGTH} characters — describe why this extra permission is needed.`);
    res.redirect('/admin/permissions');
    return;
  }

  const id = await permissionRepository.grantOverride(userId, permissionId, user.id, reason);
  await auditLogRepository.log(user.id, 'USER_PERMISSION_GRANTED', 'user_permissions', id, 'permission_id', null, String(permissionId), reason);
  flash.set(req, 'success', 'Extra permission granted.');
  res.redirect('/admin/permissions');
}

async function removeOverride(req, res) {
  const user = req.user;
  const id = parseInt(req.params.id, 10) || 0;
  await permissionRepository.removeGrantOverride(id);
  await auditLogRepository.log(user.id, 'USER_PERMISSION_REVOKED', 'user_permissions', id);
  flash.set(req, 'success', 'Extra permission removed.');
  res.redirect('/admin/permissions');
}

// --- Role CRUD ---

async function roleCreate(req, res) {
  const user = req.user;
  const name = String(req.body.name || '').trim();
  const description = String(req.body.description || '').trim() || null;

  if (name === '') {
    flash.set(req, 'error', 'Role name is required.');
    res.redirect('/admin/permissions');
    return;
  }
  if (await roleRepository.findByName(name)) {
    flash.set(req, 'error', `A role named "${name}" already exists.`);
    res.redirect('/admin/permissions');
    return;
  }

  const id = await roleRepository.create(name, description);
  await auditLogRepository.log(user.id, 'ROLE_CREATED', 'roles', id, 'name', null, name);
  flash.set(req, 'success', `Role "${name}" created — give it permissions from the matrix below, then assign it to users from Users.`);
  res.redirect('/admin/permissions');
}

async function roleEditForm(req, res) {
  const roleId = parseInt(req.params.id, 10);
  const role = await roleRepository.find(roleId);
  if (!role) {
    flash.set(req, 'error', 'Role not found.');
    res.redirect('/admin/permissions');
    return;
  }
  res.renderView('permission_admin/role_edit', { role }, 'layout/base');
}

async function roleUpdate(req, res) {
  const user = req.user;
  const roleId = parseInt(req.params.id, 10);
  const role = await roleRepository.find(roleId);
  if (!role) {
    flash.set(req, 'error', 'Role not found.');
    res.redirect('/admin/permissions');
    return;
  }

  const name = String(req.body.name || '').trim();
  const description = String(req.body.description || '').trim() || null;
  if (name === '') {
    flash.set(req, 'error', 'Role name is required.');
    res.redirect(`/admin/roles/${roleId}/edit`);
    return;
  }
  const existing = await roleRepository.findByName(name);
  if (existing && existing.id !== roleId) {
    flash.set(req, 'error', `A role named "${name}" already exists.`);
    res.redirect(`/admin/roles/${roleId}/edit`);
    return;
  }

  await roleRepository.update(roleId, name, description);
  if (name !== role.name) {
    await auditLogRepository.log(user.id, 'ROLE_UPDATED', 'roles', roleId, 'name', role.name, name);
  }
  flash.set(req, 'success', `Role "${name}" updated.`);
  res.redirect('/admin/permissions');
}

async function roleDelete(req, res) {
  const user = req.user;
  const roleId = parseInt(req.params.id, 10);
  const role = await roleRepository.find(roleId);
  if (!role) {
    flash.set(req, 'error', 'Role not found.');
    res.redirect('/admin/permissions');
    return;
  }
  if (parseInt(role.is_system_role, 10) === 1) {
    flash.set(req, 'error', `"${role.name}" is a system role and can never be deleted.`);
    res.redirect('/admin/permissions');
    return;
  }
  const usersOnRole = await roleRepository.userCount(roleId);
  if (usersOnRole > 0) {
    flash.set(req, 'error', `Can't delete "${role.name}" — ${usersOnRole} user(s) still have this role. Reassign them first (Users → Edit).`);
    res.redirect('/admin/permissions');
    return;
  }

  await roleRepository.deleteRole(roleId);
  await auditLogRepository.log(user.id, 'ROLE_DELETED', 'roles', roleId, 'name', role.name, null);
  flash.set(req, 'success', `Role "${role.name}" deleted.`);
  res.redirect('/admin/permissions');
}

// --- Edit which permissions a role has (the matrix, made editable) ---

async function rolePermissionsForm(req, res) {
  const roleId = parseInt(req.params.id, 10);
  const role = await roleRepository.find(roleId);
  if (!role) {
    flash.set(req, 'error', 'Role not found.');
    res.redirect('/admin/permissions');
    return;
  }
  const [allPermissions, enabledIds] = await Promise.all([
    permissionRepository.all(),
    permissionRepository.enabledForRole(roleId),
  ]);
  res.renderView('permission_admin/role_permissions', { role, allPermissions, enabledIds }, 'layout/base');
}

async function rolePermissionsUpdate(req, res) {
  const user = req.user;
  const roleId = parseInt(req.params.id, 10);
  const role = await roleRepository.find(roleId);
  if (!role) {
    flash.set(req, 'error', 'Role not found.');
    res.redirect('/admin/permissions');
    return;
  }
  const permissionIds = [].concat(req.body.permission_ids || []).map((v) => parseInt(v, 10)).filter((v) => v > 0);

  await permissionRepository.setForRole(roleId, permissionIds);
  await auditLogRepository.log(user.id, 'ROLE_PERMISSIONS_UPDATED', 'roles', roleId, 'permission_count', null, String(permissionIds.length));
  flash.set(req, 'success', `Permissions updated for "${role.name}".`);
  res.redirect('/admin/permissions');
}

// --- Permission definition CRUD ---

async function permissionCreate(req, res) {
  const user = req.user;
  const permissionKey = String(req.body.permission_key || '').trim().toLowerCase();
  const name = String(req.body.name || '').trim();
  const description = String(req.body.description || '').trim() || null;
  const category = String(req.body.category || '').trim() || null;

  if (permissionKey === '' || name === '') {
    flash.set(req, 'error', 'Permission key and name are required.');
    res.redirect('/admin/permissions');
    return;
  }
  if (!PERMISSION_KEY_PATTERN.test(permissionKey)) {
    flash.set(req, 'error', 'Permission key must be lowercase letters, digits, and underscores only, starting with a letter (e.g. "manage_widgets").');
    res.redirect('/admin/permissions');
    return;
  }
  if (await permissionRepository.findByKey(permissionKey)) {
    flash.set(req, 'error', `A permission with the key "${permissionKey}" already exists.`);
    res.redirect('/admin/permissions');
    return;
  }

  const id = await permissionRepository.create(permissionKey, name, description, category);
  await auditLogRepository.log(user.id, 'PERMISSION_CREATED', 'permissions', id, 'permission_key', null, permissionKey);
  flash.set(req, 'success', `Permission "${name}" created. It won't do anything until a developer adds a check for "${permissionKey}" in code — grant it to a role from the matrix below in the meantime.`);
  res.redirect('/admin/permissions');
}

async function permissionEditForm(req, res) {
  const permissionId = parseInt(req.params.id, 10);
  const permission = await permissionRepository.find(permissionId);
  if (!permission) {
    flash.set(req, 'error', 'Permission not found.');
    res.redirect('/admin/permissions');
    return;
  }
  res.renderView('permission_admin/permission_edit', { permission }, 'layout/base');
}

async function permissionUpdate(req, res) {
  const user = req.user;
  const permissionId = parseInt(req.params.id, 10);
  const permission = await permissionRepository.find(permissionId);
  if (!permission) {
    flash.set(req, 'error', 'Permission not found.');
    res.redirect('/admin/permissions');
    return;
  }

  const name = String(req.body.name || '').trim();
  const description = String(req.body.description || '').trim() || null;
  const category = String(req.body.category || '').trim() || null;
  if (name === '') {
    flash.set(req, 'error', 'Permission name is required.');
    res.redirect(`/admin/permission-definitions/${permissionId}/edit`);
    return;
  }

  await permissionRepository.update(permissionId, name, description, category);
  if (name !== permission.name) {
    await auditLogRepository.log(user.id, 'PERMISSION_UPDATED', 'permissions', permissionId, 'name', permission.name, name);
  }
  flash.set(req, 'success', `Permission "${name}" updated.`);
  res.redirect('/admin/permissions');
}

async function permissionDelete(req, res) {
  const user = req.user;
  const permissionId = parseInt(req.params.id, 10);
  const permission = await permissionRepository.find(permissionId);
  if (!permission) {
    flash.set(req, 'error', 'Permission not found.');
    res.redirect('/admin/permissions');
    return;
  }
  if (parseInt(permission.is_system_permission, 10) === 1) {
    flash.set(req, 'error', `"${permission.name}" is a system permission the application code depends on and can never be deleted.`);
    res.redirect('/admin/permissions');
    return;
  }
  const usage = await permissionRepository.usageCount(permissionId);
  if (usage > 0) {
    flash.set(req, 'error', `Can't delete "${permission.name}" — it's still granted to ${usage} role/user override(s). Remove those grants first.`);
    res.redirect('/admin/permissions');
    return;
  }

  await permissionRepository.deletePermission(permissionId);
  await auditLogRepository.log(user.id, 'PERMISSION_DELETED', 'permissions', permissionId, 'permission_key', permission.permission_key, null);
  flash.set(req, 'success', `Permission "${permission.name}" deleted.`);
  res.redirect('/admin/permissions');
}

module.exports = {
  index, grantOverride, removeOverride,
  roleCreate, roleEditForm, roleUpdate, roleDelete,
  rolePermissionsForm, rolePermissionsUpdate,
  permissionCreate, permissionEditForm, permissionUpdate, permissionDelete,
};
