'use strict';

// Owner decision D-339 (12 Nov 2026, owner "confirm 1" and "confirm 2 with option a"):
// the detailed product table — measures and rounding, which table a document gets
// (one unit / mixed units / no size / as per annexure / PCS / lines not sold by size),
// the totals by unit, the line total never worked out again, and the No. of pieces.
// PHP twin: tests/Unit/LineTableTest.php (same cases, same wording).
const lineTable = require('../../src/services/lineTable');
const unitPrices = require('../../src/services/unitPrices');

function line(id, desc, unit, l, w, h, pcs, qty, price, extra = {}) {
  const amount = qty !== null && price !== null ? lineTable.roundTo(Number(qty) * Number(price), 2).toFixed(2) : null;
  return {
    id, description: desc, finish: extra.finish ?? 'P6', unit, line_type: extra.line_type ?? 'goods',
    piece_length_cm: l, piece_width_cm: w, piece_thickness_cm: h, pieces_count: pcs,
    quantity: qty, quantity_is_tbc: 0, unit_price: price, fob_value: amount, hs_code: extra.hs_code ?? '68022310',
    as_per_annexure: extra.as_per_annexure ?? 0, size_note: extra.size_note ?? null, dimensions: extra.dimensions ?? null,
  };
}
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function formatted(lines) {
  return lines.map((l) => ({
    description: l.description, quantity: String(l.quantity), unit: l.unit,
    unit_price: l.unit_price !== null ? money(l.unit_price) : 'To Be Confirmed',
    amount: l.fob_value !== null ? money(l.fob_value) : 'To Be Confirmed',
    line_type: l.line_type, attributes: [], sac_code: null, dimensions: l.dimensions, finish: l.finish, hs_code: l.hs_code,
  }));
}
const build = (lines, currency = 'USD', letter = null, prices = true) => lineTable.build(lines, formatted(lines), currency, letter, prices);

