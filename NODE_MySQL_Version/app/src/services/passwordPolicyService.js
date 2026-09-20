'use strict';

const companySettingsRepository = require('../repositories/companySettingsRepository');

// Port of App\Services\PasswordPolicyService — DB-driven policy
// (company_settings, category 'security').

/** @returns {Promise<string|null>} an error message, or null if the password passes every configured rule */
async function validate(password) {
  const minLength = parseInt((await companySettingsRepository.get('password_min_length')) ?? '10', 10);
  const complexityJson = await companySettingsRepository.get('password_complexity_json');
  let complexity = {};
  if (complexityJson) {
    try { complexity = JSON.parse(complexityJson) || {}; } catch { complexity = {}; }
  }

  if (password.length < minLength) {
    return `Password must be at least ${minLength} characters.`;
  }
  if (complexity.require_upper && !/[A-Z]/.test(password)) {
    return 'Password must contain at least one uppercase letter.';
  }
  if (complexity.require_number && !/[0-9]/.test(password)) {
    return 'Password must contain at least one number.';
  }
  if (complexity.require_symbol && !/[^A-Za-z0-9]/.test(password)) {
    return 'Password must contain at least one symbol.';
  }
  return null;
}

module.exports = { validate };
