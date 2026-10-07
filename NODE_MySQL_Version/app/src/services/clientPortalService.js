'use strict';

const crypto = require('crypto');

const env = require('../config/env');
const { parseDbDateTime } = require('../helpers/dbDateTime');
const passwordHash = require('../helpers/passwordHash');
const { regeneratePreserving } = require('../helpers/sessionRegenerate');
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

// docs/schema.sql Section AV — set only when the current client-portal
// session was started by staff (startImpersonation()), not a real client
// password login. Holds the staff user's id, both to show "you are
// viewing as staff" in the portal and to audit-log who ends up acting.
const SESSION_IMPERSONATED_BY = '_client_portal_impersonated_by_staff_id';

// QA-5 CP-13: mirrors authService.js's own SESSION_USER_ID. Kept as a
// literal rather than required from authService.js to avoid a circular
// require (that file needs this file's SESSION_CLIENT_ID the same way) —
// see sessionRegenerate.js for why this needs preserving at all.
const STAFF_SESSION_KEY = '_auth_user_id';

// CP-07: a fixed dummy hash so passwordHash.verify() always runs real
// bcrypt work, even for an email with no client_logins row — otherwise the
// timing difference alone could tell an attacker an account exists.
const DUMMY_PASSWORD_HASH = '$2y$12$XkZEWJ9OTUrQ7pC.e9pnpO1LA.XHdoTUKJahoxDBHKjd8rQTbId22';

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

  // CP-07: the password is checked BEFORE anything about account state
  // (exists / disabled / locked) is revealed — see authService.js's
  // identical staff-side fix.
  const verified = await passwordHash.verify(password, login ? login.password_hash : DUMMY_PASSWORD_HASH);
  const passwordCorrect = login !== null && verified;

  if (!passwordCorrect) {
    if (login) {
      await clientLoginRepository.incrementFailedLogins(login.client_id);
      if (parseInt(login.failed_login_count, 10) + 1 >= 5) {
        const until = new Date(Date.now() + 15 * 60000).toISOString().slice(0, 19).replace('T', ' ');
        await clientLoginRepository.lockUntil(login.client_id, until);
      }
    }
    await loginAttemptRepository.record(null, email, ip, false);
    return { status: 'invalid_credentials' };
  }

  // Password confirmed correct — safe to reveal real account state now.
  if (!login.client_is_active || !login.is_active) {
    await loginAttemptRepository.record(null, email, ip, false);
    return { status: 'account_disabled' };
  }
  if (login.locked_until && parseDbDateTime(login.locked_until).getTime() > Date.now()) {
    return { status: 'locked_out', locked_until: login.locked_until };
  }

  await clientLoginRepository.resetFailedLogins(login.client_id);
  await regeneratePreserving(req, [STAFF_SESSION_KEY], () => {
    req.session[SESSION_CLIENT_ID] = login.client_id;
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
  await regeneratePreserving(req, [STAFF_SESSION_KEY]);
}

/**
 * docs/schema.sql Section AV — a staff-initiated client-portal session,
 * for a client who cannot use the portal themselves (no email, not
 * tech-comfortable, or an order still short of Stage 3 so no
 * client_logins row exists at all yet). Deliberately does NOT touch
 * clientLoginRepository — this is a separate channel into the portal, not
 * a stand-in for the client's own password login, and clientAuth.js's
 * per-request checks are relaxed accordingly while impersonating (see that
 * file). Call-site (clientController.impersonate()) is responsible for
 * checking client_impersonation_enabled, clients.allow_staff_impersonation,
 * and the impersonate_client permission before this is ever called — this
 * function itself only requires the client to exist and be active.
 */
async function startImpersonation(req, staffUserId, clientId) {
  await regeneratePreserving(req, [STAFF_SESSION_KEY], () => {
    req.session[SESSION_CLIENT_ID] = clientId;
    req.session[SESSION_IMPERSONATED_BY] = staffUserId;
  });
  await auditLogRepository.log(staffUserId, 'CLIENT_IMPERSONATION_STARTED', 'clients', clientId);
}

/**
 * Ends an impersonated session and returns the client id that was being
 * impersonated (for the caller's redirect), or null if the current
 * session wasn't actually impersonating anyone.
 */
async function endImpersonation(req) {
  const staffUserId = impersonatedByStaffId(req);
  if (staffUserId === null) {
    return null;
  }
  const clientId = currentClientId(req);
  if (clientId !== null) {
    await auditLogRepository.log(staffUserId, 'CLIENT_IMPERSONATION_ENDED', 'clients', clientId);
  }
  await regeneratePreserving(req, [STAFF_SESSION_KEY]);
  return clientId;
}

function isImpersonating(req) {
  return impersonatedByStaffId(req) !== null;
}

function impersonatedByStaffId(req) {
  return req.session[SESSION_IMPERSONATED_BY] ?? null;
}

async function changePassword(clientId, newPassword) {
  await clientLoginRepository.updatePassword(clientId, await passwordHash.hash(newPassword, 12), false);
  await auditLogRepository.log(null, 'CLIENT_PASSWORD_CHANGED', 'clients', clientId);
}

module.exports = {
  SESSION_CLIENT_ID,
  provisionIfNeeded,
  attemptLogin,
  currentClientId,
  currentClient,
  logout,
  changePassword,
  startImpersonation,
  endImpersonation,
  isImpersonating,
  impersonatedByStaffId,
};
