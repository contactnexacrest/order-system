'use strict';

const db = require('../config/db');

// Port of App\Repositories\CompanySettingsRepository. company_settings is a
// key-value table by design (ARCHITECTURE.md section 4.2) — this repository
// still has no idea what any given key means, same as the PHP original.

async function all() {
  return db.query('SELECT * FROM company_settings ORDER BY category, setting_key');
}

async function get(key) {
  const row = await db.queryOne('SELECT setting_value FROM company_settings WHERE setting_key = :key LIMIT 1', { key });
  return row ? row.setting_value : null;
}

async function set(key, value, updatedByUserId = null) {
  await db.execute(
    'UPDATE company_settings SET setting_value = :value, updated_by = :updated_by WHERE setting_key = :key',
    { value, updated_by: updatedByUserId, key }
  );
}

module.exports = { all, get, set };
