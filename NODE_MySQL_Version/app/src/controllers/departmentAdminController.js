'use strict';

const flash = require('../helpers/flash');
const { scalarString } = require('../helpers/input');
const { todayIst } = require('../helpers/dbDateTime');
const auditLogRepository = require('../repositories/auditLogRepository');
const departmentRepository = require('../repositories/departmentRepository');
const roleRepository = require('../repositories/roleRepository');
const userRepository = require('../repositories/userRepository');

// Port of App\Controllers\DepartmentAdminController. Owner decision D-214
// (2026-10-31), phase 1: Admin → Departments & designations (each
// designation tied to its own role, i.e. permission set), and a person's
// department memberships on their user page — the only day-to-day change.

const BACK = '/admin/departments';
const LEVELS = departmentRepository.LEVELS;

const len = (s) => [...s].length;
const isHexColor = (s) => /^#[0-9a-fA-F]{6}$/.test(s);

/** 'YYYY-MM-DD' that is a real calendar date. */
function isRealDate(value) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!m) return false;
  const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
  return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() === Number(m[2]) - 1 && d.getUTCDate() === Number(m[3]);
}

function fail(req, res, message, back = BACK) {
  flash.set(req, 'error', message);
  res.redirect(back);
}

async function index(req, res) {
  const designations = await departmentRepository.designations();
  const byDepartment = {};
  for (const d of designations) {
    (byDepartment[d.department_id] = byDepartment[d.department_id] || []).push(d);
  }
  res.renderView('departments/admin', {
    departments: await departmentRepository.departments(),
    byDepartment,
    levels: LEVELS,
    roles: await roleRepository.all(),
  }, 'layout/base');
}

async function createDepartment(req, res) {
  const user = req.user;
  const name = scalarString(req.body.name);
  const code = scalarString(req.body.code).toUpperCase();
  const color = String(req.body.color ?? '#3a4140');
  let error = null;
  if (len(name) < 2 || len(name) > 100) error = 'Enter the department name (2 to 100 characters).';
  else if (!/^[A-Z][A-Z0-9_]{1,19}$/.test(code)) error = 'Enter a short code: 2 to 20 capital letters, digits or _ (e.g. QUALITY).';
  else if (!isHexColor(color)) error = 'Choose a colour.';
  else if (await departmentRepository.departmentNameTaken(name)) error = `A department named "${name}" already exists.`;
  else if (await departmentRepository.departmentCodeTaken(code)) error = `The code ${code} is already used.`;
  if (error !== null) {
    fail(req, res, error);
    return;
  }
  const id = await departmentRepository.createDepartment(code, name, color);
  await auditLogRepository.log(user.id, 'DEPARTMENT_CREATED', 'departments', id, 'name', null, name);
  flash.set(req, 'success', `Department "${name}" created. Add its designations below.`);
  res.redirect(BACK);
}

async function updateDepartment(req, res) {
  const user = req.user;
  const dept = await departmentRepository.findDepartment(parseInt(req.params.id, 10));
  if (!dept) {
    res.status(404).send('Department not found.');
    return;
  }
  const name = scalarString(req.body.name);
  const color = String(req.body.color ?? dept.color);
  if (len(name) < 2 || len(name) > 100 || !isHexColor(color) || await departmentRepository.departmentNameTaken(name, Number(dept.id))) {
    fail(req, res, 'Enter a unique department name (2 to 100 characters) and a colour.');
    return;
  }
  const active = !!req.body.is_active;
  await departmentRepository.updateDepartment(Number(dept.id), name, color, active, !!req.body.sees_whole_company);
  await auditLogRepository.log(user.id, 'DEPARTMENT_UPDATED', 'departments', Number(dept.id), null,
    `${dept.name} (${Number(dept.is_active) ? 'active' : 'inactive'})`, `${name} (${active ? 'active' : 'inactive'})`);
  flash.set(req, 'success', `Department "${name}" saved.`);
  res.redirect(BACK);
}

/** A new designation; its role is a new one named after it, or an existing role chosen from the list. */
async function createDesignation(req, res) {
  const user = req.user;
  const departmentId = parseInt(req.body.department_id, 10) || 0;
  const dept = await departmentRepository.findDepartment(departmentId);
  const title = scalarString(req.body.title);
  const level = String(req.body.level ?? '');
  const roleChoice = String(req.body.role_id ?? 'new');
  let error = null;
  if (!dept) error = 'Choose the department.';
  else if (len(title) < 2 || len(title) > 100) error = 'Enter the designation (2 to 100 characters), e.g. Quality Manager.';
  else if (!Object.prototype.hasOwnProperty.call(LEVELS, level)) error = 'Choose the level.';
  else if (await departmentRepository.designationTitleTaken(departmentId, title)) error = `"${title}" already exists in ${dept.name}.`;
  else if (roleChoice === 'new' && await roleRepository.findByName(title)) error = `A role named "${title}" already exists — choose it from the list instead of a new one.`;
  else if (roleChoice !== 'new' && !(await roleRepository.find(parseInt(roleChoice, 10) || 0))) error = 'Choose the role.';
  if (error !== null) {
    fail(req, res, error);
    return;
  }
  const roleId = roleChoice === 'new' ? await roleRepository.create(title, `${dept.name} — ${LEVELS[level]}`) : parseInt(roleChoice, 10);
  if (roleChoice === 'new') {
    await auditLogRepository.log(user.id, 'ROLE_CREATED', 'roles', roleId, 'name', null, title);
  }
  const id = await departmentRepository.createDesignation(departmentId, title, level, roleId);
  await auditLogRepository.log(user.id, 'DESIGNATION_CREATED', 'department_designations', id, 'title', null, `${title} · ${dept.name}`);
  flash.set(req, 'success', `"${title}" added to ${dept.name}.` + (roleChoice === 'new' ? ' Its role has no permissions yet — set them with "Permissions".' : ''));
  res.redirect(BACK);
}

