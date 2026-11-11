'use strict';

const db = require('../config/db');
const env = require('../config/env');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const testModeRepository = require('../repositories/testModeRepository');
const auditLogRepository = require('../repositories/auditLogRepository');

/**
 * Owner decision D-312 (point 4 B, 11 Nov 2026): Admin › Setup checklist —
 * what a fresh installation still needs before real use, each item Done /
 * Needed (or Optional) with a button to the page where it is set; and the
 * settings that differ from the recommended values, with "Apply recommended"
 * (reason needed, audit-logged). Port of App\Services\SetupChecklist.
 */

/** The starting values in seed.sql that must be replaced by the company's own (D-313). */
const PLACEHOLDERS = {
  legal_name: [''],
  registered_office: ['Registered office address, City – 000000, India'],
  corporate_office: ['Corporate office address, City – 000000, India'],
  md_name: ['Managing Director'],
  director_name: ['Managing Director', 'Director'],
  phone: ['+91-0000000000'],
  email: ['info@email.com'],
  gstin: ['29AAAAA0000A1ZY'],
  iec_pan: ['AAAAA0000A'],
  bank_name: ['Bank name (to be entered)'],
  bank_branch: ['Branch (to be entered)'],
  bank_account_no: ['000000000000'],
  swift_bic: ['XXXXINXXXXX'],
  ifsc: ['XXXX0000000'],
  bank_address: ['Bank branch address, City – 000000, India'],
  lut_number: ['AD000000000000X'],
  rcmc_number: ['RCMC/CAPEXIL/00000/2026-2027'],
};

const IDENTITY_KEYS = { legal_name: 'Legal name', registered_office: 'Registered office', corporate_office: 'Corporate office',
  md_name: 'MD name', director_name: 'Director name', phone: 'Phone', email: 'E-mail' };
const BANK_KEYS = { bank_name: 'Bank', bank_branch: 'Branch', bank_account_no: 'Account number', ifsc: 'IFSC', swift_bic: 'SWIFT / BIC', bank_address: 'Bank address' };

/** The seeded e-mail addresses of the starting users (README › first login). */
const PLACEHOLDER_USER_EMAILS = ['md@email.com', 'ed@email.com', 'admin@email.com'];

/** Indian national holidays seeded for the current and next year (D-313). */
const NATIONAL_HOLIDAYS = { '01-26': 'Republic Day', '08-15': 'Independence Day', '10-02': 'Gandhi Jayanti' };

/** Recommended values (D-312): key => [label, value]. 'test_email' is the Test Mode e-mail (recommended: the company e-mail). */
const RECOMMENDED = {
  doc_cleanup_draft_days: ['Document clean-up: remove DRAFT copies after (days)', '90'],
  doc_cleanup_superseded_days: ['Document clean-up: remove never-sent superseded revisions after (days)', '365'],
  doc_cleanup_cafin_mode: ['Document clean-up: old CAFIN copies', 'latest_only'],
  password_expiry_days: ['Password expiry (days, 0 = off)', '0'],
  login_remember_browser_days: ['"This is my own office computer" lasts (days)', '30'],
  session_timeout_staff_minutes: ['Staff signed out after idle (minutes)', '240'],
  session_timeout_client_minutes: ['Buyers signed out after idle (minutes)', '120'],
  session_timeout_associate_minutes: ['Associates signed out after idle (minutes)', '120'],
  session_absolute_staff_hours: ['Staff log in again at least every (hours)', '12'],
  test_email: ['Test Mode e-mail', 'the company e-mail'],
};

/** Ticks a person gives on the page (setup_checklist_ticks). key => [label, permission]. */
const TICKS = {
  pricing_confirmed: ['The MD has checked the pricing settings (exchange rate and margins)', 'manage_pricing'],
  commission_levels_confirmed: ['The MD has checked the commission levels', 'manage_pricing'],
  tds_benefits_confirmed: ['TDS settings and export-benefit deadlines confirmed with the CA', 'manage_company_settings'],
};

function isPlaceholder(key, value) {
  const v = String(value === null || value === undefined ? '' : value).trim();
  if (v === '' || /PLACEHOLDER/i.test(v) || /to be entered/i.test(v)) return true;
  return (PLACEHOLDERS[key] || []).includes(v);
}

function item(key, title, done, detail, url, button, status = null) {
  return { key, title, status: status || (done ? 'done' : 'needed'), detail, url, button };
}

async function tick(key) {
  const row = await db.queryOne('SELECT t.ticked_at, t.note, u.name FROM setup_checklist_ticks t LEFT JOIN users u ON u.id = t.ticked_by WHERE t.item_key = :k', { k: key });
  return row ? { by: String(row.name || ''), at: String(row.ticked_at), note: row.note } : null;
}

