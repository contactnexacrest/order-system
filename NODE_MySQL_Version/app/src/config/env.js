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

// Deliberately independent of APP_ENV/isLocal() — a real staging/UAT
// deployment that staff poke at over the internet is not "local" but is
// exactly where a loud on-screen "this isn't production" reminder matters
// most, so this gets its own .env switch rather than riding on APP_ENV.
function isDevServer() {
  return getBool('DEV_SERVER', false);
}

// 90-item backlog ".env fallbacks": express-session signs the session-id
// cookie with this secret to stop tampering/forgery — unlike PHP's native
// session, which needs no app-level secret since PHP's own CSPRNG
// generates the session ID. server.js used to fall back to the literal
// string 'change-this-in-.env' whenever SESSION_SECRET_KEY was unset, with
// nothing to stop that silently reaching a real deployment — anyone who's
// read this (or any other NexaCrest) deployment's source then knows the
// exact secret, and can forge a validly-signed session cookie. Outside
// local dev (where tests/support/testEnv.js always supplies one), a
// missing value now fails loudly at startup instead of limping on with a
// guessable secret.
function sessionSecret() {
  const value = get('SESSION_SECRET_KEY');
  if (value) {
    return value;
  }
  if (!isLocal()) {
    throw new Error(
      'SESSION_SECRET_KEY is not set in .env. Refusing to start with a guessable ' +
        'session-signing secret outside local development — set a real random value.'
    );
  }
  return 'change-this-in-.env';
}

// 90-item backlog "storage_base_path precedence": every controller/
// service/repository that reads STORAGE_BASE_PATH used to inline its own
// fallback for when .env doesn't define it, and the fallbacks had drifted
// in two different ways. Four controllers (signatory/referenceDoc/asset/
// products) computed `path.join(__dirname, '..', '..', 'storage')` — only
// two levels up from src/controllers/, landing on app/storage (which
// doesn't exist) instead of the real storage/ sibling of app/. Everywhere
// else (documentGenerationService, sampleDataService, fileUploadService,
// testModeRepository, sampleDataRepository) fell back to '' instead, so a
// missing STORAGE_BASE_PATH there silently resolved paths against the
// filesystem root ("/documents/...") rather than any real directory.
// Centralizing the computation here — three levels up from
// src/config/, src/controllers/, src/services/, and src/repositories/ all
// alike — gives every call site the same, correct default, so this can't
// drift apart again the way it just did.
function storageBasePath() {
  return get('STORAGE_BASE_PATH', path.join(__dirname, '..', '..', '..', 'storage'));
}

module.exports = { get, getBool, getInt, isLocal, isDevServer, sessionSecret, storageBasePath };
