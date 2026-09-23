'use strict';

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
  const [users, roles] = await Promise.all([
    userRepository.listAllForAdmin(),
    lookupRepository.roles(),
  ]);
  res.renderView('users/index', { users, roles }, 'layout/base');
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
  res.renderView('users/edit', { target, roles }, 'layout/base');
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

module.exports = { index, create, editForm, update, toggleActive, forceResetPassword };
