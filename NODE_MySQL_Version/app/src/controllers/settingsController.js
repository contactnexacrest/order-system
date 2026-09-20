'use strict';

const flash = require('../helpers/flash');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');

// Port of App\Controllers\SettingsController.

async function index(req, res) {
  const settings = await companySettingsRepository.all();
  const grouped = {};
  for (const row of settings) {
    (grouped[row.category] ||= []).push(row);
  }
  res.renderView('settings/index', { grouped }, 'layout/base');
}

async function update(req, res) {
  const user = req.user;
  const submitted = req.body.settings || {};
  const reason = String(req.body.reason || '').trim();
  const unlocked = req.body.unlocked || {}; // { [setting_key]: '1' } — set only by the explicit unlock gesture

  if (typeof submitted !== 'object' || Array.isArray(submitted)) {
    flash.set(req, 'error', 'Malformed submission.');
    res.redirect('/settings');
    return;
  }

  const all = await companySettingsRepository.all();
  const byKey = {};
  for (const row of all) byKey[row.setting_key] = row;

  // First pass: find what actually changed, without writing anything yet —
  // a reason is only mandatory when there's something to justify.
  const toApply = {};
  for (const [key, rawValue] of Object.entries(submitted)) {
    if (!byKey[key]) continue; // unknown key submitted — ignore rather than trust client input
    const oldValue = byKey[key].setting_value;
    const newValue = String(rawValue).trim();
    if (oldValue !== newValue) {
      toApply[key] = { old: oldValue, new: newValue, id: byKey[key].id };
    }
  }

  if (Object.keys(toApply).length === 0) {
    flash.set(req, 'success', 'No changes were made.');
    res.redirect('/settings');
    return;
  }

  if (reason === '') {
    flash.set(req, 'error', 'A reason is required to save a settings change — nothing was saved.');
    res.redirect('/settings');
    return;
  }

  // Server-side enforcement of the unlock gesture — independent of the
  // client-side UI, which can be bypassed. A protected field changed
  // without its unlock flag present fails the whole submission (nothing
  // partial is saved), same fail-closed behavior as a missing reason.
  const blockedProtected = Object.keys(toApply).filter((key) => byKey[key].is_protected && unlocked[key] !== '1');
  if (blockedProtected.length > 0) {
    flash.set(req, 'error', `Protected field(s) must be unlocked before editing (${blockedProtected.join(', ')}) — nothing was saved.`);
    res.redirect('/settings');
    return;
  }

  for (const [key, change] of Object.entries(toApply)) {
    await companySettingsRepository.set(key, change.new, user.id);
    if (byKey[key].is_protected) {
      await auditLogRepository.log(user.id, 'PROTECTED_FIELD_UNLOCKED', 'company_settings', change.id, key, null, null, reason);
    }
    await auditLogRepository.log(user.id, 'FIELD_EDIT', 'company_settings', change.id, key, change.old, change.new, reason);
  }

  flash.set(req, 'success', `${Object.keys(toApply).length} setting(s) updated.`);
  res.redirect('/settings');
}

module.exports = { index, update };
