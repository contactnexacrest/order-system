'use strict';

const flash = require('../helpers/flash');
const userRepository = require('../repositories/userRepository');
const passwordHash = require('../helpers/passwordHash');
const passwordPolicyService = require('../services/passwordPolicyService');
const authService = require('../services/authService');

/** docs/schema.sql Section AI — self-service staff account page (email signature, self-service password change — a natural home for other self-service settings later). */

async function edit(req, res) {
  const user = await userRepository.findById(req.user.id);
  res.renderView('account/edit', { user }, 'layout/base');
}

async function updateSignature(req, res) {
  const signature = String(req.body.email_signature || '').trim();
  await userRepository.updateSignature(req.user.id, signature !== '' ? signature : null);
  flash.set(req, 'success', 'Signature saved — it will be used on every email you send through the system from now on.');
  res.redirect('/account');
}

/** User-reported batch item 20 — the per-user opt-in/out for action-notification emails. */
async function updateNotificationPreference(req, res) {
  const enabled = !!req.body.email_action_notifications_enabled;
  await userRepository.updateNotificationPreference(req.user.id, enabled);
  flash.set(req, 'success', 'Notification preference saved.');
  res.redirect('/account');
}

/**
 * 90-item backlog #26: a voluntary, logged-in self-service password
 * change — previously the only ways a staff user's password could
 * change were the forced-at-first-login flow and the logged-out
 * forgot-password email flow, with no option for an already-logged-in
 * user who simply wants to change it. Mirrors
 * clientPortalController.changePassword()'s exact pattern (current
 * password re-verified, then passwordPolicyService, then match check).
 */
async function updatePassword(req, res) {
  const userId = req.user.id;
  const current = String(req.body.current_password || '');
  const newPassword = String(req.body.new_password || '');
  const confirm = String(req.body.confirm_password || '');

  const user = await userRepository.findById(userId);
  if (!user || !(await passwordHash.verify(current, user.password_hash))) {
    flash.set(req, 'error', 'Current password is incorrect.');
    res.redirect('/account');
    return;
  }
  const policyError = await passwordPolicyService.validate(newPassword);
  if (policyError !== null) {
    flash.set(req, 'error', policyError);
    res.redirect('/account');
    return;
  }
  if (newPassword !== confirm) {
    flash.set(req, 'error', 'New passwords do not match.');
    res.redirect('/account');
    return;
  }

  await authService.changePassword(req, userId, newPassword);
  flash.set(req, 'success', 'Password updated.');
  res.redirect('/account');
}

module.exports = { edit, updateSignature, updateNotificationPreference, updatePassword };
