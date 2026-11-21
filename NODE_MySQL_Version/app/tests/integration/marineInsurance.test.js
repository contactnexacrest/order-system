'use strict';

const db = require('../../src/config/db');
const orderProductRepository = require('../../src/repositories/orderProductRepository');
const pricingRepository = require('../../src/repositories/pricingRepository');
const productCategoryRepository = require('../../src/repositories/productCategoryRepository');
const documentDataAssembler = require('../../src/services/documentDataAssembler');
const marineInsurance = require('../../src/services/marineInsurance');
const pricingService = require('../../src/services/pricingService');
const { createTestClient, createTestOrder } = require('../support/fixtures');

/**
 * Port of MarineInsuranceTest.php. Owner decisions of 21 Nov 2026:
 *  D-402 ("approve A") — the insurance of a CIF order is worked out from the CIF value: rate × 110 % × (FOB + freight)
 *        ÷ (1 − rate × 110 %), at the highest rate among the order's product categories (Natural Stone 0.30 % incl.
 *        breakage), else the default 0.20 %; rounded up; no GST; a typed figure is kept; CFR / FOB get none.
 *  D-403 (confirmed twice) — the QT / PI "Insurance" row of a CIF order names the cover.
 *  D-404 (confirmed twice) — the QT / PI line "Estimated total CIF / CFR value"; nothing on FOB orders.
 */
const S = { cover_pct: 110.0, rate_pct: 0.2, min_premium: null, rounding: 'up', add_gst: false, cover_wording: 'ICC (A) all risks' };

