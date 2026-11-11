'use strict';

const crypto = require('crypto');
const db = require('../config/db');

const env = require('../config/env');
const { parseDbDateTime, formatForDb } = require('../helpers/dbDateTime');
const passwordHash = require('../helpers/passwordHash');
const { regeneratePreserving } = require('../helpers/sessionRegenerate');
const auditLogRepository = require('../repositories/auditLogRepository');
const clientLoginRepository = require('../repositories/clientLoginRepository');
const clientPasswordResetTokenRepository = require('../repositories/clientPasswordResetTokenRepository');
const clientRepository = require('../repositories/clientRepository');
const emailTemplateRepository = require('../repositories/emailTemplateRepository');
const loginAttemptRepository = require('../repositories/loginAttemptRepository');
const emailService = require('./emailService');
const twoFactorService = require('./twoFactorService');

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
/**
 * D-280: `out.invite` = {link, sent} — the set-password link of the invite and
 * whether its e-mail went out; staff see the link with a Copy button either way.
 */
async function provisionIfNeeded(clientId, orderId, out = {}) {
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
  // Logged against the triggering order (not the client) so the
  // Notification Routing Plan's order-scoped recipient resolution (that
  // order's own assignees) can actually find it.
  await auditLogRepository.log(null, 'CLIENT_PORTAL_PROVISIONED', 'orders', orderId, null, null, null, 'Client portal provisioned — advance cleared');

  out.invite = await sendSetPasswordEmail(clientId, client.email, client.company_legal_name);

  return 'provisioned';
}

/** D-280: the staff message after a portal was opened — "e-mailed" only when it was. */
function inviteOutcome(invite) {
  if (!invite) return '';
  return invite.sent
    ? ' The client was e-mailed a link to set their portal password (valid 72 hours, works once). If they did not get it, copy the link below and send it yourself.'
    : ' The portal invite e-mail could NOT be sent — copy the set-password link below and send it to the client yourself (valid 72 hours, works once).';
}

/** D-282: the permanent client login page address (shown on the client page and in the invite). */
function loginUrl() {
  return `${String(env.get('APP_URL', '')).replace(/\/+$/, '')}/client/login`;
}

/**
 * D-282: staff "Send set-password link" on the client page — a new 72-hour,
 * one-use link to the client's login e-mail.
 * @returns {Promise<{link: string, sent: boolean, email: string}>}
 */
async function sendSetPasswordLink(clientId, staffUserId) {
  const login = await clientLoginRepository.findByClientId(clientId);
  const client = await clientRepository.find(clientId);
  if (!login || !client) {
    throw new Error('This client has no portal account yet — it is created automatically when the first advance payment clears.');
  }
  const email = String(client.email || '').trim();
  if (email === '') {
    throw new Error('This client has no e-mail on file — add one to the client record first.');
  }
  const rawToken = crypto.randomBytes(32).toString('hex');
  await clientPasswordResetTokenRepository.create(clientId, crypto.createHash('sha256').update(rawToken).digest('hex'), formatForDb(new Date(Date.now() + 72 * 3600000)), null);
  const link = `${String(env.get('APP_URL', '')).replace(/\/+$/, '')}/client/set-password/${rawToken}`;
  const rendered = await emailTemplateRepository.renderOrDefault(
    'client_portal_password_reset',
    { '{email}': email, '{reset_link}': link, '{login_link}': loginUrl() },
    'Reset your NexaCrest order portal password',
    'Hello,\n\n'
      + 'Someone asked to reset the password of your NexaCrest buyer account ({email}).\n\n'
      + 'To set a new password, open this link within 72 hours:\n{reset_link}\n\n'
      + "If you didn't request this, you can ignore this email — your password will not be changed."
  );
  const sent = await emailService.sendPlainText(email, rendered.subject, rendered.body, { kind: 'forgot_password', audience: 'buyer' });
  await auditLogRepository.log(staffUserId, 'CLIENT_SET_PASSWORD_LINK_SENT', 'clients', clientId, null, null, email, sent ? 'E-mailed' : 'Not e-mailed — link shown to staff');
  return { link, sent, email };
}