function mayTick(key, permissions) {
  const p = permissions || {};
  if (/^holidays_\d{4}$/.test(key)) return !!p.manage_company_settings;
  return !!TICKS[key] && !!p[TICKS[key][1]];
}

async function tickItem(key, title, url, button, permissions) {
  const t = await tick(key);
  const detail = t !== null
    ? `Ticked by ${t.by !== '' ? t.by : 'a user'} on ${t.at.slice(0, 10)}${t.note ? ` — ${t.note}` : ''}.`
    : `Not ticked yet — ${TICKS[key][0].charAt(0).toLowerCase()}${TICKS[key][0].slice(1)}.`;
  return { ...item(key, title, t !== null, detail, url, button), tick: key, tick_label: TICKS[key][0], may_tick: mayTick(key, permissions), ticked: t };
}

async function nationalHolidaysMissing(year) {
  const dates = Object.keys(NATIONAL_HOLIDAYS).map((md) => `${year}-${md}`);
  const r = await db.queryOne('SELECT COUNT(*) AS n FROM company_holidays WHERE holiday_date IN (:a, :b, :c)', { a: dates[0], b: dates[1], c: dates[2] });
  return dates.length - Number(r.n);
}

async function holidayCount(year) {
  const r = await db.queryOne('SELECT COUNT(*) AS n FROM company_holidays WHERE holiday_date BETWEEN :a AND :b', { a: `${year}-01-01`, b: `${year}-12-31` });
  return Number(r.n);
}

async function placeholderUserEmails() {
  const rows = await db.query('SELECT email FROM users WHERE is_active = 1 AND LOWER(email) IN (:a, :b, :c) ORDER BY email',
    { a: PLACEHOLDER_USER_EMAILS[0], b: PLACEHOLDER_USER_EMAILS[1], c: PLACEHOLDER_USER_EMAILS[2] });
  return rows.map((r) => String(r.email));
}

/** @returns the settings that differ from the recommended values */
async function differences() {
  const out = [];
  for (const [key, [label, rec]] of Object.entries(RECOMMENDED)) {
    let value = rec;
    let current;
    if (key === 'test_email') {
      current = String(((await testModeRepository.getSettings()) || {}).test_email || '');
      value = String((await companySettingsRepository.get('email')) || '').trim();
    } else {
      const v = await companySettingsRepository.get(key);
      current = v === null || v === undefined ? '' : String(v);
    }
    if (current.trim() !== value) out.push({ key, label, current, recommended: value });
  }
  return out;
}

