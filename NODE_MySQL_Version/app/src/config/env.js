'use strict';

const path = require('path');
const dotenv = require('dotenv');

// QA-5 DEF-04 (Owner Decision #5: "Use Indian Standard Time"): PHP's
// bootstrap.php sets date_default_timezone_set('Asia/Kolkata') before
// anything else runs; Node had no equivalent, so every Date computation
// (working-days/holiday calendar, 2FA/password-reset expiry windows, PI
// validity dates, report date filters, audit-log timestamps) ran in the
// container's own timezone instead of IST. Setting process.env.TZ this
// early, in the module every other config/service transitively requires
// first, gives every Date object's local-time methods
// (getHours()/toString()/etc.) IST semantics for the life of the process —
// this must run before ANY Date is constructed or read.
process.env.TZ = 'Asia/Kolkata';

// Loaded once, at process start, from .env in the app root — mirrors the
// PHP Env::load() contract (bootstrap.php requires it before anything else
// runs). dotenv.config() is a no-op if the file is missing; server.js checks
// for required keys explicitly right after this so a missing .env fails
// loudly instead of limping along with undefined DB credentials.
dotenv.config({ path: path.join(__dirname, '..', '..', '.env') });

function get(key, fallback = null) {
  const value = process.env[key];
  if (value === undefined || value === null || value === '') {
    return fallback;
  }
  return value;
}

function getBool(key, fallback = false) {
  const value = get(key);
  if (value === null) return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function getInt(key, fallback) {
  const value = get(key);
  if (value === null) return fallback;
  const n = parseInt(value, 10);
  return Number.isNaN(n) ? fallback : n;
}

function isLocal() {
  return String(get('APP_ENV', 'production')).toLowerCase() === 'local';
}

module.exports = { get, getBool, getInt, isLocal };
