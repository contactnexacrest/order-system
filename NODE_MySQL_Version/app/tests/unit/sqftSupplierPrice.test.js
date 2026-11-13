'use strict';

const lineUnits = require('../../src/services/lineUnits');
const supplierOffers = require('../../src/services/supplierOffers');
const thicknessPrices = require('../../src/services/thicknessPrices');

/**
 * Port of SqftSupplierPriceTest.php — owner decision D-364 (13 Nov 2026, "go with recommendation",
 * mockup sqm_sqft_mockup): a supplier price per SQM may be typed per SQFT — it is saved as the SQM
 * price (× 10.7639), and what was typed is kept in the notes and the price history. CBM stays for
 * 50 mm and above; a product whose own unit is SQFT keeps its SQFT price. The same unit names on
 * every screen: SQM, SQFT, CBM.
 */
describe('D-364 supplier price typed per SQFT', () => {
  test('SQFT is converted to the SQM price', () => {
    expect(supplierOffers.sqftToSqm(185)).toBe(1991.32);
    expect(supplierOffers.sqftToSqm(200)).toBe(2152.78);
    expect(supplierOffers.typedText(185)).toBe('typed ₹185 / SQFT = ₹1,991.32 / SQM (× 10.7639)');
  });

  test('only a price per SQM may be typed per SQFT', () => {
    const grid = supplierOffers.fromSqft({ price: 185, unit: 'SQFT', mm: 20 }, { reference_unit: 'SQM' }, true);
    expect([grid.price, grid.unit, grid.typed_sqft]).toEqual([1991.32, 'm2', 185]);
    const cbm = supplierOffers.fromSqft({ price: 185, unit: 'SQFT', mm: thicknessPrices.CBM }, { reference_unit: 'SQM' }, true);
    expect([cbm.price, cbm.typed_sqft]).toEqual([185, null]); // 50 mm and above is per CBM
    const sqm = supplierOffers.fromSqft({ price: 185, unit: 'SQM', mm: null }, { reference_unit: 'SQM' }, false);
    expect([sqm.price, sqm.typed_sqft]).toEqual([185, null]);
    const perUnit = supplierOffers.fromSqft({ price: 185, unit: 'SQFT', mm: null }, { reference_unit: 'SQM' }, false);
    expect([perUnit.price, perUnit.unit]).toEqual([1991.32, 'SQM']);
    const ownSqft = supplierOffers.fromSqft({ price: 185, unit: 'SQFT', mm: null }, { reference_unit: 'SQFT' }, false);
    expect([ownSqft.price, ownSqft.typed_sqft]).toEqual([185, null]); // a product sold per SQFT keeps its SQFT price
  });

  test('the notes keep what was typed and the staff notes', () => {
    const n = supplierOffers.notesWithTyped('Ex-works, Grade A', 185);
    expect(n).toBe('Ex-works, Grade A\ntyped ₹185 / SQFT = ₹1,991.32 / SQM (× 10.7639)');
    expect(supplierOffers.notesWithTyped(n, 200)).toBe('Ex-works, Grade A\ntyped ₹200 / SQFT = ₹2,152.78 / SQM (× 10.7639)');
    expect(supplierOffers.notesWithTyped(n, null)).toBe('Ex-works, Grade A');
    expect(supplierOffers.notesWithTyped(null, null)).toBeNull();
  });

  test('the unit names are SQM and SQFT', () => {
    expect([lineUnits.label('M2'), lineUnits.label('sq ft'), lineUnits.label('cbm')]).toEqual(['SQM', 'SQFT', 'CBM']);
  });
});
