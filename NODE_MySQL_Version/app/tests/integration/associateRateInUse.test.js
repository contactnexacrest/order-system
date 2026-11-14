'use strict';

const db = require('../../src/config/db');
const associateCommission = require('../../src/services/associateCommission');
const associatePayoutService = require('../../src/services/associatePayoutService');
const pricingService = require('../../src/services/pricingService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Owner, 14 Nov 2026: the associate commission estimate, the level fixed at the PI, the USD rate before the PI
 * and the USD 100 minimum for associates abroad use the pricing rate IN USE (Automatic = market less the cushion,
 * or Manual), not the Manual box while Automatic is on. PHP twin: tests/Integration/AssociateRateInUseTest.php.
 */
describe('associates use the pricing rate in use', () => {
  const FAR_DATE = '2099-01-01';
  const set = (k, v) => db.execute('UPDATE pricing_settings SET setting_value = :v WHERE setting_key = :k', { k, v });

  beforeEach(async () => {
    await set('fx_mode', 'auto');
    await set('fx_cushion_pct', '2');
    await set('pricing_fx_rate', '82.0000');
    await db.execute("INSERT INTO fx_rates (rate_date, market_rate, source, status, fetched_at) VALUES (:d, 94.25, 'TEST', 'in_use', :t)", { d: FAR_DATE, t: `${FAR_DATE} 07:00:00` });
  });

  afterEach(async () => {
    await db.execute('DELETE FROM fx_rates WHERE rate_date = :d', { d: FAR_DATE });
    for (const k of ['fx_mode', 'fx_cushion_pct', 'pricing_fx_rate']) await set(k, String(pricingService.DEFAULTS[k]));
  });

  afterAll(async () => {
    await db.pool.end();
  });

  test('all four places use the rate in use, not the Manual box', async () => {
    const id = await createTestOrder(await createTestClient(), 'FOB', 'Standard — New Buyer', 'USD');
    const order = await db.queryOne('SELECT * FROM orders WHERE id = :id', { id });

    expect(await associateCommission.estimateInr(order, 10000)).toBeCloseTo(923650, 2);
    expect(await associateCommission.realisedRate(order)).toEqual({ rate: 92.365, estimated: true });
    const eur = { ...order, currency_code: 'EUR', pricing_fx_rate_at_pi: null };
    expect(await associateCommission.usdRate(eur)).toBe(92.365);
    expect(await associateCommission.usdRate({ ...eur, pricing_fx_rate_at_pi: '91.5' })).toBe(91.5);
    expect(await associatePayoutService.usdEquivalent({ currency_code: 'EUR', amount_inr: '8700', amount_fc: '80' })).toBeCloseTo(94.19, 2);
    expect(await associatePayoutService.usdEquivalent({ currency_code: 'USD', amount_inr: '8700', amount_fc: '120' })).toBe(120);

    await set('fx_mode', 'manual');
    expect(await associateCommission.estimateInr(order, 10000)).toBeCloseTo(820000, 2);
    expect(await associateCommission.usdRate(eur)).toBe(82);
  });
});
