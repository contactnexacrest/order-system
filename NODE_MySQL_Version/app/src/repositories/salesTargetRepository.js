'use strict';

const db = require('../config/db');

/**
 * Owner decision D-378 (15 Nov 2026): sales targets — the SQL of the feature.
 *
 * Reads order data only; writes only its own tables (sales_target_settings,
 * sales_target_company, sales_targets, sales_target_credits) plus
 * orders.sales_credit_user_id (the MD / ED moving an order's target credit).
 * Every query is MySQL 5.7 safe: no CTE, no window function, no GROUP BY
 * that ONLY_FULL_GROUP_BY would refuse. The rules (periods, confirmation,
 * corrections, build-up, alerts) live in salesTargetService.
 *
 * Maps keep the row order of the query (PHP's keyed arrays keep it too).
 *
 * Port of App\Repositories\SalesTargetRepository.
 */

/** Distinct positive integer ids, in first-seen order (PHP array_values(array_unique(array_filter(array_map('intval', …))))). */
function cleanIds(ids) {
  const out = [];
  for (const v of ids || []) {
    const n = parseInt(v, 10);
    if (Number.isFinite(n) && n !== 0 && !out.includes(n)) out.push(n);
  }
  return out;
}

/** ":i0, :i1, …" placeholders and their params for an IN list. */
function inList(ids, prefix = 'i') {
  const params = {};
  const marks = ids.map((id, i) => {
    params[`${prefix}${i}`] = id;
    return `:${prefix}${i}`;
  }).join(', ');
  return { marks, params };
}

