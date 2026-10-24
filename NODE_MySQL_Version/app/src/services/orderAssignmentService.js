'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderRepository = require('../repositories/orderRepository');

/**
 * Order ownership / assignment visibility: changing orders.assigned_to,
 * the single staff "owner" of an order. Visibility-only — this never
 * touches the stage-gate or action-permission system (manage_payments,
 * clear_payments, manage_shipping, etc. are completely unaffected).
 * Reaching this service at all already means the caller holds
 * assign_order_owner (route-level requirePermission); this module
 * doesn't re-check it.
 */
async function reassign(orderId, newOwnerId, actingUserId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    throw new Error(`Order ${orderId} not found`);
  }

  const previousOwnerId = order.assigned_to !== null && order.assigned_to !== undefined ? Number(order.assigned_to) : null;
  if (previousOwnerId === newOwnerId) {
    // No-op reassignment (picked the current owner again) — nothing to
    // write, nothing to notify.
    return;
  }

  await orderRepository.setAssignedTo(orderId, newOwnerId);

  await auditLogRepository.log(
    actingUserId,
    'ORDER_OWNER_REASSIGNED',
    'orders',
    orderId,
    'assigned_to',
    previousOwnerId !== null ? String(previousOwnerId) : null,
    String(newOwnerId)
  );

  await notificationRepository.create(
    newOwnerId,
    null,
    'order_assigned',
    orderId,
    `You've been assigned as the owner of order ${order.order_reference}.`
  );
}

module.exports = { reassign };
