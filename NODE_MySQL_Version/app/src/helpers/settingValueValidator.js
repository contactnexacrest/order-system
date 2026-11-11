'use strict';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * QA-5 SET-02: company_settings.value_type (docs/schema.sql Section A) was
 * defined at the schema level but never actually enforced anywhere —
 * SettingsController::update() wrote whatever string a staff member typed
 * straight into setting_value with zero type or range checking. A
 * 'number' setting like session_timeout_minutes or
 * failed_login_lockout_count set to "abc", "-5", or blank would parse to
 * NaN or a negative threshold everywhere it's later read with
 * parseInt(...) — breaking session timeouts, lockout logic, or working-
 * days/alert-day arithmetic in ways that fail silently rather than
 * refusing the edit up front. Every currently-seeded 'number' setting is
 * a day-count, percentage, or threshold — none has a legitimate negative
 * value — so a negative number is rejected here on the same footing as
 * outright non-numeric garbage.
 *
 * @returns {string|null} an error message if invalid, null if the value passes
 */
/** D-283 (11 Nov 2026): settings with a fixed allowed range, [min, max, unit] — as PHP SettingValueValidator::RANGES. */
const RANGES = {
  session_timeout_staff_minutes: [5, 1440, 'minutes'],
  session_timeout_client_minutes: [5, 1440, 'minutes'],
  session_timeout_associate_minutes: [5, 1440, 'minutes'],
  session_absolute_staff_hours: [1, 24, 'hours'],
  login_remember_browser_days: [0, 90, 'days'],
};

function check(valueType, rawValue, key = '') {
  const value = String(rawValue ?? '').trim();

  switch (valueType) {
    case 'number': {
      if (value === '' || !Number.isFinite(Number(value))) {
        return `must be a number (got "${rawValue}")`;
      }
      if (Number(value) < 0) {
        return `must not be negative (got "${rawValue}")`;
      }
      if (Object.prototype.hasOwnProperty.call(RANGES, key)) {
        const [min, max, unit] = RANGES[key];
        const n = Number(value);
        if (n < min || n > max || n !== Math.floor(n)) {
          return `must be a whole number from ${min} to ${max} ${unit} (got "${rawValue}")`;
        }
      }
      return null;
    }
    case 'boolean': {
      if (value !== '0' && value !== '1') {
        return `must be 0 or 1 (got "${rawValue}")`;
      }
      return null;
    }
    case 'date': {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return `must be a date in YYYY-MM-DD format (got "${rawValue}")`;
      }
      const [y, m, d] = value.split('-').map((n) => parseInt(n, 10));
      const parsed = new Date(Date.UTC(y, m - 1, d));
      if (parsed.getUTCFullYear() !== y || parsed.getUTCMonth() !== m - 1 || parsed.getUTCDate() !== d) {
        return `is not a real calendar date (got "${rawValue}")`;
      }
      return null;
    }
    case 'json': {
      try {
        JSON.parse(value);
        return null;
      } catch (e) {
        return `must be valid JSON (got "${rawValue}")`;
      }
    }
    // docs/schema.sql Section AS: 'email'/'email_list' are blank-allowed
    // (an unconfigured redirect/CC address is a valid, common state) but
    // reject a non-blank value that isn't actually a deliverable address.
    case 'email': {
      if (value !== '' && !EMAIL_RE.test(value)) {
        return `must be a valid email address (got "${rawValue}")`;
      }
      return null;
    }
    case 'email_list': {
      for (const part of value.split(',')) {
        const addr = part.trim();
        if (addr !== '' && !EMAIL_RE.test(addr)) {
          return `contains an invalid email address "${addr}"`;
        }
      }
      return null;
    }
    case 'string':
    default:
      return null;
  }
}

module.exports = { check, RANGES };