/** The checklist. `req` gives the person (their permissions decide who may tick). */
async function items(req, today = null) {
  const permissions = (req && req.permissions) || {};
  const day = today || require('../helpers/dbDateTime').todayIst();
  const year = parseInt(day.slice(0, 4), 10);
  const cache = {};
  const s = async (k) => {
    if (!(k in cache)) {
      const v = await companySettingsRepository.get(k);
      cache[k] = String(v === null || v === undefined ? '' : v).trim();
    }
    return cache[k];
  };
  const out = [];

  let missing = [];
  for (const [k, label] of Object.entries(IDENTITY_KEYS)) if (isPlaceholder(k, await s(k))) missing.push(label);
  out.push(item('company', 'Company identity — name, offices, MD and director names, phone, e-mail', missing.length === 0,
    missing.length === 0 ? 'Entered.' : `Still the starting text: ${missing.join(', ')}.`, '/settings', 'Open Company Settings'));

  missing = [];
  for (const [k, label] of Object.entries(BANK_KEYS)) if (isPlaceholder(k, await s(k))) missing.push(label);
  const gstNeeded = isPlaceholder('gstin', await s('gstin')) || isPlaceholder('iec_pan', await s('iec_pan'));
  out.push(item('bank', 'Bank details, GSTIN and IEC / PAN (printed on the Proforma Invoice and other documents)', missing.length === 0 && !gstNeeded,
    missing.length === 0 && !gstNeeded ? 'Entered.' : `Still the starting text: ${missing.concat(gstNeeded ? ['GSTIN / IEC-PAN'] : []).join(', ')}.`, '/settings', 'Open Company Settings'));

  const problems = [];
  if (isPlaceholder('lut_number', await s('lut_number'))) problems.push('LUT number');
  if (isPlaceholder('rcmc_number', await s('rcmc_number'))) problems.push('RCMC number');
  for (const [k, label] of [['lut_expiry_date', 'LUT expiry date'], ['rcmc_valid_until', 'RCMC valid-until date']]) {
    const v = await s(k);
    if (v === '' || v < day) problems.push(label + (v !== '' ? ' (passed)' : ''));
  }
  out.push(item('lut_rcmc', 'LUT and RCMC — numbers and dates', problems.length === 0,
    problems.length === 0 ? `LUT ${await s('lut_number')} valid to ${await s('lut_expiry_date')}; RCMC valid to ${await s('rcmc_valid_until')}.` : `Needed: ${problems.join(', ')}.`, '/settings', 'Open Company Settings'));

  const ecgcNo = await s('ecgc_policy_no');
  const ecgcTo = await s('ecgc_policy_valid_to');
  out.push(ecgcNo === ''
    ? item('ecgc', 'ECGC policy (optional)', false, 'No ECGC policy entered — leave it empty if the company has none.', '/settings', 'Open Company Settings', 'optional')
    : item('ecgc', 'ECGC policy (optional)', ecgcTo !== '' && ecgcTo >= day, ecgcTo !== '' && ecgcTo >= day ? `Policy ${ecgcNo}, valid to ${ecgcTo}.` : `Policy ${ecgcNo}: enter a valid-until date that has not passed.`, '/settings', 'Open Company Settings'));

  const smtpMissing = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_ADDRESS'].filter((k) => String(env.get(k, '') || '').trim() === '');
  out.push(item('smtp', 'E-mail server (SMTP in app/.env) — then send a test e-mail', smtpMissing.length === 0,
    smtpMissing.length === 0 ? `Set in .env (${env.get('SMTP_HOST')}). Send a test e-mail to be sure it works.` : `Missing in app/.env: ${smtpMissing.join(', ')}. Set them on the server, then send a test e-mail.`,
    '/settings#smtp-test', 'Send a test e-mail'));

  const zohoOn = (await s('zoho_books_enabled')) === '1' && (await s('zoho_books_organization_id')) !== '';
  out.push(item('zoho', 'Zoho Books and Zoho Mail (optional)', zohoOn, zohoOn ? 'Zoho Books is switched on.' : 'Not switched on — the app works without Zoho; switch it on when the CA is ready.', '/settings', 'Open Company Settings', zohoOn ? 'done' : 'optional'));

  const b = require('./backupStatusService').settings();
  const backupMissing = [];
  if (b.backup_dir === '') backupMissing.push('BACKUP_DIR');
  if (b.encryption_key_file === '') backupMissing.push('encryption (BACKUP_ENCRYPTION_KEY_FILE)');
  if (b.offsite_target === '') backupMissing.push('off-site copy (BACKUP_OFFSITE_TARGET)');
  out.push(item('backups', 'Backups — folder, encryption and an off-site copy', backupMissing.length === 0,
    backupMissing.length === 0 ? 'Set; see the Backups page for the last runs.' : `Missing in app/.env: ${backupMissing.join(', ')}.`, '/admin/backups', 'Open Backups'));

  out.push(await tickItem('pricing_confirmed', 'Pricing confirmed by the MD — exchange rate and margins', '/pricing/settings', 'Open Pricing settings', permissions));

  const levels = await tickItem('commission_levels_confirmed', 'Commission levels', '/pricing/commission-levels', 'Open Commission levels', permissions);
  if (Number((await db.queryOne('SELECT COUNT(*) AS n FROM commission_levels WHERE is_active = 1')).n) === 0) {
    levels.status = 'needed';
    levels.detail = `No active commission level — add at least one. ${levels.detail}`;
  }
  out.push(levels);

  out.push(await tickItem('tds_benefits_confirmed', 'TDS and export-benefit deadlines confirmed with the CA', '/settings/associate-tds', 'Open TDS settings', permissions));

  const holidays = await holidayCount(year);
  const holidayTick = `holidays_${year}`;
  const ticked = await tick(holidayTick);
  const nationalMissing = (await nationalHolidaysMissing(year)) + (await nationalHolidaysMissing(year + 1));
  const h = {
    ...item('holidays', `This year's holidays (${year})`, ticked !== null,
      `${holidays} holiday(s) in ${year}. Reminder: add the state and festival holidays for ${year}, then tick.${nationalMissing > 0 ? ` ${nationalMissing} national holiday(s) for ${year}–${year + 1} are not in the calendar yet.` : ''}`,
      '/holidays', 'Open Holiday Calendar'),
    tick: holidayTick, tick_label: `State and festival holidays for ${year} added`, may_tick: true, ticked,
  };
  if (nationalMissing > 0) h.action = 'national_holidays';
  out.push(h);

  const users = await placeholderUserEmails();
  out.push(item('md_email', "The MD's real e-mail (and the other starting users')", users.length === 0,
    users.length === 0 ? 'No user still has a starting e-mail address.' : `Still a starting address: ${users.join(', ')} — login codes and approval e-mails cannot reach them.`, '/users', 'Open Users'));

  // PHP keeps sessions in storage/sessions; this version keeps them in the database (nothing to set up).
  out.push(item('sessions', 'Session folder storage/sessions is writable (PHP)', true,
    'Not needed on this version — sessions are kept in the database.', '/admin/backups', 'Open Backups'));

  const differs = (await differences()).length;
  out.push(item('recommended', 'Settings at the recommended values', differs === 0,
    differs === 0 ? 'Every listed setting is at its recommended value.' : `${differs} setting(s) differ from the recommended value — see the list below.`,
    '/admin/setup-checklist#recommended', 'See the list', differs === 0 ? 'done' : 'review'));
  return out;
}

