'use strict';

const db = require('../config/db');

/**
 * Per-order status against the compliance_task_types list (docs/schema.sql
 * Section AR). A missing row for a given order+task_type pair means
 * not_started — rows are only written the first time staff actually touch
 * that task on that order. Visibility and every write action here is gated
 * on the existing close_orders permission (checked in the controller),
 * not a new one — "the person who has permission to close the order must
 * able to see this otherwise no meaning for this."
 */
const STATUSES = {
  not_started: 'Not Started',
  pending_approval: 'Pending Approval',
  approved: 'Approved',
  skipped: 'Skipped (Not Applicable)',
};

/** @return one row per active task type, LEFT JOINed against this order's own status */
async function forOrder(orderId) {
  return db.query(
    `SELECT
        tt.id AS task_type_id,
        tt.name AS task_type_name,
        COALESCE(oct.status, 'not_started') AS status,
        oct.skip_reason,
        oct.resolved_at,
        u.name AS resolved_by_name
     FROM compliance_task_types tt
     LEFT JOIN order_compliance_tasks oct
        ON oct.task_type_id = tt.id AND oct.order_id = :order_id
     LEFT JOIN users u ON u.id = oct.resolved_by
     WHERE tt.is_active = 1
     ORDER BY tt.name`,
    { order_id: orderId }
  );
}

async function setStatus(orderId, taskTypeId, status, skipReason, userId) {
  await db.execute(
    `INSERT INTO order_compliance_tasks (order_id, task_type_id, status, skip_reason, resolved_at, resolved_by)
     VALUES (:order_id, :task_type_id, :status, :skip_reason, NOW(), :resolved_by)
     ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        skip_reason = VALUES(skip_reason),
        resolved_at = VALUES(resolved_at),
        resolved_by = VALUES(resolved_by)`,
    { order_id: orderId, task_type_id: taskTypeId, status, skip_reason: skipReason, resolved_by: userId }
  );
}

/** @return {total, approved, outstanding} outstanding = not_started + pending_approval (skipped counts as resolved, same as approved) */
async function summaryForOrder(orderId) {
  const rows = await forOrder(orderId);
  const total = rows.length;
  let approved = 0;
  for (const r of rows) {
    if (r.status === 'approved' || r.status === 'skipped') {
      approved += 1;
    }
  }
  return { total, approved, outstanding: total - approved };
}

module.exports = { STATUSES, forOrder, setStatus, summaryForOrder };
