'use strict';

const crypto = require('crypto');
const db = require('../config/db');
const env = require('../config/env');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const { formatForDb, parseDbDateTime } = require('../helpers/dbDateTime');

// Port of App\Services\RememberBrowserService — "Remember this browser for N days"
// for staff (owner decision D-265, 7 Nov 2026). Admin › Settings › Security ›
// login_remember_browser_days; 0 (the default) means the e-mailed code is asked at
// every login. When on, a staff member who ticks the box on the code screen gets a
// cookie "selector.validator.signature": HttpOnly, SameSite=Lax, Secure except in
// local dev, signed with APP_ENCRYPTION_KEY. The server keeps the selector, a
// SHA-256 of the validator, the user and their session_version
// (login_remembered_browsers). A password change or reset bumps session_version and
// deletes the rows. Buyers and associates never get this.

const COOKIE = 'nexacrest_trusted_browser';
const MAX_DAYS = 90;

async function days() {
  const d = parseInt((await companySettingsRepository.get('login_remember_browser_days')) ?? '0', 10) || 0;
  return Math.max(0, Math.min(MAX_DAYS, d));
}

function sign(payload) {
  return crypto.createHmac('sha256', `remember-browser|${env.get('APP_ENCRYPTION_KEY', '') || ''}`).update(payload).digest('hex');
}

function sha256(s) {
  return crypto.createHash('sha256').update(s).digest('hex');
}

/** The cookie value sent by this browser, if any (no cookie-parser in this app). */
function cookieFrom(req) {
  const header = (req && req.headers && req.headers.cookie) || '';
  for (const part of header.split(';')) {
    const i = part.indexOf('=');
    if (i > 0 && part.slice(0, i).trim() === COOKIE) return decodeURIComponent(part.slice(i + 1).trim());
  }
  return '';
}

function safeEqual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

/** Remembers this browser for the user; returns the cookie value ('' when the setting is 0). */
async function remember(res, user) {
  const n = await days();
  if (n <= 0) return '';
  const selector = crypto.randomBytes(12).toString('hex');
  const validator = crypto.randomBytes(32).toString('hex');
  const expires = new Date(Date.now() + n * 86400000);
  await db.execute(
    `INSERT INTO login_remembered_browsers (user_id, selector, validator_hash, session_version, expires_at)
     VALUES (:u, :s, :h, :v, :e)`,
    { u: user.id, s: selector, h: sha256(validator), v: Number(user.session_version || 0), e: formatForDb(expires) }
  );
  const value = `${selector}.${validator}.${sign(`${selector}.${validator}`)}`;
  if (res && typeof res.cookie === 'function') {
    res.cookie(COOKIE, value, { expires, path: '/', secure: !env.isLocal(), httpOnly: true, sameSite: 'lax' });
  }
  return value;
}

/** True when this browser carries a valid, unexpired, still-current remembered sign-in for the user. */
async function isRemembered(req, user, cookie = null) {
  if ((await days()) <= 0) return false;
  const value = cookie !== null ? cookie : cookieFrom(req);
  const parts = String(value).split('.');
  if (parts.length !== 3 || !safeEqual(sign(`${parts[0]}.${parts[1]}`), parts[2])) return false;
  const row = await db.queryOne('SELECT * FROM login_remembered_browsers WHERE selector = :s LIMIT 1', { s: parts[0] });
  if (!row
    || Number(row.user_id) !== Number(user.id)
    || parseDbDateTime(row.expires_at).getTime() < Date.now()
    || Number(row.session_version) !== Number(user.session_version || 0)
    || !safeEqual(row.validator_hash, sha256(parts[1]))) {
    return false;
  }
  await db.execute('UPDATE login_remembered_browsers SET last_used_at = NOW() WHERE id = :id', { id: row.id });
  return true;
}

/** Forgets every remembered browser of the user (password change or reset). */
/** Forgets every remembered browser of the user (password change or reset; D-283: "Forget all remembered computers"). Returns how many. */
async function forgetAll(userId) {
  const r = await db.execute('DELETE FROM login_remembered_browsers WHERE user_id = :u', { u: userId });
  return Number((r && r.affectedRows) || 0);
}

/** D-283: how many computers are remembered for the user today (not expired). */
async function countFor(userId) {
  const row = await db.queryOne('SELECT COUNT(*) AS n FROM login_remembered_browsers WHERE user_id = :u AND expires_at > NOW()', { u: userId });
  return Number((row && row.n) || 0);
}

module.exports = { COOKIE, MAX_DAYS, days, remember, isRemembered, forgetAll, countFor, cookieFrom };
