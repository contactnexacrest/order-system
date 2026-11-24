'use strict';

/**
 * Owner decision D-410 (24 Nov 2026): what the client's price includes for an associate.
 *  - Quote = staff floor ÷ (1 − included %) × (1 + buffer); lowest = staff floor ÷ (1 − min(rate, included %)).
 *  - The staff price and the old associate formula do not change.
 * PHP twin: tests/Unit/AssociatePriceIncludedD410Test.php.
 */
const pricingEngine = require('../../src/services/pricingEngine');
const associateRules = require('../../src/services/associateRules');

const RAW = { staff_floor: 1000, buffer_pct: 3 };

describe('Associate price with a set % included (D-410)', () => {
  it('quotes the same at every level with a fixed 5 %', () => {
    expect([2, 5, 7, 10].map((r) => pricingEngine.associateIncluded(RAW, r, 5).quote)).toEqual([1084.21, 1084.21, 1084.21, 1084.21]);
  });

  it('uses the smaller of the rate and the included % for the lowest price', () => {
    expect(pricingEngine.associateIncluded(RAW, 2, 5).floor).toBe(1020.41);
    expect(pricingEngine.associateIncluded(RAW, 7, 5).floor).toBe(1052.63);
    expect(pricingEngine.associateIncluded(RAW, 10, 5).floor).toBe(1052.63);
  });

  it('gives the old formula for the ladder rate', () => {
    for (const r of [2, 5, 7, 10]) expect(pricingEngine.associateIncluded(RAW, r, r)).toEqual(pricingEngine.associate(RAW, 'pct', r));
  });

  it('keeps the old associate formula unchanged', () => {
    expect(pricingEngine.associate(RAW, 'pct', 5)).toEqual({ floor: 1052.63, quote: 1084.21 });
    expect(pricingEngine.associate(RAW, 'fixed_usd', 25)).toEqual({ floor: 1025, quote: 1055.75 });
  });

  it('keeps the staff price unchanged', () => {
    const b = pricingEngine.build({ supplier_inr: 80000, fx_rate: 82, other_mode: 'pct', other_value: 5.5, margin_pct: 15,
      incentive_mode: 'pct', incentive_value: 15, program_on: true, buffer_pct: 3, adjust: null });
    expect([b.minimum, b.staff_floor, b.staff_quote]).toEqual([1210.9, 1238.15, 1275.29]);
  });

  it('works out the part from our margin', () => {
    expect(associateRules.marginPct(2, 5)).toBe(0);
    expect(associateRules.marginPct(5, 5)).toBe(0);
    expect(associateRules.marginPct(7, 5)).toBe(2);
    expect(associateRules.marginPct(10, 5)).toBe(5);
    expect(associateRules.marginPct(null, 5)).toBe(0);
  });

  it('refuses invalid percentages', () => {
    expect(() => pricingEngine.associateIncluded(RAW, 5, 100)).toThrow();
  });
});
