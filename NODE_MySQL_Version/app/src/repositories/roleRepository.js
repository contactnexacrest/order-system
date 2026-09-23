'use strict';

const db = require('../config/db');

/**
 * CRUD for role definitions themselves (name/description) — distinct from
 * permissionRepository, which owns what a role can DO (role_permissions).
 * See schema.sql Section X for why is_system_role blocks deletion but not
 * rename: nothing in code depends on a role's display name any more (the
 * three notification paths that used to were fixed to check the actual
 * permission instead — see permissionRepository.usersWithPermission()).
 */

async function all() {
  return db.query('SELECT * FROM roles ORDER BY id');
}

async function find(id) {
  return db.queryOne('SELECT * FROM roles WHERE id = :id', { id });
}

async function findByName(name) {
  return db.queryOne('SELECT * FROM roles WHERE name = :name', { name });
}

async function create(name, description) {
  const result = await db.execute(
    'INSERT INTO roles (name, description, is_system_role) VALUES (:name, :description, 0)',
    { name, description }
  );
  return result.insertId;
}

async function update(id, name, description) {
  await db.execute('UPDATE roles SET name = :name, description = :description WHERE id = :id', { id, name, description });
}

/** How many users currently hold this role — deletion is blocked while this is nonzero. */
async function userCount(roleId) {
  const row = await db.queryOne('SELECT COUNT(*) AS c FROM users WHERE role_id = :role_id', { role_id: roleId });
  return row ? parseInt(row.c, 10) : 0;
}

async function deleteRole(id) {
  await db.execute('DELETE FROM roles WHERE id = :id', { id });
}

module.exports = { all, find, findByName, create, update, userCount, deleteRole };
