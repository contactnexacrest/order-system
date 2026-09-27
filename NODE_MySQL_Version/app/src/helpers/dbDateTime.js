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

module.exports = { parseDbDateTime };
