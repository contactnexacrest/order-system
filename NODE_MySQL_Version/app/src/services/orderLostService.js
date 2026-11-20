'use strict';

/**
 * "Mark as Lost" in one place (owner decision D-394, 20 Nov 2026): the order
 * page's Mark Lost button (ordersController.markLost) and the daily
 * quick-quote prospect expiry (prospectService.expireDue) use the same
 * refusal rules and the same write — status "lost" (the buyer sees "Did not
 * go ahead"), the order locked, its buyer / payment links ended, an
 * ORDER_MARKED_LOST audit row. Nothing is deleted. Port of
 * App\Services\OrderLostService.
 */

const orderRepository = require('../repositories/orderRepository');
const auditLogRepository = require('../repositories/auditLogRepository');
const orderShippingRepository = require('../repositories/orderShippingRepository');
const orderCancellationRepository = require('../repositories/orderCancellationRepository');
const orderLcDetailRepository = require('../repositories/orderLcDetailRepository');

/** Why this order may not be marked lost, or null when it may (the same rules, in the same order, as Mark Lost has always had). */
async function refusal(order) {
  const orderId = Number(order.id);
  if (order.status !== 'active') {
    return `Only an active order can be marked lost (this one is already "${order.status}").`;
  }
  // 90-item backlog Phase 6 Item 3 — once a real Bill of Lading exists, the goods have shipped.
  const shipping = await orderShippingRepository.find(orderId);
  if (shipping && shipping.bl_number) {
    return 'This order already has a Bill of Lading on file — the goods have shipped, so it can no longer be marked "lost". Use Close Order instead.';
  }
  // QA batch 2 (ORD-04): "lost" is the walk-away before any money.
  if (await orderCancellationRepository.findPendingForOrder(orderId)) {
    return 'This order has a cancellation request awaiting a decision — approve or reject it instead of marking the order lost.';
  }
  const lc = await orderLcDetailRepository.find(orderId);
  if ((await require('./partPaymentService').orderReceivedTotal(orderId)) > 0 || (lc && lc.lc_confirmed_at)) {
    return 'The buyer has already paid on this order, so it cannot be marked lost. Use Request Cancellation: a second person approves it and the refund is recorded.';
  }
  return null;
}

/** Marks the order lost (locks it), with its audit row. userId null = the system (the daily prospect expiry). category is saved only when given. */
async function markLost(orderId, reason, userId, category = null) {
  const order = await orderRepository.find(orderId);
  await orderRepository.markLost(orderId, reason, userId); // lost_by NULL = the system
  await auditLogRepository.log(userId, 'ORDER_MARKED_LOST', 'orders', orderId, 'status', String((order && order.status) || 'active'), 'lost', reason);
  if (category !== null && category !== undefined) {
    await orderRepository.setLostCategory(orderId, category);
    await auditLogRepository.log(userId, 'ORDER_LOST_CATEGORY_SET', 'orders', orderId, 'lost_category', null, category, reason);
  }
}

module.exports = { refusal, markLost };
