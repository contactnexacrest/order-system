'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const reportAccessRepository = require('../repositories/reportAccessRepository');
const roleRepository = require('../repositories/roleRepository');
const userRepository = require('../repositories/userRepository');
const reportAccess = require('../services/reportAccess');

/**
 * Owner decision D-379 (15 Nov 2026): Users & roles › Roles and permissions › <role> › Reports tab
 * (the level of each report for the role, with the reason for the change), "See Reports as this
 * role" (the Reports page drawn as the role sees it, read-only), "Reset to defaults", and Edit user ›
 * "Extra report access for this person". Every route needs manage_report_access (seeded: MD, ED).
 * Audit: REPORT_ACCESS_CHANGED (one row per changed report, old → new), USER_REPORT_EXTRA_ADDED /
 * USER_REPORT_EXTRA_REMOVED.
 *
 * Routes:
 *   GET  /admin/roles/:id/reports          roleTab()
 *   POST /admin/roles/:id/reports          saveRole()
 *   POST /admin/roles/:id/reports/reset    resetRole()
 *   GET  /admin/roles/:id/reports/preview  preview()
 *   POST /users/:id/report-extras          addUserExtra()
 *   POST /users/:id/report-extras/remove   removeUserExtra()
 *
 * PHP twin: PHP_MySQL_Version/app/src/Controllers/ReportAccessController.php
 */

function me(req) {
  return Number((req.user && req.user.id) || 0);
}

/** @returns {Promise<object|null>} the role of the URL, or null after sending the person back */
async function roleOr404(req, res) {
  const role = await roleRepository.find(parseInt(req.params.id, 10) || 0);
  if (!role) {
    flash.set(req, 'error', 'Role not found.');
    res.redirect('/admin/permissions');
    return null;
  }
  return role;
}

async function roleTab(req, res) {
  const role = await roleOr404(req, res);
  if (role === null) return;
  const roleId = Number(role.id);
  const perms = new Set(await reportAccessRepository.rolePermissionKeys(roleId));
  const can = (perm) => perms.has(perm);
  const levels = await reportAccess.roleLevels(roleId);
  const sections = [];
  for (const [section, keys] of Object.entries(reportAccess.bySection())) {
    const rows = keys.map((key) => {
      const level = levels[key];
      return {
        key,
        label: reportAccess.REPORTS[key].label,
        level,
        needs: reportAccess.needsText(key),
        // D-379 cosmetic follow-up: the "Needs" column, one plain-word entry per need (✓ / ⚠).
        needsItems: reportAccess.needsItems(can, key, level),
        // "missing for this role": the level set here opens nothing without these permissions.
        missing: level === reportAccess.OFF ? [] : reportAccess.missingPermissions(can, key, level),
      };
    });
    sections.push({ key: section, label: reportAccess.SECTIONS[section], rows });
  }
  res.renderView('permission_admin/role_reports', {
    role,
    sections,
    defaults: reportAccess.defaultsForRoleName(String(role.name)),
    reportLevels: reportAccess.LEVELS,
    levelLabels: reportAccess.LEVEL_LABELS,
  }, 'layout/base');
}

async function saveRole(req, res) {
  const role = await roleOr404(req, res);
  if (role === null) return;
  const back = `/admin/roles/${Number(role.id)}/reports`;
  const body = req.body || {};
  const reason = String(body.reason ?? '').trim();
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(back);
    return;
  }
  const posted = body.levels && typeof body.levels === 'object' && !Array.isArray(body.levels) ? body.levels : {};
  const levels = {};
  for (const [key, level] of Object.entries(posted)) {
    if (typeof level === 'string' && reportAccess.isReport(key) && reportAccess.isLevel(level)) levels[key] = level;
  }
  const changed = await reportAccess.saveRoleLevels(Number(role.id), levels, me(req), reason);
  flash.set(req, 'success', changed.length === 0
    ? `Nothing changed for ${role.name}.`
    : `Saved the report access for ${role.name}: ${changed.length} report${changed.length === 1 ? '' : 's'} changed.`);
  res.redirect(back);
}

