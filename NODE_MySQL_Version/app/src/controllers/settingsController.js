'use strict';

const secretSetting = require('../helpers/secretSetting');

const flash = require('../helpers/flash');
const reasonValidator = require('../helpers/reasonValidator');
const settingValueValidator = require('../helpers/settingValueValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const lookupRepository = require('../repositories/lookupRepository');
const uiTemplateRepository = require('../repositories/uiTemplateRepository');
const zohoMailService = require('../services/zohoMailService');
const zohoBooksService = require('../services/zohoBooksService');
const zohoGuard = require('../services/zohoGuard');
const emailService = require('../services/emailService');
const superAdminService = require('../services/superAdminService');
const settingLabels = require('../helpers/settingLabels');
const mdNameSyncService = require('../services/mdNameSyncService');

// Port of App\Controllers\SettingsController.

async function index(req, res) {
  const settings = await companySettingsRepository.all();
  const grouped = {};
  for (const row of settings) {
    // Owner decision 1 Nov 2026 (C-14): a secret is never sent to the browser — only its last 4 characters.
    if (secretSetting.isSecret(row.setting_key)) {
      row.is_secret = true;
      row.secret_hint = secretSetting.hint(row.setting_value);
      row.setting_value = '';
    }
    // D-332: the plain name and help sentence (the technical key stays as small grey text).
    row.plainLabel = settingLabels.label(row.setting_key);
    row.plainHelp = settingLabels.help(row.setting_key, row.description);
    (grouped[row.category] ||= []).push(row);
  }
  if (!zohoMailService.IN_USE) {
    delete grouped.zoho; // mail goes through Bluehost SMTP only (owner decision 2026-10-29)
  }
  // Owner points 5/8/9 (2 Nov 2026): edited on its own page with its own rules (/settings/document-cleanup).
  delete grouped.document_cleanup;
  delete grouped.sop_access; // D-266: switched on Admin › SOP access
  // D-280: testing mode is set on Admin › E-mail settings (one place only).
  if (grouped.mail) grouped.mail = grouped.mail.filter((r) => r.setting_key !== 'email_testing_mode');
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
  const sortedGroups = settingLabels.sortSections(grouped); // D-332: sections in the order of their plain titles
  const sectionTitles = {};
  for (const category of Object.keys(sortedGroups)) sectionTitles[category] = settingLabels.sectionTitle(category);
  res.renderView('settings/index', {
    grouped: sortedGroups,
    sectionTitles,
    zohoServer: {
      enabled: zohoGuard.integrationEnabled(),
      environment: zohoGuard.environment(),
      government: zohoGuard.governmentFeaturesAllowed(),
      missing: zohoGuard.missingVariables(),
    },
    isSuperAdmin,
    rbiPurposeCodes,
    rbiPurposeCodeValues: rbiPurposeCodes.map((o) => o.option_value),
    activeUiTemplates,
    activeUiTemplateKeys: activeUiTemplates.map((t) => t.template_key),
  }, 'layout/base');
}

async function update(req, res) {
  const user = req.user;
  // D-349: the second step of an MD name / title change ("Save and apply" / "Save setting only")
  // replays the submission kept in the session; every check below runs again on it.
  const replay = req.mdSyncReplay || null;
  const mdChoice = replay ? replay.md_sync_choice : null;
  const input = replay || req.body;
  const submitted = input.settings || {};
  const reason = String(input.reason || '').trim();
  const unlocked = input.unlocked || {}; // { [setting_key]: '1' } — set only by the explicit unlock gesture

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
    if (byKey[key].category === 'document_cleanup') continue; // saved only through /settings/document-cleanup
    if (byKey[key].category === 'sop_access') continue; // D-266: saved only through /admin/sop-access
    const oldValue = byKey[key].setting_value;
    const newValue = String(rawValue).trim();
    // Owner decision 1 Nov 2026 (C-14): a secret's field is shown empty — left empty, the stored value stays.
    if (secretSetting.isSecret(key) && newValue === '') {
      continue;
    }
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
      const error = settingValueValidator.check(byKey[key].value_type, change.new, key); // D-283: key ranges
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

  // D-349: an MD name / title change first shows what else still carries the old value
  // (open orders, draft documents) and asks "Save and apply" or "Save setting only".
  const mdChanges = mdNameSyncService.onlyMdKeys(toApply);
  const hasMdChange = Object.keys(mdChanges).length > 0;
  if (hasMdChange && mdChoice === null) {
    req.session.md_sync_pending = {
      settings: submitted, reason, unlocked, changes: mdChanges, at: Math.floor(Date.now() / 1000),
    };
    res.redirect('/settings/md-change');
    return;
  }

  for (const [key, change] of Object.entries(toApply)) {
    await companySettingsRepository.set(key, change.new, user.id);
    if (byKey[key].is_protected) {
      await auditLogRepository.log(user.id, 'PROTECTED_FIELD_UNLOCKED', 'company_settings', change.id, key, null, null, reason);
    }
    await auditLogRepository.log(user.id, 'FIELD_EDIT', 'company_settings', change.id, key,
      secretSetting.isSecret(key) ? secretSetting.forAudit(change.old) : change.old,
      secretSetting.isSecret(key) ? secretSetting.forAudit(change.new) : change.new, reason);
  }

  let message = `${Object.keys(toApply).length} setting(s) updated.`;
  if (hasMdChange && mdChoice === 'apply') {
    const done = await mdNameSyncService.apply(mdChanges, user.id, reason, !!replay.apply_orders, !!replay.apply_documents);
    message += ` Applied to ${done.orders} open order(s) and ${done.documents} draft / in-review document(s).`;
  } else if (hasMdChange) {
    message += ' Open orders and draft documents were left as they are.';
  }
  flash.set(req, 'success', message);
  res.redirect('/settings');
}

/** The waiting MD change (30 minutes at most), or null. */
function pendingMdChange(req) {
  const p = req.session.md_sync_pending;
  if (!p || typeof p !== 'object' || (parseInt(p.at, 10) || 0) < Math.floor(Date.now() / 1000) - 1800
      || !p.settings || typeof p.settings !== 'object') {
    return null;
  }
  return p;
}

/** D-349: the "Apply it to:" step after an MD name / title change in Settings › Company. */
async function mdChangeForm(req, res) {
  const pending = pendingMdChange(req);
  if (!pending) {
    flash.set(req, 'error', 'There is no Managing Director name or title change waiting to be saved. Make the change again.');
    res.redirect('/settings');
    return;
  }
  res.renderView('settings/md_change', {
    changes: pending.changes,
    preview: await mdNameSyncService.preview(pending.changes),
  });
}

/** D-349: "Save and apply" / "Save setting only" — saves the kept submission (all checks run again). */
async function mdChangeSave(req, res) {
  const pending = pendingMdChange(req);
  delete req.session.md_sync_pending;
  const choice = String((req.query && req.query.choice) || req.body.choice || '');
  if (!pending || !['apply', 'setting_only'].includes(choice)) {
    flash.set(req, 'error', 'Nothing was saved: the change was no longer waiting, or no choice was made. Make the change again.');
    res.redirect('/settings');
    return;
  }
  req.mdSyncReplay = {
    md_sync_choice: choice,
    settings: pending.settings,
    reason: pending.reason,
    unlocked: pending.unlocked,
    apply_orders: !!req.body.apply_orders,
    apply_documents: !!req.body.apply_documents,
  };
  await update(req, res);
}

/**
 * docs/schema.sql Section AI — calls zohoMailService directly (never
 * through mailSenderService's fallback) so a real Zoho error surfaces here
 * instead of silently succeeding via SMTP, which would be useless for
 * actually verifying the Zoho credentials just entered.
 */
async function testZohoEmail(req, res) {
  if (!zohoMailService.IN_USE) {
    flash.set(req, 'error', 'Zoho Mail is not used by this application — all mail goes through the SMTP server in .env (Bluehost).');
    res.redirect('/settings');
    return;
  }
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
 * Zoho integration Stage 1 (2026-10-29) — the "Test Books connection"
 * button: logs in to Zoho Books with the saved zoho_books_* settings and
 * reports the organisation, the deposit account and any currency Books has
 * not turned on. Reads only; creates nothing in Zoho.
 */
async function testZohoBooks(req, res) {
  try {
    const result = await zohoBooksService.testConnection();
    let message = `Connected to Zoho Books: ${result.organization}. Payments are deposited into ${result.deposit_account}.`;
    if (result.missing_currencies.length > 0) {
      message += ` Turn on these currencies in Zoho Books -> Settings -> Currencies: ${result.missing_currencies.join(', ')}.`;
    }
    flash.set(req, result.missing_currencies.length === 0 ? 'success' : 'warning', message);
    await auditLogRepository.log(req.user.id, 'SETTINGS_TEST_ZOHO_BOOKS_CONNECTED', 'company_settings', null, null, null, result.organization);
  } catch (e) {
    flash.set(req, 'error', `Zoho Books connection failed: ${e.message}`);
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

module.exports = { index, update, mdChangeForm, mdChangeSave, testZohoEmail, testZohoBooks, testSmtpEmail };