function chunk(list, size) {
  const out = [];
  for (let i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
  return out;
}

// ---------------------------------------------------------------- settings

/** @returns {Promise<Object<string, ?string>>} setting_key => value (rows that exist) */
async function settings() {
  const out = {};
  for (const r of await db.query('SELECT setting_key, setting_value FROM sales_target_settings')) {
    out[String(r.setting_key)] = r.setting_value !== null ? String(r.setting_value) : null;
  }
  return out;
}

async function saveSetting(key, value, userId) {
  await db.execute(
    `INSERT INTO sales_target_settings (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)`,
    { k: key, v: value, u: userId }
  );
}

// ---------------------------------------------------------------- company target

/** The company target row that applies on `date` (greatest effective_from on or before it), or null. */
async function companyRowFor(date) {
  return db.queryOne(
    'SELECT * FROM sales_target_company WHERE effective_from <= :d ORDER BY effective_from DESC, id DESC LIMIT 1',
    { d: date }
  );
}

/** The latest company target row entered (for the settings form), or null. */
async function latestCompanyRow() {
  return db.queryOne('SELECT * FROM sales_target_company ORDER BY effective_from DESC, id DESC LIMIT 1');
}

async function insertCompanyRow(effectiveFrom, costs, profit, reason, userId) {
  const r = await db.execute(
    'INSERT INTO sales_target_company (effective_from, costs_monthly, profit_monthly, reason, created_by) VALUES (:f, :c, :p, :r, :u)',
    { f: effectiveFrom, c: costs, p: profit, r: reason, u: userId }
  );
  return Number(r.insertId);
}

// ---------------------------------------------------------------- targets (designation / user / associate)

async function target(scope, refId) {
  return db.queryOne('SELECT * FROM sales_targets WHERE scope = :s AND ref_id = :r', { s: scope, r: refId });
}

/** @returns {Promise<Map<number, object>>} ref_id => row, for one scope */
async function targetsByScope(scope) {
  const out = new Map();
  for (const r of await db.query('SELECT * FROM sales_targets WHERE scope = :s', { s: scope })) {
    out.set(Number(r.ref_id), r);
  }
  return out;
}

const TARGET_COLUMNS = ['containers', 'new_buyers', 'quotations', 'multiple', 'buildup_enabled', 'reason', 'reviewed_at'];

/**
 * Inserts or updates the row of (scope, ref_id) with the given columns
 * (only containers, new_buyers, quotations, multiple, buildup_enabled, reason, reviewed_at).
 */
async function saveTarget(scope, refId, fields, userId) {
  const cols = TARGET_COLUMNS.filter((c) => Object.prototype.hasOwnProperty.call(fields, c));
  const params = { s: scope, r: refId, u: userId };
  for (const c of cols) params[`f_${c}`] = fields[c] === undefined ? null : fields[c];
  const insertCols = ['scope', 'ref_id', 'updated_by'].concat(cols).join(', ');
  const insertVals = [':s', ':r', ':u'].concat(cols.map((c) => `:f_${c}`)).join(', ');
  const updates = ['updated_by = VALUES(updated_by)'].concat(cols.map((c) => `${c} = VALUES(${c})`)).join(', ');
  await db.execute(`INSERT INTO sales_targets (${insertCols}) VALUES (${insertVals}) ON DUPLICATE KEY UPDATE ${updates}`, params);
}

// ---------------------------------------------------------------- people

/**
 * The person's default active department membership designation (id and title), as
 * incentiveService.designationEligibility() picks it, or null.
 */
async function designationFor(userId) {
  const r = await db.queryOne(
    `SELECT d.id, d.title FROM user_department_memberships m
       JOIN department_designations d ON d.id = m.designation_id
      WHERE m.user_id = :u AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())
      ORDER BY m.is_default DESC, m.id LIMIT 1`,
    { u: userId }
  );
  return r ? { id: Number(r.id), title: String(r.title) } : null;
}

/** Active designations with their department, Sales & Export first. */
async function designations() {
  return db.query(
    `SELECT dd.id, dd.title, d.name AS department_name, d.code AS department_code
       FROM department_designations dd JOIN departments d ON d.id = dd.department_id
      WHERE dd.is_active = 1 AND d.is_active = 1
      ORDER BY (d.code = 'SALES') DESC, d.sort_order, dd.sort_order, dd.title`
  );
}

/** @returns {Promise<Array<{id:number, name:string}>>} active staff, by name */
async function activeStaff() {
  return (await db.query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name, id'))
    .map((r) => ({ id: Number(r.id), name: String(r.name) }));
}

/** @returns {Promise<number[]>} active current members of the Sales & Export department */
async function salesMembers() {
  return (await db.query(
    `SELECT DISTINCT m.user_id FROM user_department_memberships m
       JOIN department_designations dd ON dd.id = m.designation_id
       JOIN departments d ON d.id = dd.department_id
       JOIN users u ON u.id = m.user_id
      WHERE d.code = 'SALES' AND u.is_active = 1
        AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())`
  )).map((r) => Number(r.user_id));
}

/** @returns {Promise<number[]>} active users holding one of these roles, as their own role or through a current membership (sorted) */
async function usersWithRoles(roleNames) {
  if (!roleNames || roleNames.length === 0) return [];
  const a = inList(roleNames, 'ra');
  const b = inList(roleNames, 'rb');
  const rows = await db.query(
    `SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.name IN (${a.marks})
     UNION
     SELECT m.user_id FROM user_department_memberships m JOIN department_designations dd ON dd.id = m.designation_id
       JOIN roles r ON r.id = dd.role_id JOIN users u ON u.id = m.user_id
      WHERE u.is_active = 1 AND r.name IN (${b.marks}) AND m.starts_on <= CURDATE() AND (m.ends_on IS NULL OR m.ends_on >= CURDATE())`,
    { ...a.params, ...b.params }
  );
  const ids = [...new Set(rows.map((r) => Number(r.id)))];
  ids.sort((x, y) => x - y);
  return ids;
}

/** @returns {Promise<Map<number, object>>} id => users row (id, name, is_active, joined_on) */
async function usersByIds(ids) {
  const clean = cleanIds(ids);
  const out = new Map();
  if (clean.length === 0) return out;
  const { marks, params } = inList(clean);
  for (const r of await db.query(`SELECT id, name, is_active, joined_on FROM users WHERE id IN (${marks})`, params)) {
    out.set(Number(r.id), r);
  }
  return out;
}

// ---------------------------------------------------------------- order facts read by sync()

/**
 * Every real order (no test or sample data) with the facts sync() needs: the advance receipts,
 * the LC receipt, the buyer PO and PI approval, the lost / cancelled date.
 */
async function orderFacts() {
  return db.query(
    `SELECT o.id, o.client_id, o.status, o.advance_pct, o.buyer_po_received_on,
            DATE(o.frozen_at) AS frozen_on, DATE(o.lost_at) AS lost_on,
            (SELECT MIN(r.received_on) FROM order_payment_receipts r WHERE r.order_id = o.id AND r.leg = 'advance') AS advance_receipt_on,
            DATE(ps.advance_remittance_received_at) AS advance_status_on,
            lc.lc_received_at AS lc_received_on,
            (SELECT DATE(MAX(c.decided_at)) FROM order_cancellations c WHERE c.order_id = o.id AND c.status = 'approved') AS cancelled_on
       FROM orders o
       LEFT JOIN order_payment_status ps ON ps.order_id = o.id
       LEFT JOIN order_lc_details lc ON lc.order_id = o.id
      WHERE COALESCE(o.is_test_data, 0) = 0 AND COALESCE(o.is_sample_data, 0) = 0
      ORDER BY o.id`
  );
}

/** The latest Commercial Invoice that is not a draft or superseded, or null. */
async function latestIssuedCi(orderId) {
  return db.queryOne(
    `SELECT d.* FROM documents d JOIN document_types dt ON dt.id = d.document_type_id
      WHERE d.order_id = :o AND dt.code = 'CI' AND d.status NOT IN ('draft', 'superseded')
      ORDER BY d.revision_number DESC, d.id DESC LIMIT 1`,
    { o: orderId }
  );
}

async function shippingContainerCount(orderId) {
  const r = await db.queryOne('SELECT container_count FROM order_shipping WHERE order_id = :o', { o: orderId });
  return r === null || r.container_count === null || r.container_count === undefined ? null : parseInt(r.container_count, 10);
}

// ---------------------------------------------------------------- credits

/** @returns {Promise<Map<number, object>>} order_id => credit row, by confirmed_on then order id */
async function credits() {
  const out = new Map();
  for (const r of await db.query('SELECT * FROM sales_target_credits ORDER BY confirmed_on, order_id')) {
    out.set(Number(r.order_id), r);
  }
  return out;
}

async function insertCredit(row) {
  await db.execute(
    `INSERT INTO sales_target_credits (order_id, client_id, confirmed_on, how, containers_confirmed, shipped_on, containers_shipped, reversed_on, reversed_why)
     VALUES (:order_id, :client_id, :confirmed_on, :how, :containers_confirmed, :shipped_on, :containers_shipped, :reversed_on, :reversed_why)`,
    {
      order_id: row.order_id, client_id: row.client_id, confirmed_on: row.confirmed_on,
      how: row.how, containers_confirmed: row.containers_confirmed,
      shipped_on: row.shipped_on ?? null, containers_shipped: row.containers_shipped ?? null,
      reversed_on: row.reversed_on ?? null, reversed_why: row.reversed_why ?? null,
    }
  );
}

async function markShipped(orderId, shippedOn, containers) {
  await db.execute(
    'UPDATE sales_target_credits SET shipped_on = :d, containers_shipped = :n WHERE order_id = :o AND shipped_on IS NULL',
    { d: shippedOn, n: containers, o: orderId }
  );
}

async function markReversed(orderId, reversedOn, why) {
  await db.execute(
    'UPDATE sales_target_credits SET reversed_on = :d, reversed_why = :w WHERE order_id = :o AND reversed_on IS NULL',
    { d: reversedOn, w: why, o: orderId }
  );
}

/** New buyer = the first credit row (by confirmed_on, then order id) of each client; every other row is not. */
async function refreshNewBuyers() {
  const first = new Map();
  for (const r of await db.query('SELECT order_id, client_id FROM sales_target_credits ORDER BY confirmed_on, order_id')) {
    const client = Number(r.client_id);
    if (!first.has(client)) first.set(client, Number(r.order_id));
  }
  await db.execute('UPDATE sales_target_credits SET is_new_buyer = 0 WHERE is_new_buyer = 1');
  for (const part of chunk([...first.values()], 500)) {
    const { marks, params } = inList(part);
    await db.execute(`UPDATE sales_target_credits SET is_new_buyer = 1 WHERE order_id IN (${marks})`, params);
  }
}

/**
 * Who gets the target credit of each order: orders.sales_credit_user_id when the MD / ED moved it,
 * else the order's Sales & Export department owner (the same owner
 * departmentTaskRepository.ownersForOrder() shows, read for many orders at once), else null.
 *
 * @returns {Promise<Map<number, ?number>>} order id => user id or null (Unassigned)
 */
async function creditUsers(orderIds) {
  const out = new Map();
  for (const part of chunk(cleanIds(orderIds), 500)) {
    const { marks, params } = inList(part);
    const rows = await db.query(
      `SELECT o.id, o.sales_credit_user_id,
              (SELECT odo.user_id FROM order_department_owners odo JOIN departments d ON d.id = odo.department_id
                WHERE odo.order_id = o.id AND d.code = 'SALES' AND d.is_active = 1 ORDER BY odo.user_id LIMIT 1) AS sales_owner_id
         FROM orders o WHERE o.id IN (${marks})`,
      params
    );
    for (const r of rows) {
      const uid = r.sales_credit_user_id !== null ? Number(r.sales_credit_user_id) : (r.sales_owner_id !== null ? Number(r.sales_owner_id) : null);
      out.set(Number(r.id), uid);
    }
  }
  return out;
}

async function setCreditUser(orderId, userId) {
  await db.execute('UPDATE orders SET sales_credit_user_id = :u WHERE id = :o', { u: userId, o: orderId });
}

/** @returns {Promise<Map<number, {id, order_reference, client_id, company_legal_name, currency_code, status}>>} */
async function ordersByIds(ids) {
  const out = new Map();
  for (const part of chunk(cleanIds(ids), 500)) {
    const { marks, params } = inList(part);
    for (const r of await db.query(`SELECT id, order_reference, client_id, company_legal_name, currency_code, status FROM orders WHERE id IN (${marks})`, params)) {
      out.set(Number(r.id), r);
    }
  }
  return out;
}

/** @returns {Promise<Map<number, number>>} order id => the order's goods FOB value (order currency) */
async function fobValues(ids) {
  const out = new Map();
  for (const part of chunk(cleanIds(ids), 500)) {
    const { marks, params } = inList(part);
    for (const r of await db.query(`SELECT order_id, SUM(fob_value) AS fob FROM order_products WHERE is_active = 1 AND order_id IN (${marks}) GROUP BY order_id`, params)) {
      out.set(Number(r.order_id), Number(r.fob));
    }
  }
  return out;
}

/**
 * Pipeline candidates: active real orders with the PI approved (frozen_at) or a Quotation e-mailed,
 * and no credit row yet (not confirmed).
 */
async function pipelineOrders() {
  return db.query(
    `SELECT o.id, o.order_reference, o.client_id, o.company_legal_name, o.currency_code
       FROM orders o
      WHERE o.status = 'active' AND COALESCE(o.is_test_data, 0) = 0 AND COALESCE(o.is_sample_data, 0) = 0
        AND NOT EXISTS (SELECT 1 FROM sales_target_credits c WHERE c.order_id = o.id)
        AND (o.frozen_at IS NOT NULL OR EXISTS (
              SELECT 1 FROM email_log el JOIN documents d ON d.id = el.document_id JOIN document_types dt ON dt.id = d.document_type_id
               WHERE d.order_id = o.id AND dt.code = 'QT' AND el.status = 'sent'))
      ORDER BY o.id`
  );
}

/**
 * Quotations e-mailed in [from, to], per order (distinct QT documents).
 *
 * @returns {Promise<Map<number, number>>} order id => quotations sent in the range
 */
async function quotationsSent(from, to) {
  const out = new Map();
  const rows = await db.query(
    `SELECT d.order_id, COUNT(DISTINCT el.document_id) AS n
       FROM email_log el JOIN documents d ON d.id = el.document_id JOIN document_types dt ON dt.id = d.document_type_id
       JOIN orders o ON o.id = d.order_id
      WHERE dt.code = 'QT' AND el.status = 'sent' AND DATE(el.sent_at) >= :f AND DATE(el.sent_at) <= :t
        AND COALESCE(o.is_test_data, 0) = 0 AND COALESCE(o.is_sample_data, 0) = 0
      GROUP BY d.order_id`,
    { f: from, t: to }
  );
  for (const r of rows) out.set(Number(r.order_id), Number(r.n));
  return out;
}

// ---------------------------------------------------------------- alerts

/** compliance_alerts_sent dedupe, as complianceTrackingService.once(): true the first time only. */
async function once(kind, ref, threshold, today) {
  const r = await db.execute(
    'INSERT IGNORE INTO compliance_alerts_sent (alert_kind, ref_key, threshold, sent_on) VALUES (:k, :r, :t, :d)',
    { k: kind, r: ref, t: threshold, d: today }
  );
  return (r.affectedRows || 0) > 0;
}

module.exports = {
  settings, saveSetting,
  companyRowFor, latestCompanyRow, insertCompanyRow,
  target, targetsByScope, saveTarget,
  designationFor, designations, activeStaff, salesMembers, usersWithRoles, usersByIds,
  orderFacts, latestIssuedCi, shippingContainerCount,
  credits, insertCredit, markShipped, markReversed, refreshNewBuyers, creditUsers, setCreditUser,
  ordersByIds, fobValues, pipelineOrders, quotationsSent,
  once,
};
