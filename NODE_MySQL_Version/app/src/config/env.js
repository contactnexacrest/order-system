'use strict';

const path = require('path');
const dotenv = require('dotenv');

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
