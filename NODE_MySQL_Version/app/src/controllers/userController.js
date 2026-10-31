'use strict';

const departmentRepository = require('../repositories/departmentRepository');
const { todayIst } = require('../helpers/dbDateTime');

const crypto = require('crypto');

const flash = require('../helpers/flash');
const passwordHash = require('../helpers/passwordHash');
const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const lookupRepository = require('../repositories/lookupRepository');
const userRepository = require('../repositories/userRepository');

/**
 * Port of App\Controllers\UserController — admin user-management screen.
 * Gated entirely on `manage_users` (a Super Admin always passes that check
 * — see sessionAuth.js, which grants every permission key to an effective
 * Super Admin, so no separate bypass is needed here). Covers create, edit
 * (name/email/phone/role), list, deactivate/reactivate, and force a
 * password reset. Password itself is never editable here — only via
 * force-reset (always generates a new one-time temp password) or the
 * user's own change-password flow.
 */

async function index(req, res) {
  const status = ['active', 'inactive', 'archived'].includes(req.query.status) ? req.query.status : 'active';
  const [users, statusCounts, roles] = await Promise.all([
    userRepository.listAllForAdmin(status),
    userRepository.statusCounts(),
    lookupRepository.roles(),
  ]);
  res.renderView('users/index', { users, statusCounts, currentStatus: status, roles }, 'layout/base');
}

async function create(req, res) {
  const name = String(req.body.name || '').trim();
  const email = String(req.body.email || '').trim().toLowerCase();
  const phone = String(req.body.phone || '').trim() || null;
  const roleId = req.body.role_id !== undefined && req.body.role_id !== '' ? parseInt(req.body.role_id, 10) : null;

  if (name === '' || email === '') {
    flash.set(req, 'error', 'Name and email are required.');
    res.redirect('/users');
    return;
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    flash.set(req, 'error', `"${email}" doesn't look like a valid email address.`);
    res.redirect('/users');
    return;
  }

  if (await userRepository.findByEmail(email)) {
    flash.set(req, 'error', `A user with the email ${email} already exists.`);
    res.redirect('/users');
    return;
  }

  const tempPassword = generateTempPassword();
  const userId = await userRepository.create(name, email, phone, roleId, await passwordHash.hash(tempPassword));

  const actor = req.user;
  await auditLogRepository.log(actor.id, 'USER_CREATED', 'users', userId, null, null, null, `Created user ${email}`);

  // Shown once, here, in a flash message — never emailed, never
  // logged, never stored anywhere but the (already hashed) DB row.
  // Hand it to the person out-of-band; they're forced to change it
  // on first login regardless (force_password_change = 1).
  flash.set(req, 'success', `User created: ${name} (${email}). One-time temporary password: ${tempPassword} — give this to them directly; it will not be shown again, and they must change it on first login.`);
  res.redirect('/users');
}

async function editForm(req, res) {
  const userId = parseInt(req.params.id, 10);
  const target = await userRepository.findById(userId);
  if (!target) {
    flash.set(req, 'error', 'User not found.');
    res.redirect('/users');
    return;
  }
  if (parseInt(target.is_protected_account, 10) === 1) {
    flash.set(req, 'error', 'This is a protected founder account — its details can never be changed through the application, by anyone, including other Super Admins.');
    res.redirect('/users');
    return;
  }
  const roles = await lookupRepository.roles();
  // Owner decision D-214: the person's departments, and the designations they can be added to.
  const memberships = await departmentRepository.membershipsForUser(userId);
  const designations = (await departmentRepository.designations())
    .filter((d) => Number(d.is_active) === 1 && Number(d.department_active) === 1);
  res.renderView('users/edit', { target, roles, memberships, designations, today: todayIst() }, 'layout/base');
}

async function update(req, res) {
  const userId = parseInt(req.params.id, 10);
  const target = await userRepository.findById(userId);
  if (!target) {
    flash.set(req, 'error', 'User not found.');
    res.redirect('/users');
    return;
  }
  if (parseInt(target.is_protected_account, 10) === 1) {
    flash.set(req, 'error', 'This is a protected founder account — its details can never be changed through the application, by anyone, including other Super Admins.');
    res.redirect('/users');
    return;
  }

  const name = String(req.body.name || '').trim();
  const email = String(req.body.email || '').trim().toLowerCase();
  const phone = String(req.body.phone || '').trim() || null;
  const roleId = req.body.role_id !== undefined && req.body.role_id !== '' ? parseInt(req.body.role_id, 10) : null;

  if (name === '' || email === '') {
    flash.set(req, 'error', 'Name and email are required.');
    res.redirect(`/users/${userId}/edit`);
    return;
  }
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    flash.set(req, 'error', `"${email}" doesn't look like a valid email address.`);
    res.redirect(`/users/${userId}/edit`);
    return;
  }
  const existing = await userRepository.findByEmail(email);
  if (existing && existing.id !== userId) {
    flash.set(req, 'error', `A user with the email ${email} already exists.`);
    res.redirect(`/users/${userId}/edit`);
    return;
  }

  const actor = req.user;
  const changes = [
    ['name', target.name, name],
    ['email', target.email, email],
    ['phone', target.phone, phone],
    ['role_id', target.role_id, roleId],
  ].filter(([, oldVal, newVal]) => String(oldVal ?? '') !== String(newVal ?? ''));

  await userRepository.update(userId, name, email, phone, roleId);

  for (const [field, oldVal, newVal] of changes) {
    await auditLogRepository.log(actor.id, 'USER_UPDATED', 'users', userId, field, oldVal !== null && oldVal !== undefined ? String(oldVal) : null, newVal !== null && newVal !== undefined ? String(newVal) : null);
  }

  flash.set(req, 'success', `${name} updated.`);
  res.redirect('/users');
}

