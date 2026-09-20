'use strict';

const db = require('../config/db');

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

module.exports = {
  effectivePermissions, all, allKeys, roleMatrix, activeGrantOverrides,
  grantOverride, removeGrantOverride,
};
