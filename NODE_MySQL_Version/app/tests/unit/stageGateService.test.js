'use strict';

jest.mock('../../src/repositories/orderStageRepository');
jest.mock('../../src/repositories/orderRepository');

const orderStageRepository = require('../../src/repositories/orderStageRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const stageGateService = require('../../src/services/stageGateService');

describe('stageGateService.isUnlocked', () => {
  beforeEach(() => {
    // Default: a normal active order, so existing stage-status assertions
    // below aren't all forced false by the QA-5 GATE-06 order-status guard.
    orderRepository.find.mockResolvedValue({ status: 'active' });
  });
  afterEach(() => jest.clearAllMocks());

  it('returns true when the stage is in_progress', async () => {
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 9, status: 'in_progress' });
    await expect(stageGateService.isUnlocked(1, 5)).resolves.toBe(true);
  });

  it('returns false when the stage is still locked', async () => {
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 9, status: 'locked' });
    await expect(stageGateService.isUnlocked(1, 5)).resolves.toBe(false);
  });

  it('returns false when the stage is already gate_passed (not re-openable via this check)', async () => {
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 9, status: 'gate_passed' });
    await expect(stageGateService.isUnlocked(1, 5)).resolves.toBe(false);
  });

  it('returns false when the order has no such stage row', async () => {
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue(null);
    await expect(stageGateService.isUnlocked(1, 99)).resolves.toBe(false);
  });

  // QA-5 GATE-06: a lost/completed order must refuse every gate action,
  // even one whose stage row still reads 'in_progress' (markLost()/
  // markComplete() never touch order_stages, only orders.status/is_locked).
  it.each(['lost', 'complete'])('returns false once the order status is %s, even though its stage is still in_progress', async (status) => {
    orderRepository.find.mockResolvedValue({ status });
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 9, status: 'in_progress' });
    await expect(stageGateService.isUnlocked(1, 5)).resolves.toBe(false);
  });

  it('returns false when the order itself cannot be found', async () => {
    orderRepository.find.mockResolvedValue(null);
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 9, status: 'in_progress' });
    await expect(stageGateService.isUnlocked(1, 5)).resolves.toBe(false);
  });
});

describe('stageGateService.passAndUnlockNext', () => {
  beforeEach(() => {
    orderRepository.find.mockResolvedValue({ status: 'active' });
  });
  afterEach(() => jest.clearAllMocks());

  // QA-5 GATE-06: same order-status guard as isUnlocked(), added directly
  // to this method too (defense-in-depth — see the method's own comment).
  it.each(['lost', 'complete'])('refuses to pass any stage once the order status is %s, and mutates nothing', async (status) => {
    orderRepository.find.mockResolvedValue({ status });
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 9, status: 'in_progress' });

    await expect(stageGateService.passAndUnlockNext(1, 5, 42)).resolves.toBe(false);
    expect(orderStageRepository.passGate).not.toHaveBeenCalled();
  });

  it('regression (QA-1): refuses to pass a stage that is still locked, and mutates nothing', async () => {
    // This is the exact shape of the original vulnerability: a controller
    // action calling passAndUnlockNext() directly on a stage whose
    // predecessor never passed. Before the QA-1 fix, this returned void and
    // silently passed the gate anyway.
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 9, status: 'locked' });

    const result = await stageGateService.passAndUnlockNext(1, 9, 42);

    expect(result).toBe(false);
    expect(orderStageRepository.passGate).not.toHaveBeenCalled();
    expect(orderStageRepository.unlock).not.toHaveBeenCalled();
    expect(orderRepository.setCurrentStage).not.toHaveBeenCalled();
  });

  it('is idempotent when the stage was already gate_passed', async () => {
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 9, status: 'gate_passed' });

    const result = await stageGateService.passAndUnlockNext(1, 9, 42);

    expect(result).toBe(true);
    expect(orderStageRepository.passGate).not.toHaveBeenCalled();
  });

  it('returns false when the order has no such stage row', async () => {
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue(null);
    await expect(stageGateService.passAndUnlockNext(1, 99, 42)).resolves.toBe(false);
  });

  it('passes an in_progress stage and unlocks the next locked one', async () => {
    orderStageRepository.findByOrderAndStageNumber.mockImplementation((orderId, stageNumber) => {
      if (stageNumber === 5) return Promise.resolve({ id: 50, stage_id: 500, status: 'in_progress' });
      if (stageNumber === 6) return Promise.resolve({ id: 60, stage_id: 600, status: 'locked' });
      return Promise.resolve(null);
    });

    const result = await stageGateService.passAndUnlockNext(1, 5, 42);

    expect(result).toBe(true);
    expect(orderStageRepository.passGate).toHaveBeenCalledWith(50, 42);
    expect(orderStageRepository.unlock).toHaveBeenCalledWith(60);
    expect(orderRepository.setCurrentStage).toHaveBeenCalledWith(1, 600);
  });

  it('passes the final stage without attempting to unlock a next one that does not exist', async () => {
    orderStageRepository.findByOrderAndStageNumber.mockImplementation((orderId, stageNumber) => {
      if (stageNumber === 9) return Promise.resolve({ id: 90, stage_id: 900, status: 'in_progress' });
      return Promise.resolve(null); // no stage 10
    });

    const result = await stageGateService.passAndUnlockNext(1, 9, 42);

    expect(result).toBe(true);
    expect(orderStageRepository.passGate).toHaveBeenCalledWith(90, 42);
    expect(orderStageRepository.unlock).not.toHaveBeenCalled();
    expect(orderRepository.setCurrentStage).not.toHaveBeenCalled();
  });

  // QA-4 (extending QA-1 per docs/QA/TEST_PLAN.md P0.1): every one of
  // Stages 2-9 must refuse on a freshly created order (only Stage 1
  // in_progress) — not just the stages QA-1's first pass happened to
  // cover.
  it.each([2, 3, 4, 5, 6, 7, 8, 9])('refuses to pass Stage %i while the order is still on Stage 1', async (stageNumber) => {
    orderStageRepository.findByOrderAndStageNumber.mockImplementation((orderId, n) => {
      if (n === 1) return Promise.resolve({ id: 10, stage_id: 100, status: 'in_progress' });
      return Promise.resolve({ id: n * 10, stage_id: n * 100, status: 'locked' });
    });

    await expect(stageGateService.isUnlocked(1, stageNumber)).resolves.toBe(false);
    await expect(stageGateService.passAndUnlockNext(1, stageNumber, 42)).resolves.toBe(false);
    expect(orderStageRepository.passGate).not.toHaveBeenCalled();
  });
});

