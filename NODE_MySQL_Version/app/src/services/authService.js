'use strict';

const { parseDbDateTime, formatForDb } = require('../helpers/dbDateTime');
const passwordHash = require('../helpers/passwordHash');
const { regeneratePreserving } = require('../helpers/sessionRegenerate');
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
const SESSION_VERSION_KEY = '_session_version';

// QA-5 CP-13: mirrors clientPortalService.js's own SESSION_CLIENT_ID. Kept
// as a literal rather than required from clientPortalService.js to avoid a
// circular require (that file needs this file's SESSION_USER_ID the same
// way) — see sessionRegenerate.js for why this needs preserving at all.
const CLIENT_PORTAL_SESSION_KEY = '_client_portal_client_id';

// AUTH-04/AUTH-05: a fixed dummy hash so passwordHash.verify() always runs
// real bcrypt work, even for an email that doesn't exist — otherwise the
// timing difference alone could tell an attacker an account exists.
const DUMMY_PASSWORD_HASH = '$2y$12$XkZEWJ9OTUrQ7pC.e9pnpO1LA.XHdoTUKJahoxDBHKjd8rQTbId22';

async function attemptLogin(req, email, password) {
  const ip = req.ip || 'unknown';
  const user = await userRepository.findByEmail(email);

  // AUTH-04/AUTH-05: the password is checked BEFORE anything about account
  // state (exists / disabled / locked) is revealed — an attacker submitting
  // any password for a guessed email must see the exact same
  // 'invalid_credentials' response whether that account doesn't exist, is
  // perfectly normal, is disabled, or is currently locked out. Only once
  // the real password is confirmed correct does the legitimate account
  // holder get told why they still can't log in.
  const verified = await passwordHash.verify(password, user ? user.password_hash : DUMMY_PASSWORD_HASH);
  const passwordCorrect = user !== null && verified;

  if (!passwordCorrect) {
    // External QA report L-03: this failed attempt must be recorded
    // BEFORE recentFailedCount() runs, or the count it reads never
    // includes the very attempt that triggered it — with "lock after 5",
    // the 5th failure would see only 4 prior rows (4 >= 5 is false) and
    // the account would only actually lock on the 6th.
    await loginAttemptRepository.record(user ? user.id : null, email, ip, false);
    if (user) {
      await userRepository.incrementFailedLogins(user.id);

      const maxAttempts = parseInt((await companySettingsRepository.get('failed_login_lockout_count')) ?? '5', 10);
      const lockoutMinutes = parseInt((await companySettingsRepository.get('lockout_duration_minutes')) ?? '15', 10);
      const recentFailures = await loginAttemptRepository.recentFailedCount(user.id, lockoutMinutes);

      if (recentFailures >= maxAttempts) {
        // External QA report L-21/L-02: toISOString() is always UTC — see
        // dbDateTime.formatForDb()'s docblock.
        const until = new Date(Date.now() + lockoutMinutes * 60000);
        const untilStr = formatForDb(until);
        await userRepository.lockUntil(user.id, untilStr);
        await auditLogRepository.log(user.id, 'ACCOUNT_LOCKED', 'users', user.id, null, null, null, `${recentFailures} failed attempts within ${lockoutMinutes} minutes`, ip);
      }
    }
    return { status: 'invalid_credentials' };
  }

  // Password confirmed correct — safe to reveal real account state now.
  if (!user.is_active) {
    await loginAttemptRepository.record(user.id, email, ip, false);
    return { status: 'account_disabled' };
  }

  if (user.locked_until && parseDbDateTime(user.locked_until).getTime() > Date.now()) {
    await loginAttemptRepository.record(user.id, email, ip, false);
    return { status: 'locked_out', locked_until: user.locked_until };
  }

  // Password correct, active, not locked.
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

async function establishSession(req, userId) {
  // External QA report L-26: snapshot the row's current session_version
  // at login time — sessionAuth's middleware compares this against the
  // live DB value on every request.
  const user = await userRepository.findById(userId);
  await regeneratePreserving(req, [CLIENT_PORTAL_SESSION_KEY], () => {
    req.session[SESSION_USER_ID] = userId;
    req.session[SESSION_VERSION_KEY] = user ? Number(user.session_version) : 0;
  });
  await userRepository.updateLastLogin(userId);
}

function currentUserId(req) {
  return req.session[SESSION_USER_ID] ?? null;
}

/** @see sessionAuth.js — the value this session logged in with. */
function sessionVersion(req) {
  return req.session[SESSION_VERSION_KEY] ?? null;
}

/** Called after a successful in-session password change, so THIS session (unlike every other one) keeps working. */
async function refreshSessionVersion(req, userId) {
  const user = await userRepository.findById(userId);
  req.session[SESSION_VERSION_KEY] = user ? Number(user.session_version) : 0;
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
  await regeneratePreserving(req, [CLIENT_PORTAL_SESSION_KEY]);
}

async function changePassword(req, userId, newPassword) {
  const hash = await passwordHash.hash(newPassword, 12);
  await userRepository.updatePassword(userId, hash, false);
  await auditLogRepository.log(userId, 'PASSWORD_CHANGED', 'users', userId);
  // External QA report L-26: updatePassword() just bumped
  // session_version — without this, the session that JUST made this
  // very change would itself be logged out on its next request, which
  // only makes sense for every OTHER session.
  if (req && currentUserId(req) === userId) {
    await refreshSessionVersion(req, userId);
  }
}

module.exports = {
  attemptLogin, completeTwoFactor, currentUserId, sessionVersion, refreshSessionVersion, currentUser, logout, changePassword,
  SESSION_USER_ID,
};
