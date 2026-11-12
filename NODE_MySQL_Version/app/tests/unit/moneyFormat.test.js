'use strict';

// Owner decision D-343 (12 Nov 2026, owner "1 yes, 2 yes, 3 yes" and "confirm 2"):
// Indian digit grouping for the money of an INR order's documents, and the total CBM of a
// detailed order's lines for the Weight & Volume box.
// PHP twin: tests/Unit/MoneyFormatTest.php (same cases, same wording).
const moneyFormat = require('../../src/helpers/moneyFormat');
const lineTable = require('../../src/services/lineTable');

describe('D-343 money format and total CBM', () => {
  it('Indian grouping', () => {
    const cases = [
      [0, '0.00'], [999, '999.00'], [1000, '1,000.00'], [100000, '1,00,000.00'], [175000, '1,75,000.00'],
      [616070, '6,16,070.00'], [1540175, '15,40,175.00'], [123456789.5, '12,34,56,789.50'],
      [-1540175, '-15,40,175.00'], [-999.5, '-999.50'], ['32760.004', '32,760.00'], [0.005, '0.01'],
    ];
    for (const [value, expected] of cases) expect([value, moneyFormat.indian(value)]).toEqual([value, expected]);
  });

  it('only INR uses Indian grouping', () => {
    expect(moneyFormat.amount(1540175, 'INR')).toBe('15,40,175.00');
    expect(moneyFormat.amount(1540175, ' inr ')).toBe('15,40,175.00');
    for (const currency of ['USD', 'EUR', 'GBP', 'AED', '', null]) {
      expect([currency, moneyFormat.amount(1540175, currency)]).toEqual([currency, '1,540,175.00']);
      expect([currency, moneyFormat.amount(-123456789.5, currency)]).toEqual([currency, '-123,456,789.50']);
    }
  });

  const line = (unit, l, w, h, pcs, qty, extra = {}) => ({
    line_type: 'goods', unit, piece_length_cm: l, piece_width_cm: w, piece_thickness_cm: h, pieces_count: pcs, quantity: qty, quantity_is_tbc: 0, ...extra,
  });

  it('total CBM of the lines', () => {
    // The owner's example: CHM Black P6 180 × 80 × 13 × 25, 140 × 70 × 13 × 25, 120 × 60 × 13 × 10.
    const owner = [line('CBM', 180, 80, 13, 25, '4.680'), line('CBM', 140, 70, 13, 25, '3.185'), line('CBM', 120, 60, 13, 10, '0.936')];
    expect(lineTable.totalCbm(owner)).toBe(8.801);
    // A CBM line without a size counts its quantity; a SQM / SQFT line with a thickness its area × thickness.
    expect(lineTable.totalCbm([...owner, line('CBM', null, null, null, null, '1.200')])).toBe(10.001);
    expect(lineTable.totalCbm([...owner, line('SQM', null, null, 2, null, '18.00')])).toBe(9.161);
    expect(lineTable.totalCbm([line('SQFT', null, null, 2, null, '107.639')])).toBe(0.2);
    // Services and charges are not counted.
    expect(lineTable.totalCbm([...owner, line('LOT', null, null, null, null, '1', { line_type: 'charge' })])).toBe(8.801);
    // A goods line that cannot be turned into CBM: "To Be Confirmed".
    expect(lineTable.totalCbm([...owner, line('SQM', 60, 60, null, 500, '180.00')])).toBeNull(); // SQM without a thickness
    expect(lineTable.totalCbm([...owner, line('PCS', null, null, null, null, '20')])).toBeNull(); // PCS without a size
    expect(lineTable.totalCbm([...owner, line('TON', null, null, null, null, '3')])).toBeNull(); // another unit
    expect(lineTable.totalCbm([...owner, line('CBM', null, null, null, null, null, { quantity_is_tbc: 1 })])).toBeNull(); // a quantity to be confirmed
    expect(lineTable.totalCbm([])).toBeNull(); // no goods line
  });
});