async function toggleActive(req, res) {
  const userId = parseInt(req.params.id, 10);
  const target = await userRepository.findById(userId);
  if (!target) {
    flash.set(req, 'error', 'User not found.');
    res.redirect('/users');
    return;
  }

  const actor = req.user;
  if (userId === actor.id) {
    flash.set(req, 'error', "You can't deactivate your own account.");
    res.redirect('/users');
    return;
  }
  if (parseInt(target.is_super_admin, 10) === 1) {
    flash.set(req, 'error', 'A Super Admin account can never be deactivated.');
    res.redirect('/users');
    return;
  }
  if (parseInt(target.is_protected_account, 10) === 1) {
    flash.set(req, 'error', 'This is a protected founder account and can never be deactivated through the application.');
    res.redirect('/users');
    return;
  }

  const newState = !target.is_active;
  await userRepository.setActive(userId, newState);
  await auditLogRepository.log(
    actor.id,
    newState ? 'USER_REACTIVATED' : 'USER_DEACTIVATED',
    'users',
    userId,
    'is_active',
    target.is_active ? '1' : '0',
    newState ? '1' : '0'
  );

  flash.set(req, 'success', newState ? `${target.name} reactivated.` : `${target.name} deactivated — they can no longer log in.`);
  res.redirect('/users');
}

/** Returns [target, errorMessage] — the three guards archive/unarchive/delete all share with toggleActive. */
async function guardedTarget(req, userId) {
  const target = await userRepository.findById(userId);
  if (!target) {
    return [null, 'User not found.'];
  }
  if (userId === req.user.id) {
    return [null, "You can't do this to your own account."];
  }
  if (parseInt(target.is_super_admin, 10) === 1) {
    return [null, 'A Super Admin account can never be archived or deleted.'];
  }
  if (parseInt(target.is_protected_account, 10) === 1) {
    return [null, 'This is a protected founder account and can never be archived or deleted through the application.'];
  }
  return [target, null];
}

async function archive(req, res) {
  const userId = parseInt(req.params.id, 10);
  const [target, error] = await guardedTarget(req, userId);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/users');
    return;
  }
  await userRepository.archive(userId, req.user.id);
  await auditLogRepository.log(req.user.id, 'USER_ARCHIVED', 'users', userId, 'is_archived', '0', '1');
  flash.set(req, 'success', `${target.name} archived — they can no longer log in, and no longer appear in the Active or Inactive lists.`);
  res.redirect('/users?status=archived');
}

async function unarchive(req, res) {
  const userId = parseInt(req.params.id, 10);
  const [target, error] = await guardedTarget(req, userId);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/users');
    return;
  }
  await userRepository.unarchive(userId);
  await auditLogRepository.log(req.user.id, 'USER_UNARCHIVED', 'users', userId, 'is_archived', '1', '0');
  flash.set(req, 'success', `${target.name} unarchived — still deactivated; reactivate separately to let them log in again.`);
  res.redirect('/users?status=inactive');
}

/**
 * A real DELETE, not a soft-delete — see userRepository.deleteUser()'s
 * own docblock for why "is this user referenced anywhere" is left to
 * MySQL's own FK constraints rather than hand-checked here.
 */
async function deleteUser(req, res) {
  const userId = parseInt(req.params.id, 10);
  const [target, error] = await guardedTarget(req, userId);
  if (error) {
    flash.set(req, 'error', error);
    res.redirect('/users');
    return;
  }
  try {
    await userRepository.deleteUser(userId);
  } catch (err) {
    if (err && err.code === 'ER_ROW_IS_REFERENCED_2') {
      // QA Round 5, R5-09: name where the user still appears.
      const where = Object.entries(await userRepository.referencingRows(userId))
        .map(([table, n]) => `${table.replace(/_/g, ' ')} (${n})`).join(', ');
      flash.set(req, 'error', `${target.name} can't be deleted — they still appear in: ${where || 'other records'}. History is never removed, so archive (deactivate) them instead.`);
      res.redirect('/users');
      return;
    }
    throw err;
  }
  await auditLogRepository.log(req.user.id, 'USER_DELETED', 'users', userId, null, null, null, `Deleted ${target.name} (${target.email}) — had no referencing history.`);
  flash.set(req, 'success', `${target.name} permanently deleted.`);
  res.redirect('/users');
}

async function forceResetPassword(req, res) {
  const userId = parseInt(req.params.id, 10);
  const target = await userRepository.findById(userId);
  if (!target) {
    flash.set(req, 'error', 'User not found.');
    res.redirect('/users');
    return;
  }
  if (parseInt(target.is_protected_account, 10) === 1) {
    flash.set(req, 'error', 'This is a protected founder account — its password can only be reset by that person themselves, via "Forgot password" on the login screen.');
    res.redirect('/users');
    return;
  }

  const reason = String(req.body.reason || '').trim();
  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/users');
    return;
  }

  const tempPassword = generateTempPassword();
  await userRepository.adminForceResetPassword(userId, await passwordHash.hash(tempPassword));

  const actor = req.user;
  await auditLogRepository.log(actor.id, 'PASSWORD_ADMIN_RESET', 'users', userId, null, null, null, reason);

  flash.set(req, 'success', `Password reset for ${target.name} (${target.email}). One-time temporary password: ${tempPassword} — give this to them directly; they must change it on first login.`);
  res.redirect('/users');
}

// 12 random bytes -> 16-char base64url, always contains mixed
// case + digits (no dependency on the character set including a
// symbol, since force_password_change means it only has to
// survive one login, not become anyone's long-term password).
function generateTempPassword() {
  return crypto.randomBytes(12).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

module.exports = { index, create, editForm, update, toggleActive, archive, unarchive, deleteUser, forceResetPassword };
