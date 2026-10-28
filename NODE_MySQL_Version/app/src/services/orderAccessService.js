'use strict';

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

module.exports = { denialReason, orderIdForPath, denialReasonForPath };
