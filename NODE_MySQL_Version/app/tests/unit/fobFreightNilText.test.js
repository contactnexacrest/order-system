'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Port of FobFreightNilTextTest.php. Owner request (2026-10-25): for FOB, the
 * Quotation/PI freight and insurance rows print just "NIL" — "freight
 * arranged by buyer." and "buyer's responsibility." were internal notes, not
 * text for the buyer. Covers both the PDF (Nunjucks) templates and the DOCX
 * builder.
 */
describe('FOB freight/insurance print just NIL', () => {
  it('no document prints the internal FOB notes', () => {
    const app = path.join(__dirname, '..', '..');
    for (const file of [
      'templates/QT/quotation.njk',
      'templates/PI/proforma_invoice.njk',
      'src/services/docx/docxDocumentBuilder.js',
    ]) {
      const source = fs.readFileSync(path.join(app, file), 'utf8');
      expect(source).not.toContain('freight arranged by buyer');
      expect(source).not.toContain("buyer's responsibility");
    }
  });
});
