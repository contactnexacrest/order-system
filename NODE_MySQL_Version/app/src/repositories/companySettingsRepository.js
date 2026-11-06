'use strict';

const db = require('../config/db');

// Port of App\Repositories\CompanySettingsRepository. company_settings is a
// key-value table by design (ARCHITECTURE.md section 4.2) — this repository
// still has no idea what any given key means, same as the PHP original.

async function all() {
  return db.query('SELECT * FROM company_settings ORDER BY category, setting_key');
}

async function get(key) {
  // D-262: an order's frozen company values while its documents are made (orderSnapshotService.within).
  const frozen = require('../helpers/orderSnapshotContext').current();
  if (frozen && frozen.company && Object.prototype.hasOwnProperty.call(frozen.company, key)) {
    return frozen.company[key] === null || frozen.company[key] === undefined ? null : String(frozen.company[key]);
  }
  // D-257: a division's own value first (only while the request works in one division), then the company value.
  const own = await require('../services/divisionService').overrideFor('company', key);
  if (own !== null) return own;
  return companyValue(key);
}

/** The company's own value, without any division's (Admin › Divisions shows both). */
async function companyValue(key) {
  const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :key LIMIT 1', { key });
  return row ? row.setting_value : null;
}

async function set(key, value, updatedByUserId = null) {
  await db.execute(
    'UPDATE company_settings SET setting_value = :value, updated_by = :updated_by WHERE setting_key = :key',
    { value, updated_by: updatedByUserId, key }
  );
}

module.exports = { all, get, set, companyValue };
