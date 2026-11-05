'use strict';

const flash = require('../helpers/flash');
const { scalarString } = require('../helpers/input');
const auditLogRepository = require('../repositories/auditLogRepository');
const companySettingsRepository = require('../repositories/companySettingsRepository');
const divisionRepository = require('../repositories/divisionRepository');
const divisionService = require('../services/divisionService');
const referenceNumberService = require('../services/referenceNumberService');

/**
 * Owner decision D-257 (5 Nov 2026): divisions.
 *  - POST /divisions/choose — the header switcher (company level, two or more active divisions).
 *  - Admin › Divisions (manage_divisions; the MD): modules, the division's own
 *    settings, the pre-flight checklist and Activate. Export cannot be deactivated.
 *  - The Division of a user / a department (shown only with two or more active divisions).
 * Port of PHP App\Controllers\DivisionController.
 */

const SETTING_SCOPES = { company: 'Company setting', pricing: 'Pricing setting', incentive: 'Incentive setting' };

async function choose(req, res) {
  if (req.divisionUi && req.divisionUi.showSwitcher) await divisionService.choose(req.session, scalarString(req.body.division, 'all'));
  const back = String(req.body.back || '/');
  res.redirect(/^\/[^/]/.test(back) || back === '/' ? back : '/');
}

async function index(req, res) {
  const rows = [];
  for (const d of await divisionService.all()) rows.push({ ...d, counts: await divisionRepository.counts(d.id) });
  res.renderView('divisions/index', { divisions: rows });
}

/** The order number format this division uses (its own, else the company one; non-Export numbers start with the code). */
async function numberFormat(divisionId) {
  const own = (await divisionRepository.settings(divisionId)).find((r) => r.scope === 'company' && r.setting_key === 'order_ref_format');
  if (own) return String(own.setting_value);
  const company = (await companySettingsRepository.companyValue('order_ref_format')) || referenceNumberService.DEFAULT_ORDER_REF_FORMAT;
  const division = await divisionService.find(divisionId);
  return Number(divisionId) === divisionService.EXPORT_ID ? company : `${division ? division.code : ''}/${company}`;
}

async function show(req, res) {
  const division = await divisionService.find(parseInt(req.params.id, 10));
  if (!division) {
    res.status(404).send('Division not found.');
    return;
  }
  const id = Number(division.id);
  res.renderView('divisions/show', {
    division,
    modules: await divisionRepository.modules(id),
    moduleList: Object.entries(divisionService.MODULES).map(([key, [label]]) => ({ key, label })),
    settings: await divisionRepository.settings(id),
    settingScopes: SETTING_SCOPES,
    counts: await divisionRepository.counts(id),
    preflight: division.status === 'planned' ? await divisionRepository.preflight(id) : [],
    orderRefFormat: await numberFormat(id),
    isExport: id === divisionService.EXPORT_ID,
    starterDepartments: divisionRepository.STARTER_DEPARTMENTS[division.code] || [],
    starterDepartmentNames: (divisionRepository.STARTER_DEPARTMENTS[division.code] || []).map((d) => d[1]).join(', '),
    staffChoices: division.status === 'planned' ? await divisionRepository.staffChoices(id) : [],
  });
}

async function saveModules(req, res) {
  const id = parseInt(req.params.id, 10);
  if (!(await divisionService.find(id))) {
    res.status(404).send('Division not found.');
    return;
  }
  const posted = [].concat(req.body.modules || req.body['modules[]'] || []).map(String);
  const keys = Object.keys(divisionService.MODULES).filter((k) => posted.includes(k));
  await divisionRepository.saveModules(id, keys, req.user.id);
  await auditLogRepository.log(req.user.id, 'DIVISION_MODULES_SAVED', 'divisions', id, 'modules', null, keys.join(', '));
  flash.set(req, 'success', 'Modules saved.');
  res.redirect(`/admin/divisions/${id}`);
}

async function saveSetting(req, res) {
  const id = parseInt(req.params.id, 10);
  const scope = String(req.body.scope || '');
  const key = scalarString(req.body.setting_key);
  const value = scalarString(req.body.setting_value);
  let error = null;
  if (!(await divisionService.find(id))) error = 'Division not found.';
  else if (!SETTING_SCOPES[scope]) error = 'Choose which kind of setting this is.';
  else if (!/^[a-z][a-z0-9_:.-]{1,99}$/i.test(key)) error = 'Enter the setting name exactly as on its settings page (e.g. order_ref_format).';
  else if ([...value].length > 2000) error = 'The value is too long (2,000 characters at most).';
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect(`/admin/divisions/${id}`);
    return;
  }
  await divisionRepository.setSetting(id, scope, key, value, req.user.id);
  await auditLogRepository.log(req.user.id, 'DIVISION_SETTING_SAVED', 'divisions', id, `${scope}:${key}`, null, value);
  flash.set(req, 'success', `Setting "${key}" saved for this division.`);
  res.redirect(`/admin/divisions/${id}`);
}

async function deleteSetting(req, res) {
  const id = parseInt(req.params.id, 10);
  const scope = String(req.body.scope || '');
  const key = scalarString(req.body.setting_key);
  await divisionRepository.deleteSetting(id, scope, key);
  await auditLogRepository.log(req.user.id, 'DIVISION_SETTING_REMOVED', 'divisions', id, `${scope}:${key}`, null, null);
  flash.set(req, 'success', `Setting "${key}" removed: the company value applies again.`);
  res.redirect(`/admin/divisions/${id}`);
}