async function resetRole(req, res) {
  const role = await roleOr404(req, res);
  if (role === null) return;
  const back = `/admin/roles/${Number(role.id)}/reports`;
  const reason = String((req.body || {}).reason ?? '').trim();
  const error = reasonValidator.check(reason);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect(back);
    return;
  }
  const changed = await reportAccess.resetRole(Number(role.id), String(role.name), me(req), reason);
  flash.set(req, 'success', changed.length === 0
    ? `${role.name} already has the default report access.`
    : `Reset ${role.name} to the default report access: ${changed.length} report${changed.length === 1 ? '' : 's'} changed.`);
  res.redirect(back);
}

/** "See Reports as this role": the Reports page with the role's own levels and permissions, read-only. */
async function preview(req, res) {
  const role = await roleOr404(req, res);
  if (role === null) return;
  reportAccess.previewAsRole(Number(role.id));
  let data;
  try {
    data = await require('./reportController').indexData('', role);
  } finally {
    reportAccess.previewAsRole(null); // the menus of the page stay the signed-in person's
  }
  res.renderView('reports/index', data, 'layout/base');
}

async function addUserExtra(req, res) {
  const userId = parseInt(req.params.id, 10) || 0;
  const target = await userRepository.findById(userId);
  if (!target) {
    flash.set(req, 'error', 'User not found.');
    res.redirect('/users');
    return;
  }
  const body = req.body || {};
  const error = await reportAccess.addUserExtra(
    userId,
    target.role_id !== null && target.role_id !== undefined ? Number(target.role_id) : null,
    String(body.report_key ?? ''),
    String(body.level ?? ''),
    String(body.reason ?? ''),
    me(req)
  );
  flash.set(req, error === null ? 'success' : 'error', error ?? 'Extra report access saved.');
  res.redirect(`/users/${userId}/edit#report-extras`);
}

async function removeUserExtra(req, res) {
  const userId = parseInt(req.params.id, 10) || 0;
  const removed = await reportAccess.removeUserExtra(userId, String((req.body || {}).report_key ?? ''), me(req));
  flash.set(req, removed ? 'success' : 'error', removed ? 'Extra report access removed.' : 'That extra report access was already removed.');
  res.redirect(`/users/${userId}/edit#report-extras`);
}


/**
 * Users › Edit: loads the "Extra report access for this person" card (only for holders of
 * manage_report_access), then hands on. PHP draws it in views/users/_report_extras.php.
 */
async function loadUserExtrasCard(req, res, next) {
  if (req.permissions && req.permissions.manage_report_access) {
    const userId = parseInt(req.params.id, 10) || 0;
    const target = await userRepository.findById(userId);
    if (target) {
      const roleId = target.role_id !== null && target.role_id !== undefined ? Number(target.role_id) : null;
      const extras = await reportAccess.userExtras(userId);
      const gives = await reportAccess.roleGivesFor(userId, roleId);
      req.reportExtrasCard = {
        userId,
        rows: Object.entries(extras).filter(([key]) => reportAccess.isReport(key)).map(([key, x]) => ({
          key,
          label: reportAccess.REPORTS[key].label,
          gives: reportAccess.LEVEL_LABELS[gives[key]],
          gets: reportAccess.LEVEL_LABELS[String(x.level)] || String(x.level),
          reason: String(x.reason ?? ''),
          by: x.created_by_name ?? '—',
          on: x.created_at,
        })),
        options: Object.keys(reportAccess.REPORTS).filter((key) => gives[key] !== reportAccess.ALL).map((key) => ({
          key, label: reportAccess.REPORTS[key].label, gives: gives[key], givesLabel: reportAccess.LEVEL_LABELS[gives[key]],
        })),
        levels: [reportAccess.OWN, reportAccess.DEPARTMENT, reportAccess.ALL].map((l) => ({ value: l, label: reportAccess.LEVEL_LABELS[l], rank: reportAccess.rank(l) })),
      };
    }
  }
  next();
}

module.exports = { roleTab, saveRole, resetRole, preview, addUserExtra, removeUserExtra, loadUserExtrasCard };
