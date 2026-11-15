'use strict';

const db = require('../config/db');

/**
 * Owner decision D-379: the tables behind services/reportAccess.js — role_report_access (a role's
 * level per report; no row = off) and user_report_extras (a person's higher level for one report,
 * with the reason) — plus the department and order lookups the report scope needs.
 * MySQL 5.7-safe: no window functions, no CTEs; integer id lists are inlined after parseInt().
 *
 * PHP twin: PHP_MySQL_Version/app/src/Repositories/ReportAccessRepository.php
 */

const intList = (ids) => ids.map((v) => parseInt(v, 10)).filter((v) => Number.isInteger(v)).join(',');

/** @returns {Promise<Object<string,string>>} report key => level stored for the role */
async function levelsForRole(roleId) {
  const out = {};
  for (const row of await db.query('SELECT report_key, level FROM role_report_access WHERE role_id = :r', { r: roleId })) {
    out[String(row.report_key)] = String(row.level);
  }
  return out;
}

async function setLevel(roleId, key, level, byUserId) {
  await db.execute(
    `INSERT INTO role_report_access (role_id, report_key, level, updated_by) VALUES (:r, :k, :l, :u)
     ON DUPLICATE KEY UPDATE level = VALUES(level), updated_by = VALUES(updated_by)`,
    { r: roleId, k: key, l: level, u: byUserId }
  );
}

/** @returns {Promise<Object<string, object>>} report key => row with the name of who set it */
async function extrasForUser(userId) {
  const rows = await db.query(
    `SELECT x.report_key, x.level, x.reason, x.created_by, x.created_at, u.name AS created_by_name
       FROM user_report_extras x LEFT JOIN users u ON u.id = x.created_by
      WHERE x.user_id = :u ORDER BY x.report_key`,
    { u: userId }
  );
  const out = {};
  for (const row of rows) out[String(row.report_key)] = row;
  return out;
}

async function saveExtra(userId, key, level, reason, byUserId) {
  await db.execute(
    `INSERT INTO user_report_extras (user_id, report_key, level, reason, created_by) VALUES (:u, :k, :l, :r, :b)
     ON DUPLICATE KEY UPDATE level = VALUES(level), reason = VALUES(reason), created_by = VALUES(created_by), created_at = CURRENT_TIMESTAMP`,
    { u: userId, k: key, l: level, r: reason, b: byUserId }
  );
}

async function deleteExtra(userId, key) {
  await db.execute('DELETE FROM user_report_extras WHERE user_id = :u AND report_key = :k', { u: userId, k: key });
}

/** @returns {Promise<string[]>} the permission keys a role grants (role_permissions only — for the Reports tab and its preview) */
async function rolePermissionKeys(roleId) {
  const rows = await db.query(
    `SELECT p.permission_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
      WHERE rp.role_id = :r AND rp.is_enabled = 1`,
    { r: roleId }
  );
  return rows.map((r) => String(r.permission_key));
}

/** The membership rule of departmentRepository.activeMembershipsForUser(): started, not ended, department and designation active. */
const CURRENT_MEMBERSHIP = 'm.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE()) AND dd.is_active = 1 AND d.is_active = 1';

/** @returns {Promise<number[]>} the departments a person currently belongs to */
async function currentDepartmentIds(userId) {
  const rows = await db.query(
    `SELECT DISTINCT dd.department_id
       FROM user_department_memberships m
       JOIN department_designations dd ON dd.id = m.designation_id
       JOIN departments d ON d.id = dd.department_id
      WHERE m.user_id = :u AND ${CURRENT_MEMBERSHIP}`,
    { u: userId }
  );
  return rows.map((r) => Number(r.department_id));
}

/** @returns {Promise<number[]>} every current member of these departments */
async function currentMemberIds(departmentIds) {
  if (departmentIds.length === 0) return [];
  const rows = await db.query(
    `SELECT DISTINCT m.user_id
       FROM user_department_memberships m
       JOIN department_designations dd ON dd.id = m.designation_id
       JOIN departments d ON d.id = dd.department_id
      WHERE dd.department_id IN (${intList(departmentIds)}) AND ${CURRENT_MEMBERSHIP}`
  );
  return rows.map((r) => Number(r.user_id));
}

/** @returns {Promise<string[]>} */
async function departmentCodes(departmentIds) {
  if (departmentIds.length === 0) return [];
  return (await db.query(`SELECT code FROM departments WHERE id IN (${intList(departmentIds)}) ORDER BY sort_order`)).map((r) => String(r.code));
}

/** @returns {Promise<number[]>} those ids that meet the scope condition scopeSql on `orders o` */
async function orderIdsWhere(orderIds, scopeSql, params) {
  const rows = await db.query(`SELECT o.id FROM orders o WHERE o.id IN (${intList(orderIds)})${scopeSql}`, params);
  return rows.map((r) => Number(r.id));
}

/** @returns {Promise<number[]>} the clients of the orders meeting scopeSql on `orders o` */
async function clientIdsWhere(scopeSql, params) {
  const rows = await db.query(`SELECT DISTINCT o.client_id FROM orders o WHERE o.client_id IS NOT NULL${scopeSql}`, params);
  return rows.map((r) => Number(r.client_id));
}

module.exports = {
  levelsForRole, setLevel, extrasForUser, saveExtra, deleteExtra, rolePermissionKeys,
  currentDepartmentIds, currentMemberIds, departmentCodes, orderIdsWhere, clientIdsWhere,
};
