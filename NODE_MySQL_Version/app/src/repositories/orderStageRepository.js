'use strict';

const db = require('../config/db');
const { formatForDb } = require('../helpers/dbDateTime');

// QA-5 ORD-05: optional executor lets this join the caller's transaction
// (order creation — see ordersController.store()), defaulting to the plain
// pool for every other existing call site.
/**
 * Creates all 9 order_stages rows for a new order. Stage 1 starts
 * unlocked/in_progress, the rest locked.
 *
 * External QA report L-14: orders.current_stage_id — a separate column
 * from these order_stages rows, read by the per-order/per-stage audit
 * file_store folder layout — stayed NULL until the first gate actually
 * passed (stageGateService.passAndUnlockNext() is the only other writer),
 * so every brand-new order's own Stage 1 documents filed into a generic
 * fallback folder instead of its real Stage 1 folder. This is the one
 * chokepoint all 3 "new order" call sites (ordersController.store(),
 * orderDuplicationService, sampleDataService) already go through, so
 * setting it here (via the same `executor`, so it stays inside whichever
 * transaction the caller is already running) closes the gap for all of
 * them at once.
 */
async function initializeForOrder(orderId, executor = db) {
  const stages = await executor.query('SELECT id, stage_number FROM stages_master ORDER BY sequence');
  let firstStageId = null;
  for (const stage of stages) {
    const isFirst = parseInt(stage.stage_number, 10) === 1;
    if (isFirst) {
      firstStageId = stage.id;
    }
    await executor.execute(
      'INSERT INTO order_stages (order_id, stage_id, status, unlocked_at) VALUES (:order_id, :stage_id, :status, :unlocked_at)',
      {
        order_id: orderId,
        stage_id: stage.id,
        status: isFirst ? 'in_progress' : 'locked',
        // External QA report L-21/L-02: toISOString() is always UTC — see
        // dbDateTime.formatForDb()'s docblock.
        unlocked_at: isFirst ? formatForDb(new Date()) : null,
      }
    );
  }
  if (firstStageId !== null) {
    await executor.execute('UPDATE orders SET current_stage_id = :stage_id WHERE id = :id', { stage_id: firstStageId, id: orderId });
  }
}

async function forOrder(orderId) {
  return db.query(
    `SELECT os.*, sm.stage_number, sm.stage_slug, sm.stage_name, sm.sequence
     FROM order_stages os
     JOIN stages_master sm ON sm.id = os.stage_id
     WHERE os.order_id = :order_id
     ORDER BY sm.sequence`,
    { order_id: orderId }
  );
}

async function findByOrderAndStageNumber(orderId, stageNumber) {
  return db.queryOne(
    `SELECT os.*, sm.stage_number, sm.stage_slug, sm.stage_name
     FROM order_stages os
     JOIN stages_master sm ON sm.id = os.stage_id
     WHERE os.order_id = :order_id AND sm.stage_number = :stage_number`,
    { order_id: orderId, stage_number: stageNumber }
  );
}

async function passGate(orderStageId, userId) {
  await db.execute(
    "UPDATE order_stages SET status = 'gate_passed', gate_passed_at = NOW(), gate_passed_by = :user_id WHERE id = :id",
    { user_id: userId, id: orderStageId }
  );
}

async function unlock(orderStageId) {
  await db.execute("UPDATE order_stages SET status = 'in_progress', unlocked_at = NOW() WHERE id = :id", { id: orderStageId });
}

async function skip(orderStageId, reason) {
  await db.execute("UPDATE order_stages SET status = 'skipped', unlocked_at = NOW(), skip_reason = :reason WHERE id = :id", { reason, id: orderStageId });
}

module.exports = { initializeForOrder, forOrder, findByOrderAndStageNumber, passGate, unlock, skip };
