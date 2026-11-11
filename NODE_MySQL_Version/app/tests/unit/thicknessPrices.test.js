'use strict';

const thicknessPrices = require('../../src/services/thicknessPrices');
const priceRules = require('../../src/services/priceRules');
const fxRateService = require('../../src/services/fxRateService');

/**
 * Port of ThicknessPricesTest.php — owner decisions of 11 Nov 2026, double-confirmed — the pure maths:
 *  - D-300 (point 12): one supplier price for every thickness — 30 mm = 20 mm + 35 %, 40 mm = 20 mm + 70 %,
 *    50 mm+ per CBM = 20 mm per m² × 40, in every direction; TYPED prices stay, CALCULATED ones follow;
 *    0 / empty is calculated again, all empty is refused; CHA + transport per container → per unit.
 *  - D-301 (point 4): the market rate from either source; pricing rate = market × (1 − cushion %).
 * Figures are the ones in the owner's mockups (p12.png, p12b.png, rules.png).
 */
const rules = (extra = {}) => ({
  grid: true, base_mm: 20, k: 40, cbm_from: 50,
  rows: [{ mm: 15, pct: -20, option: '15 mm' }, { mm: 18, pct: -5, option: '18 mm' }, { mm: 20, pct: 0, option: '20 mm' },
    { mm: 30, pct: 35, option: '30 mm' }, { mm: 40, pct: 70, option: '40 mm' }],
  container_cbm: 8, container_max_t: 27, t_per_cbm: 2.7, own: [], category_id: 1, category_name: 'Natural Stone', unit: 'SQM',
  ...extra,
});
const prices = (done) => thicknessPrices.ordered(done).map((v) => [v.mm, v.price, v.how]);

