'use strict';

const permissionRepository = require('../repositories/permissionRepository');
const departmentRepository = require('../repositories/departmentRepository');

/**
 * Port of GrantGuard.php. Owner decision 1 Nov 2026 (A-8 / QA R7-N13):
 * nobody hands out a right they do not hold themselves, and nobody changes
 * their own rights — not through a role's permissions, a personal
 * exception, their own role, or a department membership. A Super Admin
 * holds every permission; the "own" rules still let a Super Admin act on
 * their own account, so the founder accounts can never lock themselves out.
 */

/** The permission keys a role carries. */
async function roleKeys(roleId) {
  if (roleId === null || roleId === undefined || roleId === '') return [];
  const all = await permissionRepository.all();
  const enabled = new Set((await permissionRepository.enabledForRole(Number(roleId))).map(Number));
  return all.filter((p) => enabled.has(Number(p.id))).map((p) => p.permission_key);
}

/** The first of these permissions the signed-in person does not hold, or null. */
function firstNotHeld(req, keys) {
  if (req.isSuperAdmin) return null;
  const perms = req.permissions || {};
  return keys.find((k) => !perms[k]) || null;
}

/** The roles the person works under: their own role, the acting one and their memberships'. */
async function ownRoleIds(req) {
  const ids = new Set();
  if (req.user.own_role_id !== null && req.user.own_role_id !== undefined) ids.add(Number(req.user.own_role_id));
  if (req.user.role_id !== null && req.user.role_id !== undefined) ids.add(Number(req.user.role_id));
  for (const id of await departmentRepository.activeRoleIdsForUser(req.user.id)) ids.add(Number(id));
  return [...ids];
}

async function notHeldMessage(key) {
  const p = await permissionRepository.findByKey(key);
  return `You cannot give the "${p ? p.name : key}" permission: you do not hold it yourself. Ask someone who holds it.`;
}

module.exports = { roleKeys, firstNotHeld, ownRoleIds, notHeldMessage };
