'use strict';

const lineUnits = require('../../src/services/lineUnits');

/**
 * Port of LineUnitsTest.php — owner decision D-270 (point 25, 11 Nov 2026, double-confirmed):
 * a line price in any unit is converted to the product's reference unit with the piece size.
 * The figures are the owner's example on mockup p25b (180 × 80 × 13 cm = 0.1872 CBM).
 */
describe('D-270 line units', () => {
  it('recognises units with their usual spellings', () => {
    expect(['cbm', 'SQM', 'm²', 'Sq Ft', 'PCS', 'NOS', 'RMT', 'TON', 'MT', 'SET', 'LOT', ''].map(lineUnits.canonical))
      .toEqual(['CBM', 'M2', 'M2', 'SQFT', 'PCS', 'PCS', 'RMT', 'TON', 'TON', null, null, null]);
  });

  it('one piece of 180 × 80 × 13 cm is 0.1872 CBM', () => {
    const f = lineUnits.factor('PCS', 'CBM', 180, 80, 13, 2.7);
    expect(f.factor).toBeCloseTo(0.1872, 9);
    expect(f.missing).toEqual([]);
    expect((495 / f.factor).toFixed(2)).toBe('2644.23');
    expect((320 / lineUnits.factor('PCS', 'CBM', 140, 70, 13, null).factor).toFixed(2)).toBe('2511.77');
  });

  it('square metre and square feet need no thickness between them', () => {
    expect(lineUnits.factor('SQM', 'SQFT', null, null, null, null).factor).toBeCloseTo(10.7639, 9);
    expect(lineUnits.factor('SQFT', 'SQM', null, null, null, null).factor).toBeCloseTo(1 / 10.7639, 12);
  });

  it('square metre to CBM uses the thickness', () => {
    expect(lineUnits.factor('SQM', 'CBM', null, null, 2, null).factor).toBeCloseTo(0.02, 12);
    expect(lineUnits.factor('SQM', 'CBM', null, null, null, null).missing).toEqual(['piece_thickness_cm']);
  });

  it('running metre needs width and thickness', () => {
    expect(lineUnits.factor('RMT', 'CBM', null, 30, 3, null).factor).toBeCloseTo(0.3 * 0.03, 12);
    expect(lineUnits.factor('RMT', 'CBM', null, null, null, null).missing).toEqual(['piece_width_cm', 'piece_thickness_cm']);
  });

  it('pieces need all three sizes', () => {
    expect(lineUnits.factor('PCS', 'CBM', null, null, null, null).missing).toEqual(['piece_length_cm', 'piece_width_cm', 'piece_thickness_cm']);
    expect(lineUnits.factor('PCS', 'CBM', null, 80, 13, null).missing).toEqual(['piece_length_cm']);
  });

  it('Ton uses the weight per CBM', () => {
    expect(lineUnits.factor('TON', 'CBM', null, null, null, 2.7).factor).toBeCloseTo(1 / 2.7, 12);
    expect(lineUnits.factor('TON', 'CBM', null, null, null, null).missing).toEqual(['weight']);
  });

  it('Set and Lot cannot be converted, and the same unit needs nothing', () => {
    expect(lineUnits.factor('SET', 'CBM', null, null, null, null).unconvertible).toBe(true);
    expect(lineUnits.factor('PCS', 'LOT', 1, 1, 1, null).unconvertible).toBe(true);
    expect(lineUnits.factor('CBM', 'CBM', null, null, null, null).factor).toBe(1);
    expect(lineUnits.factor('SQM', 'm2', null, null, null, null).factor).toBe(1);
    expect(lineUnits.factor('', 'CBM', null, null, null, null).factor).toBe(1);
  });

  it('missing messages ask for the size', () => {
    expect(lineUnits.missingMessage('piece_length_cm', 'PCS', 'CBM')).toBe("Enter the length (cm) — needed to compare a price per piece with the product's price per CBM.");
    expect(lineUnits.missingMessage('piece_thickness_cm', 'SQM', 'CBM')).not.toContain('too low');
  });

  it('totals: CBM and containers of 8 CBM', () => {
    expect(lineUnits.cbmPerUnit('PCS', 180, 80, 13, null)).toBeCloseTo(0.1872, 9);
    expect(lineUnits.cbmPerUnit('PCS', null, 80, 13, null)).toBeNull();
    expect([0, 0.5, 8, 8.01, 23.1].map(lineUnits.containers)).toEqual([0, 1, 1, 2, 3]);
  });

  it('dimensions text and size input', () => {
    expect(lineUnits.dimensionsText(180, 80, 13)).toBe('180 × 80 × 13 cm');
    expect(lineUnits.dimensionsText(null, null, 2.5)).toBe('2.5 cm');
    const [size, err] = lineUnits.readSize({ piece_length_cm: '180', piece_width_cm: '80.5', piece_thickness_cm: '' });
    expect(err).toBeNull();
    expect(size).toEqual({ piece_length_cm: '180.00', piece_width_cm: '80.50', piece_thickness_cm: null });
    expect(lineUnits.readSize({ piece_length_cm: '-1' })[1]).not.toBeNull();
    expect(lineUnits.readSize({ piece_width_cm: '1.234' })[1]).not.toBeNull();
    expect(lineUnits.readSize({ piece_thickness_cm: '20000' })[1]).not.toBeNull();
  });

  it('a product without a reference unit is compared as it is', () => {
    // productSpecs labels a product with no reference unit "unit": any line unit compares 1:1, never "needs MD approval".
    expect(lineUnits.factor('PCS', 'unit', null, null, null, null)).toEqual({ factor: 1, missing: [], unconvertible: false });
    expect(lineUnits.factor('SQM', '', null, null, null, null).factor).toBe(1);
    expect(lineUnits.factor('BOX', 'CBM', null, null, null, null).unconvertible).toBe(true);
  });
});
