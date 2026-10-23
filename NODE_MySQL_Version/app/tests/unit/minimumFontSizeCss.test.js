'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-20: "About 340 text elements across the 30 tested
 * screens are smaller than 11px (badges, helper text) — hard to read on
 * phones." Fix direction: minimum 12px for helper text, 11px for badges.
 * Port of MinimumFontSizeCssTest.php.
 */
describe('Minimum font size CSS (External QA report U-20)', () => {
  let css;
  beforeAll(() => {
    css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');
  });

  it('nav-badge meets the 11px badge floor', () => {
    expect(css).toMatch(/\.nav-badge\{[^}]*font-size:11px/);
    expect(css).not.toContain('font-size:10px');
  });

  it('helper text labels meet the 12px floor', () => {
    expect(css).toMatch(/\.product-row label\{[^}]*font-size:0\.75rem/);
    expect(css).toMatch(/\.stage-chip \.stage-chip-num\{[^}]*font-size:0\.75rem/);
    expect(css).toMatch(/\.process-step-label\{[^}]*font-size:0\.75rem/);
  });
});
