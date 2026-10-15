'use strict';

const fs = require('fs');
const path = require('path');

/**
 * 90-item backlog P5 content/copy polish — items #20 (payment-screenshot
 * upload label wording) and #79 (spell out ETD/ETA in full rather than
 * the bare abbreviation). Pure copy guards: no behavior to exercise,
 * just pinning the wording so a future edit doesn't silently revert it.
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
});
