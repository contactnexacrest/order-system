'use strict';

const orderAssigneeRepository = require('../repositories/orderAssigneeRepository');

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

module.exports = { denialReason };
