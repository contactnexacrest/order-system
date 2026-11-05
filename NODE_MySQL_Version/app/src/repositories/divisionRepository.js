'use strict';

const db = require('../config/db');
const divisionService = require('../services/divisionService');

/**
 * Owner decision D-257 (5 Nov 2026): Admin › Divisions — modules, the
 * division's own settings, the pre-flight checklist and activation.
 * Port of PHP App\Repositories\DivisionRepository.
 */

/** module key => on, every module listed (a missing row is on) */
async function modules(divisionId) {
  const out = {};
  for (const key of Object.keys(divisionService.MODULES)) out[key] = await divisionService.moduleEnabledFor(divisionId, key);
  return out;
}

async function saveModules(divisionId, enabledKeys, userId) {
  for (const key of Object.keys(divisionService.MODULES)) {
    await db.execute(
      `INSERT INTO division_modules (division_id, module_key, is_enabled, updated_by) VALUES (:d, :k, :e, :u)
       ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by)`,
      { d: divisionId, k: key, e: enabledKeys.includes(key) ? 1 : 0, u: userId }
    );
  }
}

async function settings(divisionId) {
  return db.query('SELECT * FROM division_settings WHERE division_id = :d ORDER BY scope, setting_key', { d: divisionId });
}

async function setSetting(divisionId, scope, key, value, userId) {
  await db.execute(
    `INSERT INTO division_settings (division_id, scope, setting_key, setting_value, updated_by) VALUES (:d, :s, :k, :v, :u)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)`,
    { d: divisionId, s: scope, k: key, v: value, u: userId }
  );
}

async function deleteSetting(divisionId, scope, key) {
  await db.execute('DELETE FROM division_settings WHERE division_id = :d AND scope = :s AND setting_key = :k', { d: divisionId, s: scope, k: key });
}

/** orders, staff, departments, clients of one division */
async function counts(divisionId) {
  const one = async (sql) => Number((await db.queryOne(sql, { d: divisionId })).n);
  return {
    orders: await one('SELECT COUNT(*) AS n FROM orders WHERE division_id = :d'),
    staff: await one('SELECT COUNT(*) AS n FROM users WHERE division_id = :d AND is_active = 1'),
    departments: await one('SELECT COUNT(*) AS n FROM departments WHERE division_id = :d AND is_active = 1'),
    clients: await one('SELECT COUNT(*) AS n FROM client_divisions WHERE division_id = :d'),
  };
}

/** The pre-flight checklist before a planned division is activated: [{ label, ok, help }]. */
async function preflight(divisionId) {
  const c = await counts(divisionId);
  const own = new Set((await settings(divisionId)).map((r) => `${r.scope}:${r.setting_key}`));
  const modulesOn = Object.values(await modules(divisionId)).filter(Boolean);
  const items = [
    { label: 'At least one department works in this division', ok: c.departments > 0, help: 'Admin › Departments: set the Division of its departments.' },
    { label: 'At least one active staff member works in this division', ok: c.staff > 0, help: 'Admin › Users: set the Division of its staff.' },
    { label: 'Order number format set', ok: own.has('company:order_ref_format'), help: 'Add the division setting "order_ref_format" below (e.g. NC/DOM/{YY}/{DDMM}{NNN}).' },
    { label: 'Modules chosen', ok: modulesOn.length > 0, help: 'Tick the modules this division uses.' },
    { label: 'Settings reviewed (company, pricing, incentive)', ok: own.size >= 2, help: "Add the division's own values where they differ from the company (at least the number format and one more)." },
  ];
  // D-257 Phase 2: what Domestic Trade needs before its first GST sale.
  const division = await divisionService.find(divisionId);
  if (division && division.code === 'DOM') {
    const G = require('../services/gstCalculator');
    const gstin = String((await require('./companySettingsRepository').companyValue('gstin')) || '');
    items.push({ label: "NexaCrest's GSTIN is valid (it gives the GST state)", ok: G.isValidGstin(gstin), help: 'Settings: enter the company GSTIN.' });
    items.push({ label: 'GST rates set (confirm them with the CA)', ok: (await require('./domesticOrderRepository').gstRates(true)).length > 0, help: 'Admin › GST rates: add or confirm the rate per HSN.' });
    items.push({ label: 'E-way bill threshold and e-invoicing set (confirm them with the CA)', ok: own.has('company:dom_eway_threshold_inr') && own.has('company:dom_e_invoice_applicable'), help: 'Division settings dom_eway_threshold_inr (₹50,000 as a start) and dom_e_invoice_applicable (0 or 1).' });
    const ds = divisionService;
    items.push({ label: 'Export-only modules switched off (export shipping, LUT, benefits)', ok: !(await ds.moduleEnabledFor(divisionId, 'export_shipping')) && !(await ds.moduleEnabledFor(divisionId, 'lut_igst')) && !(await ds.moduleEnabledFor(divisionId, 'export_benefits')), help: 'Untick them under Modules.' });
  }
  // D-257 Phase 3: what Import & Distribution needs before its first import and local sale.
  if (division && division.code === 'IMP') {
    const G = require('../services/gstCalculator');
    const I = require('../services/importService');
    const company = require('./companySettingsRepository');
    const gstin = String((await company.companyValue('gstin')) || '');
    const iec = String((await company.companyValue('iec_pan')) || '').trim();
    const ds = divisionService;
    items.push({ label: "NexaCrest's IEC is set (the importer of record)", ok: iec !== '' && !iec.toUpperCase().includes('PLACEHOLDER'), help: 'Settings: enter the Import Export Code.' });
    items.push({ label: "NexaCrest's GSTIN is valid (IGST credit and local sales)", ok: G.isValidGstin(gstin), help: 'Settings: enter the company GSTIN.' });
    items.push({ label: 'AD bank for import LCs set', ok: (await I.setting('imp_lc_bank')) !== '', help: 'Division setting imp_lc_bank (the bank that opens LCs and remits abroad).' });
    items.push({ label: 'Customs agent (CHA) set', ok: (await I.setting('imp_default_cha')) !== '', help: 'Division setting imp_default_cha.' });
    items.push({ label: 'Import duty rates set (confirm them with the CA and the customs agent)', ok: (await require('./importRepository').dutyRates(true)).length > 0, help: 'Admin › Import duty rates.' });
    items.push({ label: 'Import, stock and landed cost module on; export-only modules off', ok: (await ds.moduleEnabledFor(divisionId, 'import_stock')) && !(await ds.moduleEnabledFor(divisionId, 'export_shipping')) && !(await ds.moduleEnabledFor(divisionId, 'lut_igst')) && !(await ds.moduleEnabledFor(divisionId, 'export_benefits')), help: 'Tick and untick them under Modules.' });
  }
  // D-257 Phase 4: what Merchant Trade needs before its first deal (RBI merchanting, through the AD bank).
  if (division && division.code === 'MTT') {
    const M = require('../services/merchantTradeService');
    const iec = String((await require('./companySettingsRepository').companyValue('iec_pan')) || '').trim();
    const ds = divisionService;
    items.push({ label: "NexaCrest's IEC is set", ok: iec !== '' && !iec.toUpperCase().includes('PLACEHOLDER'), help: 'Settings: enter the Import Export Code.' });
    items.push({ label: "AD bank's merchanting approval recorded", ok: (await M.setting('mtt_ad_bank_approval')) !== '', help: 'Division setting mtt_ad_bank_approval: the AD bank and its approval or letter reference for merchanting trade.' });
    items.push({ label: "RBI limits and the bank's document list set (confirm them with the AD bank and the CA)", ok: own.has('company:mtt_max_months_total') && own.has('company:mtt_max_outlay_months') && own.has('company:mtt_advance_limit_usd') && own.has('company:mtt_bank_documents'), help: 'Division settings mtt_max_months_total (9), mtt_max_outlay_months (4), mtt_advance_limit_usd (500000), mtt_bank_documents (a list separated by |).' });
    items.push({ label: 'Merchant trade module on; export-only modules off', ok: (await ds.moduleEnabledFor(divisionId, 'merchant_trade')) && !(await ds.moduleEnabledFor(divisionId, 'export_shipping')) && !(await ds.moduleEnabledFor(divisionId, 'lut_igst')) && !(await ds.moduleEnabledFor(divisionId, 'export_benefits')), help: 'Tick and untick them under Modules.' });
  }
  return items;
}

