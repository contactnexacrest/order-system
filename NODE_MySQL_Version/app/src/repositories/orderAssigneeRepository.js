'use strict';

const db = require('../config/db');

/**
 * Order ownership / assignment visibility (docs/migrations/2026-10-24c-
 * order-multi-assignee.sql). One row per (order, assigned staff member) —
 * a pure membership table, unlike documentReviewRepository's reviews (no
 * per-row status). An order can have 0, 1, or more assignees at once,
 * e.g. an Export Executive handling office work and a Logistics
 * Executive handling CHA/factory work on the SAME order concurrently.
 * See orderAssignmentService.js for the write path (audit log +
 * notification).
 */
async function forOrder(orderId) {
  return db.query(
    `SELECT oa.order_id, oa.user_id, oa.assigned_at, u.name, r.name AS role_name
     FROM order_assignees oa
     JOIN users u ON u.id = oa.user_id
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE oa.order_id = :order_id
     ORDER BY oa.assigned_at`,
    { order_id: orderId }
  );
}

async function isAssignedTo(orderId, userId, executor = db) {
  const row = await executor.queryOne(
    'SELECT 1 FROM order_assignees WHERE order_id = :order_id AND user_id = :user_id',
    { order_id: orderId, user_id: userId }
  );
  return !!row;
}

/**
 * Optional executor lets this join a caller's transaction (see
 * orderRepository.insertOrderRow(), QA-5 ORD-05) so a new order's first
 * assignee row rolls back together with the order itself on any failure.
 * @returns {Promise<boolean>} true if a new row was actually inserted (false = already assigned).
 */
async function add(orderId, userId, assignedBy, executor = db) {
  if (await isAssignedTo(orderId, userId, executor)) {
    return false;
  }
  await executor.execute(
    'INSERT INTO order_assignees (order_id, user_id, assigned_by) VALUES (:order_id, :user_id, :assigned_by)',
    { order_id: orderId, user_id: userId, assigned_by: assignedBy }
  );
  return true;
}

async function remove(orderId, userId) {
  await db.execute('DELETE FROM order_assignees WHERE order_id = :order_id AND user_id = :user_id', {
    order_id: orderId,
    user_id: userId,
  });
}

module.exports = { forOrder, isAssignedTo, add, remove };
