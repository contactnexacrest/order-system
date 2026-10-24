'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-12: "On phones the 1-9 progress line shows only
 * stages 1-4 with no hint that it scrolls; section tabs wrap and the
 * long tab names are cut." Port of PHP's StepperScrollHintTest.
 */
describe('Stepper scroll hint and short tab labels (External QA report U-12)', () => {
  function view() {
    return fs.readFileSync(path.join(__dirname, '../../views/orders/show.njk'), 'utf8');
  }

  it('the stepper has a mobile scroll hint', () => {
    const html = view();

    expect(html).toContain('stepper-scroll-hint');
    expect(html).toContain('Swipe to see all stages');
  });

  it('scroll hint is hidden at desktop width and shown only below the same mobile breakpoint as the rest of the page', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');

    expect(css).toMatch(/\.stepper-scroll-hint\{display:none;/);
    expect(css).toMatch(/@media \(max-width:900px\)\{\s*\.stepper-scroll-hint\{display:block;\}/);
  });

  /**
   * The order-detail-page rebuild (step-card sequence) removed the
   * JS-built mobile section-tab bar this alias existed to shorten labels
   * for — every section is now a native <details>/<summary> step-card
   * stacked vertically, with no per-section tab strip to build on mobile
   * at all, so there is nothing left to alias. The `data-tab-label`
   * attribute and its consuming
   * `heading.dataset.tabLabel || heading.textContent.trim()` JS line
   * (covered by the two tests this replaced) were deleted along with
   * that tab-building script.
   */
  it('the obsolete section tab-label alias machinery is gone', () => {
    const html = view();

    expect(html).not.toContain('data-tab-label');
    expect(html).not.toContain('heading.dataset.tabLabel');
  });
});
