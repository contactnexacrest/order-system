'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-22: "Keyboard focus ring uses :focus-visible only
 * — not supported by Safari 14-15.3 (iOS 14-15.3), so keyboard users
 * there see no focus outline." Fix direction: add a :focus fallback.
 * Port of FocusFallbackCssTest.php.
 */
describe('Focus fallback CSS (External QA report U-22)', () => {
  it('a :focus-visible fallback exists for browsers without :focus-visible support', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');

    expect(css).toContain('@supports not selector(:focus-visible)');
    expect(css).toMatch(/@supports not selector\(:focus-visible\)\{\s*a:focus, button:focus, input:focus, select:focus, textarea:focus, summary:focus, \[tabindex\]:focus\{/);
  });
});
