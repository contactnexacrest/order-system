'use strict';

const orderRepository = require('../repositories/orderRepository');
const orderStageRepository = require('../repositories/orderStageRepository');

// Port of App\Services\StageGateService. See StageGate.docx for the full
// Stage 1-9 gate list (mirrored in the PHP source's class-level comment).

/**
 * @returns {Promise<boolean>} true if the gate was passed (or already had
 *   been — idempotent), false if stage `stageNumber` isn't actually
 *   unlocked yet (still 'locked', meaning the stage before it hasn't
 *   genuinely passed). Every caller MUST check this return value and
 *   refuse the surrounding action on false — without it, any
 *   manage_orders holder could force-pass any stage on any order by
 *   POSTing directly to its gate endpoint (e.g. /orders/:id/close),
 *   skipping every stage before it with no error at all.
 */
async function passAndUnlockNext(orderId, stageNumber, userId) {
  const current = await orderStageRepository.findByOrderAndStageNumber(orderId, stageNumber);
  if (!current) {
    return false; // stage doesn't exist for this order
  }
  if (current.status === 'gate_passed') {
    return true; // already passed — idempotent no-op
  }
  if (current.status !== 'in_progress') {
    return false; // still locked — the stage before this one hasn't passed yet
  }
  await orderStageRepository.passGate(current.id, userId);

  const next = await orderStageRepository.findByOrderAndStageNumber(orderId, stageNumber + 1);
  if (next && next.status === 'locked') {
    await orderStageRepository.unlock(next.id);
    await orderRepository.setCurrentStage(orderId, next.stage_id);
  }
  return true;
}

/**
 * Pre-flight check for a "stage N -> N+1" controller action, called
 * BEFORE any of that action's own writes — so an out-of-order attempt is
 * refused before anything is recorded, not just before the stage-gate
 * row itself is updated.
 */
async function isUnlocked(orderId, stageNumber) {
  const stage = await orderStageRepository.findByOrderAndStageNumber(orderId, stageNumber);
  return !!stage && stage.status === 'in_progress';
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

module.exports = { passAndUnlockNext, isUnlocked, maybeAutoSkipFreightStage, currentStage };
