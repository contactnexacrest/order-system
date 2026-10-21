'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Phase 0 item 2 of the approved remediation plan: the user's later,
 * more specific correction to the Freight Debit Note said it must ALWAYS
 * be a pure cost reimbursement with no GST/IGST branch at all — not a
 * document whose GST treatment depends on a staff-chosen setting. The
 * original flaw fix only replaced a vague "As advised by CA" placeholder
 * with an IGST_18%/NIL toggle, which still left a conditional branch the
 * correction explicitly ruled out.
 */
describe('FDN is always a pure cost reimbursement, no GST/IGST branch (Phase 0 item 2)', () => {
  it('FDN template has no GST/IGST conditional branch', () => {
    const njk = fs.readFileSync(path.join(__dirname, '../../templates/FDN/freight_debit_note.njk'), 'utf8');

    expect(njk).not.toContain('gst_treatment');
    expect(njk).not.toContain('IGST_18');
    expect(njk).toContain('pure cost reimbursement');
  });

  it('FDN docx builder has no GST/IGST conditional branch', () => {
    const js = fs.readFileSync(path.join(__dirname, '../../src/services/docx/docxDocumentBuilder.js'), 'utf8');

    expect(js).not.toContain('gst_treatment');
    expect(js).toContain('pure cost reimbursement, not a taxable service supply');
  });
});
