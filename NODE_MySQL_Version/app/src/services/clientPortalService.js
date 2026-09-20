'use strict';

const crypto = require('crypto');

const env = require('../config/env');
const passwordHash = require('../helpers/passwordHash');
const auditLogRepository = require('../repositories/auditLogRepository');
const clientLoginRepository = require('../repositories/clientLoginRepository');
const clientPasswordResetTokenRepository = require('../repositories/clientPasswordResetTokenRepository');
const clientRepository = require('../repositories/clientRepository');
const loginAttemptRepository = require('../repositories/loginAttemptRepository');
const emailService = require('./emailService');

/**
 * Port of App\Services\ClientPortalService. Client portal auth — a
 * structurally separate surface from staff/`users` sessions (its own
 * session key, its own login table). No client can log in until
 * clientLoginRepository has a row for them, and that row is only ever
 * created here, called from the Stage 3 advance-cleared gate. A client
 * with more than one order does not get provisioned twice — whichever
 * order clears its advance first triggers it, and every subsequent
 * order's documents are simply visible under the same login.
 *
 * Every function takes `req` where the PHP original touched $_SESSION,
 * since Express sessions are per-request objects, not a superglobal —
 * same convention as authService.js.
 */

const SESSION_CLIENT_ID = '_client_portal_client_id';

/**
 * Called from ordersController.clearAdvancePayment().
 * @returns {Promise<string>} 'provisioned' | 'already_provisioned' | 'no_email_on_file'
 *   — the caller surfaces 'no_email_on_file' to staff, since a client with
 *   no email can never receive their login otherwise, and that must not
 *   fail silently.
 */
async function provisionIfNeeded(clientId, orderId) {
  if (await clientLoginRepository.findByClientId(clientId)) {
    return 'already_provisioned';
  }

  const client = await clientRepository.find(clientId);
  if (!client || !client.email) {
    return 'no_email_on_file';
  }

  // Random password the client will never actually see or use —
  // force_password_change (default 1) means the very first thing they do
  // is set their own via the emailed link below, mirroring the staff
  // admin-created-account pattern.
  const randomPassword = crypto.randomBytes(16).toString('hex');
  await clientLoginRepository.create(clientId, await passwordHash.hash(randomPassword, 12), orderId);
  await auditLogRepository.log(null, 'CLIENT_PORTAL_PROVISIONED', 'clients', clientId, null, null, null, `Triggered by order #${orderId} advance cleared`);

  await sendSetPasswordEmail(clientId, client.email, client.company_legal_name);

  return 'provisioned';
}

async function sendSetPasswordEmail(clientId, email) {
  const rawToken = crypto.randomBytes(32).toString('hex');
  const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
  const expiresAt = new Date(Date.now() + 72 * 3600000).toISOString().slice(0, 19).replace('T', ' '); // longer than the staff 45-minute window — a client may not check email immediately
  await clientPasswordResetTokenRepository.create(clientId, tokenHash, expiresAt, null);

  const setUrl = `${env.get('APP_URL', '').replace(/\/+$/, '')}/client/set-password/${rawToken}`;
  const body = 'Hello,\n\n'
    + 'Your advance payment has been received and cleared — thank you.\n\n'
    + 'You can now log in to track your order and download your documents (Quotation, Proforma Invoice, and everything issued from here onward) at any time.\n\n'
    + `To set up your login, open this link within 72 hours:\n${setUrl}\n\n`
    + `Your login email will be: ${email}\n\n`
    + 'NexaCrest International Private Limited';

  await emailService.sendPlainText(email, 'Your NexaCrest order portal access', body);
}

async function attemptLogin(req, email, password) {
  const ip = req.ip || 'unknown';
  const login = await clientLoginRepository.findByEmail(email);

  if (!login) {
    await loginAttemptRepository.record(null, email, ip, false);
    return { status: 'invalid_credentials' };
  }
  if (!login.client_is_active || !login.is_active) {
    await loginAttemptRepository.record(null, email, ip, false);
    return { status: 'account_disabled' };
  }
  if (login.locked_until && new Date(login.locked_until).getTime() > Date.now()) {
    return { status: 'locked_out', locked_until: login.locked_until };
  }
  if (!(await passwordHash.verify(password, login.password_hash))) {
    await clientLoginRepository.incrementFailedLogins(login.client_id);
    if (parseInt(login.failed_login_count, 10) + 1 >= 5) {
      const until = new Date(Date.now() + 15 * 60000).toISOString().slice(0, 19).replace('T', ' ');
      await clientLoginRepository.lockUntil(login.client_id, until);
      return { status: 'locked_out', locked_until: until };
    }
    return { status: 'invalid_credentials' };
  }

  await clientLoginRepository.resetFailedLogins(login.client_id);
  await new Promise((resolve, reject) => {
    req.session.regenerate((err) => {
      if (err) return reject(err);
      // Must be set inside the callback, not after the awaited promise
      // resolves — regenerate() replaces req.session's contents, so a
      // write outside this callback risks landing on a stale reference
      // depending on the session store. Matches authService.js's
      // establishSession() convention.
      req.session[SESSION_CLIENT_ID] = login.client_id;
      resolve();
    });
  });
  await clientLoginRepository.updateLastLogin(login.client_id);
  await auditLogRepository.log(null, 'CLIENT_LOGIN_SUCCESS', 'clients', login.client_id);

  return { status: 'ok', force_password_change: !!login.force_password_change };
}

function currentClientId(req) {
  return req.session[SESSION_CLIENT_ID] ?? null;
}

async function currentClient(req) {
  const id = currentClientId(req);
  return id ? clientRepository.find(id) : null;
}

async function logout(req) {
  const clientId = currentClientId(req);
  if (clientId) {
    await auditLogRepository.log(null, 'CLIENT_LOGOUT', 'clients', clientId);
  }
  await new Promise((resolve, reject) => {
    req.session.regenerate((err) => {
      if (err) return reject(err);
      resolve();
    });
  });
}

async function changePassword(clientId, newPassword) {
  await clientLoginRepository.updatePassword(clientId, await passwordHash.hash(newPassword, 12), false);
  await auditLogRepository.log(null, 'CLIENT_PASSWORD_CHANGED', 'clients', clientId);
}

module.exports = {
  SESSION_CLIENT_ID, provisionIfNeeded, attemptLogin, currentClientId, currentClient, logout, changePassword,
};
