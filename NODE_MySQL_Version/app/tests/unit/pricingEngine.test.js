'use strict';

const pricingEngine = require('../../src/services/pricingEngine');

/**
 * Port of PricingEngineTest.php — owner decision D-245 (4 Nov 2026): the price
 * build-up to the paisa with the plan's worked example.
 */
describe('Pricing engine (D-245)', () => {
  const example = (programOn = true) => pricingEngine.build({
    supplier_inr: 80000, fx_rate: 82, other_mode: 'pct', other_value: 2.5, margin_pct: 20,
    incentive_mode: 'pct', incentive_value: 15, program_on: programOn, buffer_pct: 3,
  });

  it("the plan's worked example", () => {
    const b = example();
    expect([b.supplier_usd, b.other_costs, b.cost, b.minimum, b.reserve, b.staff_floor, b.staff_quote])
      .toEqual([975.61, 24.39, 1000, 1250, 37.5, 1287.5, 1326.13]);
  });

  it('associate levels', () => {
    const { raw } = example();
    expect(pricingEngine.associate(raw, 'pct', 2)).toEqual({ floor: 1313.78, quote: 1353.19 });
    expect(pricingEngine.associate(raw, 'pct', 5)).toEqual({ floor: 1355.26, quote: 1395.92 });
    expect(pricingEngine.associate(raw, 'pct', 7)).toEqual({ floor: 1384.41, quote: 1425.94 });
    expect(pricingEngine.associate(raw, 'pct', 10)).toEqual({ floor: 1430.56, quote: 1473.47 });
    expect(pricingEngine.associate(raw, 'fixed_usd', 30)).toEqual({ floor: 1317.5, quote: 1357.03 });
  });

  it('program off adds no reserve', () => {
    const b = example(false);
    expect([b.reserve, b.staff_floor, b.staff_quote]).toEqual([0, 1250, 1287.5]);
  });

  it('fixed amounts, and margin not markup', () => {
    const b = pricingEngine.build({
      supplier_inr: 82000, fx_rate: 82, other_mode: 'usd', other_value: 0, margin_pct: 20,
      incentive_mode: 'usd', incentive_value: 50, program_on: true, buffer_pct: 3,
    });
    expect([b.cost, b.minimum, b.reserve, b.staff_floor, b.staff_quote]).toEqual([1000, 1250, 50, 1300, 1339]);
  });

  it('rounding is half up, ignoring binary noise', () => {
    expect(pricingEngine.round2(1326.125)).toBe(1326.13);
    expect(pricingEngine.round2(1317.5 * 1.03)).toBe(1357.03);
    expect(pricingEngine.round2(0.005)).toBe(0.01);
    expect(pricingEngine.round2(-2.345)).toBe(-2.35);
    expect(pricingEngine.round2(1287.5 / 0.95)).toBe(1355.26);
  });

  it('bad inputs are refused', () => {
    expect(() => pricingEngine.build({ supplier_inr: 1, fx_rate: 0, other_mode: 'pct', other_value: 0, margin_pct: 20, incentive_mode: 'pct', incentive_value: 0, program_on: false, buffer_pct: 0 })).toThrow();
  });
});
