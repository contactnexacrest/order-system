'use strict';

const requestContext = require('../helpers/requestContext');
const reasonValidator = require('../helpers/reasonValidator');
const reportAccessRepository = require('../repositories/reportAccessRepository');
const moneyVisibility = require('./moneyVisibility');

// Required lazily: these load repositories that reach back into the services.
const auditLogRepository = () => require('../repositories/auditLogRepository');
const permissionRepository = () => require('../repositories/permissionRepository');
const departmentRepository = () => require('../repositories/departmentRepository');
const superAdminService = () => require('./superAdminService');
const divisionService = () => require('./divisionService');
const orderAccessService = () => require('./orderAccessService');

/**
 * Owner decision D-379 (15 Nov 2026): report access per role.
 *
 * One registry of reports (REPORTS, the same keys in both stacks). Each role
 * has a level per report — off / own / department / all — kept in
 * role_report_access (no row = off); a person can get a higher level for one
 * report (user_report_extras, with a reason). The effective level of a
 * person is:
 *
 *   - an effective Super Admin: all, for every report (not while previewing a designation);
 *   - else the highest level over the role they are acting as and every role of a current
 *     department membership (as sopAccessService.roleIdsFor), raised by their extra when that
 *     is higher;
 *   - capped by the report's base permission(s): a level is only worth something with the
 *     permission(s) of the old gate, and money permissions are never bypassed
 *     (missingPermissions()). Without them the level is off.
 *
 * Levels limit rows through scopeSql():
 *   own        orders where the viewer is an assignee (order_assignees), a department owner
 *              (order_department_owners) or the salesperson credited (orders.sales_credit_user_id);
 *   department own, plus orders where any assignee or department owner is a current member of a
 *              department the viewer currently belongs to;
 *   all        every order (division filter, archived rule and test-data flag as before).
 * Reports without order rows use peopleScope() (Staff productivity, Audit log, Incentives) or
 * departmentCodes() (Cockpit).
 *
 * "The signed-in person" is the staff request in the request context (helpers/requestContext:
 * sessionAuth puts the user, the role they act as, the preview flag and their permissions there);
 * the per-request caches PHP keeps in statics live on that context, so they last one request.
 * Outside a request (scripts, tests) a module cache is used; resetCache() clears it.
 *
 * PHP twin: PHP_MySQL_Version/app/src/Services/ReportAccess.php (same registry, keys, defaults,
 * wording and rules).
 */

const OFF = 'off';
const OWN = 'own';
const DEPARTMENT = 'department';
const ALL = 'all';

/** In rank order. */
const LEVELS = [OFF, OWN, DEPARTMENT, ALL];

const LEVEL_LABELS = { [OFF]: 'Off', [OWN]: 'Own', [DEPARTMENT]: 'Department', [ALL]: 'All' };

/** The 7 headings of the Reports page and of the Reports tab, in order. */
const SECTIONS = {
  sales: 'Sales & orders',
  payments: 'Payments & receivables',
  disputes: 'Disputes & amendments',
  freight: 'Freight, products & suppliers',
  people: 'People',
  finance: 'Finance & CA',
  management: 'Management',
};

const BANNER_OWN = 'Showing your own business — orders you are assigned to or own, your clients and your own figures.';
const BANNER_DEPARTMENT = "Showing your department's orders";
const REFUSED = 'This report is not open to you. Ask whoever manages report access (Users & roles › Roles and permissions › Reports) if you need it.';

/**
 * The registry. Per report:
 *  label, section, href (the button on the Reports page; null = drawn on the page itself),
 *  all  = permissions all needed (the old route gate), any = at least one of these,
 *  money = moneyVisibility classes all needed, orders = rows are orders (scopeSql),
 *  all_level_needs = extra permissions only the All level needs (Sales targets),
 *  page_min = the lowest level that opens a page (Sales targets: Own is the Dashboard box only),
 *  description = the line under the button.
 */