async function updateDesignation(req, res) {
  const user = req.user;
  const d = await departmentRepository.findDesignation(parseInt(req.params.id, 10));
  if (!d) {
    res.status(404).send('Designation not found.');
    return;
  }
  const title = scalarString(req.body.title);
  const level = String(req.body.level ?? '');
  const limitRaw = String(req.body.approval_limit_inr ?? '').trim();
  const limitNumber = Number(limitRaw);
  let error = null;
  if (len(title) < 2 || len(title) > 100) error = 'Enter the designation (2 to 100 characters).';
  else if (!Object.prototype.hasOwnProperty.call(LEVELS, level)) error = 'Choose the level.';
  else if (await departmentRepository.designationTitleTaken(Number(d.department_id), title, Number(d.id))) error = `"${title}" already exists in ${d.department_name}.`;
  else if (limitRaw !== '' && (!Number.isFinite(limitNumber) || limitNumber < 0)) error = 'Enter the approval limit in rupees, or leave it empty for none.';
  if (error !== null) {
    fail(req, res, error);
    return;
  }
  await departmentRepository.updateDesignation(Number(d.id), title, level, !!req.body.is_active, limitRaw === '' ? null : Math.round(limitNumber * 100) / 100);
  await auditLogRepository.log(user.id, 'DESIGNATION_UPDATED', 'department_designations', Number(d.id), null,
    `${d.title} · ${d.level} · limit ${d.approval_limit_inr ?? '—'}`, `${title} · ${level} · limit ${limitRaw === '' ? '—' : limitRaw}`);
  flash.set(req, 'success', `"${title}" saved.`);
  res.redirect(BACK);
}

// ---- a person's memberships (on the user's page) ----

/** A protected founder account's details (memberships included) never change through the application. */
function protectedAccount(req, res, target) {
  if (Number((target && target.is_protected_account) || 0) !== 1) {
    return false;
  }
  fail(req, res, 'This is a protected founder account — its details can never be changed through the application, by anyone, including other Super Admins.', '/users');
  return true;
}

async function addMembership(req, res) {
  const user = req.user;
  const target = await userRepository.findById(parseInt(req.params.id, 10));
  const back = `/users/${parseInt(req.params.id, 10)}/edit#departments`;
  if (!target) {
    res.status(404).send('User not found.');
    return;
  }
  if (protectedAccount(req, res, target)) {
    return;
  }
  const designation = await departmentRepository.findDesignation(parseInt(req.body.designation_id, 10) || 0);
  const startsOn = String(req.body.starts_on ?? '').trim() || todayIst();
  let error = null;
  if (!designation || Number(designation.is_active) !== 1) error = 'Choose the department and designation.';
  else if (!isRealDate(startsOn)) error = 'Enter the date the membership starts.';
  else if (await departmentRepository.hasOpenMembership(Number(target.id), Number(designation.id))) error = `${target.name} is already ${designation.title} in ${designation.department_name}.`;
  if (error !== null) {
    fail(req, res, error, back);
    return;
  }
  const id = await departmentRepository.addMembership(Number(target.id), Number(designation.id), startsOn, user.id);
  await auditLogRepository.log(user.id, 'DEPARTMENT_MEMBERSHIP_ADDED', 'user_department_memberships', id, 'user_id', null,
    `${target.name}: ${designation.title} · ${designation.department_name} from ${startsOn}`);
  flash.set(req, 'success', `${target.name} is now ${designation.title} in ${designation.department_name} (from ${startsOn}).`);
  res.redirect(back);
}

async function endMembership(req, res) {
  const user = req.user;
  const m = await departmentRepository.findMembership(parseInt(req.params.membershipId, 10));
  const back = `/users/${parseInt(req.params.id, 10)}/edit#departments`;
  if (!m || Number(m.user_id) !== parseInt(req.params.id, 10)) {
    res.status(404).send('Membership not found.');
    return;
  }
  if (protectedAccount(req, res, await userRepository.findById(Number(m.user_id)))) {
    return;
  }
  const endsOn = String(req.body.ends_on ?? '').trim() || todayIst();
  if (!isRealDate(endsOn) || endsOn < String(m.starts_on)) {
    fail(req, res, 'Enter the last day of the membership (not before it started).', back);
    return;
  }
  await departmentRepository.endMembership(Number(m.id), user.id, endsOn);
  await auditLogRepository.log(user.id, 'DEPARTMENT_MEMBERSHIP_ENDED', 'user_department_memberships', Number(m.id), 'ends_on', null, endsOn,
    `${m.designation_title} · ${m.department_name}`);
  flash.set(req, 'success', `Membership ${m.designation_title} · ${m.department_name} ends on ${endsOn}.`);
  res.redirect(back);
}

module.exports = { index, createDepartment, updateDepartment, createDesignation, updateDesignation, addMembership, endMembership };