/** How many items are Needed (the menu badge); never breaks a page. */
async function neededCount(req) {
  try {
    return (await items(req)).filter((i) => i.status === 'needed').length;
  } catch (e) {
    return 0;
  }
}

/** "Apply recommended" for the chosen keys (all differing when empty); refusal text or null. */
async function applyRecommended(keys, reason, userId) {
  const error = require('../helpers/reasonValidator').check(String(reason || ''));
  if (error) return error;
  const diffs = {};
  for (const d of await differences()) diffs[d.key] = d;
  const chosen = keys.length === 0 ? Object.keys(diffs) : keys.filter((k) => k in diffs);
  if (chosen.length === 0) return 'Nothing to change — these settings are already at the recommended values.';
  const why = String(reason).trim();
  for (const key of chosen) {
    const d = diffs[key];
    if (key === 'test_email') {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.recommended)) return 'Enter the company e-mail in Company Settings first.';
      await testModeRepository.setTestEmail(d.recommended);
      await auditLogRepository.log(userId, 'TEST_MODE_TEST_EMAIL_UPDATED', 'test_mode_settings', 1, 'test_email', d.current, d.recommended, why);
      continue;
    }
    await companySettingsRepository.set(key, d.recommended, userId);
    await auditLogRepository.log(userId, 'FIELD_EDIT', 'company_settings', null, key, d.current, d.recommended, why);
  }
  await auditLogRepository.log(userId, 'SETUP_RECOMMENDED_APPLIED', 'company_settings', null, null, null, chosen.join(', '), why);
  return null;
}

/** Ticks or unticks an item; refusal text or null. */
async function setTick(key, on, note, userId, permissions) {
  if (!mayTick(key, permissions)) {
    return TICKS[key] ? `Only someone with the "${TICKS[key][1]}" permission may tick this (the MD for pricing).` : 'Unknown checklist item.';
  }
  const n = String(note || '').trim();
  if ([...n].length > 300) return 'Keep the note under 300 characters.';
  if (on) {
    await db.execute(`INSERT INTO setup_checklist_ticks (item_key, ticked_by, ticked_at, note) VALUES (:k, :u, NOW(), :n)
                      ON DUPLICATE KEY UPDATE ticked_by = VALUES(ticked_by), ticked_at = VALUES(ticked_at), note = VALUES(note)`, { k: key, u: userId, n: n !== '' ? n : null });
  } else {
    await db.execute('DELETE FROM setup_checklist_ticks WHERE item_key = :k', { k: key });
  }
  await auditLogRepository.log(userId, on ? 'SETUP_CHECKLIST_TICKED' : 'SETUP_CHECKLIST_UNTICKED', 'setup_checklist_ticks', null, key, null, on ? '1' : '0', n !== '' ? n : null);
  return null;
}

/** Adds the national holidays missing for this year and next; returns how many were added. */
async function addNationalHolidays(year, userId) {
  let added = 0;
  for (const y of [year, year + 1]) {
    for (const [md, name] of Object.entries(NATIONAL_HOLIDAYS)) {
      const r = await db.execute(`INSERT INTO company_holidays (holiday_date, description, created_by)
                                  SELECT :d, :n, :u FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM company_holidays WHERE holiday_date = :d2)`,
      { d: `${y}-${md}`, n: name, u: userId, d2: `${y}-${md}` });
      added += Number(r.affectedRows || 0);
    }
  }
  if (added > 0) await auditLogRepository.log(userId, 'NATIONAL_HOLIDAYS_ADDED', 'company_holidays', null, null, null, String(added), `National holidays for ${year} and ${year + 1}`);
  return added;
}

module.exports = {
  PLACEHOLDERS, IDENTITY_KEYS, BANK_KEYS, PLACEHOLDER_USER_EMAILS, NATIONAL_HOLIDAYS, RECOMMENDED, TICKS,
  isPlaceholder, items, neededCount, differences, applyRecommended, tick, setTick, mayTick, addNationalHolidays, nationalHolidaysMissing,
};
