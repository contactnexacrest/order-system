'use strict';

const db = require('../config/db');
const orderAssigneeRepository = require('../repositories/orderAssigneeRepository');
const orderRepository = require('../repositories/orderRepository');
const documentRepository = require('../repositories/documentRepository');

/**
 * Who may see an order at all — the archived-order and order-assignment
 * rules the order page applies, in one place so every route that serves
 * something belonging to an order (its page, its files) uses the same
 * answer. Visibility only: it never decides who may act on a step.
 * Port of PHP's OrderAccessService.
 *
 * @returns {Promise<string|null>} why this user may not see the order, or null when they may
 */
async function denialReason(order, userId, permissions) {
  const perms = permissions || {};
  if (Number(order.is_archived) === 1 && !perms.view_archived_orders) {
    return 'This order has been archived. You need the "View archived orders" permission to open it.';
  }
  if (!perms.view_all_orders && !(await orderAssigneeRepository.isAssignedTo(order.id, userId))) {
    return 'This order is assigned to a different user. You can only open orders assigned to you.';
  }
  return null;
}

/**
 * QA Round 5, R5-24: the order a staff URL belongs to — every page and
 * action under /orders/:id/, the order's Full Report, and the document
 * routes (/documents/:id/...) — so sessionAuth can apply denialReason()
 * to all of them, GET and POST, not just the order page.
 *
 * @returns {Promise<number|null>}
 */
async function orderIdForPath(path) {
  let m = /^\/(?:orders|reports\/order)\/(\d+)(?:\/|$)/.exec(path);
  if (m) return parseInt(m[1], 10);
  m = /^\/documents\/(\d+)(?:\/|$)/.exec(path);
  if (m) {
    const document = await documentRepository.find(parseInt(m[1], 10));
    return document ? Number(document.order_id) : null;
  }
  // QA Round 6, R6-N08: routes keyed by a record that belongs to an order
  // (an amendment, dispute, complaint, queued email, PI-stage submission,
  // reorder request, document review or export benefit) follow the same
  // rule as the order itself.
  for (const [pattern, sql] of CHILD_ROUTES) {
    m = pattern.exec(path);
    if (m) {
      const row = await db.queryOne(sql, { id: parseInt(m[1], 10) });
      return row && row.order_id !== null && row.order_id !== undefined ? Number(row.order_id) : null;
    }
  }
  return null;
}

/** path pattern => query returning the owning order id (as order_id) */
const CHILD_ROUTES = [
  [/^\/amendments\/(\d+)(?:\/|$)/, 'SELECT order_id FROM amendments WHERE id = :id'],
  [/^\/disputes\/(\d+)(?:\/|$)/, 'SELECT order_id FROM disputes WHERE id = :id'],
  [/^\/complaints\/(\d+)(?:\/|$)/, 'SELECT order_id FROM order_complaints WHERE id = :id'],
  [/^\/email-log\/(\d+)(?:\/|$)/, 'SELECT order_id FROM email_log WHERE id = :id'],
  [/^\/pi-intake-review\/(\d+)(?:\/|$)/, 'SELECT order_id FROM pi_intake_submissions WHERE id = :id'],
  [/^\/reorder-requests\/(\d+)(?:\/|$)/, 'SELECT source_order_id AS order_id FROM order_reorder_requests WHERE id = :id'],
  [/^\/reviews\/(\d+)(?:\/|$)/, 'SELECT d.order_id FROM document_reviews r JOIN documents d ON d.id = r.document_id WHERE r.id = :id'],
  [/^\/ca\/export-benefits\/(\d+)(?:\/|$)/, 'SELECT order_id FROM ca_export_benefits WHERE id = :id'],
];

/** @returns {Promise<string|null>} why this user may not open the order this path belongs to */
async function denialReasonForPath(path, userId, permissions) {
  const orderId = await orderIdForPath(path);
  const order = orderId !== null ? await orderRepository.find(orderId) : null;
  return order ? denialReason(order, userId, permissions) : null;
}

/**
 * For list pages (the CA hub, reports, ...) deciding whether an order
 * reference is a link or plain text: returns canOpenOrder(id), the same
 * answer as /orders/:id would give — manage_orders or view_orders, plus
 * denialReason()'s archived and assignment rules. Two queries at most.
 */
async function orderLinkChecker(userId, permissions) {
  const perms = permissions || {};
  if (!perms.manage_orders && !perms.view_orders) return () => false;
  return orderVisibilityChecker(userId, perms);
}

/**
 * The archived and assignment rules alone (no page permission): whether a
 * record belonging to an order may appear in a list the user can open, e.g.
 * the Disputes and Complaints lists (QA Round 6, R6-N09).
 */
async function orderVisibilityChecker(userId, permissions) {
  const perms = permissions || {};
  const assigned = perms.view_all_orders
    ? null
    : new Set((await db.query('SELECT order_id FROM order_assignees WHERE user_id = :u', { u: userId })).map((r) => Number(r.order_id)));
  const archived = perms.view_archived_orders
    ? null
    : new Set((await db.query('SELECT id FROM orders WHERE is_archived = 1')).map((r) => Number(r.id)));
  return (orderId) => {
    const id = Number(orderId);
    if (archived && archived.has(id)) return false;
    return assigned === null || assigned.has(id);
  };
}

/** The rows whose order the user may see. */
async function visibleRows(rows, userId, permissions, orderIdKey = 'order_id') {
  const canSee = await orderVisibilityChecker(userId, permissions);
  return rows.filter((r) => canSee(r[orderIdKey]));
}

/**
 * The same rules as a SQL condition on an orders alias, for queries that
 * aggregate over orders (reports, R6-N01). Returns { sql, params }; the sql
 * starts with " AND " or is empty.
 */
function visibilitySql(userId, permissions, alias = 'o') {
  const perms = permissions || {};
  if (!userId) return { sql: '', params: {} };
  let sql = '';
  const params = {};
  if (!perms.view_all_orders) {
    sql += ` AND ${alias}.id IN (SELECT vis_oa.order_id FROM order_assignees vis_oa WHERE vis_oa.user_id = :vis_user_id)`;
    params.vis_user_id = userId;
  }
  if (!perms.view_archived_orders) sql += ` AND ${alias}.is_archived = 0`;
  return { sql, params };
}

module.exports = { denialReason, orderIdForPath, denialReasonForPath, orderLinkChecker, orderVisibilityChecker, visibleRows, visibilitySql };