const REPORTS = {
  order_search: { label: 'Find an order', section: 'sales', href: null, all: ['view_reports'], any: [], money: [], orders: true,
    description: "Search by order reference or client name and open that order's Full Report." },
  aggregate: { label: 'Aggregate report', section: 'sales', href: '/reports/aggregate', all: ['view_reports'], any: [], money: ['selling'], orders: true,
    description: 'Filter orders by date range, stage, Incoterm or country; save the filters as a report.' },
  per_client: { label: 'Per-client report', section: 'sales', href: null, all: ['view_reports'], any: [], money: ['selling', 'buyer_payments'], orders: true,
    description: 'One client: orders, documents, payments and products.' },
  performance: { label: 'Sales performance', section: 'sales', href: '/reports/performance', all: ['view_reports'], any: [], money: ['selling'], orders: true,
    description: 'Quotations and PIs sent, won and lost, FOB value won, compared with the period before.' },
  trends: { label: 'Trends', section: 'sales', href: '/reports/trends', all: ['view_reports'], any: [], money: ['selling'], orders: true,
    description: 'Orders created, quotations and PIs sent, lost and FOB value, month over month.' },
  conversion: { label: 'Conversion rate', section: 'sales', href: '/reports/conversion', all: ['view_reports'], any: [], money: [], orders: true,
    description: 'The quotation and PI funnel as percentages.' },
  product_sales: { label: 'Product sales', section: 'sales', href: '/reports/products', all: ['view_reports'], any: [], money: ['selling'], orders: true,
    description: 'Sales by product and HS code.' },
  payments: { label: 'Payments', section: 'payments', href: '/reports/payments', all: ['view_reports'], any: [], money: ['buyer_payments'], orders: true,
    description: 'Collected and outstanding by currency — advance, balance and freight.' },
  todays_payments: { label: "Today's payments", section: 'payments', href: '/reports/todays-payments', all: ['view_reports'], any: [], money: ['buyer_payments'], orders: true,
    description: 'What cleared or was paid on one day, incoming and outgoing.' },
  ageing: { label: 'Debtors / receivables ageing', section: 'payments', href: '/reports/ageing', all: ['view_reports'], any: [], money: ['buyer_payments'], orders: true,
    description: 'Every outstanding leg by how overdue it is.' },
  missing_ebrc: { label: 'Missing eBRC / FIRC', section: 'payments', href: '/reports/missing-ebrc', all: [], any: ['ca_module_view', 'manage_export_benefit_claims', 'manage_order_financials'], money: [], orders: true,
    description: 'Buyer payments received with no FIRC / eBRC reference yet.' },
  disputes: { label: 'Disputes', section: 'disputes', href: '/reports/disputes', all: ['view_reports'], any: [], money: [], orders: true,
    description: 'Disputes by status and age.' },
  amendments: { label: 'Amendments', section: 'disputes', href: '/reports/amendments', all: ['view_reports'], any: [], money: ['selling'], orders: true,
    description: 'Amendments by status and who asked for them.' },
  freight_cost: { label: 'Freight cost', section: 'freight', href: '/reports/freight-cost', all: ['view_logistics_costs'], any: [], money: [], orders: true,
    description: 'Freight cost by forwarder.' },
  supplier_performance: { label: 'Supplier performance', section: 'freight', href: '/reports/suppliers', all: ['view_purchase_prices'], any: [], money: [], orders: true,
    description: 'Supplier PO signing and delivery on time.' },
  staff_productivity: { label: 'Staff productivity', section: 'people', href: '/reports/staff', all: ['view_staff_reports'], any: [], money: [], orders: false,
    description: 'Documents generated and audit-log activity per person.' },
  incentives: { label: 'Incentives & commission', section: 'people', href: '/reports/incentives', all: [], any: ['manage_incentives', 'pay_incentives', 'view_price_buildup'], money: [], orders: false,
    description: "Staff shares and associate commission per order — register, to pay, owed at month end and one person's statement." },
  sales_targets: { label: 'Sales targets', section: 'people', href: '/sales-targets', all: [], any: [], money: [], orders: false,
    all_level_needs: ['manage_sales_targets'], page_min: DEPARTMENT,
    description: 'Targets and progress per person, in containers.' },
  profitability: { label: 'Order profitability', section: 'finance', href: '/reports/order-profitability', all: ['manage_order_financials'], any: [], money: [], orders: true,
    description: 'Revenue against every direct cost, per order, for any period.' },
  cafin: { label: 'CAFIN export', section: 'finance', href: '/ca/cafin-export', all: ['view_cafin'], any: [], money: [], orders: true,
    description: 'The CA Financial Annexure of a financial year as CSV. Internal only — never for the client.' },
  ca_reports: { label: 'CA reports', section: 'finance', href: '/ca', all: ['ca_module_view'], any: [], money: [], orders: true,
    description: 'The CA / Accounting module: settlement register, revenue report, export benefits and the company-level CA pages.' },
  cockpit: { label: 'Management cockpit', section: 'management', href: '/cockpit', all: ['view_cockpit'], any: [], money: [], orders: false,
    description: 'Open work per department, escalations and supplier delays.' },
  queues: { label: 'Operations queues', section: 'management', href: '/reports/queues', all: ['view_reports'], any: [], money: [], orders: true,
    description: 'How many orders wait in each queue now, and the funnel for a date range.' },
  audit_log: { label: 'Audit log', section: 'management', href: '/audit-log', all: ['view_audit_log'], any: [], money: [], orders: false,
    description: 'Who did what, when — read-only.' },
};

const REPORT_KEYS = Object.keys(REPORTS);

/** The four role names the "MD" column of the defaults covers. */
const MD_ROLE_NAMES = ['Managing Director', 'Founder & Managing Director', 'Executive Director', 'Founder & Executive Director'];

/**
 * Spec D-379 §4 — the seeded default level per role (docs/seed.sql, migration 2026-11-15b).
 * Key 'MD' = every name in MD_ROLE_NAMES. A role not listed, or a report not listed for a role: off.
 */
