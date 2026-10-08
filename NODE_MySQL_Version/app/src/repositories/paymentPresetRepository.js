'use strict';

const db = require('../config/db');

/**
 * Full CRUD for payment_presets (docs/schema.sql Section — payment
 * presets), previously seed-data only with no admin screen. Every
 * order's advance/balance split and balance-trigger wording come from
 * whichever preset it's assigned, via orderRepository.find()'s
 * COALESCE(override, preset) columns — so editing a preset here changes
 * every order on it, and a NEW preset (e.g. 20% advance) is picked up
 * automatically by documentDataAssembler without any further code
 * change (it computes purely off advance_pct/balance_pct, never a
 * hardcoded split).
 *
 * is_protected mirrors the tc_clauses/company_settings pattern (Section
 * L): both seeded presets ship protected, since they drive where money
 * is actually sent/received. A protected preset must be unlocked via
 * the existing Field Protection flow (/admin/field-protection) before
 * it can be edited or deactivated — enforced here AND by the DB
 * trigger (trg_payment_presets_bu) as a backstop.
 */

function bindData(data) {
  return {
    preset_name: data.preset_name,
    is_default: data.is_default ? 1 : 0,
    advance_pct: data.advance_pct,
    advance_trigger_text: data.advance_trigger_text,
    balance_pct: data.balance_pct,
    balance_trigger_option: data.balance_trigger_option,
    balance_days: data.balance_days,
    balance_trigger_wording: data.balance_trigger_wording ?? null,
    currency_id: data.currency_id,
    requires_md_approval: data.requires_md_approval ? 1 : 0,
  };
}

/** @returns {Promise<Array>} newest-default-first; includes inactive when includeInactive */
async function all(includeInactive = false) {
  let sql = 'SELECT pp.*, cur.code AS currency_code FROM payment_presets pp JOIN currencies cur ON cur.id = pp.currency_id';
  if (!includeInactive) {
    sql += ' WHERE pp.is_active = 1';
  }
  sql += ' ORDER BY pp.is_default DESC, pp.preset_name';
  return db.query(sql);
}

async function find(id) {
  return db.queryOne(
    'SELECT pp.*, cur.code AS currency_code FROM payment_presets pp JOIN currencies cur ON cur.id = pp.currency_id WHERE pp.id = :id',
    { id }
  );
}

/** @returns {Promise<boolean>} true if any order currently uses this preset (informational — never blocks edit/deactivate on its own) */
async function isInUse(id) {
  const row = await db.queryOne('SELECT COUNT(*) AS n FROM orders WHERE payment_preset_id = :id', { id });
  return row.n > 0;
}

async function create(data, createdBy) {
  const result = await db.execute(
    `INSERT INTO payment_presets
        (preset_name, is_default, advance_pct, advance_trigger_text, balance_pct,
         balance_trigger_option, balance_days, balance_trigger_wording, currency_id,
         requires_md_approval, is_active, created_by)
     VALUES
        (:preset_name, :is_default, :advance_pct, :advance_trigger_text, :balance_pct,
         :balance_trigger_option, :balance_days, :balance_trigger_wording, :currency_id,
         :requires_md_approval, 1, :created_by)`,
    { ...bindData(data), created_by: createdBy }
  );
  const id = result.insertId;

  if (data.is_default) {
    await clearOtherDefaults(id);
  }
  return id;
}

async function update(id, data) {
  await db.execute(
    `UPDATE payment_presets SET
        preset_name = :preset_name, is_default = :is_default, advance_pct = :advance_pct,
        advance_trigger_text = :advance_trigger_text, balance_pct = :balance_pct,
        balance_trigger_option = :balance_trigger_option, balance_days = :balance_days,
        balance_trigger_wording = :balance_trigger_wording, currency_id = :currency_id,
        requires_md_approval = :requires_md_approval
     WHERE id = :id`,
    { ...bindData(data), id }
  );

  if (data.is_default) {
    await clearOtherDefaults(id);
  }
}

/** Exactly one preset may be is_default=1 (the order form's pre-selected choice) — enforced here in the app layer, not a DB constraint. */
async function clearOtherDefaults(exceptId) {
  await db.execute('UPDATE payment_presets SET is_default = 0 WHERE id != :id', { id: exceptId });
}

/** @throws {Error} if the preset is_protected — caller must unlock via Field Protection first */
async function toggleActive(id) {
  const preset = await find(id);
  if (!preset) {
    return;
  }
  if (preset.is_protected) {
    throw new Error('This preset is protected and cannot be deactivated until unlocked via Field Protection.');
  }
  await db.execute('UPDATE payment_presets SET is_active = 1 - is_active WHERE id = :id', { id });
}

module.exports = { all, find, isInUse, create, update, toggleActive };
