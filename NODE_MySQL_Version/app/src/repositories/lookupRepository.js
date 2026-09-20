'use strict';

const db = require('../config/db');

// Port of App\Repositories\LookupRepository — small read-only reference
// tables that only populate dropdowns.

async function incoterms() {
  return db.query('SELECT * FROM incoterms WHERE is_active = 1 ORDER BY sort_order, code');
}

async function currencies() {
  return db.query('SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, code');
}

async function ports(role = 'both') {
  return db.query(
    "SELECT * FROM ports WHERE is_active = 1 AND (port_role = :role OR port_role = 'both') ORDER BY sort_order, name",
    { role }
  );
}

async function paymentPresets() {
  return db.query('SELECT * FROM payment_presets WHERE is_active = 1 ORDER BY is_default DESC, preset_name');
}

async function stagesMaster() {
  return db.query('SELECT * FROM stages_master WHERE is_active = 1 ORDER BY sequence');
}

async function roles() {
  return db.query('SELECT * FROM roles ORDER BY id');
}

async function dropdownOptions(listKey) {
  return db.query('SELECT * FROM dropdown_options WHERE list_key = :list_key AND is_active = 1 ORDER BY sort_order', { list_key: listKey });
}

module.exports = { incoterms, currencies, ports, paymentPresets, stagesMaster, roles, dropdownOptions };
