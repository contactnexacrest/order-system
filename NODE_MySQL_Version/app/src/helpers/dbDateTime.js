'use strict';

// QA-5 DEF-04 follow-on: every DATETIME value this app writes with
// `new Date(...).toISOString()` (token expiries, lockout timers) or with a
// raw SQL `NOW()` (password_changed_at, etc.) is a UTC wall-clock string —
// toISOString() is always UTC, and MySQL's own NOW() reflects this server's
// SYSTEM time zone, which is UTC. Before DEF-04 set process.env.TZ to
// 'Asia/Kolkata', `new Date(naiveString)` on a plain "YYYY-MM-DD HH:MM:SS"
// value (no offset) happened to parse as local time that was ALSO UTC, so
// reading these columns back with a bare `new Date(...)` looked correct by
// accident. Now that the process default time zone is IST, that same parse
// silently reinterprets the UTC digits as IST wall-clock — a stored value 5
// hours 30 minutes off from what it actually represents. Every read of one
// of these SQL/toISOString-written columns for a real time comparison
// (never merely for display) must go through this explicit-UTC parse
// instead of a bare `new Date(value)`.
function parseDbDateTime(value) {
  if (value === null || value === undefined) {
    return null;
  }
  if (value instanceof Date) {
    return value;
  }
  return new Date(`${String(value).replace(' ', 'T')}Z`);
}

/**
 * 90-item backlog "timezone": today's CALENDAR date in this process's
 * configured timezone (Asia/Kolkata — see config/env.js), as 'YYYY-MM-DD'.
 * `new Date().toISOString().slice(0, 10)` — used all over this codebase
 * before this helper existed — is NOT the same thing: toISOString()
 * always formats in UTC regardless of process.env.TZ, so between 00:00
 * and 05:29 IST it returns YESTERDAY's date, a full calendar day behind
 * what date_default_timezone_set('Asia/Kolkata') gives PHP's date('Y-m-d')
 * at the same instant. getFullYear()/getMonth()/getDate() are LOCAL-time
 * getters, so they correctly follow process.env.TZ.
 */
function todayIst() {
  return ymd(new Date());
}

/** @returns {string} `date`'s LOCAL (process.env.TZ, i.e. IST) calendar date as 'YYYY-MM-DD'. */
function ymd(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * External QA report L-21/L-02: formats a JS Date as a 'YYYY-MM-DD HH:MM:SS'
 * wall-clock string in this process's own time zone (Asia/Kolkata —
 * config/env.js), for writing into a TIMESTAMP/DATETIME column. Needed
 * because `date.toISOString()` is always UTC — writing that string as a
 * literal, now that config/db.js pins the DB session to the same +05:30
 * offset, would have MySQL read a UTC wall-clock string as if it were
 * already IST wall-clock and land 5h30m off (this was the actual bug:
 * e.g. a Buyer Action Link's expiry stored as 06:20 instead of 11:50).
 * getFullYear()/getMonth()/.../getSeconds() are LOCAL-time getters, so
 * they already follow process.env.TZ correctly — never use toISOString()
 * for a value headed into the database or shown to a user.
 */
function formatForDb(date) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${ymd(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

const RFC2822_DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const RFC2822_MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * External QA report L-46: nodemailer's own Date header defaults to UTC
 * (+0000) regardless of process.env.TZ, while PHPMailer's matches this
 * process's date_default_timezone_set('Asia/Kolkata') and shows IST — so
 * the same order's PHP-sent and Node-sent emails carried different-looking
 * send times. getDay()/getDate()/getMonth()/.../getSeconds() are LOCAL-time
 * getters, so (like formatForDb() above) they already follow
 * process.env.TZ=Asia/Kolkata correctly; this just also appends the
 * matching +0530 offset, the one this single-timezone business app always
 * actually runs in.
 */
function toRfc2822Ist(date = new Date()) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${RFC2822_DAY_NAMES[date.getDay()]}, ${pad(date.getDate())} ${RFC2822_MONTH_NAMES[date.getMonth()]} ${date.getFullYear()} `
    + `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())} +0530`;
}

module.exports = { parseDbDateTime, todayIst, ymd, formatForDb, toRfc2822Ist };
