'use strict';

const orderRepository = require('../repositories/orderRepository');
const orderStageRepository = require('../repositories/orderStageRepository');

// Port of App\Services\StageGateService. See StageGate.docx for the full
// Stage 1-9 gate list (mirrored in the PHP source's class-level comment).

async function passAndUnlockNext(orderId, stageNumber, userId) {
  const current = await orderStageRepository.findByOrderAndStageNumber(orderId, stageNumber);
  if (!current || current.status === 'gate_passed') {
    return; // already passed, or stage doesn't exist — idempotent no-op
  }
  await orderStageRepository.passGate(current.id, userId);

  const next = await orderStageRepository.findByOrderAndStageNumber(orderId, stageNumber + 1);
  if (next && next.status === 'locked') {
    await orderStageRepository.unlock(next.id);
    await orderRepository.setCurrentStage(orderId, next.stage_id);
  }
}

/**
 * Call immediately after Stage 5 (Supplier PO) passes. FOB orders skip
 * Stage 6 (Freight Payment) entirely — buyer arranges/pays freight
 * directly; NexaCrest never issues a Freight Debit Note for FOB.
 */
async function maybeAutoSkipFreightStage(orderId, userId) {
  const order = await orderRepository.find(orderId);
  if (!order || String(order.incoterm_code).toUpperCase() !== 'FOB') {
    return;
  }
  const freightStage = await orderStageRepository.findByOrderAndStageNumber(orderId, 6);
  if (!freightStage || freightStage.status !== 'in_progress') {
    return; // already skipped/passed, or not yet unlocked — idempotent no-op
  }
  await orderStageRepository.skip(freightStage.id, 'FOB — freight stage auto-skipped, buyer arranges own freight');

  const next = await orderStageRepository.findByOrderAndStageNumber(orderId, 7);
  if (next && next.status === 'locked') {
    await orderStageRepository.unlock(next.id);
    await orderRepository.setCurrentStage(orderId, next.stage_id);
  }
}

/** Current stage's status/number for an order, for UI gating decisions. */
async function currentStage(orderId) {
  const stages = await orderStageRepository.forOrder(orderId);
  for (const stage of stages) {
    if (stage.status !== 'gate_passed' && stage.status !== 'skipped') {
      return stage;
    }
  }
  return stages.length ? stages[stages.length - 1] : null;
}

module.exports = { passAndUnlockNext, maybeAutoSkipFreightStage, currentStage };
