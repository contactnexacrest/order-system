'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const permissionRepository = require('../repositories/permissionRepository');
const userRepository = require('../repositories/userRepository');
const lookupRepository = require('../repositories/lookupRepository');

// Port of App\Controllers\PermissionAdminController — grant-only per-user
// permission overrides, plus a read-only role matrix. See the PHP source's
// docblock for why bulk role-matrix editing is deliberately out of scope.

const MIN_REASON_LENGTH = 10;

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

module.exports = { index, grantOverride, removeOverride };