describe('Marine insurance on CIF orders (D-402 / D-403 / D-404)', () => {
  const createdCategories = [];

  afterAll(async () => {
    for (const id of createdCategories) {
      await db.execute('DELETE FROM product_categories WHERE id = :id', { id });
    }
    await db.pool.end();
  });

  it('the premium is on the CIF value at 110 %, rounded up', () => {
    // FOB 4,500 + freight 950 at 0.30 %: 0.0033 × 5,450 ÷ 0.9967 = 18.044… → 19 (18.04 at 2 decimals).
    expect(marineInsurance.premium(4500, 950, 0.30, S)).toBe(19);
    expect(marineInsurance.premium(4500, 950, 0.30, { ...S, rounding: '2dp' })).toBe(18.04);
    // The cover is a true 110 % of CIF: premium = rate × 110 % × (FOB + freight + premium).
    const p = marineInsurance.premium(4500, 950, 0.30, { ...S, rounding: '2dp' });
    expect(Math.abs(0.003 * 1.1 * (5450 + p) - p)).toBeLessThan(0.01);
    // Default rate 0.20 %: 0.0022 × 5,450 ÷ 0.9978 = 12.016… → 13.
    expect(marineInsurance.premium(4500, 950, 0.20, S)).toBe(13);
    // GST added only when the setting says so; a minimum premium lifts a small figure.
    expect(marineInsurance.premium(4500, 950, 0.30, { ...S, add_gst: true })).toBe(22);
    expect(marineInsurance.premium(4500, 950, 0.30, { ...S, min_premium: 25.0 })).toBe(25);
    expect(marineInsurance.premium(0, 0, 0.30, S)).toBe(0);
  });

  it('the highest category rate wins and Natural Stone insures with breakage', async () => {
    const stone = await marineInsurance.rateFor([null], S);
    expect(stone.rate_pct).toBe(0.3);
    expect(stone.wording).toBe('ICC (A) incl. breakage');
    expect(stone.category).toBe('Natural Stone');

    const glass = (await db.execute("INSERT INTO product_categories (name, is_system, is_active, sort_order) VALUES ('Jest Glassware', 0, 1, 9)")).insertId;
    const steel = (await db.execute("INSERT INTO product_categories (name, is_system, is_active, sort_order) VALUES ('Jest Steel', 0, 1, 9)")).insertId;
    createdCategories.push(glass, steel);
    await db.execute("UPDATE product_categories SET insurance_rate_pct = 0.4500, insurance_cover_wording = 'ICC (A) incl. breakage and leakage' WHERE id = :id", { id: glass });
    // A category without its own rate uses the default (0.20 %).
    expect((await marineInsurance.rateFor([steel], S)).rate_pct).toBe(0.2);
    expect((await marineInsurance.rateFor([steel], S)).wording).toBe('ICC (A) all risks');
    // Mixed: the highest rate among the order's categories.
    expect((await marineInsurance.rateFor([null, glass, steel], S)).rate_pct).toBe(0.45);
    expect((await marineInsurance.rateFor([null, steel], S)).rate_pct).toBe(0.3);
  });

  it('a CIF order without a figure gets the calculated one and a typed figure is kept', async () => {
    const order = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
    await orderProductRepository.add(order, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
    const insurance = async (id) => (await db.queryOne('SELECT indicative_insurance_amount AS v FROM orders WHERE id = :id', { id })).v;
    await db.execute('UPDATE orders SET indicative_freight_amount = 950, indicative_insurance_amount = NULL WHERE id = :id', { id: order });
    await marineInsurance.fillIfEmpty(order);
    expect(String(await insurance(order))).toBe('19.00');

    await db.execute('UPDATE orders SET indicative_insurance_amount = 25 WHERE id = :id', { id: order });
    await marineInsurance.fillIfEmpty(order);
    expect(String(await insurance(order))).toBe('25.00');

    // CFR and FOB orders never get insurance.
    for (const term of ['CFR', 'FOB']) {
      const o = await createTestOrder(await createTestClient(), term, 'Standard — New Buyer', 'EUR');
      await orderProductRepository.add(o, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
      await db.execute('UPDATE orders SET indicative_freight_amount = 950, indicative_insurance_amount = NULL WHERE id = :id', { id: o });
      await marineInsurance.fillIfEmpty(o);
      expect(await insurance(o)).toBeNull();
    }
  });

  it('the settings form and the category form check their values', async () => {
    const base = { margin_pct: '15', other_cost_pct: '5.5', pricing_fx_rate: '82', buffer_pct: '3', incentive_mode: 'pct', incentive_value: '15', stale_days: '60' };
    let [values, errors] = pricingService.readSettingsForm({ ...base, insurance_cover_pct: '110', insurance_rate_pct: '0.3', insurance_min_premium: '', insurance_rounding: 'up', insurance_add_gst: '0', insurance_cover_wording: 'ICC (A) all risks' });
    expect(errors).toEqual([]);
    expect(values.insurance_cover_pct).toBe('110.00');
    expect(values.insurance_rate_pct).toBe('0.3000');
    expect(values.insurance_min_premium).toBe('');
    [, errors] = pricingService.readSettingsForm({ ...base, insurance_cover_pct: '90', insurance_rate_pct: '0', insurance_cover_wording: '' });
    expect(errors).toHaveLength(3);
    // A form without the fields keeps the stored values (nothing is read).
    [values] = pricingService.readSettingsForm(base);
    expect(values).not.toHaveProperty('insurance_rate_pct');

    let [s, error] = await productCategoryRepository.parseSettings({ insurance_rate_pct: '0.35', insurance_cover_wording: 'ICC (A) incl. breakage' }, [], []);
    expect(error).toBeNull();
    expect(s.insurance_rate_pct).toBe('0.3500');
    [, error] = await productCategoryRepository.parseSettings({ insurance_rate_pct: '9' }, [], []);
    expect(String(error)).toContain('Insurance rate');
    [s] = await productCategoryRepository.parseSettings({ insurance_rate_pct: '' }, [], []);
    expect(s.insurance_rate_pct).toBeNull();
    // The global settings are read with their defaults until someone saves the page.
    expect((await marineInsurance.settings(await pricingRepository.settings())).cover_pct).toBe(110);
  });

  it('the estimated value line and the cover wording', async () => {
    const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const cif = documentDataAssembler.estimatedValue(false, true, 4500.0, '950.00', '19.00', money, 'EUR', [], false);
    expect(cif.term).toBe('CIF');
    expect(money(4519)).toBe('4,519.00'); // sanity of the helper
    expect(cif.total).toBe('5,469.00');
    expect(cif.breakdown).toBe('= FOB 4,500.00 + freight 950.00 + insurance 19.00');
    expect(cif.note).toBe('Advance and balance are on the FOB value. The Commercial Invoice will show FOB + the actual freight & insurance (billed by Debit Note). Open any Letter of Credit for this CIF value.');

    const cfr = documentDataAssembler.estimatedValue(false, false, 4500.0, '950.00', null, money, 'EUR', [], false);
    expect(cfr.term).toBe('CFR');
    expect(cfr.total).toBe('5,450.00');
    expect(cfr.breakdown).toBe('= FOB 4,500.00 + indicative freight 950.00');
    expect(cfr.note).toContain('Insurance is arranged by the buyer (CFR). Open any Letter of Credit for this CFR value.');

    // Freight not entered yet: the known part and "To Be Confirmed".
    const open = documentDataAssembler.estimatedValue(false, true, 8040.0, null, null, money, 'USD', [], false);
    expect(open.total).toBe('8,040.00 + freight and insurance To Be Confirmed');
    expect(open.breakdown).toBe('= FOB 8,040.00 + freight To Be Confirmed + insurance To Be Confirmed');

    // FOB: nothing.
    expect(documentDataAssembler.estimatedValue(true, false, 4500.0, '950', '19', money, 'EUR', [], false)).toBeNull();

    const order = await createTestOrder(await createTestClient(), 'CIF', 'Standard — New Buyer', 'EUR');
    await orderProductRepository.add(order, 1, 'Granite Memorial Headstone', '', 'Polished', '100', false, 'PCS', '45.00');
    expect(await documentDataAssembler.insuranceCoverText(order)).toBe('ICC (A) incl. breakage, 110% of CIF value — arranged by seller; actual premium billed by Debit Note.');
  });
});
