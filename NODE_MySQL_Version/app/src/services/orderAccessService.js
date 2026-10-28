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
  return null;
}

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

module.exports = { denialReason, orderIdForPath, denialReasonForPath, orderLinkChecker };
