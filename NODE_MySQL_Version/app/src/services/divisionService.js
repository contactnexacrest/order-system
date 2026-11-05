'use strict';

const db = require('../config/db');
const requestContext = require('../helpers/requestContext');

/**
 * Owner decision D-257 (5 Nov 2026): divisions. One company, several
 * divisions (Export today; Domestic Trade, Merchant Trade and Import &
 * Distribution later). Every order belongs to one division for life; every
 * staff member works in one division and sees only it; company-level people
 * (permission see_all_divisions) see every division, or one they pick.
 *
 * Divisions stay invisible until two or more are ACTIVE: with one active
 * division nobody sees a switcher, a Division filter or a Division field.
 *
 * This module is the one place that decides "which divisions does this
 * request see". sessionAuth works the scope out once per request and keeps
 * it in the request context ({ divisionIds }), so every repository reads it
 * without a `req`. No signed-in staff request (cron, CLI, client portal) =
 * no filter. Port of PHP App\Services\DivisionService.
 */

const EXPORT_ID = 1;
const SESSION_CHOICE = '_division_choice';

/** Module registry: key => [label, URL path patterns that belong to the module]. */
const MODULES = {
  // QA ACC-11: every module names its own addresses, the order-scoped ones included
  // (checked again in the order's own division once that is known — sessionAuth).
  sales_documents: ['Quotation, PI and order confirmation', [/^\/orders\/\d+\/(?:oc-acknowledgment|oc-sent-to-buyer|pi-form|pi-form-link)(?:\/|$)/]],
  supplier_purchase: ['Supplier purchase and RFQ', [/^\/procurement(?:\/|$)/, /^\/orders\/\d+\/(?:supplier-po|purchase-requirements)(?:\/|$)/]],
  export_shipping: ['Export shipping (BL, shipping bill, CHA, COO)', [/^\/orders\/\d+\/(?:shipping|shipping-bill|draft-bl|bl-copy|bl-endorsed|bl-endorsement|bl-issued|bl-originals-received|scanned-bl-sent|packing)(?:\/|$)/]],
  lut_igst: ['LUT / IGST on export and GST refunds', [/^\/ca\/gst-refunds(?:\/|$)/]],
  export_benefits: ['Export benefits (RoDTEP, drawback)', [/^\/ca\/export-benefits(?:\/|$)/, /^\/orders\/\d+\/(?:export-benefits|export-benefit-schemes)(?:\/|$)/]],
  firc_fema: ['FIRC / eBRC / FEMA', [/^\/reports\/missing-ebrc(?:\/|$)/]],
  ecgc: ['ECGC', [/^\/orders\/\d+\/ecgc-declaration(?:\/|$)/]],
  associates: ['Associates, price requests and pricing', [/^\/associates(?:\/|$)/, /^\/reports\/associates(?:\/|$)/]],
  team_incentive: ['Team incentive and CAFIN', [/^\/incentives(?:\/|$)/, /^\/my-incentives(?:\/|$)/, /^\/reports\/incentives(?:\/|$)/, /^\/orders\/\d+\/incentive(?:\/|$)/]],
  // D-257 Phase 3: imports, landed cost and stock — on for Import & Distribution only.
  import_stock: ['Import, stock and landed cost', [/^\/import(?:\/|$)/]],
  // D-257 Phase 4: merchant trade deals and their RBI checks — on for Merchant Trade only.
  merchant_trade: ['Merchant trade', [/^\/merchant(?:\/|$)/]],
};

/** @returns {Promise<object[]>} every division, in order */
async function all() {
  return db.query('SELECT * FROM divisions ORDER BY sort_order, id');
}

async function active() {
  return (await all()).filter((d) => d.status === 'active');
}

async function find(id) {
  return (await all()).find((d) => Number(d.id) === Number(id)) || null;
}

/** Divisions become visible (switcher, filters, fields) only when two or more are active. */
async function isMulti() {
  return (await active()).length >= 2;
}

/** The division a person works in (company-level people: Export until they choose). */
function userDivisionId(user) {
  return Number((user && user.division_id) || EXPORT_ID) || EXPORT_ID;
}

/**
 * Works out the request's scope once (sessionAuth): { ids, seesAll, multi }.
 * ids null = every division (no filter).
 */