const DEFAULTS = {
  MD: { order_search: 'all', aggregate: 'all', per_client: 'all', performance: 'all', trends: 'all', conversion: 'all', product_sales: 'all', payments: 'all', todays_payments: 'all', ageing: 'all', missing_ebrc: 'all', disputes: 'all', amendments: 'all', freight_cost: 'all', supplier_performance: 'all', staff_productivity: 'all', incentives: 'all', sales_targets: 'all', profitability: 'all', cafin: 'all', ca_reports: 'all', cockpit: 'all', queues: 'all', audit_log: 'all' },
  Admin: { order_search: 'all', cockpit: 'all', queues: 'all', audit_log: 'all' },
  'System Administrator': { audit_log: 'all' },
  'Sales Director': { order_search: 'all', aggregate: 'all', per_client: 'all', performance: 'all', trends: 'all', conversion: 'all', product_sales: 'all', payments: 'all', todays_payments: 'all', ageing: 'all', disputes: 'all', amendments: 'all', staff_productivity: 'all', sales_targets: 'department', cockpit: 'all', queues: 'all' },
  'Sales/Export Manager': { order_search: 'department', aggregate: 'department', per_client: 'department', performance: 'department', trends: 'department', conversion: 'department', product_sales: 'department', payments: 'department', todays_payments: 'department', ageing: 'department', disputes: 'department', amendments: 'department', staff_productivity: 'department', sales_targets: 'own', queues: 'department' },
  'Export Executive': { order_search: 'own', aggregate: 'own', per_client: 'own', performance: 'own', trends: 'own', conversion: 'own', product_sales: 'own', payments: 'own', todays_payments: 'own', ageing: 'own', disputes: 'own', amendments: 'own', sales_targets: 'own' },
  'Finance Director': { order_search: 'all', aggregate: 'all', per_client: 'all', performance: 'all', trends: 'all', conversion: 'all', product_sales: 'all', payments: 'all', todays_payments: 'all', ageing: 'all', missing_ebrc: 'all', disputes: 'all', amendments: 'all', freight_cost: 'all', supplier_performance: 'all', staff_productivity: 'all', incentives: 'all', profitability: 'all', cafin: 'all', ca_reports: 'all', cockpit: 'all', queues: 'all' },
  'Finance Manager': { order_search: 'all', aggregate: 'all', per_client: 'all', performance: 'all', trends: 'all', conversion: 'all', product_sales: 'all', payments: 'all', todays_payments: 'all', ageing: 'all', missing_ebrc: 'all', disputes: 'all', amendments: 'all', freight_cost: 'all', supplier_performance: 'all', incentives: 'all', profitability: 'all', cafin: 'all', ca_reports: 'all' },
  'Accounts Executive': { order_search: 'all', aggregate: 'all', per_client: 'all', payments: 'all', todays_payments: 'all', ageing: 'all', missing_ebrc: 'all', freight_cost: 'all', profitability: 'all', ca_reports: 'all' },
  'CA / Chartered Accountant': { order_search: 'all', aggregate: 'all', per_client: 'all', payments: 'all', todays_payments: 'all', ageing: 'all', missing_ebrc: 'all', freight_cost: 'all', profitability: 'all', cafin: 'all', ca_reports: 'all' },
  'Procurement Director': { order_search: 'department', supplier_performance: 'all', staff_productivity: 'department', cockpit: 'all', queues: 'all' },
  'Procurement Manager': { order_search: 'department', supplier_performance: 'department' },
  'Procurement Executive': { order_search: 'own', supplier_performance: 'own' },
  'Logistics Director': { order_search: 'department', disputes: 'department', freight_cost: 'all', staff_productivity: 'department', cockpit: 'all', queues: 'all' },
  'Logistics Manager': { order_search: 'department', disputes: 'department', freight_cost: 'department' },
  'Logistics Executive': { order_search: 'own', disputes: 'own', freight_cost: 'own' },
  'Export Compliance Director': { missing_ebrc: 'all', staff_productivity: 'department', cockpit: 'all', queues: 'all' },
  'Export Compliance Manager': { missing_ebrc: 'all' },
  'Export Compliance Executive': { missing_ebrc: 'all' },
  'Order Coordinator': { order_search: 'all', disputes: 'all', cockpit: 'all', queues: 'all' },
  'Operations Manager': { order_search: 'all', disputes: 'all', cockpit: 'all', queues: 'all' },
  'Viewer / Auditor': { order_search: 'all', aggregate: 'all', per_client: 'all', performance: 'all', trends: 'all', conversion: 'all', product_sales: 'all', payments: 'all', todays_payments: 'all', ageing: 'all', missing_ebrc: 'all', disputes: 'all', amendments: 'all', freight_cost: 'all', supplier_performance: 'all', profitability: 'all', ca_reports: 'all', audit_log: 'all' },
  'Investor (view only)': { performance: 'all', trends: 'all', conversion: 'all', cockpit: 'all', queues: 'all' },
};