describe('D-300 thickness prices and D-301 exchange rate (pure)', () => {
  it('typing 20 mm works out every other thickness', () => {
    const d = thicknessPrices.complete(rules(), { 20: 1600 });
    expect(d.ok).toBe(true);
    expect(prices(d)).toEqual([[15, 1280, 'calculated'], [18, 1520, 'calculated'], [20, 1600, 'typed'], [30, 2160, 'calculated'], [40, 2720, 'calculated'], [0, 64000, 'calculated']]);
    expect(d.values[30].rule).toBe('20 mm + 35%');
    expect(d.values[0].rule).toBe('20 mm price × 40');
  });

  it('works in every direction', () => {
    let d = thicknessPrices.complete(rules(), { 0: 64000 });
    expect([d.values[20].price, d.values[20].how]).toEqual([1600, 'calculated']);
    expect(d.values[30].price).toBe(2160);
    d = thicknessPrices.complete(rules(), { 40: 2720 });
    expect(d.values[20].price).toBe(1600);
    expect(d.values[0].price).toBe(64000);
    d = thicknessPrices.complete(rules(), { 30: 2100 });
    expect(d.values[20].price).toBe(1555.56);
    expect(d.values[40].price).toBe(2644.44);
    expect(d.values[0].price).toBe(62222.22);
  });

  it('typed prices stay and calculated ones follow the base', () => {
    let d = thicknessPrices.complete(rules(), { 20: 1600, 40: 2650 });
    expect([d.values[40].price, d.values[40].how, d.values[40].calc]).toEqual([2650, 'typed', 2720]);
    expect(d.values[0].price).toBe(64000);
    d = thicknessPrices.complete(rules(), { 20: 1700, 30: 2100 });
    expect([d.values[30].price, d.values[30].how, d.values[30].calc]).toEqual([2100, 'typed', 2295]);
    expect(d.values[40].price).toBe(2890);
    expect(d.values[0].price).toBe(68000);
  });

  it('nothing typed is refused', () => {
    for (const typed of [{}, { 20: 0 }, { 30: 0, 0: 0 }]) {
      const d = thicknessPrices.complete(rules(), typed);
      expect(d.ok).toBe(false);
      expect(d.error).toBe('Enter at least one price.');
    }
  });

  it('reads the form', () => {
    const r = rules();
    let [t, e] = thicknessPrices.readForm({ price: { 20: '1600.00', 30: '2160.00', 0: '64000.00' }, how: { 20: 'typed', 30: 'calculated', 0: 'calculated' } }, r);
    expect(e).toBeNull();
    expect(t).toEqual({ 20: 1600 });
    [t] = thicknessPrices.readForm({ price: { 20: '1600', 30: '2100', 40: '0', 0: '' }, how: { 20: 'typed', 30: 'calculated', 40: 'typed' } }, r);
    expect(t).toEqual({ 20: 1600, 30: 2100 });
    [t] = thicknessPrices.readForm({ price: { 0: '64,000' } }, r);
    expect(t).toEqual({ 0: 64000 });
    // qs turns price[0]…price[40] into a sparse array — read the same way.
    const sparse = [];
    sparse[20] = '1600';
    [t] = thicknessPrices.readForm({ price: sparse, how: [] }, r);
    expect(t).toEqual({ 20: 1600 });
    expect(thicknessPrices.readForm({ price: { 20: '', 30: '0' } }, r)[1]).toBe('Enter at least one price.');
    expect(thicknessPrices.readForm({ price: { 30: 'abc' } }, r)[1]).toMatch(/^30 mm: enter the price in ₹/);
    expect(thicknessPrices.readForm({ price: { 20: '1600', 25: '1800' } }, r)[0]).toEqual({ 20: 1600 });
  });

  it('CHA and transport per unit from what a container carries', () => {
    let c = thicknessPrices.chaPerCbm(60000, 8, 27, 2.7);
    expect([c.per_cbm, c.fits_cbm, c.by_weight]).toEqual([7500, 8, false]);
    expect(c.weight_t).toBeCloseTo(21.6, 9);
    c = thicknessPrices.chaPerCbm(60000, 8, 27, 3.5);
    expect(c.by_weight).toBe(true);
    expect(c.fits_cbm).toBeCloseTo(27 / 3.5, 9);
    expect(c.per_cbm).toBeCloseTo(7777.78, 2);
    expect(thicknessPrices.chaPerCbm(null, 8, 27, 2.7).per_cbm).toBeNull();
    expect([thicknessPrices.m2ToCbm(1600, 20), thicknessPrices.m2ToCbm(2160, 30), thicknessPrices.m2ToCbm(2650, 40)]).toEqual([80000, 72000, 66250]);
  });

  it('FOB cost per unit', () => {
    const row = { cha_transport_per_container: '60000.00', includes_packing_loading: 1 };
    let c = thicknessPrices.cost(rules(), { 20: 1600 }, row, {}, 'unit', null);
    expect(c.supplier).toBeCloseTo(1600, 6);
    expect(c.cha).toBeCloseTo(150, 6);
    expect(c.total).toBeCloseTo(1750, 6);
    expect(thicknessPrices.cost(rules(), { 20: 1600 }, row, {}, 'unit', 30).total).toBeCloseTo(2385, 6);
    expect(thicknessPrices.cost(rules({ unit: 'CBM' }), { 20: 1600 }, row, {}, 'unit', null).total).toBeCloseTo(87500, 6);
    expect(thicknessPrices.cost(rules({ unit: 'CBM' }), { 20: 1600 }, row, {}, 'cbm', 130).total).toBeCloseTo(71500, 6);
    c = thicknessPrices.cost(rules(), { 20: 1600 }, { cha_transport_per_container: null, includes_packing_loading: 0 },
      { cha_transport_per_container: '40000', default_packing_cost: '10', default_loading_cost: '5' }, 'unit', null);
    expect(c.packing).toBeCloseTo(15, 6);
    expect(c.cha).toBeCloseTo(100, 6);
    expect(thicknessPrices.cost(rules(), {}, row, {}, 'unit', null)).toBeNull();
  });

  it('the rules summary line', () => {
    expect(priceRules.summary(rules())).toBe('15 mm = 20 mm − 20 % · 18 mm = 20 mm − 5 % · 30 mm = 20 mm + 35 % · 40 mm = 20 mm + 70 % · 50 mm+ per CBM = 20 mm × 40 · container 8 CBM / 27 t');
    expect(thicknessPrices.label(0)).toBe('50 mm and above');
    expect(thicknessPrices.fmt(2.5)).toBe('2.5');
  });

  it('exchange rate parsing and cushion', () => {
    expect(fxRateService.parse('{"amount":1.0,"base":"USD","date":"2026-11-10","rates":{"INR":88.4}}')).toBe(88.4);
    expect(fxRateService.parse('{"result":"success","base_code":"USD","rates":{"EUR":0.92,"INR":88.41}}')).toBe(88.41);
    expect(fxRateService.parse('not json')).toBeNull();
    expect(fxRateService.parse('{"rates":{"EUR":0.92}}')).toBeNull();
    expect(fxRateService.parse('{"rates":{"INR":0.5}}')).toBeNull();
    expect(fxRateService.pricingRateFor(88.40, 2.0)).toBe(86.632);
    expect(fxRateService.pricingRateFor(88.40, 0)).toBe(88.4);
    expect(fxRateService.signed(2.4)).toBe('+2.40 %');
    expect(fxRateService.signed(-3.1)).toBe('−3.10 %');
    expect(fxRateService.readForm({ fx_mode: 'manual', fx_cushion_pct: '2.5' })).toEqual([{ fx_mode: 'manual', fx_cushion_pct: '2.5' }, []]);
    for (const bad of ['5.5', '2.25', '-1', 'x']) expect(fxRateService.readForm({ fx_cushion_pct: bad })[1]).not.toEqual([]);
    expect(fxRateService.readForm({ fx_mode: 'sometimes' })[1]).not.toEqual([]);
    expect(fxRateService.readForm({})).toEqual([{}, []]);
  });
});
