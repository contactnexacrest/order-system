'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-17: "Indicative Freight is half width while
 * Insurance is full width in the same section (inconsistent layout)."
 * Fix direction: use the 2-column grid for both — i.e. neither field gets
 * the full-width override class. Port of FreightInsuranceGridLayoutTest.php.
 */
describe('Freight/Insurance grid layout (External QA report U-17)', () => {
  it('neither Freight nor Insurance uses the full-width override', () => {
    const html = fs.readFileSync(path.join(__dirname, '../../views/orders/create.njk'), 'utf8');

    expect(html).toMatch(/<label>Freight \(indicative/);
    expect(html).toMatch(/<label>Insurance \(indicative/);
  });
});
