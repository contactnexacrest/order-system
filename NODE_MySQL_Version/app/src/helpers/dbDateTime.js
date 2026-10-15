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
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

module.exports = { parseDbDateTime, todayIst };
