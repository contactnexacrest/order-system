'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-07: "New Order product line is cramped: 7 inputs
 * in one row, placeholders cut off." (Unit and HS Code were already
 * admin-managed dropdowns/validated against the master list from an
 * earlier fix — M-05's generic dropdown_options admin screen already
 * covers "Product units add/edit/delete" via its 'unit' list_key, and
 * ordersController.store() already rejects any HS code not on the master
 * list — so the only remaining gap here is the layout itself.) Port of
 * PHP's ProductRowLayoutTest.
 */
describe('Product row layout (External QA report U-07)', () => {
  it('product-row is a two-row four-column grid, not a single cramped row', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');

    expect(css).toMatch(/\.product-row\{display:grid;grid-template-columns:repeat\(4,\s*minmax\(150px,\s*1fr\)\)/);
    expect(css).not.toContain('.product-row{display:grid;grid-template-columns:2fr 1fr 1fr 0.8fr 0.8fr 0.9fr 0.8fr auto');
  });
});
