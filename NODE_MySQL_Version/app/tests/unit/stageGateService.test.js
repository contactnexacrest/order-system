'use strict';

jest.mock('../../src/repositories/orderStageRepository');
jest.mock('../../src/repositories/orderRepository');

const orderStageRepository = require('../../src/repositories/orderStageRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const stageGateService = require('../../src/services/stageGateService');

describe('stageGateService.isUnlocked', () => {
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
});

describe('stageGateService.passAndUnlockNext', () => {
  afterEach(() => jest.clearAllMocks());

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
});