/** D-257 Phase 2: the starting departments of a planned division, created on request from Admin › Divisions. */
const STARTER_DEPARTMENTS = {
  DOM: [['DOM_SALES', 'Domestic Sales', '#2D7D56'], ['DOM_PURCHASE', 'Domestic Purchase', '#1D6FA8'], ['DOM_DISPATCH', 'Domestic Dispatch', '#8A5A00']],
  IMP: [['IMP_PURCHASE', 'Import Purchase', '#1D6FA8'], ['IMP_WAREHOUSE', 'Warehouse', '#8A5A00'], ['IMP_SALES', 'Distribution Sales', '#2D7D56']],
  MTT: [['MTT_TRADE', 'Merchant Trade Desk', '#1D6FA8'], ['MTT_DOCS', 'Merchant Trade Documents', '#8A5A00']],
};

/** Creates the division's starting departments that do not exist yet; returns how many were made. */
async function createStarterDepartments(divisionId) {
  const departmentRepository = require('./departmentRepository');
  const division = await divisionService.find(divisionId);
  let made = 0;
  for (const [code, name, color] of STARTER_DEPARTMENTS[division ? division.code : ''] || []) {
    if ((await departmentRepository.departmentCodeTaken(code)) || (await departmentRepository.departmentNameTaken(name))) continue;
    const id = await departmentRepository.createDepartment(code, name, color);
    await setDepartmentDivision(id, divisionId);
    made++;
  }
  return made;
}

/** Active staff who work in another division (company-level people are left out). */
async function staffChoices(divisionId) {
  return db.query('SELECT id, name, email FROM users WHERE is_active = 1 AND division_id IS NOT NULL AND division_id <> :d ORDER BY name', { d: divisionId });
}

async function setStatus(divisionId, status, userId) {
  await db.execute(
    `UPDATE divisions SET status = :s, activated_at = IF(:s2 = 'active', NOW(), activated_at), activated_by = IF(:s3 = 'active', :u, activated_by) WHERE id = :id`,
    { s: status, s2: status, s3: status, u: userId, id: divisionId }
  );
}

async function setUserDivision(userId, divisionId) {
  await db.execute('UPDATE users SET division_id = :d WHERE id = :id', { d: divisionId, id: userId });
}

async function setDepartmentDivision(departmentId, divisionId) {
  await db.execute('UPDATE departments SET division_id = :d WHERE id = :id', { d: divisionId, id: departmentId });
}

module.exports = { modules, saveModules, settings, setSetting, deleteSetting, counts, preflight, setStatus, setUserDivision, setDepartmentDivision, STARTER_DEPARTMENTS, createStarterDepartments, staffChoices };