async function resolveScope(user, permissions, session) {
  const seesAll = !!(permissions && permissions.see_all_divisions);
  const multi = await isMulti();
  let ids;
  if (seesAll) {
    const choice = (session && session[SESSION_CHOICE]) || 'all';
    ids = null;
    if (choice !== 'all' && multi) {
      const d = await find(parseInt(choice, 10));
      ids = d && d.status === 'active' ? [Number(d.id)] : null;
    }
  } else {
    ids = [userDivisionId(user)];
  }
  return { ids, seesAll, multi, user };
}

/** @returns {number[]|null} the request's division ids (null = no filter) */
function currentIds() {
  const ctx = requestContext.get();
  if (!ctx) return null;
  if (ctx.forcedDivision) return [Number(ctx.forcedDivision)];
  return ctx.divisionIds === undefined ? null : ctx.divisionIds;
}

/** The single division this request works in, or null when it sees all. */
function currentId() {
  const ids = currentIds();
  return ids !== null && ids.length === 1 ? ids[0] : null;
}

/** "col IN (…)" for the current scope, or null when nothing needs filtering. */
function condition(column = 'o.division_id') {
  const ids = currentIds();
  if (ids === null) return null;
  return `${column} IN (${ids.map((i) => parseInt(i, 10)).join(',')})`;
}

/** " AND col IN (…)" or "" — ids are integers, safe to inline. */
function sql(column = 'o.division_id') {
  const c = condition(column);
  return c === null ? '' : ` AND ${c}`;
}

/** " AND col IN (orders of the current scope)" for a table with an order_id; $allowNull keeps company-level rows. */
function orderIdSql(column, allowNull = false) {
  const c = condition('dv_o.division_id');
  if (c === null) return '';
  const inSql = `${column} IN (SELECT dv_o.id FROM orders dv_o WHERE ${c})`;
  return allowNull ? ` AND (${column} IS NULL OR ${inSql})` : ` AND ${inSql}`;
}

/** " AND …" limiting `clients alias` to the current scope (an unlinked client is Export's). */
function clientSql(alias = 'c') {
  const ids = currentIds();
  if (ids === null) return '';
  const inList = ids.map((i) => parseInt(i, 10)).join(',');
  let s = ` AND (EXISTS (SELECT 1 FROM client_divisions cdv WHERE cdv.client_id = ${alias}.id AND cdv.division_id IN (${inList}))`;
  if (ids.includes(EXPORT_ID)) s += ` OR NOT EXISTS (SELECT 1 FROM client_divisions cdv2 WHERE cdv2.client_id = ${alias}.id)`;
  return `${s})`;
}

/** Whether the current scope includes this division. */
function allows(divisionId) {
  const ids = currentIds();
  return ids === null || ids.includes(Number(divisionId));
}

async function allowsClient(clientId) {
  const ids = currentIds();
  if (ids === null) return true;
  const linked = (await db.query('SELECT division_id FROM client_divisions WHERE client_id = :c', { c: clientId })).map((r) => Number(r.division_id));
  if (linked.length === 0) return ids.includes(EXPORT_ID);
  return linked.some((d) => ids.includes(d));
}

async function allowsOrderId(orderId) {
  const ids = currentIds();
  if (ids === null || orderId === null || orderId === undefined) return true;
  const row = await db.queryOne('SELECT division_id FROM orders WHERE id = :id', { id: orderId });
  return !row || ids.includes(Number(row.division_id));
}

/** A checker for many order ids at once (one query). */
async function orderIdChecker() {
  const c = condition('division_id');
  if (c === null) return () => true;
  const outside = new Set((await db.query(`SELECT id FROM orders WHERE NOT (${c})`)).map((r) => Number(r.id)));
  return (orderId) => orderId === null || orderId === undefined || !outside.has(Number(orderId));
}

/** Links a client to a division (a client may buy from several). An unlinked client is Export's, so keep that link too. */
async function linkClient(clientId, divisionId, executor = db) {
  if (Number(divisionId) !== EXPORT_ID) {
    const has = await executor.queryOne('SELECT 1 AS x FROM client_divisions WHERE client_id = :c LIMIT 1', { c: clientId });
    if (!has) {
      const exp = await executor.queryOne('SELECT 1 AS x FROM orders WHERE client_id = :c AND division_id = :d LIMIT 1', { c: clientId, d: EXPORT_ID });
      if (exp) await executor.execute('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)', { c: clientId, d: EXPORT_ID });
    }
  }
  await executor.execute('INSERT IGNORE INTO client_divisions (client_id, division_id) VALUES (:c, :d)', { c: clientId, d: divisionId });
}

