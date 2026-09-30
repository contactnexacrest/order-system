'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Point 9 — HS codes must be 6-8 plain digits, no dots (see
 * hsCodeController's validation regex). sampleDataService used to seed
 * demo orders with the app's own former dotted default ('6802.93',
 * '2516.11') even after that format was declared wrong everywhere else —
 * a fresher browsing sample data would see the "wrong" format held up as
 * an example. This guards against that regressing: no dotted digit-group
 * HS-code-shaped literal may appear in sampleDataService's source.
 */
describe('HS code format consistency (Point 9)', () => {
  it('sampleDataService contains no dotted HS code literals', () => {
    const lines = fs.readFileSync(path.join(__dirname, '../../src/services/sampleDataService.js'), 'utf8').split('\n');
    for (const line of lines) {
      // Money amounts elsewhere in this file are also N.NN literals (e.g.
      // '95000.00'), so only lines that actually mention hs_code are in
      // scope here.
      if (!/hs_code/i.test(line)) continue;
      expect(line).not.toMatch(/['"]\d{4}\.\d{2}['"]/);
    }
  });

  it('orderProductRepository default hs_code is six or eight digits, no dots', () => {
    const source = fs.readFileSync(path.join(__dirname, '../../src/repositories/orderProductRepository.js'), 'utf8');
    const matches = [...source.matchAll(/hs_code.*?['"](\d+(?:\.\d+)?)['"]/g)].map((m) => m[1]);
    expect(matches.length).toBeGreaterThan(0);
    for (const code of matches) {
      expect(code).toMatch(/^\d{6}$|^\d{8}$/);
    }
  });
});
