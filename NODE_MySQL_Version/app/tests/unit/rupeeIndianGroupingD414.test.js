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

describe('D-414 follow-up: messages the app writes use Indian grouping too', () => {
  // Owner, 25 Nov 2026 ("go ahead with 6"). Stored money descriptions, audit entries and documents are unchanged.
  const mf = require('../../src/helpers/moneyFormat');
  it('regroups a Western-formatted amount and leaves anything else alone', () => {
    expect(mf.regroup('1,234,567.89')).toBe('12,34,567.89');
    expect(mf.regroup('500,000')).toBe('5,00,000');
    expect(mf.regroup('84.1000')).toBe('84.1000');
    expect(mf.regroup('-12,345.50')).toBe('-12,345.50');
    expect(mf.regroup('none')).toBe('none');
  });
  it('writes the messages the same as PHP', () => {
    expect(require('../../src/services/courierCharges').parse({ courier_charges_inr: '150000' }))
      .toBe('Courier charges of ₹1,50,000.00 look too high for one document courier (more than ₹1,00,000.00). Check the courier bill.');
    expect(require('../../src/services/remittanceInr').statement('USD', 1800, null, 84.1))
      .toBe('Received USD 1,800.00 at the exchange rate of ₹84.1000 (≈ ₹1,51,380.00, estimated until the INR credited is known).');
    const w = require('../../src/helpers/vendorBillsView').toPayNow({ doc_kind: 'invoice', amount_due: 250000, requested_amount_inr: null, carried_paid: 0, paid: 0, remaining: 250000 });
    expect(w.working).toContain('bill total ₹2,50,000.00');
  });
});