describe('stageGateService — sequential walk through all 9 stages', () => {
  // A stateful in-memory fake of order_stages, driven by the real
  // stageGateService calls — this is the positive-path complement to the
  // locked-stage refusal tests above: proves the QA-1 guard never blocks
  // genuine in-order progression, and that a skip-ahead attempt from every
  // intermediate position is still refused mid-walk.
  function makeFakeStageTable() {
    const stages = {};
    for (let n = 1; n <= 9; n++) {
      stages[n] = { id: n, stage_id: n * 100, status: n === 1 ? 'in_progress' : 'locked' };
    }
    return stages;
  }

  beforeEach(() => {
    jest.clearAllMocks();
    orderRepository.find.mockResolvedValue({ status: 'active' });
  });

  it('succeeds stage by stage while every stage ahead stays refused', async () => {
    const stages = makeFakeStageTable();
    orderStageRepository.findByOrderAndStageNumber.mockImplementation((orderId, n) => Promise.resolve(stages[n] ? { ...stages[n] } : null));
    orderStageRepository.passGate.mockImplementation((id) => {
      const entry = Object.values(stages).find((s) => s.id === id);
      if (entry) entry.status = 'gate_passed';
      return Promise.resolve();
    });
    orderStageRepository.unlock.mockImplementation((id) => {
      const entry = Object.values(stages).find((s) => s.id === id);
      if (entry) entry.status = 'in_progress';
      return Promise.resolve();
    });

    for (let stage = 1; stage <= 9; stage++) {
      for (let ahead = stage + 1; ahead <= 9; ahead++) {
        await expect(stageGateService.isUnlocked(1, ahead)).resolves.toBe(false);
      }
      await expect(stageGateService.isUnlocked(1, stage)).resolves.toBe(true);
      await expect(stageGateService.passAndUnlockNext(1, stage, 42)).resolves.toBe(true);
    }

    for (let n = 1; n <= 9; n++) {
      expect(stages[n].status).toBe('gate_passed');
    }
  });
});

describe('stageGateService.maybeAutoSkipFreightStage', () => {
  afterEach(() => jest.clearAllMocks());

  it('auto-skips Stage 6 for an FOB order and unlocks Stage 7', async () => {
    orderRepository.find.mockResolvedValue({ incoterm_code: 'FOB' });
    orderStageRepository.findByOrderAndStageNumber.mockImplementation((orderId, n) => {
      if (n === 6) return Promise.resolve({ id: 60, status: 'in_progress' });
      if (n === 7) return Promise.resolve({ id: 70, stage_id: 700, status: 'locked' });
      return Promise.resolve(null);
    });

    await stageGateService.maybeAutoSkipFreightStage(1, 42);

    expect(orderStageRepository.skip).toHaveBeenCalledWith(60, expect.stringContaining('FOB'));
    expect(orderStageRepository.unlock).toHaveBeenCalledWith(70);
    expect(orderRepository.setCurrentStage).toHaveBeenCalledWith(1, 700);
  });

  it('does NOT skip Stage 6 for a CIF order', async () => {
    orderRepository.find.mockResolvedValue({ incoterm_code: 'CIF' });
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 60, status: 'in_progress' });

    await stageGateService.maybeAutoSkipFreightStage(1, 42);

    expect(orderStageRepository.skip).not.toHaveBeenCalled();
    expect(orderStageRepository.unlock).not.toHaveBeenCalled();
  });

  it('is a no-op if Stage 6 is not currently in_progress (already passed/skipped, or not yet unlocked)', async () => {
    orderRepository.find.mockResolvedValue({ incoterm_code: 'FOB' });
    orderStageRepository.findByOrderAndStageNumber.mockResolvedValue({ id: 60, status: 'gate_passed' });

    await stageGateService.maybeAutoSkipFreightStage(1, 42);

    expect(orderStageRepository.skip).not.toHaveBeenCalled();
  });
});
