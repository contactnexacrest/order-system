'use strict';

const db = require('../config/db');

async function initializeForOrder(orderId) {
  const stages = await db.query('SELECT id, stage_number FROM stages_master ORDER BY sequence');
  for (const stage of stages) {
    const isFirst = parseInt(stage.stage_number, 10) === 1;
    await db.execute(
      'INSERT INTO order_stages (order_id, stage_id, status, unlocked_at) VALUES (:order_id, :stage_id, :status, :unlocked_at)',
      {
        order_id: orderId,
        stage_id: stage.id,
        status: isFirst ? 'in_progress' : 'locked',
        unlocked_at: isFirst ? new Date().toISOString().slice(0, 19).replace('T', ' ') : null,
      }
    );
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
