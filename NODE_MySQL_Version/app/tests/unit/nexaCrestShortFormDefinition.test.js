'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Phase 0 item 1 of the approved remediation plan: every generated document
 * must tie the short form "NexaCrest", used throughout the body clause
 * text, back to the full legal entity name ("NexaCrest International
 * Private Limited") — a gap the user caught in their own legal document
 * review self-audit. _layout.njk's signature_block is shared by every
 * document template (QT, PI, OC, CI, AMD, etc.), so one addition there
 * covers all of them.
 */
describe('NexaCrest short-form definition on every document (Phase 0 item 1)', () => {
  it('the shared layout defines the NexaCrest short form against the full legal name', () => {
    const html = fs.readFileSync(path.join(__dirname, '../../templates/_layout.njk'), 'utf8');

    expect(html).toContain('footer-entity-note');
    expect(html).toContain('"NexaCrest" in this document refers to {{ company.legal_name }}.');
  });
});
