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
 * standard, reusable option. Adds two seeded 30/70 presets — "Extended
 * Terms — Before Shipment" and "Extended Terms — Post-BL" (the latter
 * renamed from the original single "Extended Terms — 30/70" once a
 * Before-Shipment sibling existed, mirroring the existing 40/60
 * bucket's own Before-Shipment/Post-BL pair) — and proves an order's
 * advance_pct/balance_pct are snapshotted from whichever preset it's
 * assigned at creation (orderRepository's resolveSnapshot()), never a
 * hardcoded 40/60 split.
 */
describe('Extended Terms 30/70 payment presets (90-item backlog "30/70 payment preset")', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  it('the Post-BL preset is seeded with the right split', async () => {
    const preset = await db.queryOne('SELECT * FROM payment_presets WHERE preset_name = :name', { name: 'Extended Terms — Post-BL' });

    expect(preset).toBeTruthy();
    expect(Number(preset.advance_pct)).toBe(30);
    expect(Number(preset.balance_pct)).toBe(70);
    expect(preset.balance_trigger_option).toBe('B_AGAINST_BL');
    expect(Number(preset.is_active)).toBe(1);
    expect(Number(preset.is_protected)).toBe(1);
  });

  it('the Before-Shipment preset is seeded with the right split', async () => {
    const preset = await db.queryOne('SELECT * FROM payment_presets WHERE preset_name = :name', { name: 'Extended Terms — Before Shipment' });

    expect(preset).toBeTruthy();
    expect(Number(preset.advance_pct)).toBe(30);
    expect(Number(preset.balance_pct)).toBe(70);
    expect(preset.balance_trigger_option).toBe('A_BEFORE_SHIPMENT');
    expect(Number(preset.balance_days)).toBe(3);
    expect(Number(preset.is_active)).toBe(1);
    expect(Number(preset.is_protected)).toBe(1);
  });

  // A sight LC has no separate cash advance leg — the full amount is
  // paid once shipping documents are presented to the bank, so this
  // preset's split is 0% advance / 100% balance, not 30/70.
  it('the LC preset has no advance leg', async () => {
    const preset = await db.queryOne('SELECT * FROM payment_presets WHERE preset_name = :name', { name: 'Irrevocable Letter of Credit at Sight' });

    expect(preset).toBeTruthy();
    expect(Number(preset.advance_pct)).toBe(0);
    expect(Number(preset.balance_pct)).toBe(100);
    expect(preset.payment_method).toBe('LC');
    expect(Number(preset.is_protected)).toBe(1);
  });

  it('an order on the Post-BL preset computes its own split with no hardcoded percentage', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB', 'Extended Terms — Post-BL');

    const order = await orderRepository.find(orderId);

    expect(Number(order.advance_pct)).toBe(30);
    expect(Number(order.balance_pct)).toBe(70);
  });

  it('an order on the Before-Shipment preset computes its own split', async () => {
    const clientId = await createTestClient();
    const orderId = await createTestOrder(clientId, 'FOB', 'Extended Terms — Before Shipment');

    const order = await orderRepository.find(orderId);

    expect(Number(order.advance_pct)).toBe(30);
    expect(Number(order.balance_pct)).toBe(70);
  });
});
