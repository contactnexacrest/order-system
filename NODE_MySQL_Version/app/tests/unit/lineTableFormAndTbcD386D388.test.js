'use strict';

// Owner decisions D-386 and D-388 (18 Nov 2026, document changes confirmed twice), the pure part:
//  - D-386: the grey line of the detailed table prints "Product Form: <form>" immediately before
//    "HS Code:" for a line with a Sold as (the form comes in names, set only for the QT / PI / OC /
//    CI); a line without one prints exactly as before;
//  - D-388: a line whose quantity is "To Be Confirmed" never prints 0.00, and the TOTAL is
//    "To Be Confirmed" (every line TBC), "<known> + line 2 To Be Confirmed" (one) or
//    "<known> + lines 2, 4 To Be Confirmed" (several).
// PHP twin: tests/Unit/LineTableFormAndTbcD386D388Test.php.
const lineTable = require('../../src/services/lineTable');
const moneyFormat = require('../../src/helpers/moneyFormat');

function line(id, unit, qty, price, tbc = false) {
  const amount = !tbc && qty !== null && price !== null ? lineTable.roundTo(Number(qty) * Number(price), 2).toFixed(2) : null;
  return {
    id, description: `Black Galaxy ${id}`, finish: 'Polished', unit, line_type: 'goods',
    piece_length_cm: null, piece_width_cm: null, piece_thickness_cm: null, pieces_count: null,
    quantity: tbc ? null : qty, quantity_is_tbc: tbc ? 1 : 0, unit_price: price, fob_value: amount,
    hs_code: '25161100', as_per_annexure: 0, size_note: null, dimensions: null,
  };
}

function formatted(lines) {
  return lines.map((l) => ({
    description: l.description, quantity: l.quantity_is_tbc ? 'To Be Confirmed' : String(l.quantity), unit: l.unit,
    unit_price: l.unit_price !== null ? moneyFormat.amount(l.unit_price, 'USD') : 'To Be Confirmed',
    amount: l.fob_value !== null ? moneyFormat.amount(l.fob_value, 'USD') : 'To Be Confirmed',
    line_type: 'goods', attributes: [], sac_code: null, dimensions: null, finish: l.finish, hs_code: l.hs_code,
  }));
}

const build = (lines, names = {}) => lineTable.build(lines, formatted(lines), 'USD', null, true, {}, names);
const labels = (row) => row.detail.map((d) => String(d.label));

describe('D-386 / D-388 detailed table: Product Form and "To Be Confirmed" totals', () => {
  it('D-386: Product Form comes immediately before the HS Code', () => {
    const t = build([line(11, 'CBM', '25', '1950.00'), line(12, 'CBM', '10', '1950.00')], { 11: { form: 'Raw block' } });
    expect(labels(t.rows[0])).toEqual(['Product Form:', 'HS Code:', 'Country of Origin:']);
    expect(t.rows[0].detail[0].text).toBe('Raw block');
    // A line without a Sold as prints exactly as before.
    expect(labels(t.rows[1])).toEqual(['HS Code:', 'Country of Origin:']);
    // No form passed (Buyer PO, Packing List, every other document): no "Product Form".
    const plain = build([line(11, 'CBM', '25', '1950.00')]);
    expect(labels(plain.rows[0])).not.toContain('Product Form:');
  });

  it('D-386: after the size note and attributes', () => {
    const l = { ...line(21, 'CBM', '25', '1950.00'), size_note: 'Length 160–210 cm' };
    const t = build([l], { 21: { form: 'Sawn block' } });
    expect(labels(t.rows[0])).toEqual(['Sizes:', 'Product Form:', 'HS Code:', 'Country of Origin:']);
  });

  it('D-388: the TBC total wording', () => {
    expect(lineTable.tbcTotal('9,750.00', [], false)).toBe('9,750.00'); // no line to be confirmed: as today
    expect(lineTable.tbcTotal('0.00', [1, 2], true)).toBe('To Be Confirmed');
    expect(lineTable.tbcTotal('9,750.00', [2], false)).toBe('9,750.00 + line 2 To Be Confirmed');
    expect(lineTable.tbcTotal('9,750.00', [4, 2], false)).toBe('9,750.00 + lines 2, 4 To Be Confirmed');
  });

  it('D-388: every line TBC — the total is "To Be Confirmed"', () => {
    const t = build([line(1, 'CBM', null, '1950.00', true), line(2, 'CBM', null, '1800.00', true)]);
    expect(t.total.amount).toBe('To Be Confirmed');
    expect(t.rows.map((r) => r.total)).toEqual(['To Be Confirmed', 'To Be Confirmed']); // a TBC line never prints 0.00
    expect(t.rows[0].qty).toBe('To Be Confirmed');
    expect(t.rows[0].price_unit).toBe('1,950.00'); // the price per unit prints as today
  });

  it('D-388: one line TBC', () => {
    const t = build([line(1, 'CBM', '5', '1950.00'), line(2, 'CBM', null, '1800.00', true)]);
    expect(t.total.amount).toBe('9,750.00 + line 2 To Be Confirmed');
    expect(t.rows.map((r) => r.total)).toEqual(['9,750.00', 'To Be Confirmed']);
  });

  it('D-388: several lines TBC', () => {
    const t = build([
      line(1, 'CBM', '5', '1950.00'), line(2, 'CBM', null, '1800.00', true),
      line(3, 'CBM', '2', '1000.00'), line(4, 'CBM', null, '1700.00', true),
    ]);
    expect(t.total.amount).toBe('11,750.00 + lines 2, 4 To Be Confirmed');
  });
});
