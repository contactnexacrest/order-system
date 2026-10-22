'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-08/U-10: small screen-text defects, grouped into
 * one file since each is a one-line static-text check with nothing else
 * to set up. Port of PHP's UiTextCleanupTest.
 */
describe('UI text cleanup (External QA report U-08/U-10)', () => {
  /**
   * U-08: the "(only shown when Incoterm is not FOB)" hint was never
   * actually true — the fieldset always renders regardless of Incoterm,
   * with no show/hide JS anywhere on the page. Reworded to describe what
   * actually happens (the Quotation itself prints NIL for FOB — see
   * templates/QT/quotation.njk's own is_fob branch) rather than building
   * new conditional-visibility JS.
   */
  it('order create no longer claims the freight section hides for FOB', () => {
    const html = fs.readFileSync(path.join(__dirname, '../../views/orders/create.njk'), 'utf8');

    expect(html).not.toContain('only shown when Incoterm is not FOB');
    expect(html).toContain('printed on the Quotation only for CFR/CIF');
  });

  /** U-10: developer jargon ("workingDaysCalculator") shown to end users on /holidays. */
  it('holidays page has no developer jargon', () => {
    const html = fs.readFileSync(path.join(__dirname, '../../views/holidays/index.njk'), 'utf8');

    expect(html).not.toContain('workingDaysCalculator');
  });
});
