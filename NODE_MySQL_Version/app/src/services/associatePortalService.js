'use strict';

const crypto = require('crypto');
const db = require('../config/db');
const env = require('../config/env');
const passwordHash = require('../helpers/passwordHash');
const { parseDbDateTime, formatForDb } = require('../helpers/dbDateTime');
const { regeneratePreserving } = require('../helpers/sessionRegenerate');
const associateRepository = require('../repositories/associateRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const loginAttemptRepository = require('../repositories/loginAttemptRepository');
const emailService = require('./emailService');
const twoFactorService = require('./twoFactorService');

/**
 * Port of AssociatePortalService.php. Owner decision 1 Nov 2026 (AS-1,
 * AS-8): the associate portal's own login — password, then a 6-digit code
 * e-mailed each time — separate from staff and buyer logins; 5 wrong
 * passwords lock it for 15 minutes. Staff can "view as" an associate,
 * read-only.
 */

const SESSION_ID = '_associate_portal_id';
const SESSION_PENDING = '_associate_portal_pending_id';
const SESSION_VIEW_AS = '_associate_portal_view_as_staff_id';
/** QA ACC-10: fingerprint of the password this session logged in with (a change or reset ends other sessions). */
const SESSION_PASSWORD_FP = '_associate_portal_password_fp';

/** Whether this session's password is still the associate's current one (a session from before the check is accepted and pinned). */
function sessionPasswordStillCurrent(req, currentHash) {
  const fp = sha256(String(currentHash || ''));
  if (req.session[SESSION_PASSWORD_FP] === undefined || req.session[SESSION_PASSWORD_FP] === null) {
    req.session[SESSION_PASSWORD_FP] = fp;
    return true;
  }
  return req.session[SESSION_PASSWORD_FP] === fp;
}
const DUMMY_HASH = '$2y$12$XkZEWJ9OTUrQ7pC.e9pnpO1LA.XHdoTUKJahoxDBHKjd8rQTbId22';
// Staff and buyer logins live in the same session: rotating the id keeps them.
const OTHER_KEYS = ['_auth_user_id', '_client_portal_client_id', '_client_portal_impersonated_by_staff_id', '_flash'];

const sha256 = (v) => crypto.createHash('sha256').update(String(v)).digest('hex');

/** Rotates the session id where the session supports it, then applies the change. */
async function regenerate(req, applyFn) {
  if (req.session && typeof req.session.regenerate === 'function') {
    await regeneratePreserving(req, OTHER_KEYS, applyFn);
    return;
  }
  if (applyFn) applyFn();
}

/** A one-time token (set password) or 6-digit code (login), stored hashed. Returns the raw value. */
async function issueToken(associateId, purpose, minutes) {
  await db.execute('UPDATE associate_tokens SET used_at = NOW() WHERE associate_id = :a AND purpose = :p AND used_at IS NULL', { a: associateId, p: purpose });
  const raw = purpose === 'login_code' ? String(crypto.randomInt(0, 1000000)).padStart(6, '0') : crypto.randomBytes(32).toString('hex');
  await db.execute(`INSERT INTO associate_tokens (associate_id, purpose, token_hash, expires_at) VALUES (:a, :p, :h, DATE_ADD(NOW(), INTERVAL ${parseInt(minutes, 10)} MINUTE))`,
    { a: associateId, p: purpose, h: sha256(raw) });
  return raw;
}

/** The associate a valid set-password token belongs to (not used, not expired). */
/**
 * D-282 (11 Nov 2026): the associate's own "Forgot password?". A link to set a
 * new password (72 hours, once) goes to the address when it belongs to an
 * associate whose portal is on. The answer on screen is the same either way.
 * At most 5 links an hour per associate. Returns the link, or null.
 */
async function sendResetLink(req, email) {
  const e = String(email || '').trim().toLowerCase();
  const a = e !== '' ? await associateRepository.findByEmail(e) : null;
  if (!a || Number(a.portal_enabled) !== 1 || ['ended', 'inactive'].includes(a.status)) return null;
  const recent = await db.queryOne("SELECT COUNT(*) AS n FROM associate_tokens WHERE associate_id = :a AND purpose = 'set_password' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", { a: a.id });
  if (Number(recent.n) >= 5) return null;
  const raw = await issueToken(Number(a.id), 'set_password', 72 * 60);
  const link = `${String(env.get('APP_URL', '')).replace(/\/+$/, '')}/associate/set-password/${raw}`;
  try {
    await emailService.sendPlainText(String(a.email), 'Reset your NexaCrest associate portal password', resetBody(a, link), { isSecurityEmail: true, kind: 'forgot_password', audience: 'associate' });
  } catch (err) {
    // the screen gives the same answer either way
  }
  await auditLogRepository.log(null, 'ASSOCIATE_PASSWORD_RESET_REQUESTED', 'associates', Number(a.id), null, null, null, `Requested from IP ${(req && req.ip) || 'unknown'}`);
  return link;
}

/** D-282: the associate's forgot-password e-mail. */
function resetBody(a, link) {
  return `Hello ${a.name},\n\nA new password was requested for your NexaCrest associate portal (${a.email}).\n\n`
    + `To set a new password, open this link within 72 hours (it works once):\n${link}\n\n`
    + 'If you did not ask for this, you can ignore this e-mail — your password stays as it is.\n\n'
    + `Log in any time at:\n${require('./associateService').loginUrl()}\n\nNexaCrest International Private Limited`;
}

async function associateForSetPassword(raw) {
  const row = await db.queryOne("SELECT associate_id FROM associate_tokens WHERE purpose = 'set_password' AND token_hash = :h AND used_at IS NULL AND expires_at > NOW()", { h: sha256(raw) });
  return row ? associateRepository.find(Number(row.associate_id)) : null;
}

async function setPassword(raw, password) {
  const a = await associateForSetPassword(raw);
  if (!a) return false;
  await db.execute("UPDATE associate_tokens SET used_at = NOW() WHERE purpose = 'set_password' AND token_hash = :h", { h: sha256(raw) });
  await associateRepository.setFields(Number(a.id), { password_hash: await passwordHash.hash(password), force_password_change: 0, failed_login_count: 0, locked_until: null });
  await auditLogRepository.log(null, 'ASSOCIATE_PASSWORD_SET', 'associates', Number(a.id));
  return true;
}

/**
 * Step 1: e-mail and password. On success a login code is e-mailed and the
 * session waits for it. Returns {status: 'code_sent'|'invalid'|'locked'|'disabled', code?}.
 */
async function attemptLogin(req, email, password) {
  const ip = req.ip || 'unknown';
  const a = email !== '' ? await associateRepository.findByEmail(email) : null;
  // Locked: refused before the password is looked at, so a locked account never tells a right password from a wrong one.
  if (a && a.locked_until && parseDbDateTime(a.locked_until).getTime() > Date.now()) {
    await loginAttemptRepository.record(null, email, ip, false);
    return { status: 'locked' };
  }
  const verified = await passwordHash.verify(String(password), a && a.password_hash ? a.password_hash : DUMMY_HASH);
  const ok = verified && a !== null && !!a.password_hash;
  if (!ok) {
    if (a) {
      const fails = Number(a.failed_login_count || 0) + 1;
      await associateRepository.setFields(Number(a.id), { failed_login_count: fails, ...(fails >= 5 ? { locked_until: formatForDb(new Date(Date.now() + 15 * 60000)) } : {}) });
    }
    await loginAttemptRepository.record(null, email, ip, false);
    return { status: 'invalid' };
  }
  if (Number(a.portal_enabled) !== 1 || ['ended', 'inactive'].includes(a.status)) {
    return { status: 'disabled' };
  }
  if (!(await twoFactorService.claimCodeSend('associate', Number(a.id)))) {
    await auditLogRepository.log(null, 'ASSOCIATE_LOGIN_CODE_LIMIT_REACHED', 'associates', Number(a.id)); // HNT-L04
    return { status: 'too_many_codes' };
  }
  const code = await issueToken(Number(a.id), 'login_code', 10);
  try {
    await emailService.sendPlainText(String(a.email), 'Your NexaCrest login code', `Your login code is ${code}. It is valid for 10 minutes.\n\nIf you did not try to log in, change your password.`, { isSecurityEmail: true, kind: 'login_code', audience: 'associate' }); // QA batch 3 MAIL-13: a one-time login code is security mail
  } catch (e) {
    // shown on screen in development; otherwise the associate asks again
  }
  await regenerate(req, () => {
    delete req.session[SESSION_ID];
    delete req.session[SESSION_VIEW_AS];
    req.session[SESSION_PENDING] = Number(a.id);
  });
  return { status: 'code_sent', code };
}

/** Step 2: the e-mailed code. */
async function verifyCode(req, code) {
  const id = req.session[SESSION_PENDING] ?? null;
  if (id === null) return false;
  const t = await db.queryOne(
    "SELECT id, token_hash, attempts FROM associate_tokens WHERE associate_id = :a AND purpose = 'login_code' AND used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1",
    { a: id }
  );
  if (!t || Number(t.attempts) >= 5) return false;
  // HNT-001: claim the try in one atomic UPDATE before checking the code, so tries
  // sent in parallel can never all read attempts < 5 and all be checked.
  const claimed = await db.execute('UPDATE associate_tokens SET attempts = attempts + 1 WHERE id = :id AND attempts < 5 AND used_at IS NULL', { id: t.id });
  if (claimed.affectedRows !== 1) return false;
  const given = sha256(String(code || '').trim());
  if (given.length !== String(t.token_hash).length || !crypto.timingSafeEqual(Buffer.from(given), Buffer.from(String(t.token_hash)))) {
    return false;
  }
  const used = await db.execute('UPDATE associate_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL', { id: t.id });
  if (used.affectedRows !== 1) return false;
  const loggedIn = await associateRepository.find(Number(id));
  await regenerate(req, () => {
    delete req.session[SESSION_PENDING];
    req.session[SESSION_ID] = Number(id);
    req.session._associate_last_activity_ts = Date.now(); // D-283: the idle clock starts now
    req.session[SESSION_PASSWORD_FP] = sha256(String((loggedIn && loggedIn.password_hash) || ''));
  });
  await associateRepository.setFields(Number(id), { failed_login_count: 0, locked_until: null, last_login_at: formatForDb(new Date()) });
  await twoFactorService.resetCodeSends('associate', Number(id)); // HNT-L04
  await auditLogRepository.log(null, 'ASSOCIATE_LOGIN', 'associates', Number(id));
  return true;
}

function pendingId(req) {
  return req.session[SESSION_PENDING] !== undefined && req.session[SESSION_PENDING] !== null ? Number(req.session[SESSION_PENDING]) : null;
}

function currentId(req) {
  return req.session[SESSION_ID] !== undefined && req.session[SESSION_ID] !== null ? Number(req.session[SESSION_ID]) : null;
}

async function current(req) {
  const id = currentId(req);
  return id === null ? null : associateRepository.find(id);
}

async function logout(req) {
  await regenerate(req, () => {
    delete req.session[SESSION_ID];
    delete req.session[SESSION_PENDING];
    delete req.session[SESSION_VIEW_AS];
    delete req.session[SESSION_PASSWORD_FP];
  });
  delete req.session[SESSION_ID];
  delete req.session[SESSION_PENDING];
  delete req.session[SESSION_VIEW_AS];
}

/** Staff "view as associate" — read-only, audit-logged (part 6.2). */
async function startViewAs(req, staffId, associateId) {
  await regenerate(req, () => {
    req.session[SESSION_ID] = associateId;
    req.session[SESSION_VIEW_AS] = staffId;
    req.session._associate_last_activity_ts = Date.now(); // D-283
  });
  await auditLogRepository.log(staffId, 'ASSOCIATE_VIEW_AS_STARTED', 'associates', associateId);
}

function viewAsStaffId(req) {
  return req.session[SESSION_VIEW_AS] !== undefined && req.session[SESSION_VIEW_AS] !== null ? Number(req.session[SESSION_VIEW_AS]) : null;
}

async function endViewAs(req) {
  const staff = viewAsStaffId(req);
  const id = currentId(req);
  if (staff !== null && id !== null) {
    await auditLogRepository.log(staff, 'ASSOCIATE_VIEW_AS_ENDED', 'associates', id);
  }
  delete req.session[SESSION_ID];
  delete req.session[SESSION_VIEW_AS];
  return id;
}

function showCodeOnScreen() {
  return env.isLocal();
}

/**
 * QA Round 8 (Q-R8-2, AS-3): a suspended associate may log in and see their
 * clients, orders and commission, but cannot invite clients or change their
 * code, profile, bank details or documents. Messages to NexaCrest and
 * commission invoices stay open, so they can still raise a question and be
 * paid for work already done.
 */
const SUSPENDED_BANNER = 'Your account is suspended — you can see your clients, orders and commission, but cannot invite clients or change your details.';
const SUSPENDED_REFUSED = 'Your account is suspended — you cannot invite clients or change your details. Please contact your NexaCrest relationship manager.';

function isSuspended(a) {
  return Boolean(a) && a.status === 'suspended';
}

module.exports = {
  sessionPasswordStillCurrent,
  SUSPENDED_BANNER, SUSPENDED_REFUSED, isSuspended,
  SESSION_ID, SESSION_PENDING, SESSION_VIEW_AS, issueToken, associateForSetPassword, setPassword, attemptLogin, verifyCode, sendResetLink, resetBody,
  pendingId, currentId, current, logout, startViewAs, viewAsStaffId, endViewAs, showCodeOnScreen,
};
