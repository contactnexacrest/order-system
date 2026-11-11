'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');

/**
 * Port of App\Services\SessionTimeouts — owner decision D-283 (11 Nov 2026,
 * point 21): the idle timeouts set in Admin › Settings › Security really apply.
 *
 *  - Separate idle timeouts: staff 4 h, buyers 2 h, associates 2 h
 *    (session_timeout_staff_minutes / _client_ / _associate_).
 *  - Staff log in again at least every 12 h (session_absolute_staff_hours).
 *  - The session store and cookie follow the longest idle timeout and roll on
 *    every request (server.js: req.session.cookie.maxAge, rolling), so the store
 *    never drops a session earlier than the app's own timeout (it used to keep
 *    a session one day after it was last saved).
 *  - Pages of a signed-in area carry <meta name="session-idle">; app.js shows a
 *    warning 2 minutes before the end with "Stay logged in".
 */

const AREAS = ['staff', 'client', 'associate'];
const KEYS = { staff: 'session_timeout_staff_minutes', client: 'session_timeout_client_minutes', associate: 'session_timeout_associate_minutes' };
const DEFAULTS = { staff: 240, client: 120, associate: 120 };
const ABSOLUTE_KEY = 'session_absolute_staff_hours';
const ABSOLUTE_DEFAULT = 12;
const WARN_SECONDS = 120;
const KEEPALIVE = { staff: '/keep-alive', client: '/client/keep-alive', associate: '/associate/keep-alive' };
const LOGIN = { staff: '/login', client: '/client/login', associate: '/associate/login' };
const STAFF_LOGIN_AT_KEY = '_staff_login_at_ts';

/** Idle minutes for an area, from Admin (5 to 1440). */
async function idleMinutes(area) {
  let raw = null;
  try {
    raw = await companySettingsRepository.get(KEYS[area] || KEYS.staff);
  } catch (e) {
    // no database: the default
  }
  const m = raw === null || raw === undefined || String(raw).trim() === '' ? (DEFAULTS[area] || 240) : parseInt(raw, 10);
  return Math.max(5, Math.min(1440, Number.isFinite(m) ? m : (DEFAULTS[area] || 240)));
}

/** Staff absolute limit in hours, from Admin (1 to 24). */
async function absoluteHours() {
  let raw = null;
  try {
    raw = await companySettingsRepository.get(ABSOLUTE_KEY);
  } catch (e) {
    // no database: the default
  }
  const h = raw === null || raw === undefined || String(raw).trim() === '' ? ABSOLUTE_DEFAULT : parseInt(raw, 10);
  return Math.max(1, Math.min(24, Number.isFinite(h) ? h : ABSOLUTE_DEFAULT));
}

/** The longest idle timeout of the three areas, in milliseconds — the session's life in the store and the cookie. */
async function maxIdleMs() {
  let max = 0;
  for (const a of AREAS) max = Math.max(max, (await idleMinutes(a)) * 60000);
  return max;
}

/** Whether the idle limit of an area has passed since lastActivity (ms; null = no activity yet). */
async function idleExpired(area, lastActivity, now = Date.now()) {
  return lastActivity !== null && lastActivity !== undefined && (now - Number(lastActivity)) > (await idleMinutes(area)) * 60000;
}

/** Whether the staff 12-hour limit has passed since loginAt (ms). */
async function absoluteExpired(loginAt, now = Date.now()) {
  return loginAt !== null && loginAt !== undefined && (now - Number(loginAt)) > (await absoluteHours()) * 3600000;
}

/** Seconds left before the staff 12-hour limit (null when there is no login time). */
async function staffAbsoluteLeft(loginAt, now = Date.now()) {
  if (loginAt === null || loginAt === undefined) return null;
  return Math.max(0, Math.round((Number(loginAt) + (await absoluteHours()) * 3600000 - now) / 1000));
}

/** "4 hours", "90 minutes", "1 hour" — for the signed-out message. */
function describeMinutes(minutes) {
  if (minutes % 60 === 0) {
    const h = minutes / 60;
    return `${h} hour${h === 1 ? '' : 's'}`;
  }
  return `${minutes} minutes`;
}

/** The auth middleware marks the page's area (for the warning popup); the layouts print the <meta>. */
async function markPage(req, area, absoluteLeft = null) {
  req.idleMeta = {
    area,
    idle: (await idleMinutes(area)) * 60,
    warn: WARN_SECONDS,
    absoluteLeft,
    keepalive: KEEPALIVE[area],
    login: LOGIN[area],
  };
}

module.exports = {
  AREAS, KEYS, DEFAULTS, ABSOLUTE_KEY, ABSOLUTE_DEFAULT, WARN_SECONDS, KEEPALIVE, LOGIN, STAFF_LOGIN_AT_KEY,
  idleMinutes, absoluteHours, maxIdleMs, idleExpired, absoluteExpired, staffAbsoluteLeft, describeMinutes, markPage,
};
