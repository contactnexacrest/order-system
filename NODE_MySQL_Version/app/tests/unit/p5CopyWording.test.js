'use strict';

const fs = require('fs');
const path = require('path');

/**
 * 90-item backlog P5 content/copy polish — items #20 (payment-screenshot
 * upload label wording), #79 (spell out ETD/ETA in full rather than
 * the bare abbreviation), and #78 (Supplier PO form field clarification:
 * placeholder hints on free-text fields that had none, plus a reciprocal
 * auto-calc hint on Unit Price mirroring the one Basic Value already had).
 * Pure copy guards: no behavior to exercise, just pinning the wording so
 * a future edit doesn't silently revert it.
 */
describe('P5 copy wording (backlog #20, #79)', () => {
  function read(relativePath) {
    return fs.readFileSync(path.join(__dirname, '../..', relativePath), 'utf8');
  }

  it('payment screenshot label clarifies proof of payment on buyer action form', () => {
    const html = read('views/buyer_action/show.njk');
    expect(html).toContain('Payment Screenshot / Proof of Payment (optional)');
  });

  it('payment screenshot label clarifies proof of payment on client portal form', () => {
    const html = read('views/client_portal/order_show.njk');
    expect(html).toContain('Payment Screenshot / Proof of Payment (optional)');
  });

  it('ETD/ETA are spelled out on the shipping details form', () => {
    const html = read('views/orders/show.njk');
    expect(html).toContain('ETD <small class="muted">(Estimated Time of Departure)</small>');
    expect(html).toContain('ETA <small class="muted">(Estimated Time of Arrival)</small>');
  });

  it('ETD/ETA are spelled out on the BLI PDF template', () => {
    const html = read('templates/BLI/bl_instruction.njk');
    expect(html).toContain('ETD (Estimated Time of Departure) *');
    expect(html).toContain('ETA (Estimated Time of Arrival) *');
    expect(html).not.toContain('Est. Departure');
    expect(html).not.toContain('Est. Arrival');
  });

  it('ETD/ETA are spelled out on the DOCX builder', () => {
    const js = read('src/services/docx/docxDocumentBuilder.js');
    expect(js).toContain('ETD (Estimated Time of Departure) *');
    expect(js).toContain('ETA (Estimated Time of Arrival) *');
  });

  it('Supplier PO free-text fields have placeholder hints', () => {
    const html = read('views/orders/show.njk');
    expect(html).toContain('name="material_stone_type" placeholder="e.g., Black Granite"');
    expect(html).toContain('name="dimensions" placeholder="e.g., 600 x 600 x 20 mm"');
    // User-reported batch item 12: Dimensional Tolerance and Delivery
    // Location both moved from bare placeholder hints to real dropdowns
    // (admin-editable, and the selected supplier's own factory addresses
    // respectively — see supplierFactoryAddressAndDimensionalTolerance.
    // test.js) — neither is a free-text field by default any more, so no
    // placeholder-hint guard applies to them here any more.
    expect(html).toContain('name="colour_reference" placeholder="e.g., Jet Black"');
    expect(html).toContain('name="special_requirements" placeholder="e.g., Polished finish, edge chamfer"');
    expect(html).toContain('name="packing_requirement" placeholder="e.g., Wooden crates, export packing"');
  });

  it('Supplier PO Unit Price has a reciprocal auto-calc hint', () => {
    const html = read('views/orders/show.njk');
    expect(html).toContain('Unit Price (INR) <small class="muted">(auto-calculates Basic Value when multiplied by Quantity)</small>');
  });
});
