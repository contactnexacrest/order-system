'use strict';

const flash = require('../helpers/flash');
const authService = require('../services/authService');
const departmentRepository = require('../repositories/departmentRepository');
const userRepository = require('../repositories/userRepository');
const activeDepartmentService = require('../services/activeDepartmentService');

// Port of App\Controllers\DepartmentController. Owner decision D-214
// (2026-10-31), phase 1: "Log in as" a department after sign-in, switching
// department without logging out, and Admin's "Preview as" a designation.

async function chooseForm(req, res) {
  const userId = authService.currentUserId(req);
  const memberships = await departmentRepository.activeMembershipsForUser(userId);
  if (memberships.length === 0) {
    res.redirect('/');
    return;
  }
  const current = await activeDepartmentService.current(req, userId);
  res.renderView('departments/choose', {
    user: await userRepository.findById(userId),
    memberships,
    selectedId: Number(current ? current.id : memberships[0].id),
  }, 'layout/bare');
}

async function choose(req, res) {
  const userId = authService.currentUserId(req);
  const membershipId = parseInt(req.body.membership_id, 10) || 0;
  if (!(await activeDepartmentService.activate(req, userId, membershipId, false))) {
    flash.set(req, 'error', 'Choose one of your departments.');
    res.redirect('/choose-department');
    return;
  }
  if (req.body.remember) {
    await departmentRepository.setDefault(userId, membershipId);
  }
  res.redirect('/');
}

/** From the menu: work as another of one's own departments, without logging out. */
async function switchDepartment(req, res) {
  const userId = authService.currentUserId(req);
  const membershipId = parseInt(req.body.membership_id, 10) || 0;
  if (!(await activeDepartmentService.activate(req, userId, membershipId))) {
    flash.set(req, 'error', 'That department is not one of yours (or it has ended).');
    res.redirect('/');
    return;
  }
  const m = await departmentRepository.findMembership(membershipId);
  flash.set(req, 'success', `You are now working as ${activeDepartmentService.label(m)}.`);
  res.redirect('/');
}

/** Admin "Preview the app as …" a designation (and MD/ED "view as" a department). */
async function previewStart(req, res) {
  const userId = authService.currentUserId(req);
  if (!(await activeDepartmentService.startPreview(req, userId, parseInt(req.body.designation_id, 10) || 0))) {
    flash.set(req, 'error', 'Designation not found.');
    res.redirect('/admin/departments');
    return;
  }
  const p = await activeDepartmentService.preview(req);
  flash.set(req, 'success', `Previewing the app as ${p.title} · ${p.department_name}. Use "Exit preview" at the top to return.`);
  res.redirect('/');
}

async function previewEnd(req, res) {
  await activeDepartmentService.endPreview(req, authService.currentUserId(req));
  flash.set(req, 'success', 'Preview ended.');
  res.redirect('/admin/departments');
}

module.exports = { chooseForm, choose, switchDepartment, previewStart, previewEnd };
