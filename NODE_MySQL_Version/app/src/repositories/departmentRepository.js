'use strict';

const db = require('../config/db');

// Port of App\Repositories\DepartmentRepository. Owner decision D-214
// (2026-10-31), phase 1: departments, their designation ladders (each
// designation tied to its own role, i.e. permission set) and people's
// memberships. A person can hold several memberships and picks one at
// "Log in as"; a person with none keeps their own role (users.role_id).

const LEVELS = { executive: 'Executive', manager: 'Manager', director: 'Director', other: 'Other' };

async function departments(activeOnly = false) {
  return db.query(`SELECT * FROM departments${activeOnly ? ' WHERE is_active = 1' : ''} ORDER BY sort_order, name`);
}

async function findDepartment(id) {
  return db.queryOne('SELECT * FROM departments WHERE id = :id', { id });
}

async function designations() {
  return db.query(
    `SELECT dd.*, d.name AS department_name, d.color AS department_color, d.is_active AS department_active, r.name AS role_name,
            (SELECT COUNT(*) FROM user_department_memberships m JOIN users u ON u.id = m.user_id
              WHERE m.designation_id = dd.id AND u.is_active = 1 AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())) AS member_count
       FROM department_designations dd
       JOIN departments d ON d.id = dd.department_id
       JOIN roles r ON r.id = dd.role_id
      ORDER BY d.sort_order, d.name, FIELD(dd.level, 'executive', 'manager', 'director', 'other'), dd.sort_order, dd.title`
  );
}

async function findDesignation(id) {
  return db.queryOne(
    `SELECT dd.*, d.name AS department_name, d.color AS department_color, r.name AS role_name
       FROM department_designations dd JOIN departments d ON d.id = dd.department_id JOIN roles r ON r.id = dd.role_id
      WHERE dd.id = :id`,
    { id }
  );
}

async function createDepartment(code, name, color) {
  const next = await db.queryOne('SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM departments');
  const result = await db.execute(
    'INSERT INTO departments (code, name, color, sort_order) VALUES (:c, :n, :col, :s)',
    { c: code, n: name, col: color, s: Number(next.n) }
  );
  return result.insertId;
}

async function updateDepartment(id, name, color, active, seesWholeCompany) {
  await db.execute(
    'UPDATE departments SET name = :n, color = :col, is_active = :a, sees_whole_company = :w WHERE id = :id',
    { n: name, col: color, a: active ? 1 : 0, w: seesWholeCompany ? 1 : 0, id }
  );
}

async function departmentNameTaken(name, exceptId = null) {
  const row = await db.queryOne('SELECT 1 AS x FROM departments WHERE name = :n AND (:id IS NULL OR id <> :id2)', { n: name, id: exceptId, id2: exceptId });
  return !!row;
}

async function departmentCodeTaken(code) {
  return !!(await db.queryOne('SELECT 1 AS x FROM departments WHERE code = :c', { c: code }));
}

async function createDesignation(departmentId, title, level, roleId) {
  const next = await db.queryOne('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM department_designations WHERE department_id = :d', { d: departmentId });
  const result = await db.execute(
    'INSERT INTO department_designations (department_id, title, level, role_id, sort_order) VALUES (:d, :t, :l, :r, :s)',
    { d: departmentId, t: title, l: level, r: roleId, s: Number(next.n) }
  );
  return result.insertId;
}

async function updateDesignation(id, title, level, active, approvalLimit) {
  await db.execute(
    'UPDATE department_designations SET title = :t, level = :l, is_active = :a, approval_limit_inr = :lim WHERE id = :id',
    { t: title, l: level, a: active ? 1 : 0, lim: approvalLimit, id }
  );
}

async function designationTitleTaken(departmentId, title, exceptId = null) {
  const row = await db.queryOne(
    'SELECT 1 AS x FROM department_designations WHERE department_id = :d AND title = :t AND (:id IS NULL OR id <> :id2)',
    { d: departmentId, t: title, id: exceptId, id2: exceptId }
  );
  return !!row;
}

const MEMBERSHIP_SELECT = `SELECT m.*, dd.title AS designation_title, dd.level, dd.role_id, dd.is_active AS designation_active,
                                  d.id AS department_id, d.name AS department_name, d.color AS department_color, d.is_active AS department_active,
                                  d.sees_whole_company, r.name AS role_name
                             FROM user_department_memberships m
                             JOIN department_designations dd ON dd.id = m.designation_id
                             JOIN departments d ON d.id = dd.department_id
                             JOIN roles r ON r.id = dd.role_id`;

/** Every membership of a person, current and past. */
async function membershipsForUser(userId) {
  return db.query(`${MEMBERSHIP_SELECT} WHERE m.user_id = :u ORDER BY (m.ends_on IS NULL OR m.ends_on >= CURDATE()) DESC, d.sort_order, m.starts_on DESC`, { u: userId });
}

/** The memberships a person can log in as today (started, not ended, department and designation active). */
async function activeMembershipsForUser(userId) {
  return db.query(
    `${MEMBERSHIP_SELECT}
      WHERE m.user_id = :u AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
        AND dd.is_active = 1 AND d.is_active = 1
      ORDER BY m.is_default DESC, d.sort_order, dd.sort_order`,
    { u: userId }
  );
}

async function findMembership(id) {
  return db.queryOne(`${MEMBERSHIP_SELECT} WHERE m.id = :id`, { id });
}

async function addMembership(userId, designationId, startsOn, createdBy) {
  const result = await db.execute(
    'INSERT INTO user_department_memberships (user_id, designation_id, starts_on, created_by) VALUES (:u, :d, :s, :c)',
    { u: userId, d: designationId, s: startsOn, c: createdBy }
  );
  return result.insertId;
}

async function hasOpenMembership(userId, designationId) {
  const row = await db.queryOne(
    'SELECT 1 AS x FROM user_department_memberships WHERE user_id = :u AND designation_id = :d AND (ends_on IS NULL OR ends_on >= CURDATE())',
    { u: userId, d: designationId }
  );
  return !!row;
}

/** Ends a membership (it stays on record). */
async function endMembership(id, endedBy, endsOn) {
  await db.execute('UPDATE user_department_memberships SET ends_on = :e, ended_by = :b, is_default = 0 WHERE id = :id', { e: endsOn, b: endedBy, id });
}

/** The membership "Log in as" starts with (only one per person). */
async function setDefault(userId, membershipId) {
  await db.execute('UPDATE user_department_memberships SET is_default = 0 WHERE user_id = :u', { u: userId });
  if (membershipId !== null) {
    await db.execute('UPDATE user_department_memberships SET is_default = 1 WHERE id = :id AND user_id = :u', { id: membershipId, u: userId });
  }
}

/** Role ids a person holds today through their memberships. */
async function activeRoleIdsForUser(userId) {
  return [...new Set((await activeMembershipsForUser(userId)).map((m) => Number(m.role_id)))];
}

module.exports = {
  LEVELS, departments, findDepartment, designations, findDesignation, createDepartment, updateDepartment,
  departmentNameTaken, departmentCodeTaken, createDesignation, updateDesignation, designationTitleTaken,
  membershipsForUser, activeMembershipsForUser, findMembership, addMembership, hasOpenMembership,
  endMembership, setDefault, activeRoleIdsForUser,
};
