'use strict';

const crypto = require('crypto');
const env = require('../config/env');
const flash = require('../helpers/flash');
const authService = require('../services/authService');
const twoFactorService = require('../services/twoFactorService');
const userRepository = require('../repositories/userRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const passwordResetTokenRepository = require('../repositories/passwordResetTokenRepository');
const passwordPolicyService = require('../services/passwordPolicyService');
const emailService = require('../services/emailService');

// Port of App\Controllers\AuthController.

async function showLogin(req, res) {
  if (authService.currentUserId(req)) {
    res.redirect('/');
    return;
  }
  res.renderView('auth/login', {}, 'layout/bare');
}

async function login(req, res) {
  const email = String(req.body.email || '').trim();
  const password = String(req.body.password || '');

  if (email === '' || password === '') {
    flash.set(req, 'error', 'Email and password are both required.');
    res.redirect('/login');
    return;
  }

  const result = await authService.attemptLogin(req, email, password);

  switch (result.status) {
    case 'ok':
      res.redirect(result.force_password_change ? '/force-password-change' : '/');
      return;
    case 'requires_2fa':
      req.session._2fa_dev_code = result.dev_code || '';
      res.redirect('/2fa');
      return;
    case 'locked_out':
      flash.set(req, 'error', `Account locked until ${result.locked_until} after repeated failed attempts.`);
      res.redirect('/login');
      return;
    case 'account_disabled':
      flash.set(req, 'error', 'This account has been disabled. Contact your administrator.');
      res.redirect('/login');
      return;
    default:
      flash.set(req, 'error', 'Invalid email or password.');
      res.redirect('/login');
  }
}

async function show2fa(req, res) {
  if (!twoFactorService.pendingUserId(req)) {
    res.redirect('/login');
    return;
  }
  res.renderView('auth/two_factor', { devCode: req.session._2fa_dev_code || '' }, 'layout/bare');
}

async function verify2fa(req, res) {
  const code = String(req.body.code || '').trim();
  const result = await authService.completeTwoFactor(req, code);

  if (result.status === 'ok') {
    delete req.session._2fa_dev_code;
    res.redirect(result.force_password_change ? '/force-password-change' : '/');
    return;
  }

  flash.set(req, 'error', 'Invalid or expired verification code.');
  res.redirect('/2fa');
}

async function showForcePasswordChange(req, res) {
  res.renderView('auth/force_password_change', {}, 'layout/bare');
}

async function forcePasswordChange(req, res) {
  const user = req.user;
  const newPassword = String(req.body.new_password || '');
  const confirm = String(req.body.confirm_password || '');

  const policyError = await passwordPolicyService.validate(newPassword);
  if (policyError !== null) {
    flash.set(req, 'error', policyError);
    res.redirect('/force-password-change');
    return;
  }
  if (newPassword !== confirm) {
    flash.set(req, 'error', 'Passwords do not match.');
    res.redirect('/force-password-change');
    return;
  }

  await authService.changePassword(user.id, newPassword);
  flash.set(req, 'success', 'Password updated.');
  res.redirect('/');
}

async function showForgotPassword(req, res) {
  res.renderView('auth/forgot_password', {}, 'layout/bare');
}

async function forgotPassword(req, res) {
  const email = String(req.body.email || '').trim().toLowerCase();
  const genericMessage = `If ${email} matches an account, a password reset link has been sent to it. The link expires in 45 minutes.`;

  const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  if (email === '' || !emailValid) {
    flash.set(req, 'success', genericMessage);
    res.redirect('/login');
    return;
  }

  const user = await userRepository.findByEmail(email);

  if (user && user.is_active) {
    if ((await passwordResetTokenRepository.countRecentForUser(user.id, 60)) < 5) {
      const rawToken = crypto.randomBytes(32).toString('hex');
      const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
      const expiresAt = new Date(Date.now() + 45 * 60000).toISOString().slice(0, 19).replace('T', ' ');
      const ip = req.ip || null;

      await passwordResetTokenRepository.create(user.id, tokenHash, expiresAt, ip);

      const resetUrl = `${env.get('APP_URL', '').replace(/\/+$/, '')}/reset-password/${rawToken}`;
      const body = `Hello ${user.name},\n\n`
        + `A password reset was requested for your NexaCrest Export Operations account (${email}).\n\n`
        + `To set a new password, open this link within 45 minutes:\n${resetUrl}\n\n`
        + `If you didn't request this, you can ignore this email — your password will not be changed.`;

      await emailService.sendPlainText(email, 'Reset your NexaCrest password', body, { isSecurityEmail: true });
      await auditLogRepository.log(user.id, 'PASSWORD_RESET_REQUESTED', 'users', user.id, null, null, null, `Requested from IP ${ip}`);
    }
  }

  flash.set(req, 'success', genericMessage);
  res.redirect('/login');
}

async function showResetPassword(req, res) {
  const token = String(req.params.token || '');
  const row = await passwordResetTokenRepository.findValidByHash(crypto.createHash('sha256').update(token).digest('hex'));

  if (!row) {
    flash.set(req, 'error', 'This password reset link is invalid or has expired. Request a new one below.');
    res.redirect('/forgot-password');
    return;
  }

  res.renderView('auth/reset_password', { token }, 'layout/bare');
}

async function resetPassword(req, res) {
  const token = String(req.params.token || '');
  const row = await passwordResetTokenRepository.findValidByHash(crypto.createHash('sha256').update(token).digest('hex'));

  if (!row) {
    flash.set(req, 'error', 'This password reset link is invalid or has expired. Request a new one below.');
    res.redirect('/forgot-password');
    return;
  }

  const newPassword = String(req.body.new_password || '');
  const confirm = String(req.body.confirm_password || '');

  const policyError = await passwordPolicyService.validate(newPassword);
  if (policyError !== null) {
    flash.set(req, 'error', policyError);
    res.redirect(`/reset-password/${token}`);
    return;
  }
  if (newPassword !== confirm) {
    flash.set(req, 'error', 'Passwords do not match.');
    res.redirect(`/reset-password/${token}`);
    return;
  }

  await authService.changePassword(row.user_id, newPassword);
  await passwordResetTokenRepository.markUsed(row.id);
  await passwordResetTokenRepository.invalidateAllForUser(row.user_id);
  await auditLogRepository.log(row.user_id, 'PASSWORD_RESET_VIA_EMAIL', 'users', row.user_id);

  flash.set(req, 'success', 'Password updated. Sign in with your new password.');
  res.redirect('/login');
}

async function logout(req, res) {
  await authService.logout(req);
  res.redirect('/login');
}

module.exports = {
  showLogin, login, show2fa, verify2fa, showForcePasswordChange, forcePasswordChange,
  showForgotPassword, forgotPassword, showResetPassword, resetPassword, logout,
};
