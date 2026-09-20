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

module.exports = { effectivePermissions };
