'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const caFyLockRepository = require('../repositories/caFyLockRepository');
const flash = require('../helpers/flash');

// CA / Accounting module (Phase 7) — the one narrow exception to a
// financial year lock. Reopening a whole year (caFyLockRepository.unlock())
// removes the lock for every CA write path until it's re-locked; this is
// for the much narrower case where one specific, trusted person needs to
// push through a single genuine backdated correction without reopening
// the year for anyone else.
//
// Gated on the ca_fy_lock_override permission — a Super Admin already
// satisfies this since sessionAuth middleware sets every permission key
// true on req.permissions for a Super Admin, so "the override is always
// available to a Super Admin" needs no special-casing here. Every use is
// logged to the audit log (action CA_FY_LOCK_OVERRIDDEN) and flashed as a
// visible warning — never silent.

/**
 * @param {object} req the request (needs req.user and req.permissions)
 * @param {string|null} date the record's own date (a leg's cleared_at, an
 *        expense's expense_date, ...) — never today's date.
 * @returns {Promise<boolean>} true if the write may proceed (either the
 *          date isn't in a locked FY, or the override was used and
 *          logged); false if blocked (an error flash has already been
 *          set — the caller must not proceed with the write).
 */
async function allow(req, date, entityType, entityId, field) {
  const lockMessage = await caFyLockRepository.lockMessageForDate(date);
  if (!lockMessage) {
    return true;
  }
  if (!req.permissions.ca_fy_lock_override) {
    flash.set(req, 'error', lockMessage);
    return false;
  }
  await auditLogRepository.log(req.user.id, 'CA_FY_LOCK_OVERRIDDEN', entityType, entityId, field, null, lockMessage);
  flash.set(req, 'warning', `Financial year lock overridden to record this (${field}) — logged for audit.`);
  return true;
}

/** For list-filtering: does this user see/act on locked-FY items at all? */
function canOverride(req) {
  return !!req.permissions.ca_fy_lock_override;
}

module.exports = { allow, canOverride };
