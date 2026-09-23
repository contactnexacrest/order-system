'use strict';

const db = require('../config/db');
const superAdminService = require('../services/superAdminService');

// Port of App\Repositories\PermissionRepository.

/**
 * Effective permission keys for a user: role's enabled permissions, with
 * per-user overrides in user_permissions taking final precedence.
 * @returns {Promise<Object<string,boolean>>}
 */
async function effectivePermissions(userId, roleId) {
  const effective = {};

  if (roleId !== null && roleId !== undefined) {
    const rows = await db.query(
      `SELECT p.permission_key, rp.is_enabled
       FROM role_permissions rp
       JOIN permissions p ON p.id = rp.permission_id
       WHERE rp.role_id = :role_id`,
      { role_id: roleId }
    );
    for (const row of rows) {
      effective[row.permission_key] = !!row.is_enabled;
    }
  }

  const overrides = await db.query(
    `SELECT p.permission_key, up.is_enabled
     FROM user_permissions up
     JOIN permissions p ON p.id = up.permission_id
     WHERE up.user_id = :user_id`,
    { user_id: userId }
  );
  for (const row of overrides) {
    effective[row.permission_key] = !!row.is_enabled;
  }

  return effective;
}

/**
 * Active users who effectively hold a given permission — role grant, minus
 * any per-user force-disable, plus a per-user grant override, and always
 * including an effective Super Admin regardless of role. Used to route
 * approval/escalation notifications (e.g. "notify whoever can approve
 * this") by the actual permission the action requires, not by a role's
 * display name — a role can be renamed via /admin/roles, so a hardcoded
 * name like 'Admin' or 'Managing Director' would silently stop matching
 * after a rename. Not indexed/optimized — called for occasional
 * notification fan-out (an amendment request, a daily alert sweep), never
 * a request hot path.
 * @returns {Promise<Array<number>>} user ids
 */
async function usersWithPermission(permissionKey) {
  const activeUsers = await db.query('SELECT id, role_id FROM users WHERE is_active = 1');
  const matched = [];
  for (const u of activeUsers) {
    if (await superAdminService.isEffective(u.id)) {
      matched.push(u.id);
      continue;
    }
    const effective = await effectivePermissions(u.id, u.role_id);
    if (effective[permissionKey]) {
      matched.push(u.id);
    }
  }
  return matched;
}

/** @returns {Promise<Array<object>>} every permission, for pickers and the role matrix */
async function all() {
  return db.query('SELECT * FROM permissions ORDER BY category, name');
}

async function allKeys() {
  const rows = await all();
  return rows.map((r) => r.permission_key);
}

/**
 * Read-only role -> permission matrix, for admin visibility. See
 * permissionAdminController's docblock for why bulk role-matrix editing is
 * deliberately out of scope.
 * @returns {Promise<Object<string, Object<string, boolean>>>} role name => (permission_key => enabled)
 */
async function roleMatrix() {
  const roles = await db.query('SELECT id, name FROM roles ORDER BY id');
  const matrix = {};
  for (const role of roles) {
    const rows = await db.query(
      `SELECT p.permission_key, rp.is_enabled
       FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
       WHERE rp.role_id = :role_id`,
      { role_id: role.id }
    );
    const perms = {};
    for (const row of rows) {
      perms[row.permission_key] = !!row.is_enabled;
    }
    matrix[role.name] = perms;
  }
  return matrix;
}

/**
 * Active grant-only overrides (is_enabled = 1) with names joined in.
 * Force-disable rows (is_enabled = 0) exist in the schema/
 * effectivePermissions() logic but are never created by this screen.
 * @returns {Promise<Array<object>>}
 */
async function activeGrantOverrides() {
  return db.query(
    `SELECT up.id, up.user_id, u.name AS user_name, p.permission_key, p.name AS permission_name,
            up.granted_by, g.name AS granted_by_name, up.granted_at, up.reason
     FROM user_permissions up
     JOIN users u ON u.id = up.user_id
     JOIN permissions p ON p.id = up.permission_id
     LEFT JOIN users g ON g.id = up.granted_by
     WHERE up.is_enabled = 1
     ORDER BY up.granted_at DESC`
  );
}

