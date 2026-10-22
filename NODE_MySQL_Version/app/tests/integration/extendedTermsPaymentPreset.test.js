'use strict';

const db = require('../../src/config/db');
const orderRepository = require('../../src/repositories/orderRepository');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of ExtendedTermsPaymentPresetTest.php. 90-item backlog "30/70
 * payment preset": only two seeded presets existed (both 40% advance /
 * 60% balance), and the full Payment Presets CRUD screen
 * (/payment-presets) already lets an admin create a custom one — but
 * nobody had actually added the 30/70 split staff wanted as a
 * standard, reusable option. Adds a third seeded preset, "Extended
 * Terms — 30/70", and proves an order's advance_pct/balance_pct are
 * snapshotted from whichever preset it's assigned at creation
 * (orderRepository's resolveSnapshot()), never a hardcoded 40/60 split.
 */
describe('Extended Terms 30/70 payment preset (90-item backlog "30/70 payment preset")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('the preset is seeded with the right split', async () => {
    const preset = await db.queryOne('SELECT * FROM payment_presets WHERE preset_name = :name', { name: 'Extended Terms — 30/70' });

    expect(preset).toBeTruthy();
    expect(Number(preset.advance_pct)).toBe(30);
    expect(Number(preset.balance_pct)).toBe(70);
    expect(preset.balance_trigger_option).toBe('B_AGAINST_BL');
    expect(Number(preset.is_active)).toBe(1);
    expect(Number(preset.is_protected)).toBe(0);
  });

  it('an order on this preset computes its own split with no hardcoded percentage', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB', 'Extended Terms — 30/70');

    const order = await orderRepository.find(orderId);

    expect(Number(order.advance_pct)).toBe(30);
    expect(Number(order.balance_pct)).toBe(70);
  });
});