/** Plain-word names of the permissions the Reports tab may say are missing. */
const PERMISSION_WORDS = {
  view_selling_prices: 'selling prices',
  view_buyer_payments: 'buyer payments',
  view_purchase_prices: 'buying prices',
  view_logistics_costs: 'logistics costs',
};

// ================================================================ caches (one request)

function newCache() {
  return {
    roleLevels: new Map(), // role id => { key: level }
    extras: new Map(), // user id => { key: extra row }
    departments: new Map(), // user id => department ids
    members: new Map(), // "1,2" => member user ids
    rolePermissions: new Map(), // role id => Set of permission keys (preview only)
    userPermissions: new Map(), // "user|role" => permissions object (someone other than the signed-in person)
    levelCache: new Map(), // "user|role|key" => effective level
    roleIdsCache: new Map(), // "user|role|acting" => the roles that count
    superCache: new Map(), // user id => effective Super Admin
  };
}

let moduleCache = newCache();
let modulePreviewRoleId = null;
let paramSeq = 0;

/** The cache of this request (the request context), or the module cache outside a request. */
function cache() {
  const ctx = requestContext.get();
  if (ctx) {
    if (!ctx.reportAccessCache) ctx.reportAccessCache = newCache();
    return ctx.reportAccessCache;
  }
  return moduleCache;
}

function resetCache() {
  moduleCache = newCache();
  const ctx = requestContext.get();
  if (ctx) ctx.reportAccessCache = newCache();
}

// ================================================================ registry helpers

function isReport(key) {
  return Object.prototype.hasOwnProperty.call(REPORTS, String(key));
}

function isLevel(level) {
  return LEVELS.includes(level);
}

function rank(level) {
  const i = LEVELS.indexOf(level);
  return i < 0 ? 0 : i;
}

function higher(a, b) {
  return rank(a) >= rank(b) ? a : b;
}

/** The lowest level that opens the report's page (Sales targets: Department; Own is its Dashboard box). */
function pageMin(key) {
  return (REPORTS[key] && REPORTS[key].page_min) || OWN;
}

/** @returns {Object<string, string[]>} section key => report keys, in registry order */
function bySection() {
  const out = {};
  for (const s of Object.keys(SECTIONS)) out[s] = [];
  for (const key of REPORT_KEYS) out[REPORTS[key].section].push(key);
  return out;
}

/** @returns {Object<string,string>} report key => default level for this role name (off for anything not listed) */
function defaultsForRoleName(roleName) {
  const group = MD_ROLE_NAMES.includes(roleName) ? 'MD' : roleName;
  const set = Object.prototype.hasOwnProperty.call(DEFAULTS, group) ? DEFAULTS[group] : {};
  const out = {};
  for (const key of REPORT_KEYS) out[key] = String(set[key] || OFF);
  return out;
}

// ================================================================ the signed-in person

/** @returns {{id: number, roleId: number|null}|null} the signed-in staff person of this request (role_id = the role they act as) */
function currentUser() {
  const ctx = requestContext.get();
  if (!ctx || !ctx.userId) return null;
  const roleId = ctx.effectiveRoleId !== null && ctx.effectiveRoleId !== undefined ? Number(ctx.effectiveRoleId) : null;
  return { id: Number(ctx.userId), roleId };
}

function isCurrent(userId) {
  const ctx = requestContext.get();
  return !!(ctx && ctx.userId && Number(ctx.userId) === Number(userId));
}

/** Whether the signed-in person is previewing a designation (a preview never carries Super Admin rights). */
function isPreviewing(userId) {
  return isCurrent(userId) && !!requestContext.get().previewing;
}

/** The role the person acts as (only the signed-in person can act as another role). */
function effectiveRoleId(userId, roleId) {
  if (isCurrent(userId)) {
    const ctx = requestContext.get();
    if (ctx.effectiveRoleId !== undefined) return ctx.effectiveRoleId !== null ? Number(ctx.effectiveRoleId) : null;
  }
  return roleId !== null && roleId !== undefined ? Number(roleId) : null;
}

async function isSuperAdmin(userId) {
  const c = cache();
  const id = Number(userId);
  if (!c.superCache.has(id)) {
    const ctx = requestContext.get();
    const known = isCurrent(id) && ctx.isSuperAdmin !== undefined ? !!ctx.isSuperAdmin : null;
    c.superCache.set(id, known !== null ? known : await superAdminService().isEffective(id));
  }
  return c.superCache.get(id);
}