async function sendSetPasswordEmail(clientId, email) {
  const rawToken = crypto.randomBytes(32).toString('hex');
  const tokenHash = crypto.createHash('sha256').update(rawToken).digest('hex');
  // External QA report L-21/L-02: toISOString() is always UTC — see
  // dbDateTime.formatForDb()'s docblock.
  const expiresAt = formatForDb(new Date(Date.now() + 72 * 3600000)); // longer than the staff 45-minute window — a client may not check email immediately
  await clientPasswordResetTokenRepository.create(clientId, tokenHash, expiresAt, null);

  const setUrl = `${env.get('APP_URL', '').replace(/\/+$/, '')}/client/set-password/${rawToken}`;

  // 90-item backlog #37: admin-editable (Admin > Settings > Email
  // Templates) — this exact original wording is the fallback.
  const rendered = await emailTemplateRepository.renderOrDefault(
    'client_portal_invite',
    { '{set_password_link}': setUrl, '{email}': email, '{login_link}': loginUrl() }, // D-282: {login_link}
    'Your NexaCrest order portal access',
    'Hello,\n\n'
      + 'Thank you — we have received your advance payment.\n\n'
      + 'You can now sign in at any time to follow your order and download your documents: the quotation, the proforma invoice and every document we send you from now on.\n\n'
      + 'To set up your login, open this link within 72 hours (it works once):\n{set_password_link}\n\n'
      + 'Your login email will be: {email}\n\n'
      + 'After that, log in any time at:\n{login_link}\n\n'
      + 'NexaCrest International Private Limited'
  );

  // D-280: a set-password invite is a MUST e-mail (access-critical) — never held.
  const sent = await emailService.sendPlainText(email, rendered.subject, rendered.body, { kind: 'set_password_invite', audience: 'buyer' });
  return { link: setUrl, sent };
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
        const until = formatForDb(new Date(Date.now() + 15 * 60000));
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
  // Owner decision D-265 (7 Nov 2026): a buyer, too, enters a 6-digit code e-mailed
  // to their login address at every sign-in. Staff "Log in as this client" is unaffected.
  const devCode = await twoFactorService.issueCodeFor(req, Number(login.client_id), 'email', String(login.client_email), 'client');
  return { status: 'requires_code', dev_code: devCode };
}

/** D-265 step 2: the e-mailed code. Signs the buyer in when it matches. */
async function completeLogin(req, code) {
  const clientId = twoFactorService.pendingUserId(req, 'client');
  if (!clientId) return { status: 'no_pending' };
  if (!(await twoFactorService.verify(req, code, 'client'))) {
    await auditLogRepository.log(null, 'CLIENT_LOGIN_CODE_FAILED', 'clients', clientId);
    return { status: twoFactorService.pendingUserId(req, 'client') ? 'invalid_code' : 'no_pending' };
  }
  const login = await clientLoginRepository.findByClientId(clientId);
  if (!login || !login.is_active) return { status: 'no_pending' };
  await regeneratePreserving(req, [STAFF_SESSION_KEY], () => {
    req.session[SESSION_CLIENT_ID] = Number(login.client_id);
    req.session._client_last_activity_ts = Date.now(); // D-283: the idle clock starts now
    req.session[SESSION_PASSWORD_FP] = passwordFingerprint(login.password_hash);
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
    req.session._client_last_activity_ts = Date.now(); // D-283
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

/**
 * QA ACC-10: a fingerprint of the password this session logged in with. A
 * password change or a staff reset changes the stored hash, so every other
 * session of that client ends on its next request (as staff sessions do
 * through users.session_version). Port of ClientPortalService::passwordFingerprint().
 */
const SESSION_PASSWORD_FP = '_client_portal_password_fp';
function passwordFingerprint(hash) {
  return require('crypto').createHash('sha256').update(String(hash || '')).digest('hex');
}
/** Whether this session's password is still the client's current one (a session from before the check is accepted and pinned). */
function sessionPasswordStillCurrent(req, currentHash) {
  const fp = passwordFingerprint(currentHash);
  if (req.session[SESSION_PASSWORD_FP] === undefined || req.session[SESSION_PASSWORD_FP] === null) {
    req.session[SESSION_PASSWORD_FP] = fp;
    return true;
  }
  return req.session[SESSION_PASSWORD_FP] === fp;
}

async function changePassword(clientId, newPassword, req = null) {
  const hash = await passwordHash.hash(newPassword, 12);
  await clientLoginRepository.updatePassword(clientId, hash, false);
  // QA ACC-10: this session (the one changing it) stays signed in; others end.
  if (req && req.session && Number(currentClientId(req)) === Number(clientId)) {
    req.session[SESSION_PASSWORD_FP] = passwordFingerprint(hash);
  }
  await auditLogRepository.log(null, 'CLIENT_PASSWORD_CHANGED', 'clients', clientId);
}

/**
 * QA Round 6, R6-N24: why the buyer cannot report a payment on this order yet
 * (or any more) — null when they can. Nothing is owed before a Proforma
 * Invoice has been issued, nor on a cancelled or lost order. Used by the
 * portal and by the e-mailed buyer link. Port of
 * ClientPortalService::paymentReportRefusal().
 */
async function paymentReportRefusal(order) {
  if (['cancelled', 'lost'].includes(order.status)) {
    return `${order.status === 'lost' ? 'This order did not go ahead' : 'This order was cancelled'}, so there is no payment to tell us about.`;
  }
  const pi = await db.queryOne(
    `SELECT 1 AS found FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
     WHERE d.order_id = :order_id AND dt.code = 'PI' AND d.status IN ('approved', 'sent', 'superseded') LIMIT 1`,
    { order_id: Number(order.id) }
  );
  return pi ? null : 'You can tell us about a payment after we send you the proforma invoice for this order.';
}

module.exports = {
  paymentReportRefusal,
  SESSION_CLIENT_ID,
  provisionIfNeeded,
  inviteOutcome,
  loginUrl,
  sendSetPasswordLink,
  attemptLogin,
  completeLogin,
  currentClientId,
  currentClient,
  logout,
  changePassword,
  startImpersonation,
  endImpersonation,
  isImpersonating,
  impersonatedByStaffId,
  passwordFingerprint,
  sessionPasswordStillCurrent,
};
