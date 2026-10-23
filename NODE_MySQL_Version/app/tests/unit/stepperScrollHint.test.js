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

  it('the longest section headings have a short tab label alias', () => {
    const html = view();

    const expected = {
      'Stage 4 — Order Confirmation: Buyer Acknowledgement': 'Stage 4 — OC Ack',
      'Stage 5 — Supplier Purchase Order (Material Procurement)': 'Stage 5 — Supplier PO',
      'Order Financials — Government Benefits, Costs &amp; Profitability': 'Financials',
    };
    for (const [fullHeading, shortLabel] of Object.entries(expected)) {
      expect(html).toContain(`data-tab-label="${shortLabel}">${fullHeading}</h2>`);
    }
  });

  it('the tab builder JS prefers the short alias over the full heading text', () => {
    const html = view();

    expect(html).toContain('heading.dataset.tabLabel || heading.textContent.trim()');
  });
});
