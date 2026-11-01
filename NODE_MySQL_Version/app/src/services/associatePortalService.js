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
  const code = await issueToken(Number(a.id), 'login_code', 10);
  try {
    await emailService.sendPlainText(String(a.email), 'Your NexaCrest login code', `Your login code is ${code}. It is valid for 10 minutes.\n\nIf you did not try to log in, change your password.`);
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
  const given = sha256(String(code || '').trim());
  if (given.length !== String(t.token_hash).length || !crypto.timingSafeEqual(Buffer.from(given), Buffer.from(String(t.token_hash)))) {
    await db.execute('UPDATE associate_tokens SET attempts = attempts + 1 WHERE id = :id', { id: t.id });
    return false;
  }
  await db.execute('UPDATE associate_tokens SET used_at = NOW() WHERE id = :id', { id: t.id });
  await regenerate(req, () => {
    delete req.session[SESSION_PENDING];
    req.session[SESSION_ID] = Number(id);
  });
  await associateRepository.setFields(Number(id), { failed_login_count: 0, locked_until: null, last_login_at: formatForDb(new Date()) });
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

module.exports = {
  SESSION_ID, SESSION_PENDING, SESSION_VIEW_AS, issueToken, associateForSetPassword, setPassword, attemptLogin, verifyCode,
  pendingId, currentId, current, logout, startViewAs, viewAsStaffId, endViewAs, showCodeOnScreen,
};