function seesAll() {
  const ctx = requestContext.get();
  return !!(ctx && ctx.divisionSeesAll);
}

/**
 * The division a new record (order, client, associate) belongs to: the
 * person's own; for company-level people the one they chose (validated),
 * else the one they picked in the switcher, else (one active) Export's.
 */
async function forNewRecord(chosen = null) {
  const ctx = requestContext.get();
  if (!ctx || !ctx.userId) return EXPORT_ID;
  if (!ctx.divisionSeesAll) return userDivisionId(ctx.divisionUser);
  if (chosen !== null && chosen !== undefined) {
    const d = await find(chosen);
    if (d && d.status === 'active') return Number(d.id);
  }
  const current = currentId();
  if (current !== null) return current;
  const act = await active();
  return act.length === 1 ? Number(act[0].id) : userDivisionId(ctx.divisionUser);
}

/** Whether a new-order form must ask which division (company level, several active, "All" chosen). */
async function needsChoiceOnCreate() {
  return seesAll() && currentId() === null && (await isMulti());
}

/** Runs fn with the scope forced to one division (documents, numbering for an order). */
function withDivision(divisionId, fn) {
  const store = Object.assign({}, requestContext.get() || {}, { forcedDivision: Number(divisionId), divisionSettingsCache: null });
  return requestContext.run(store, fn);
}

async function choose(session, choice) {
  if (choice === 'all') {
    session[SESSION_CHOICE] = 'all';
    return;
  }
  const d = await find(parseInt(choice, 10));
  if (d && d.status === 'active') session[SESSION_CHOICE] = String(Number(d.id));
}

/** A division's own setting values (scope: company | pricing | incentive), only while the request works in one division. */
async function settingOverrides(scope) {
  const id = currentId();
  if (id === null) return {};
  const ctx = requestContext.get();
  const cacheKey = `${scope}:${id}`;
  if (ctx) {
    if (!ctx.divisionSettingsCache) ctx.divisionSettingsCache = {};
    if (ctx.divisionSettingsCache[cacheKey]) return ctx.divisionSettingsCache[cacheKey];
  }
  const out = {};
  for (const row of await db.query('SELECT setting_key, setting_value FROM division_settings WHERE division_id = :d AND scope = :s', { d: id, s: scope })) {
    out[String(row.setting_key)] = String(row.setting_value === null ? '' : row.setting_value);
  }
  if (ctx) ctx.divisionSettingsCache[cacheKey] = out;
  return out;
}

async function overlay(scope, values) {
  const over = await settingOverrides(scope);
  return Object.keys(over).length === 0 ? values : Object.assign({}, values, over);
}

async function overrideFor(scope, key) {
  const over = await settingOverrides(scope);
  return Object.prototype.hasOwnProperty.call(over, key) ? over[key] : null;
}

async function moduleEnabledFor(divisionId, moduleKey) {
  const row = await db.queryOne('SELECT is_enabled FROM division_modules WHERE division_id = :d AND module_key = :k', { d: divisionId, k: moduleKey });
  return row ? Number(row.is_enabled) === 1 : true; // a module with no row is on
}

/** Whether a module is on for the current scope ("All" = on in any active division). */
async function moduleEnabled(moduleKey) {
  const ids = currentIds() || (await active()).map((d) => Number(d.id));
  for (const id of ids) {
    if (await moduleEnabledFor(id, moduleKey)) return true;
  }
  return false;
}

/** The module a URL belongs to, when that module is switched off for the current scope; else null. */
async function disabledModuleForPath(path) {
  for (const [key, [label, patterns]] of Object.entries(MODULES)) {
    for (const pattern of patterns) {
      if (pattern.test(path) && !(await moduleEnabled(key))) return label;
    }
  }
  return null;
}

module.exports = {
  EXPORT_ID, SESSION_CHOICE, MODULES,
  all, active, find, isMulti, userDivisionId, resolveScope, currentIds, currentId, condition, sql, orderIdSql, clientSql,
  allows, allowsClient, allowsOrderId, orderIdChecker, linkClient, seesAll, forNewRecord, needsChoiceOnCreate, withDivision, choose,
  settingOverrides, overlay, overrideFor, moduleEnabledFor, moduleEnabled, disabledModuleForPath,
};