async function activate(req, res) {
  const id = parseInt(req.params.id, 10);
  const division = await divisionService.find(id);
  if (!division || division.status !== 'planned') {
    flash.set(req, 'error', 'Only a planned division can be activated.');
    res.redirect(`/admin/divisions/${id}`);
    return;
  }
  const missing = (await divisionRepository.preflight(id)).filter((i) => !i.ok);
  if (missing.length > 0 || String(req.body.confirm || '') !== '1') {
    flash.set(req, 'error', missing.length > 0 ? `Finish the checklist first: ${missing.map((i) => i.label).join('; ')}.` : 'Tick the box to confirm.');
    res.redirect(`/admin/divisions/${id}`);
    return;
  }
  await divisionRepository.setStatus(id, 'active', req.user.id);
  await auditLogRepository.log(req.user.id, 'DIVISION_ACTIVATED', 'divisions', id, 'status', 'planned', 'active');
  flash.set(req, 'success', `${division.name} is now active. Company-level people now see the division switcher.`);
  res.redirect(`/admin/divisions/${id}`);
}

async function deactivate(req, res) {
  const id = parseInt(req.params.id, 10);
  const division = await divisionService.find(id);
  const c = division ? await divisionRepository.counts(id) : { orders: 0 };
  let error = null;
  if (!division) error = 'Division not found.';
  else if (id === divisionService.EXPORT_ID) error = "Export is the company's main division and always stays active.";
  else if (division.status !== 'active') error = 'This division is not active.';
  else if (c.orders > 0) error = 'This division already has orders, so it stays active.';
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect(`/admin/divisions/${id}`);
    return;
  }
  await divisionRepository.setStatus(id, 'planned', req.user.id);
  await auditLogRepository.log(req.user.id, 'DIVISION_DEACTIVATED', 'divisions', id, 'status', 'active', 'planned');
  flash.set(req, 'success', `${division.name} is back to planned.`);
  res.redirect(`/admin/divisions/${id}`);
}

/** D-257 Phase 2: creates a planned division's starting departments (Domestic Trade: Sales, Purchase, Dispatch). */
async function starterDepartments(req, res) {
  const id = parseInt(req.params.id, 10);
  const division = await divisionService.find(id);
  if (!division || id === divisionService.EXPORT_ID) {
    flash.set(req, 'error', 'Division not found.');
    res.redirect('/admin/divisions');
    return;
  }
  const made = await divisionRepository.createStarterDepartments(id);
  await auditLogRepository.log(req.user.id, 'DIVISION_STARTER_DEPARTMENTS', 'divisions', id, 'departments', null, String(made));
  flash.set(req, 'success', made > 0 ? `${made} departments created for ${division.name}. Add their designations in Admin › Departments.` : 'Its starting departments already exist.');
  res.redirect(`/admin/divisions/${id}`);
}

/**
 * D-257 Phase 2: puts a staff member into a division from Admin › Divisions —
 * the way to staff a planned division before it is activated (the user's own
 * Division field only appears once two divisions are active).
 */
async function addStaff(req, res) {
  const id = parseInt(req.params.id, 10);
  const division = await divisionService.find(id);
  const staffId = parseInt(req.body.user_id || 0, 10);
  const staff = staffId > 0 ? await require('../repositories/userRepository').findById(staffId) : null;
  let error = null;
  if (!division) error = 'Division not found.';
  else if (!staff || Number(staff.is_active || 0) !== 1) error = 'Choose an active staff member.';
  else if (staff.division_id === null) error = 'This person works at company level (sees every division); their division is not changed here.';
  if (error !== null) {
    flash.set(req, 'error', error);
    res.redirect(`/admin/divisions/${id}`);
    return;
  }
  await divisionRepository.setUserDivision(staffId, id);
  await auditLogRepository.log(req.user.id, 'USER_DIVISION_SET', 'users', staffId, 'division_id', String(staff.division_id), String(id));
  flash.set(req, 'success', `${staff.name} now works in ${division.name}.`);
  res.redirect(`/admin/divisions/${id}`);
}

async function parseDivisionField(raw) {
  const value = String(raw === undefined || raw === null ? '' : raw);
  if (value === '') return { ok: true, id: null };
  const id = parseInt(value, 10);
  return { ok: !!(await divisionService.find(id)), id };
}

async function setUserDivision(req, res) {
  const userId = parseInt(req.params.id, 10);
  const parsed = await parseDivisionField(req.body.division_id);
  if (!(await divisionService.isMulti()) || !parsed.ok) {
    flash.set(req, 'error', 'Choose a division.');
    res.redirect(`/users/${userId}/edit`);
    return;
  }
  await divisionRepository.setUserDivision(userId, parsed.id);
  await auditLogRepository.log(req.user.id, 'USER_DIVISION_SET', 'users', userId, 'division_id', null, parsed.id === null ? 'company level' : String(parsed.id));
  flash.set(req, 'success', 'Division saved.');
  res.redirect(`/users/${userId}/edit`);
}

async function setDepartmentDivision(req, res) {
  const departmentId = parseInt(req.params.id, 10);
  const parsed = await parseDivisionField(req.body.division_id);
  if (!(await divisionService.isMulti()) || !parsed.ok) {
    flash.set(req, 'error', 'Choose a division.');
    res.redirect('/admin/departments');
    return;
  }
  await divisionRepository.setDepartmentDivision(departmentId, parsed.id);
  await auditLogRepository.log(req.user.id, 'DEPARTMENT_DIVISION_SET', 'departments', departmentId, 'division_id', null, parsed.id === null ? 'company level' : String(parsed.id));
  flash.set(req, 'success', 'Division saved.');
  res.redirect('/admin/departments');
}

module.exports = { SETTING_SCOPES, choose, index, show, numberFormat, saveModules, saveSetting, deleteSetting, activate, deactivate, setUserDivision, setDepartmentDivision, starterDepartments, addStaff };