/** PermissionService::can() for this person: the request's permissions for the signed-in person, else their role's and own. */
async function permissionsOf(userId, roleId) {
  const ctx = requestContext.get();
  if (isCurrent(userId) && ctx.permissions) return ctx.permissions;
  const c = cache();
  const k = `${userId}|${roleId ?? '-'}`;
  if (!c.userPermissions.has(k)) {
    let perms = await permissionRepository().effectivePermissions(userId, roleId, isPreviewing(userId));
    if (!isPreviewing(userId) && (await isSuperAdmin(userId))) {
      perms = new Proxy({}, { get: () => true });
    }
    c.userPermissions.set(k, perms);
  }
  return c.userPermissions.get(k);
}

async function userCan(userId, roleId, perm) {
  return !!(await permissionsOf(userId, roleId))[perm];
}

// ================================================================ levels

/** @returns {Promise<Object<string,string>>} every report key => this role's stored level (no row = off) */
async function roleLevels(roleId) {
  const c = cache();
  const id = Number(roleId);
  if (!c.roleLevels.has(id)) {
    const stored = await reportAccessRepository.levelsForRole(id);
    const out = {};
    for (const key of REPORT_KEYS) out[key] = stored[key] !== undefined && isLevel(stored[key]) ? stored[key] : OFF;
    c.roleLevels.set(id, out);
  }
  return c.roleLevels.get(id);
}

async function roleLevel(roleId, key) {
  return (await roleLevels(roleId))[key] || OFF;
}

/** @returns {Promise<Object<string, object>>} report key => extra row (level, reason, created_by, created_by_name, created_at) */
async function userExtras(userId) {
  const c = cache();
  const id = Number(userId);
  if (!c.extras.has(id)) c.extras.set(id, await reportAccessRepository.extrasForUser(id));
  return c.extras.get(id);
}

/**
 * The roles whose levels count for this person: the role they act as and every role of a
 * current department membership — only the previewed one during a designation preview.
 *
 * @returns {Promise<number[]>}
 */
async function roleIdsFor(userId, roleId) {
  const previewing = isPreviewing(userId);
  const acting = effectiveRoleId(userId, roleId);
  const k = `${userId}|${roleId ?? '-'}|${acting ?? '-'}${previewing ? '|preview' : ''}`;
  const c = cache();
  if (!c.roleIdsCache.has(k)) {
    const ids = previewing ? [] : await roleIdsForAdmin(userId, roleId);
    if (acting !== null) ids.push(acting);
    c.roleIdsCache.set(k, [...new Set(ids.map(Number))]);
  }
  return c.roleIdsCache.get(k);
}

/** The level from the roles and the person's extra, before the base-permission ceiling. */
async function rawLevelFor(userId, roleId, key) {
  let level = OFF;
  for (const rid of await roleIdsFor(userId, roleId)) {
    level = higher(await roleLevel(rid, key), level);
  }
  if (!isPreviewing(userId)) {
    const extra = (await userExtras(userId))[key];
    if (extra && isLevel(String(extra.level))) level = higher(String(extra.level), level);
  }
  return level;
}

/**
 * The permissions a level of this report needs that `can` does not give (empty = none missing).
 * "any" permissions come back as one entry "a or b or c".
 *
 * @param {function(string): boolean} can
 * @returns {string[]}
 */
function missingPermissions(can, key, level = OWN) {
  const r = REPORTS[key];
  if (!r) return [];
  const missing = [];
  for (const perm of r.all) {
    if (!can(perm)) missing.push(perm);
  }
  if (r.any.length > 0 && !r.any.some((perm) => can(perm))) missing.push(r.any.join(' or '));
  for (const cls of r.money) {
    const perm = moneyVisibility.PERMISSIONS[cls];
    if (perm && !can(perm)) missing.push(perm);
  }
  if (level === ALL) {
    for (const perm of r.all_level_needs || []) {
      if (!can(perm)) missing.push(perm);
    }
  }
  return missing;
}

/** "needs: selling prices" wording for the Reports tab (money in plain words, other keys as they are). */
function needsText(key) {
  const r = REPORTS[key];
  const words = [];
  for (const perm of r.all) words.push(PERMISSION_WORDS[perm] || perm);
  if (r.any.length > 0) words.push(r.any.join(' or '));
  for (const cls of r.money) {
    const perm = moneyVisibility.PERMISSIONS[cls];
    words.push(PERMISSION_WORDS[perm] || perm);
  }
  if (r.all_level_needs && r.all_level_needs.length > 0) words.push(`All: ${r.all_level_needs.join(', ')}`);
  return words.length === 0 ? '' : `needs: ${words.join(', ')}`;
}

/** Applies the base-permission ceiling to a raw level. */
function capped(can, key, level) {
  if (level === OFF) return OFF;
  if (missingPermissions(can, key, OWN).length > 0) return OFF;
  if (level === ALL && missingPermissions(can, key, ALL).length > 0) {
    return DEPARTMENT; // Sales targets: All without manage_sales_targets is the read-only team view
  }
  return level;
}

