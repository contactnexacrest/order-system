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

/**
 * External QA report R-13: a dropdown_options-backed field like coo_type
 * was never validated server-side — any string the client sent (e.g.
 * "a,b" from array-shaped input, or stale text from before an admin
 * removed an option) was stored as-is, even though the form itself only
 * ever offers the active options plus the "nothing chosen yet" sentinel.
 * Returns value unchanged when it matches an active option for listKey
 * or is the literal "To Be Confirmed" sentinel every such field already
 * falls back to when blank; otherwise returns '' so the caller's own
 * existing `|| <fallback>` handles an invalid value exactly like a blank
 * submission.
 */
async function sanitizeOption(listKey, value) {
  if (value === '' || value === 'To Be Confirmed') {
    return value;
  }
  return matchOption((await dropdownOptions(listKey)).map((row) => row.option_value), value);
}

/**
 * Owner report (2026-10-26): a client typing "GSP Form A" or
 * "non-preferential" on the Quotation/PI details forms lost their answer
 * entirely, because only an exact match to "GSP Form A (Preferential)" /
 * "Non-Preferential" was kept. Matches ignoring case and spacing, and
 * accepts the start of an option's name; returns the option's own
 * spelling, or '' when nothing (or more than one option) fits.
 */
function matchOption(options, value) {
  const norm = (v) => String(v ?? '').trim().replace(/\s+/g, ' ').toLowerCase();
  const wanted = norm(value);
  if (wanted === '') return '';
  const exact = options.find((o) => norm(o) === wanted);
  if (exact !== undefined) return String(exact);
  const starts = options.filter((o) => norm(o).startsWith(wanted));
  return starts.length === 1 ? String(starts[0]) : '';
}

module.exports = { incoterms, currencies, ports, paymentPresets, stagesMaster, roles, dropdownOptions, sanitizeOption, matchOption };
