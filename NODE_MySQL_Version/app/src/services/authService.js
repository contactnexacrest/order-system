'use strict';

const passwordHash = require('../helpers/passwordHash');
const userRepository = require('../repositories/userRepository');
const loginAttemptRepository = require('../repositories/loginAttemptRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const smsService = require('./smsService');
const twoFactorService = require('./twoFactorService');

// Port of App\Services\AuthService. Every function takes `req` where the PHP
// original touched $_SESSION, since Express sessions are per-request objects,
// not a superglobal. Login lockout thresholds stay DB-driven
// (company_settings, category 'security'), same as the original.

const SESSION_USER_ID = '_auth_user_id';

async function attemptLogin(req, email, password) {
  const ip = req.ip || 'unknown';
  const user = await userRepository.findByEmail(email);

  if (!user) {
    await loginAttemptRepository.record(null, email, ip, false);
    return { status: 'invalid_credentials' };
  }

  if (!user.is_active) {
    await loginAttemptRepository.record(user.id, email, ip, false);
    return { status: 'account_disabled' };
  }

  if (user.locked_until && new Date(user.locked_until).getTime() > Date.now()) {
    await loginAttemptRepository.record(user.id, email, ip, false);
    return { status: 'locked_out', locked_until: user.locked_until };
  }

  const passwordOk = await passwordHash.verify(password, user.password_hash);
  if (!passwordOk) {
    await userRepository.incrementFailedLogins(user.id);
    await loginAttemptRepository.record(user.id, email, ip, false);

    const maxAttempts = parseInt((await companySettingsRepository.get('failed_login_lockout_count')) ?? '5', 10);
    const lockoutMinutes = parseInt((await companySettingsRepository.get('lockout_duration_minutes')) ?? '15', 10);
    const recentFailures = await loginAttemptRepository.recentFailedCount(user.id, lockoutMinutes);

    if (recentFailures >= maxAttempts) {
      const until = new Date(Date.now() + lockoutMinutes * 60000);
      const untilStr = until.toISOString().slice(0, 19).replace('T', ' ');
      await userRepository.lockUntil(user.id, untilStr);
      await auditLogRepository.log(user.id, 'ACCOUNT_LOCKED', 'users', user.id, null, null, null, `${recentFailures} failed attempts within ${lockoutMinutes} minutes`, ip);
      return { status: 'locked_out', locked_until: untilStr };
    }

    return { status: 'invalid_credentials' };
  }

  // Password correct.
  await userRepository.resetFailedLogins(user.id);
  await loginAttemptRepository.record(user.id, email, ip, true);

  if (user.two_fa_enabled) {
    let method = user.two_fa_method || 'email';
    let destination = method === 'sms' ? (user.phone || '') : user.email;

    if (method === 'sms' && !smsService.isAvailable()) {
      method = 'email';
      destination = user.email;
    }

    const devCode = await twoFactorService.issueCodeFor(req, user.id, method, destination);
    return { status: 'requires_2fa', method, dev_code: devCode };
  }

  await establishSession(req, user.id);
  await auditLogRepository.log(user.id, 'LOGIN_SUCCESS', 'users', user.id, null, null, null, null, ip);
  return { status: 'ok', force_password_change: !!user.force_password_change };
}

async function completeTwoFactor(req, submittedCode) {
  const userId = twoFactorService.pendingUserId(req);
  if (!userId) return { status: 'no_pending_2fa' };

  if (!(await twoFactorService.verify(req, submittedCode))) {
    await auditLogRepository.log(userId, 'LOGIN_2FA_FAILED', 'users', userId);
    return { status: 'invalid_code' };
  }

  const user = await userRepository.findById(userId);
  await establishSession(req, userId);
  await auditLogRepository.log(userId, 'LOGIN_SUCCESS_2FA', 'users', userId);
  return { status: 'ok', force_password_change: !!(user && user.force_password_change) };
}

function establishSession(req, userId) {
  return new Promise((resolve, reject) => {
    req.session.regenerate((err) => {
      if (err) return reject(err);
      req.session[SESSION_USER_ID] = userId;
      userRepository.updateLastLogin(userId).then(resolve, reject);
    });
  });
}

function currentUserId(req) {
  return req.session[SESSION_USER_ID] ?? null;
}

async function currentUser(req) {
  const id = currentUserId(req);
  return id ? userRepository.findById(id) : null;
}

async function logout(req) {
  const userId = currentUserId(req);
  if (userId) {
    await auditLogRepository.log(userId, 'LOGOUT', 'users', userId);
  }
  return new Promise((resolve, reject) => {
    req.session.regenerate((err) => {
      if (err) return reject(err);
      resolve();
    });
  });
}

async function changePassword(userId, newPassword) {
  const hash = await passwordHash.hash(newPassword, 12);
  await userRepository.updatePassword(userId, hash, false);
  await auditLogRepository.log(userId, 'PASSWORD_CHANGED', 'users', userId);
}

module.exports = {
  attemptLogin, completeTwoFactor, currentUserId, currentUser, logout, changePassword,
  SESSION_USER_ID,
};