/** The effective level of a person for one report. */
async function effectiveLevelFor(userId, roleId, key) {
  if (!isReport(key)) return OFF;
  const c = cache();
  const k = `${userId}|${roleId ?? '-'}|${key}`;
  if (c.levelCache.has(k)) return c.levelCache.get(k);
  if (!isPreviewing(userId) && (await isSuperAdmin(userId))) {
    c.levelCache.set(k, ALL);
    return ALL;
  }
  const perms = await permissionsOf(userId, roleId);
  const level = capped((perm) => !!perms[perm], key, await rawLevelFor(userId, roleId, key));
  c.levelCache.set(k, level);
  return level;
}

/** The effective level of a role alone (the Reports tab and "See Reports as this role"): its stored level and its own permissions. */
async function effectiveLevelForRole(roleId, key) {
  const c = cache();
  const id = Number(roleId);
  if (!c.rolePermissions.has(id)) c.rolePermissions.set(id, new Set(await reportAccessRepository.rolePermissionKeys(id)));
  const perms = c.rolePermissions.get(id);
  return capped((perm) => perms.has(perm), key, await roleLevel(id, key));
}

/** "See Reports as this role": the Reports page asks for this role's levels until cleared (null). One request. */
function previewAsRole(roleId) {
  const ctx = requestContext.get();
  const value = roleId === null || roleId === undefined ? null : Number(roleId);
  if (ctx) ctx.reportPreviewRoleId = value;
  else modulePreviewRoleId = value;
}

function previewRoleId() {
  const ctx = requestContext.get();
  if (ctx) return ctx.reportPreviewRoleId ?? null;
  return modulePreviewRoleId;
}

/** The signed-in person's effective level (or the previewed role's). */
async function level(key) {
  const preview = previewRoleId();
  if (preview !== null) return effectiveLevelForRole(preview, key);
  const user = currentUser();
  if (user === null) return OFF;
  return effectiveLevelFor(user.id, user.roleId, key);
}

/** Whether the signed-in person's level for the report reaches min. */
async function can(key, min = OWN) {
  return rank(await level(key)) >= rank(min);
}

/** Whether the report's page opens for the signed-in person (Sales targets needs Department). */
async function canOpenPage(key) {
  return can(key, pageMin(key));
}

/** @returns {Promise<Object<string,string>>} every report key => the signed-in person's effective level */
async function levels() {
  const out = {};
  for (const key of REPORT_KEYS) out[key] = await level(key);
  return out;
}

/** At least one report page opens (the gate of /reports). */
async function anyOpen() {
  for (const key of REPORT_KEYS) {
    if (await canOpenPage(key)) return true;
  }
  return false;
}

/** The same answer as anyOpen(), from levels() already worked out (permittedLinks, the menu). */
function anyOpenIn(levelsByKey) {
  return REPORT_KEYS.some((key) => rank(levelsByKey[key] || OFF) >= rank(pageMin(key)));
}

/** The same answer as can(), from levels() already worked out. */
function canIn(levelsByKey, key, min = null) {
  return rank((levelsByKey || {})[key] || OFF) >= rank(min || pageMin(key));
}

/**
 * The facts the menu config asks about (navigation.json "when": {"report": key, "reportMin": level}):
 * "report:<key>" = the page opens, "report:<key>:<level>" = the level reaches it, "report:any".
 *
 * @param {Object<string,string>} levelsByKey levels() already worked out (sessionAuth); navFlags() works them out
 * @returns {Object<string,boolean>}
 */
function navFlagsFor(levelsByKey) {
  const flags = { 'report:any': false };
  for (const key of REPORT_KEYS) {
    const lvl = levelsByKey[key] || OFF;
    flags[`report:${key}`] = rank(lvl) >= rank(pageMin(key));
    for (const min of [OWN, DEPARTMENT, ALL]) flags[`report:${key}:${min}`] = rank(lvl) >= rank(min);
    flags['report:any'] = flags['report:any'] || flags[`report:${key}`];
  }
  return flags;
}

async function navFlags() {
  return navFlagsFor(await levels());
}

/** The Reports page banner: own business when every open order report is Own, the department's when the highest is Department. */
async function banner() {
  let highest = null;
  let allOwn = true;
  for (const key of REPORT_KEYS) {
    if (!REPORTS[key].orders || !(await canOpenPage(key))) continue;
    const lvl = await level(key);
    highest = highest === null ? lvl : higher(lvl, highest);
    allOwn = allOwn && lvl === OWN;
  }
  if (highest === null) return null;
  if (allOwn) return BANNER_OWN;
  return highest === DEPARTMENT ? BANNER_DEPARTMENT : null;
}

// ================================================================ scope

/** @returns {Promise<number[]>} the departments the person currently belongs to */
async function departmentIds(userId) {
  const c = cache();
  const id = Number(userId);
  if (!c.departments.has(id)) c.departments.set(id, await reportAccessRepository.currentDepartmentIds(id));
  return c.departments.get(id);
}

/** @returns {Promise<string[]>} the codes of those departments (the Cockpit's department rows) */
async function departmentCodes(userId) {
  return reportAccessRepository.departmentCodes(await departmentIds(userId));
}

