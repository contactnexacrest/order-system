'use strict';

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const settingValueValidator = require('../helpers/settingValueValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const lookupRepository = require('../repositories/lookupRepository');
const uiTemplateRepository = require('../repositories/uiTemplateRepository');
const zohoMailService = require('../services/zohoMailService');
const emailService = require('../services/emailService');
const superAdminService = require('../services/superAdminService');

// Port of App\Controllers\SettingsController.

async function index(req, res) {
  const settings = await companySettingsRepository.all();
  const grouped = {};
  for (const row of settings) {
    (grouped[row.category] ||= []).push(row);
  }
  const isSuperAdmin = await superAdminService.isEffective(req.user.id);
  // 90-item backlog #31: rbi_purpose_code_advance/balance/freight were
  // plain free-text settings with no standard list — same dropdown
  // treatment as #43/#66/#51a, special-cased in the view by setting_key
  // since company_settings.value_type has no 'select' variant (see the
  // view's own comment for why this was scoped as a 3-key special case
  // rather than a new generic settings field type). rbiPurposeCodeValues
  // is the same list flattened to plain strings, used by the view's
  // "is the current value already in the admin list" check — Nunjucks
  // has no mutable loop-scoped variable (no namespace() like Jinja2), so
  // that check is done with the `in` operator against a plain array
  // instead of a loop-tracked boolean.
  const rbiPurposeCodes = await lookupRepository.dropdownOptions('rbi_purpose_code');
  // docs/schema.sql Section BQ — default_ui_template gets the same
  // by-setting_key dropdown special-case as the RBI codes above, sourced
  // from the active UI templates rather than a lookup list.
  const activeUiTemplates = await uiTemplateRepository.active();
  res.renderView('settings/index', {
    grouped,
    isSuperAdmin,
    rbiPurposeCodes,
    rbiPurposeCodeValues: rbiPurposeCodes.map((o) => o.option_value),
    activeUiTemplates,
    activeUiTemplateKeys: activeUiTemplates.map((t) => t.template_key),
  }, 'layout/base');
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

  const reasonError = reasonValidator.check(reason);
  if (reasonError) {
    flash.set(req, 'error', reasonError);
    res.redirect('/settings');
    return;
  }

  // QA-5 SET-02: company_settings.value_type was defined in the schema but
  // never actually enforced — any string could be saved into a 'number'
  // setting like session_timeout_minutes, parsing to NaN or a negative
  // threshold everywhere it's later read. Same fail-closed contract as the
  // protected-field check below: one bad value fails the whole submission.
  const valueErrors = Object.entries(toApply)
    .map(([key, change]) => {
      const error = settingValueValidator.check(byKey[key].value_type, change.new);
      return error ? `${key} ${error}` : null;
    })
    .filter(Boolean);
  if (valueErrors.length > 0) {
    flash.set(req, 'error', `Invalid value(s) — nothing was saved: ${valueErrors.join('; ')}`);
    res.redirect('/settings');
    return;
  }

  // 90-item backlog #33: storage_base_path is rendered readonly in the
  // view (see settings/index.njk) since changing it has zero effect on
  // the app — the real path only ever comes from .env. Enforced here
  // too, same fail-closed contract as the checks below, so a crafted
  // POST can't bypass the client-side readonly and write a value into
  // company_settings that looks live but silently isn't.
  if (toApply.storage_base_path) {
    flash.set(req, 'error', 'storage_base_path is read-only here and has no effect on the app — change STORAGE_BASE_PATH in .env instead. Nothing was saved.');
    res.redirect('/settings');
    return;
  }

  // docs/schema.sql Section BQ — unlike the freely-trusted RBI-code/
  // lead_time_display_unit dropdowns, this setting has wider blast radius
  // (it's what every user with no personal preference actually sees), so
  // a submitted value that isn't among the currently-active template keys
  // is rejected outright rather than silently saved.
  if (toApply.default_ui_template) {
    const activeKeys = (await uiTemplateRepository.active()).map((t) => t.template_key);
    if (!activeKeys.includes(toApply.default_ui_template.new)) {
      flash.set(req, 'error', 'default_ui_template must be one of the currently active UI templates. Nothing was saved.');
      res.redirect('/settings');
      return;
    }
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

  // docs/schema.sql Section AS: requires_super_admin fields (the mail
  // redirect CC list) refuse the edit outright for anyone who isn't an
  // effective Super Admin — no unlock gesture can override this, unlike
  // is_protected above.
  if (!(await superAdminService.isEffective(user.id))) {
    const blockedSuperAdmin = Object.keys(toApply).filter((key) => byKey[key].requires_super_admin);
    if (blockedSuperAdmin.length > 0) {
      flash.set(req, 'error', `Only a Super Admin can change: ${blockedSuperAdmin.join(', ')} — nothing was saved.`);
      res.redirect('/settings');
      return;
    }
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

/**
 * docs/schema.sql Section AI — calls zohoMailService directly (never
 * through mailSenderService's fallback) so a real Zoho error surfaces here
 * instead of silently succeeding via SMTP, which would be useless for
 * actually verifying the Zoho credentials just entered.
 */
async function testZohoEmail(req, res) {
  const to = String(req.body.test_to || '').trim();
  if (to === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
    flash.set(req, 'error', 'Enter a valid email address to send the test to.');
    res.redirect('/settings');
    return;
  }

  try {
    await zohoMailService.send(
      to,
      'NexaCrest — Zoho Mail test',
      `This is a test email sent from the NexaCrest order system's Zoho Mail integration.\n\nIf you received this, the connection is working.\n\nSent by ${req.user.name}.`
    );
    flash.set(req, 'success', `Test email sent via Zoho Mail to ${to}.`);
    // External QA report N-08: this action never reached the audit log,
    // so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'SETTINGS_TEST_ZOHO_EMAIL_SENT', 'company_settings', null, null, null, to);
  } catch (e) {
    flash.set(req, 'error', `Zoho test send failed: ${e.message}`);
  }
  res.redirect('/settings');
}

/**
 * docs/schema.sql Section AS — calls emailService.sendTestEmail() directly
 * (never through mailSenderService's Zoho-first fallback, and never
 * through Test Mode/Mail Redirect) so a real SMTP error surfaces here
 * instead of silently logging, which would be useless for actually
 * verifying the SMTP_* .env credentials just configured.
 */
async function testSmtpEmail(req, res) {
  const to = String(req.body.test_to || '').trim();
  if (to === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
    flash.set(req, 'error', 'Enter a valid email address to send the test to.');
    res.redirect('/settings');
    return;
  }

  try {
    await emailService.sendTestEmail(
      to,
      'NexaCrest — SMTP test',
      `This is a test email sent from the NexaCrest order system's SMTP connection.\n\nIf you received this, the connection is working.\n\nSent by ${req.user.name}.`
    );
    flash.set(req, 'success', `Test email sent via SMTP to ${to}.`);
    // External QA report N-08: this action never reached the audit log,
    // so it never notified anyone either.
    await auditLogRepository.log(req.user.id, 'SETTINGS_TEST_SMTP_EMAIL_SENT', 'company_settings', null, null, null, to);
  } catch (e) {
    flash.set(req, 'error', `SMTP test send failed: ${e.message}`);
  }
  res.redirect('/settings');
}

module.exports = { index, update, testZohoEmail, testSmtpEmail };
