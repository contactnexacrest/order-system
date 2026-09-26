'use strict';

const db = require('../config/db');
const financialYear = require('../helpers/financialYear');

// CA / Accounting module (Phase 6) — year-end financial year lock. Once a
// CA closes a financial year, every CA-module write path that would touch
// that year's data (INR actuals, FIRC/eBRC references, the assumed
// exchange rate, expense TDS annotations, bank-statement matching) is
// blocked, so a closed year's numbers can never be quietly changed after
// the fact.
//
// A lock is a row with unlocked_at IS NULL. Unlocking never deletes the
// row — it stamps unlocked_at/unlocked_by/unlock_reason — and re-locking
// the same year inserts a fresh row, so the full lock/unlock history for
// every year stays on record (the same append-only spirit as
// zoho_sync_log, not a single mutable status flag).

/** @returns {Promise<string[]>} every financial year currently locked, e.g. ['2025-26'] */
async function lockedYears() {
  const rows = await db.query('SELECT DISTINCT financial_year FROM ca_fy_locks WHERE unlocked_at IS NULL');
  return rows.map((r) => r.financial_year);
}

async function isLocked(fy) {
  const row = await db.queryOne('SELECT 1 AS present FROM ca_fy_locks WHERE financial_year = :fy AND unlocked_at IS NULL LIMIT 1', { fy });
  return !!row;
}

/**
 * @param {string|null} date any date string (e.g. a leg's cleared_at or an
 *        expense's expense_date) — null (not yet cleared/dated) is never
 *        locked, since there's nothing to have closed yet.
 * @returns {Promise<string|null>} a ready-to-display message if the date's
 *          FY is locked, otherwise null.
 */
async function lockMessageForDate(date) {
  if (date === null || date === undefined || date === '') {
    return null;
  }
  const fy = financialYear.label(date);
  if (!(await isLocked(fy))) {
    return null;
  }
  return `This falls in FY ${fy}, which is locked for CA data entry. A CA module admin must reopen it first (CA / Accounting → Financial Year Lock) if this is a genuine correction.`;
}

/** @returns {Promise<Array<object>>} every lock/unlock event, newest first */
async function history() {
  return db.query('SELECT * FROM ca_fy_locks ORDER BY locked_at DESC, id DESC');
}

async function lock(fy, lockedBy) {
  await db.execute('INSERT INTO ca_fy_locks (financial_year, locked_by) VALUES (:fy, :locked_by)', { fy, locked_by: lockedBy });
}

async function unlock(fy, unlockedBy, reason) {
  await db.execute(
    `UPDATE ca_fy_locks
     SET unlocked_at = NOW(), unlocked_by = :unlocked_by, unlock_reason = :reason
     WHERE financial_year = :fy AND unlocked_at IS NULL`,
    { unlocked_by: unlockedBy, reason, fy }
  );
}

module.exports = { lockedYears, isLocked, lockMessageForDate, history, lock, unlock };