/** @returns {Promise<number[]>} every current member of the person's departments, the person included */
async function departmentMemberIds(userId) {
  const ids = await departmentIds(userId);
  const k = ids.join(',');
  const c = cache();
  if (!c.members.has(k)) c.members.set(k, ids.length === 0 ? [] : await reportAccessRepository.currentMemberIds(ids));
  const out = c.members.get(k).slice();
  out.push(Number(userId));
  return [...new Set(out.map(Number))];
}

/**
 * The condition limiting `orders <orderAlias>` to the rows this report shows the signed-in person.
 * Nothing when nobody is signed in (cron, scripts). A level of off — a page the gate already
 * refuses — keeps the order-visibility rule used before D-379 (orderAccessService).
 *
 * @returns {Promise<{sql: string, params: Object<string, number>}>} sql is " AND …" or ""
 */
async function scopeSql(report, orderAlias = 'o') {
  const user = currentUser();
  if (user === null) return { sql: '', params: {} };
  return scopeSqlFor(user.id, user.roleId, report, orderAlias);
}

/** @returns {Promise<{sql: string, params: Object<string, number>}>} */
async function scopeSqlFor(userId, roleId, report, orderAlias = 'o') {
  const a = orderAlias;
  const lvl = await effectiveLevelFor(userId, roleId, report);
  if (lvl === OFF) {
    return orderAccessService().visibilitySql(userId, await permissionsOf(userId, roleId), a);
  }
  // As before D-379: the request's division(s), archived orders only with view_archived_orders.
  let sql = divisionService().sql(`${a}.division_id`);
  if (!(await userCan(userId, roleId, 'view_archived_orders'))) sql += ` AND ${a}.is_archived = 0`;
  if (lvl === ALL) return { sql, params: {} };
  paramSeq += 1;
  const p = `ra${paramSeq}_`;
  const conds = [
    `${a}.id IN (SELECT ${p}oa.order_id FROM order_assignees ${p}oa WHERE ${p}oa.user_id = :${p}u1)`,
    `${a}.id IN (SELECT ${p}odo.order_id FROM order_department_owners ${p}odo WHERE ${p}odo.user_id = :${p}u2)`,
    `${a}.sales_credit_user_id = :${p}u3`,
  ];
  const id = Number(userId);
  const params = { [`${p}u1`]: id, [`${p}u2`]: id, [`${p}u3`]: id };
  if (lvl === DEPARTMENT) {
    const members = await departmentMemberIds(userId);
    if (members.length > 1) {
      const inList = members.map((m) => parseInt(m, 10)).join(','); // integers, safe to inline
      conds.push(`${a}.id IN (SELECT ${p}doa.order_id FROM order_assignees ${p}doa WHERE ${p}doa.user_id IN (${inList}))`);
      conds.push(`${a}.id IN (SELECT ${p}dodo.order_id FROM order_department_owners ${p}dodo WHERE ${p}dodo.user_id IN (${inList}))`);
    }
  }
  return { sql: `${sql} AND (${conds.join(' OR ')})`, params };
}

/** @returns {Promise<number[]>} those of the orders this report shows the signed-in person */
async function orderIdsInScope(report, orderIds) {
  const ids = [...new Set(orderIds.map((v) => parseInt(v, 10)).filter((v) => Number.isInteger(v)))];
  if (ids.length === 0 || currentUser() === null) return ids;
  const scope = await scopeSql(report, 'o');
  return reportAccessRepository.orderIdsWhere(ids, scope.sql, scope.params);
}

/** @returns {Promise<object[]>} the rows whose order this report shows the signed-in person */
async function visibleRows(report, rows, orderIdKey = 'order_id') {
  const ids = [];
  for (const r of rows) {
    if (r[orderIdKey] !== undefined && r[orderIdKey] !== null) ids.push(Number(r[orderIdKey]));
  }
  const keep = new Set(await orderIdsInScope(report, ids));
  return rows.filter((r) => r[orderIdKey] !== undefined && r[orderIdKey] !== null && keep.has(Number(r[orderIdKey])));
}

/** @returns {Promise<number[]|null>} the clients of the orders in scope; null = every client (All, or nobody signed in) */
async function clientIdsInScope(report) {
  const user = currentUser();
  const lvl = await level(report);
  if (user === null || lvl === ALL || lvl === OFF) return null;
  const scope = await scopeSql(report, 'o');
  return reportAccessRepository.clientIdsWhere(scope.sql, scope.params);
}

/** @returns {Promise<number[]|null>} the people whose rows a people report shows; null = everybody */
async function peopleScope(report) {
  const user = currentUser();
  if (user === null) return null;
  const lvl = await level(report);
  if (lvl === OWN) return [user.id];
  if (lvl === DEPARTMENT) return departmentMemberIds(user.id);
  return null;
}

// ================================================================ changes (all audited)

