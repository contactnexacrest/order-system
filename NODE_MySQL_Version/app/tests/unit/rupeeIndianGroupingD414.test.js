'use strict';

// Owner decision D-414 (25 Nov 2026): every screen shows rupees in Indian grouping (₹1,85,477.76) — the `inr` /
// `inr0` filters, never `money` / `money0`, which group the Western way (₹185,477.76). Exempt: exchange rates
// ("₹84.00 per USD") and plain numbers in input values. CSV files and PDFs are unchanged.
// PHP twin: RupeeIndianGroupingD414Test.php.

const fs = require('fs');
const path = require('path');
const { indian } = require('../../src/helpers/moneyFormat');

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]));
}

describe('D-414 rupees in Indian grouping on every screen', () => {
  it('no view groups rupees the Western way', () => {
    const root = path.join(__dirname, '../../views');
    const bad = [];
    for (const file of walk(root).filter((f) => f.endsWith('.njk'))) {
      fs.readFileSync(file, 'utf8').split('\n').forEach((raw, n) => {
        if (raw.includes('per USD')) return;
        const line = raw.replace(/value="[^"]*"/g, 'value=""');
        if (/(₹|&#8377;)\{\{[^}]*\|\s*money0?\s*\}\}|_inr\s*\|\s*money0?\b/.test(line)) bad.push(`${path.relative(root, file)}:${n + 1}`);
      });
    }
    expect(bad).toEqual([]);
  });

  it('groups the Indian way', () => {
    expect(indian(185477.76)).toBe('1,85,477.76');
    expect(indian(500000, 0)).toBe('5,00,000');
    expect(indian(-1234567)).toBe('-12,34,567.00');
    expect(indian(999.5)).toBe('999.50');
  });
});