describe('D-339 detailed product table', () => {
  it('measures and rounding', () => {
    expect(lineTable.perPiece('CBM', 180, 80, 13)).toBe(0.1872);
    expect(lineTable.perPiece('SQM', 60, 60, 2)).toBe(0.36);
    expect(lineTable.perPiece('SQM', 140, 70, null)).toBe(0.98);
    expect(lineTable.perPiece('SQFT', 180, 80, 2)).toBe(15.5);
    expect(lineTable.perPiece('SQFT', 140, 70, 2)).toBe(10.55);
    expect(lineTable.perPiece('CBM', 180, 80, null)).toBeNull(); // CBM needs the thickness
    expect(lineTable.perPiece('PCS', 180, 80, 13)).toBeNull(); // a piece has no measure
    expect(lineTable.perPiece('TON', 180, 80, 13)).toBeNull();
    expect(lineTable.quantityFromPieces('CBM', 180, 80, 13, 25)).toBe('4.680');
    expect(lineTable.quantityFromPieces('CBM', 140, 70, 13, 25)).toBe('3.185');
    expect(lineTable.quantityFromPieces('CBM', 120, 60, 13, 10)).toBe('0.936');
    expect(lineTable.quantityFromPieces('SQM', 60, 60, 2, 500)).toBe('180.000');
    expect(lineTable.quantityFromPieces('SQFT', 140, 70, 2, 25)).toBe('263.750');
    expect(lineTable.quantityFromPieces('PCS', null, null, null, 20)).toBe('20.000');
    expect(lineTable.pricePerPiece(1950, 0.1872)).toBe(365.04);
    expect(lineTable.pricePerPiece(175000, 0.1872)).toBe(32760);
    expect(lineTable.summary('CBM', 180, 80, 13, 25, 1950, 'USD'))
      .toBe('This line: 0.1872 CBM per piece · 25 pcs = 4.680 CBM · USD 365.04 per piece · total USD 9,126.00');
  });

  it("the owner's example: one unit, full table", () => {
    const t = build([
      line(1, 'CHM Black', 'CBM', 180, 80, 13, 25, '4.680', '175000.00'),
      line(2, 'CHM Black', 'CBM', 140, 70, 13, 25, '3.185', '175000.00'),
      line(3, 'CHM Black', 'CBM', 120, 60, 13, 10, '0.936', '175000.00'),
    ], 'INR');
    expect(t.variant).toBe('full');
    expect(t.unit).toBe('CBM');
    expect(t.heads.top.map((x) => x.text)).toEqual(['Material', 'Surface', 'Dimensions (cm)', 'CBM', 'Qty', 'CBM', 'Price (INR)', 'Price (INR)', 'Total Price']);
    expect(t.heads.bottom).toEqual(['Colour', 'Finish', 'L', 'W', 'H', 'per piece', 'No. of pcs', 'all pcs', 'per CBM', 'per piece', '(INR)']);
    const r = t.rows[0];
    expect([r.l, r.w, r.h, r.per_piece, r.pcs, r.all_pcs, r.price_unit, r.price_piece, r.total])
      .toEqual(['180', '80', '13', '0.1872', '25', '4.680', '175,000.00', '32,760.00', '819,000.00']);
    expect([t.total.pcs, t.total.measure, t.total.amount]).toEqual(['60', '8.801', '1,540,175.00']);
    expect(t.totals_by_unit).toBeNull();
    expect(r.detail).toEqual([{ label: 'HS Code:', text: '68022310' }, { label: 'Country of Origin:', text: 'India' }]);
  });

  it('the line total is never worked out again', () => {
    // A total that is not price per piece × pieces (rounded figures) prints as stored.
    const l = line(1, 'CHM Black', 'SQFT', 140, 70, 2, 25, '263.75', '18.00');
    l.fob_value = '4747.50';
    const t = build([l]);
    expect(t.rows[0].total).toBe('4,747.50');
    expect(t.total.amount).toBe('4,747.50');
    expect(t.rows[0].price_piece).toBe('189.90');
  });

  it('mixed units name their unit and total by unit', () => {
    const t = build([
      line(1, 'CHM Black slabs', 'CBM', 180, 80, 13, 25, '4.680', '1950.00'),
      line(2, 'CHM Black tiles', 'SQM', 60, 60, 2, 500, '180.00', '32.00', { finish: 'Polished' }),
      line(3, 'Carved planter', 'PCS', 60, 40, 40, 20, '20', '85.00', { finish: 'Honed', hs_code: '68029100' }),
    ]);
    expect(t.mixed).toBe(true);
    expect(t.heads.bottom).toEqual(['Colour', 'Finish', 'L', 'W', 'H', 'per piece', 'No. of pcs', 'all pcs', 'per unit', 'per piece', '(USD)']);
    expect(t.heads.top[3].text).toBe('Measure');
    expect([t.rows[0].per_piece, t.rows[0].all_pcs, t.rows[0].price_unit, t.rows[0].price_piece]).toEqual(['0.1872 CBM', '4.680 CBM', '1,950.00 /CBM', '365.04']);
    expect([t.rows[1].per_piece, t.rows[1].all_pcs, t.rows[1].price_unit, t.rows[1].price_piece]).toEqual(['0.36 SQM', '180.00 SQM', '32.00 /SQM', '11.52']);
    // PCS: no measure; the price per unit is the price per piece.
    expect([t.rows[2].per_piece, t.rows[2].pcs, t.rows[2].all_pcs, t.rows[2].price_unit, t.rows[2].price_piece]).toEqual(['—', '20', '—', '85.00 /PCS', '85.00']);
    expect([t.total.pcs, t.total.measure, t.total.amount]).toEqual(['545', '', '16,586.00']);
    expect(t.totals_by_unit).toBe('Totals by unit: 4.680 CBM · 180.00 SQM · 20 PCS');
  });

  it('no size gives the short table with the size note', () => {
    const t = build([
      line(1, 'CHM Black — gangsaw blocks, random sizes', 'CBM', null, null, null, null, '25', '1950.00', { finish: 'Rough', size_note: 'random, as available', hs_code: '25161100' }),
      line(2, 'CHM Black slabs', 'CBM', null, null, 13, null, '8.801', '1950.00'),
    ]);
    expect(t.variant).toBe('short');
    expect(t.heads.top.map((x) => x.text)).toEqual(['Material', 'Surface', 'Thickness', 'Qty', 'Price (USD)', 'Total Price']);
    expect(t.heads.bottom).toEqual(['Colour', 'Finish', '(cm)', 'CBM', 'per CBM', '(USD)']);
    expect([t.rows[0].thickness, t.rows[0].qty, t.rows[0].price_unit, t.rows[0].total]).toEqual(['—', '25.000', '1,950.00', '48,750.00']);
    expect(t.rows[1].thickness).toBe('13');
    expect(t.rows[0].detail[0]).toEqual({ label: 'Sizes:', text: 'random, as available' });
    expect([t.total.measure, t.total.amount]).toEqual(['33.801', '65,911.95']);
  });

  it('a line without size in a full table shows dashes', () => {
    const t = build([
      line(1, 'CHM Black', 'CBM', 180, 80, 13, 25, '4.680', '1950.00'),
      line(2, 'CHM Black — random sizes', 'CBM', null, null, null, null, '25.000', '1950.00', { finish: 'Rough' }),
    ]);
    expect(t.variant).toBe('full');
    const r = t.rows[1];
    expect([r.l, r.w, r.h, r.per_piece, r.pcs, r.all_pcs, r.price_unit, r.price_piece, r.total])
      .toEqual(['—', '—', '—', '—', '—', '25.000', '1,950.00', '—', '48,750.00']);
    expect([t.total.pcs, t.total.measure]).toEqual(['25', '29.680']);
  });

  it('an "As per annexure" line', () => {
    const lines = [
      line(1, 'CHM Black', 'CBM', 180, 80, 13, 25, '4.680', '175000.00'),
      line(4, 'CHM Black', 'CBM', null, null, null, null, '2.400', '175000.00', { finish: 'Leather', as_per_annexure: 1 }),
    ];
    const t = build(lines, 'INR', 'A');
    const r = t.rows[1];
    expect(r.annexure).toBe('As per Annexure A');
    expect([r.per_piece, r.pcs, r.all_pcs, r.price_unit, r.price_piece, r.total]).toEqual(['—', '—', '2.400', '175,000.00', '—', '420,000.00']);
    expect(r.detail[0]).toEqual({ label: null, text: 'Qty as per Annexure A' });
    expect([t.total.pcs, t.total.measure, t.total.amount]).toEqual(['25', '7.080', '1,239,000.00']);
    // Annexure B on the CI / PL when chosen (D-336).
    expect(build(lines, 'INR', 'B').rows[1].annexure).toBe('As per Annexure B');
  });

  it("services and lines not sold by size keep today's rows", () => {
    const t = build([
      line(1, 'CHM Black', 'SQFT', 180, 80, 2, 25, '387.50', '18.00'),
      line(2, 'Inspection', 'LOT', null, null, null, null, '1', '150.00', { line_type: 'service' }),
      line(3, 'Fixing kit', 'SET', null, null, null, null, '2', '40.00'),
    ]);
    expect(t.unit).toBe('SQFT');
    expect(t.rows).toHaveLength(1);
    expect(t.simple.map((s) => s.no)).toEqual([2, 3]);
    expect(t.simple.map((s) => s.description)).toEqual(['Inspection', 'Fixing kit']);
    // Only services and charges: no detailed table at all.
    expect(build([line(1, 'Freight', 'LOT', null, null, null, null, '1', '900.00', { line_type: 'charge' })]).variant).toBe('none');
  });

  it('the Packing List has no prices', () => {
    const t = build([line(1, 'CHM Black', 'CBM', 180, 80, 13, 25, '4.680', '1950.00')], 'USD', null, false);
    expect(t.heads.bottom).toEqual(['Colour', 'Finish', 'L', 'W', 'H', 'per piece', 'No. of pcs', 'all pcs', 'Packages']);
    expect(t.heads.top[t.heads.top.length - 1].text).toBe('Crates /');
  });

  it('the server works the quantity out from the pieces', () => {
    // With the size in a measure unit the pieces are required and the quantity is worked out.
    expect(lineTable.serverQuantity('CBM', 180, 80, 13, null, false, false))
      .toEqual([null, 'enter the No. of pieces — with the size given, the quantity is worked out from the pieces.']);
    expect(lineTable.serverQuantity('CBM', 180, 80, 13, 25, false, false)).toEqual(['4.680', null]);
    expect(lineTable.serverQuantity('PCS', null, null, null, 20, false, false)).toEqual(['20.000', null]);
    // Without a size: the typed quantity stands; as per annexure: D-336 unchanged.
    expect(lineTable.serverQuantity('CBM', null, null, null, 25, false, false)).toEqual([null, null]);
    expect(lineTable.serverQuantity('CBM', 180, 80, 13, null, true, false)).toEqual([null, null]);
    expect(lineTable.readPieces('2.5')).toEqual([null, 'No. of pieces must be a whole number from 1 to 9999999 (e.g. 25).']);
    expect(lineTable.readPieces(' 25 ')).toEqual([25, null]);
  });

  it('prices in other units', () => {
    // Mockup screen 2: USD 1,950.00 per CBM at 2 cm = 39.00 per SQM = 3.62 per SQFT.
    expect(unitPrices.convert(1950, 'CBM', 20)).toEqual({ CBM: 1950, SQM: 39, SQFT: 3.62 });
    expect(unitPrices.convert(1950, 'CBM', 30)).toEqual({ CBM: 1950, SQM: 58.5, SQFT: 5.43 });
    expect(unitPrices.convert(1950, 'CBM', 130)).toEqual({ CBM: 1950, SQM: 253.5, SQFT: 23.55 });
    // Typed per SQM: per SQFT without a thickness; per CBM needs one.
    expect(unitPrices.convert(41.2, 'SQM', null)).toEqual({ CBM: null, SQM: 41.2, SQFT: 3.83 });
    expect(unitPrices.convert(null, 'SQM', 20)).toEqual({ CBM: null, SQM: null, SQFT: null });
  });
});