/**
 * Saves a role's levels; one REPORT_ACCESS_CHANGED audit row per report whose level changed.
 *
 * @param {Object<string,string>} levelsByKey report key => level (unknown keys and levels are ignored)
 * @returns {Promise<string[]>} the report keys changed
 */
async function saveRoleLevels(roleId, levelsByKey, byUserId, reason) {
  const old = await roleLevels(roleId);
  const changed = [];
  for (const [rawKey, rawLevel] of Object.entries(levelsByKey || {})) {
    const key = String(rawKey);
    const lvl = String(rawLevel);
    if (!isReport(key) || !isLevel(lvl) || old[key] === lvl) continue;
    await reportAccessRepository.setLevel(roleId, key, lvl, byUserId);
    await auditLogRepository().log(byUserId, 'REPORT_ACCESS_CHANGED', 'roles', roleId, key, old[key], lvl, reason);
    changed.push(key);
  }
  const c = cache();
  c.roleLevels.delete(Number(roleId));
  c.levelCache.clear();
  return changed;
}

/** Reset to defaults: the role gets the seeded levels of its name (off for a role not in the defaults). */
async function resetRole(roleId, roleName, byUserId, reason) {
  return saveRoleLevels(roleId, defaultsForRoleName(roleName), byUserId, reason);
}

/**
 * Extra report access for one person: only a level above what their roles give.
 *
 * @returns {Promise<string|null>} the error, or null when saved
 */
async function addUserExtra(userId, roleId, key, lvl, reason, byUserId) {
  if (!isReport(key)) return 'Choose a report.';
  if (![OWN, DEPARTMENT, ALL].includes(lvl)) return 'Choose a level.';
  const error = reasonValidator.check(reason);
  if (error) return error;
  let roleGives = OFF;
  for (const rid of await roleIdsForAdmin(userId, roleId)) {
    roleGives = higher(await roleLevel(rid, key), roleGives);
  }
  if (rank(lvl) <= rank(roleGives)) {
    return `An extra can only raise the level: their roles already give ${LEVEL_LABELS[roleGives]} for ${REPORTS[key].label}.`;
  }
  const existing = (await userExtras(userId))[key];
  const old = existing ? String(existing.level) : null;
  const trimmed = String(reason).trim();
  await reportAccessRepository.saveExtra(userId, key, lvl, trimmed, byUserId);
  const c = cache();
  c.extras.delete(Number(userId));
  c.levelCache.clear();
  await auditLogRepository().log(byUserId, 'USER_REPORT_EXTRA_ADDED', 'users', userId, key, old, lvl, trimmed);
  return null;
}

async function removeUserExtra(userId, key, byUserId) {
  const existing = (await userExtras(userId))[key];
  if (!existing) return false;
  await reportAccessRepository.deleteExtra(userId, key);
  const c = cache();
  c.extras.delete(Number(userId));
  c.levelCache.clear();
  await auditLogRepository().log(byUserId, 'USER_REPORT_EXTRA_REMOVED', 'users', userId, key, String(existing.level), null);
  return true;
}

/**
 * The roles that count for a person seen from Edit user (not the editor's own session):
 * their own role and every role of a current department membership.
 *
 * @returns {Promise<number[]>}
 */
async function roleIdsForAdmin(userId, roleId) {
  const ids = (await departmentRepository().activeRoleIdsForUser(userId)).map(Number);
  if (roleId !== null && roleId !== undefined) ids.push(Number(roleId));
  return [...new Set(ids)];
}

/** What a person's roles give for each report (Edit user › Extra report access). @returns {Promise<Object<string,string>>} */
async function roleGivesFor(userId, roleId) {
  const rids = await roleIdsForAdmin(userId, roleId);
  const out = {};
  for (const key of REPORT_KEYS) {
    let lvl = OFF;
    for (const rid of rids) lvl = higher(await roleLevel(rid, key), lvl);
    out[key] = lvl;
  }
  return out;
}

module.exports = {
  OFF, OWN, DEPARTMENT, ALL, LEVELS, LEVEL_LABELS, SECTIONS, BANNER_OWN, BANNER_DEPARTMENT, REFUSED,
  REPORTS, MD_ROLE_NAMES, DEFAULTS,
  resetCache, isReport, isLevel, rank, higher, pageMin, bySection, defaultsForRoleName,
  roleLevels, roleLevel, userExtras, roleIdsFor, rawLevelFor, missingPermissions, needsText,
  effectiveLevelFor, effectiveLevelForRole, previewAsRole, previewRoleId, level, can, canOpenPage,
  levels, anyOpen, anyOpenIn, canIn, navFlags, navFlagsFor, banner,
  departmentIds, departmentCodes, departmentMemberIds, scopeSql, scopeSqlFor, orderIdsInScope,
  visibleRows, clientIdsInScope, peopleScope,
  saveRoleLevels, resetRole, addUserExtra, removeUserExtra, roleIdsForAdmin, roleGivesFor,
};
