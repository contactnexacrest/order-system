'use strict';

/**
 * Port of App\Helpers\ReasonValidator — single source of truth for the
 * user's explicit standing instruction that every reason field across the
 * app have a minimum length. A one-character "x" satisfies "not empty"
 * but documents nothing useful on the audit trail. Originally enforced
 * only in superAdminService/permissionAdminController; this closes the
 * gap for every other reason field (settings edits, field-protection
 * requests, admin overrides, amendment requests, force password resets,
 * marking an order lost) that previously only checked for non-empty.
 */

const MIN_LENGTH = 10;

/** @returns {string|null} an error message if invalid, null if the reason passes */
function check(reason) {
  const trimmed = String(reason || '').trim();
  if (trimmed === '') {
    return 'A reason is required — nothing was saved.';
  }
  if (trimmed.length < MIN_LENGTH) {
    return `Reason must be at least ${MIN_LENGTH} characters — describe why this change is being made.`;
  }
  return null;
}

module.exports = { MIN_LENGTH, check };
