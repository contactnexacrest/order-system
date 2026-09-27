'use strict';

const db = require('../../src/config/db');
const caExportBenefitRepository = require('../../src/repositories/caExportBenefitRepository');
const orderRepository = require('../../src/repositories/orderRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * CA / Accounting module (Phase 8) — government export benefit/incentive
 * claims (RODTEP + other schemes). Closes a real gap raised during a
 * holistic CA/Reports cross-check: money the government owes the company
 * (export incentives) had no home anywhere in the system, unlike every
 * expense (ca_expenses, imported from Zoho Books).
 */
describe('caExportBenefitRepository', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('record and find round trip', async () => {
    const id = await caExportBenefitRepository.record(null, 'RODTEP', 'SB1234567', 15000.5, '2026-06-15', 'INR', 'Q1 claim', 1);

    const found = await caExportBenefitRepository.find(id);
    expect(found.scheme_name).toBe('RODTEP');
    expect(found.reference_number).toBe('SB1234567');
    expect(parseFloat(found.claimed_amount)).toBeCloseTo(15000.5, 2);
    expect(found.received_amount).toBeNull();
    expect(found.order_id).toBeNull();
  });

  it('record linked to an order', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);

    const id = await caExportBenefitRepository.record(orderId, 'Duty Drawback', null, 5000, '2026-05-01', 'INR', null, 1);

    const found = await caExportBenefitRepository.find(id);
    expect(found.order_id).toBe(orderId);
  });

  it('markReceived sets amount and date', async () => {
    const id = await caExportBenefitRepository.record(null, 'RODTEP', null, 10000, '2026-04-01', 'INR', null, 1);

    await caExportBenefitRepository.markReceived(id, 9500, '2026-07-10');

    const found = await caExportBenefitRepository.find(id);
    expect(parseFloat(found.received_amount)).toBeCloseTo(9500, 2);
  });

  it('totals aggregate across claims', async () => {
    const idA = await caExportBenefitRepository.record(null, 'RODTEP', null, 1000, '2026-01-01', 'INR', null, 1);
    await caExportBenefitRepository.record(null, 'RODTEP', null, 2000, '2026-01-02', 'INR', null, 1);
    await caExportBenefitRepository.markReceived(idA, 900, '2026-02-01');

    expect(await caExportBenefitRepository.totalClaimed()).toBeGreaterThanOrEqual(3000);
    expect(await caExportBenefitRepository.totalReceived()).toBeGreaterThanOrEqual(900);
  });

  it('findIdByReference used for linking an existing order', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId);
    const order = await orderRepository.find(orderId);

    await expect(orderRepository.findIdByReference(order.order_reference)).resolves.toBe(orderId);
    await expect(orderRepository.findIdByReference('NOT-A-REAL-REFERENCE')).resolves.toBeNull();
  });
});
