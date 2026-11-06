'use strict';

const db = require('../config/db');
const dates = require('../helpers/dates');
const auditLogRepository = require('../repositories/auditLogRepository');
const notificationRepository = require('../repositories/notificationRepository');
const orderComplianceTaskRepository = require('../repositories/orderComplianceTaskRepository');
const orderPaymentStatusRepository = require('../repositories/orderPaymentStatusRepository');
const orderRepository = require('../repositories/orderRepository');
const permissionRepository = require('../repositories/permissionRepository');
const stageGateService = require('./stageGateService');

/**
 * Port of ShipmentGateService.php. Gap list answers (owner, 1 Nov 2026),
 * G-1 and G-3: what must be done before the Bill of Lading is recorded
 * (Step 10f) — a "before shipment" balance cleared, and a pre-shipment
 * inspection recorded as Passed. A manager (override_shipment_gates) can
 * release the step with a written reason; every release is kept.
 */

const INSPECTION_TASK = 'Pre-Shipment Inspection';
/** D-262: the fixed code of the inspection task (renaming the task never switches the gate off). */
const INSPECTION_TASK_KEY = 'pre_shipment_inspection';

/** A checklist row (orderComplianceTaskRepository.forOrder) that is the pre-shipment inspection. */
function isInspectionTask(task) {
  const key = task && task.task_key !== undefined ? task.task_key : null;
  return key === INSPECTION_TASK_KEY || ((key === null || key === undefined) && task && task.task_type_name === INSPECTION_TASK);
}

const MESSAGES = {
  balance: 'This order\'s balance is due before shipment: record and clear the balance (Steps 11c and 11d) before recording the Bill of Lading.',
  inspection: 'The pre-shipment inspection has not passed: record a Passed inspection (Step 8a) before recording the Bill of Lading, or mark the inspection not applicable on the compliance checklist.',
};

/** A "before shipment" order: its balance is paid before the goods leave (not LC). */
function isPreShipmentBalance(order) {
  return !!order && (order.balance_trigger_option || '') === 'A_BEFORE_SHIPMENT' && String(order.payment_method || '').toUpperCase() !== 'LC';
}

/** G-1: the balance can be recorded and cleared now, before Stage 8 — from the freight stage on. */
async function balanceOpenEarly(orderId) {
  const order = await orderRepository.find(orderId);
  if (!order || !isPreShipmentBalance(order)) {
    return false;
  }
  return (await stageGateService.isReachedOrPassed(orderId, 6)) || stageGateService.isReachedOrPassed(orderId, 7);
}

async function inspectionApplies(orderId) {
  for (const task of await orderComplianceTaskRepository.forOrder(orderId)) {
    if (isInspectionTask(task) && task.status !== 'skipped') {
      return true;
    }
  }
  return false;
}

async function inspection(orderId) {
  return db.queryOne(
    'SELECT i.*, u.name AS recorded_by_name FROM order_inspections i LEFT JOIN users u ON u.id = i.recorded_by WHERE i.order_id = :o',
    { o: orderId }
  );
}

/** What still stops the Bill of Lading: { balance?: message, inspection?: message } in that order. */
async function blockers(orderId) {
  const order = await orderRepository.find(orderId);
  if (!order) {
    return {};
  }
  const out = {};
  if (isPreShipmentBalance(order)) {
    const payment = await orderPaymentStatusRepository.find(orderId);
    if (payment && Number(payment.balance_amount || 0) > 0 && payment.balance_cleared_at === null) {
      out.balance = MESSAGES.balance;
    }
  }
  if ((await inspectionApplies(orderId)) && ((await inspection(orderId)) || {}).result !== 'passed') {
    out.inspection = MESSAGES.inspection;
  }
  return out;
}

/** G-3: stores the inspection result; Passed resolves the checklist line, Failed reopens it and tells the managers. */
async function saveInspection(order, result, date, agency, fileId, note, userId) {
  const orderId = Number(order.id);
  const before = await inspection(orderId);
  await db.execute(
    `INSERT INTO order_inspections (order_id, result, inspected_on, agency_name, report_file_id, note, recorded_by)
     VALUES (:o, :r, :d, :a, :f, :n, :u)
     ON DUPLICATE KEY UPDATE result = VALUES(result), inspected_on = VALUES(inspected_on), agency_name = VALUES(agency_name),
        report_file_id = VALUES(report_file_id), note = VALUES(note), recorded_by = VALUES(recorded_by)`,
    { o: orderId, r: result, d: date, a: agency, f: fileId, n: note, u: userId }
  );
  await auditLogRepository.log(userId, 'INSPECTION_RESULT_RECORDED', 'orders', orderId, 'inspection_result', before ? before.result : null, result, note);
  for (const task of await orderComplianceTaskRepository.forOrder(orderId)) {
    if (isInspectionTask(task)) {
      await orderComplianceTaskRepository.setStatus(orderId, Number(task.task_type_id), result === 'passed' ? 'approved' : 'pending_approval', null, userId);
    }
  }
  if (result === 'failed') {
    const message = `Pre-shipment inspection FAILED on order ${order.order_reference} (${agency}, ${dates.short(date)}): ${note} The Bill of Lading waits for a Passed inspection.`;
    for (const uid of await permissionRepository.usersWithPermission('override_shipment_gates')) {
      await notificationRepository.create(uid, null, 'inspection_failed', orderId, message);
    }
  }
}

async function recordOverride(orderId, gates, reason, userId) {
  await db.execute('INSERT INTO order_shipment_gate_overrides (order_id, gates, reason, user_id) VALUES (:o, :g, :r, :u)', {
    o: orderId, g: gates.join(','), r: reason, u: userId,
  });
}

/** The releases of the Bill of Lading step, oldest first. */
async function overrides(orderId) {
  return db.query(
    'SELECT g.*, u.name AS user_name FROM order_shipment_gate_overrides g JOIN users u ON u.id = g.user_id WHERE g.order_id = :o ORDER BY g.id',
    { o: orderId }
  );
}

/** "the pre-shipment balance and the inspection" */
function gatesLabel(gates) {
  const labels = { balance: 'the pre-shipment balance', inspection: 'the inspection' };
  return String(gates).split(',').map((g) => labels[g] || g).join(' and ');
}

module.exports = {
  INSPECTION_TASK, INSPECTION_TASK_KEY, isInspectionTask, MESSAGES, isPreShipmentBalance, balanceOpenEarly, inspectionApplies, inspection, blockers, saveInspection,
  recordOverride, overrides, gatesLabel,
};