async function grantOverride(userId, permissionId, grantedBy, reason) {
  const result = await db.execute(
    `INSERT INTO user_permissions (user_id, permission_id, is_enabled, granted_by, reason)
     VALUES (:user_id, :permission_id, 1, :granted_by, :reason)
     ON DUPLICATE KEY UPDATE is_enabled = 1, granted_by = VALUES(granted_by), reason = VALUES(reason), granted_at = CURRENT_TIMESTAMP`,
    { user_id: userId, permission_id: permissionId, granted_by: grantedBy, reason }
  );
  return result.insertId;
}

/** Only ever removes a grant-only override row (is_enabled = 1) — never touches a force-disable row. */
async function removeGrantOverride(id) {
  await db.execute('DELETE FROM user_permissions WHERE id = :id AND is_enabled = 1', { id });
}

async function find(id) {
  return db.queryOne('SELECT * FROM permissions WHERE id = :id', { id });
}

async function findByKey(permissionKey) {
  return db.queryOne('SELECT * FROM permissions WHERE permission_key = :permission_key', { permission_key: permissionKey });
}

/** Freshly created permissions are never system-protected — see schema.sql Section X. */
async function create(permissionKey, name, description, category) {
  const result = await db.execute(
    `INSERT INTO permissions (permission_key, name, description, category, is_system_permission)
     VALUES (:permission_key, :name, :description, :category, 0)`,
    { permission_key: permissionKey, name, description, category }
  );
  return result.insertId;
}

/** permission_key is immutable once created — every requirePermission() call in code is a string literal against it. */
async function update(id, name, description, category) {
  await db.execute(
    'UPDATE permissions SET name = :name, description = :description, category = :category WHERE id = :id',
    { id, name, description, category }
  );
}

/** How many role or per-user grants currently reference this permission — deletion is blocked while this is nonzero. */
async function usageCount(permissionId) {
  const row = await db.queryOne(
    `SELECT
       (SELECT COUNT(*) FROM role_permissions WHERE permission_id = :id) +
       (SELECT COUNT(*) FROM user_permissions WHERE permission_id = :id) AS c`,
    { id: permissionId }
  );
  return row ? parseInt(row.c, 10) : 0;
}

async function deletePermission(id) {
  await db.execute('DELETE FROM permissions WHERE id = :id', { id });
}

/**
 * Replace a role's entire permission set in one go — the actual "edit
 * which permissions a role has" the read-only matrix used to explicitly
 * rule out. role_permissions is a pure junction table nothing else
 * references, so delete-then-reinsert for just this one role_id is safe
 * and doesn't touch any other role's rows.
 */
async function setForRole(roleId, enabledPermissionIds) {
  await db.execute('DELETE FROM role_permissions WHERE role_id = :role_id', { role_id: roleId });
  for (const permissionId of enabledPermissionIds) {
    await db.execute(
      'INSERT INTO role_permissions (role_id, permission_id, is_enabled) VALUES (:role_id, :permission_id, 1)',
      { role_id: roleId, permission_id: permissionId }
    );
  }
}

/** The permission_ids currently enabled for one role — for pre-checking the edit-permissions form. */
async function enabledForRole(roleId) {
  const rows = await db.query(
    'SELECT permission_id FROM role_permissions WHERE role_id = :role_id AND is_enabled = 1',
    { role_id: roleId }
  );
  return rows.map((r) => r.permission_id);
}

module.exports = {
  effectivePermissions, usersWithPermission, all, allKeys, roleMatrix, activeGrantOverrides,
  grantOverride, removeGrantOverride, find, findByKey, create, update, usageCount, deletePermission,
  setForRole, enabledForRole,
};
