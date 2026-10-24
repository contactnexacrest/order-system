'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderAssigneeRepository = require('../repositories/orderAssigneeRepository');
const orderRepository = require('../repositories/orderRepository');

/**
 * Order ownership / assignment visibility: who's in orders.assigned_to's
 * successor, order_assignees (docs/migrations/2026-10-24c-order-multi-
 * assignee.sql). Visibility-only — this never touches the stage-gate or
 * action-permission system (manage_payments, clear_payments,
 * manage_shipping, etc. are completely unaffected). Reaching this
 * service at all already means the caller holds assign_order_owner
 * (route-level requirePermission); this module doesn't re-check it.
 */
async function addAssignee(orderId, userId, actingUserId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }

  const inserted = await orderAssigneeRepository.add(orderId, userId, actingUserId);
  if (!inserted) {
    // Already assigned — no-op, nothing to write or notify.
    return;
  }

  await auditLogRepository.log(actingUserId, 'ORDER_ASSIGNEE_ADDED', 'orders', orderId, 'assignee', null, String(userId));

  await notificationRepository.create(
    userId,
    null,
    'order_assigned',
    orderId,
    `You've been added as an assignee on order ${order.order_reference}.`
  );
}

async function removeAssignee(orderId, userId, actingUserId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }

  await orderAssigneeRepository.remove(orderId, userId);

  await auditLogRepository.log(actingUserId, 'ORDER_ASSIGNEE_REMOVED', 'orders', orderId, 'assignee', String(userId), null);
}

module.exports = { addAssignee, removeAssignee };
