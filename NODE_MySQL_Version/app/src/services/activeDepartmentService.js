'use strict';

const departmentRepository = require('../repositories/departmentRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const requestContext = require('../helpers/requestContext');

// Port of App\Services\ActiveDepartmentService. Owner decision D-214
// (2026-10-31), phase 1: "Log in as". The department membership a person is
// working as this session decides their role, and so every permission check
// (sessionAuth loads req.permissions with effectiveRoleId()). A person with
// no membership keeps their own role. Admin's "Preview as" a designation
// (and MD/ED's "view as" a department) borrows that designation's role for
// the session until they exit.

const SESSION_MEMBERSHIP = '_active_membership_id';
const SESSION_PREVIEW = '_preview_designation_id';

function label(m) {
  return `${m.designation_title} · ${m.department_name}`;
}

function previewLabel(d) {
  return `Preview: ${d.title} · ${d.department_name}`;
}

/** Logs with the acting label this session now has (the request's own context was opened before the change). */
async function logActing(userId, actingAs, ...args) {
  const store = Object.assign({}, requestContext.get() || {}, { userId, actingAs });
  return requestContext.run(store, () => auditLogRepository.log(userId, ...args));
}

/**
 * After the password (and 2FA) check: one membership is chosen straight
 * away, so is the remembered default; otherwise the person is asked.
 * @returns {Promise<string|null>} the page to go to next, or null when nothing is needed
 */
async function afterLogin(req, userId) {
  delete req.session[SESSION_MEMBERSHIP];
  delete req.session[SESSION_PREVIEW];
  const memberships = await departmentRepository.activeMembershipsForUser(userId);
  if (memberships.length === 0) {
    return null;
  }
  const def = memberships.filter((m) => Number(m.is_default) === 1);
  if (memberships.length === 1 || def.length > 0) {
    await activate(req, userId, Number((def[0] || memberships[0]).id), false);
    return null;
  }
  return '/choose-department';
}

/** The membership this session works as (re-checked every request: an ended membership stops at once). */
async function current(req, userId) {
  const id = req.session[SESSION_MEMBERSHIP];
  if (id === undefined || id === null) {
    return null;
  }
  const found = (await departmentRepository.activeMembershipsForUser(userId)).find((m) => Number(m.id) === Number(id)) || null;
  if (found === null) {
    delete req.session[SESSION_MEMBERSHIP];
  }
  return found;
}

/** Whether this person still has to pick a department before using the app. */
async function needsChoice(req, userId) {
  if ((await current(req, userId)) !== null) {
    return false;
  }
  return (await departmentRepository.activeMembershipsForUser(userId)).length > 0;
}

/** Starts working as one of this person's own current memberships. @returns {Promise<boolean>} false when it is not theirs or not current */
async function activate(req, userId, membershipId, logSwitch = true) {
  const memberships = await departmentRepository.activeMembershipsForUser(userId);
  const m = memberships.find((x) => Number(x.id) === Number(membershipId));
  if (!m) {
    return false;
  }
  const previous = await current(req, userId);
  req.session[SESSION_MEMBERSHIP] = Number(membershipId);
  delete req.session[SESSION_PREVIEW];
  await logActing(userId, label(m), logSwitch ? 'DEPARTMENT_SWITCHED' : 'DEPARTMENT_SELECTED', 'user_department_memberships', Number(membershipId), 'acting_as',
    previous !== null ? label(previous) : null, label(m));
  return true;
}

/** Admin "Preview as" / MD-ED "view as": the designation borrowed for this session. */
async function preview(req) {
  const id = req.session[SESSION_PREVIEW];
  if (id === undefined || id === null) {
    return null;
  }
  return (await departmentRepository.findDesignation(Number(id))) || null;
}

async function startPreview(req, userId, designationId) {
  const designation = await departmentRepository.findDesignation(designationId);
  if (!designation) {
    return false;
  }
  req.session[SESSION_PREVIEW] = Number(designationId);
  await logActing(userId, previewLabel(designation), 'DEPARTMENT_PREVIEW_STARTED', 'department_designations', Number(designationId), null, null,
    `${designation.title} · ${designation.department_name}`);
  return true;
}

async function endPreview(req, userId) {
  const designation = await preview(req);
  delete req.session[SESSION_PREVIEW];
  if (designation !== null) {
    const m = await current(req, userId);
    await logActing(userId, m !== null ? label(m) : null, 'DEPARTMENT_PREVIEW_ENDED', 'department_designations', Number(designation.id));
  }
}

/**
 * Everything sessionAuth needs for this request: the role every permission
 * check uses (the previewed designation's, else the active membership's,
 * else the person's own), whether it is a preview, and the audit label.
 */
async function resolve(req, userId, ownRoleId) {
  const designation = await preview(req);
  if (designation !== null) {
    return { membership: await current(req, userId), preview: designation, effectiveRoleId: Number(designation.role_id), previewing: true, actingAs: previewLabel(designation) };
  }
  const m = await current(req, userId);
  return {
    membership: m,
    preview: null,
    effectiveRoleId: m !== null ? Number(m.role_id) : ownRoleId,
    previewing: false,
    actingAs: m !== null ? label(m) : null,
  };
}

module.exports = {
  SESSION_MEMBERSHIP, SESSION_PREVIEW, afterLogin, needsChoice, activate, current, preview,
  startPreview, endPreview, resolve, label,
};
