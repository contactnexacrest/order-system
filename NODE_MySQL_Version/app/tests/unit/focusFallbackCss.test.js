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

  // QA Round 5, R5-10: Safari 14-15.3 also ignore :has(), and drop a whole
  // rule when one selector in its list is unsupported. Every :has()
  // current/locked highlight needs an is-current/is-locked twin with the
  // same declarations (app.js sets those classes), and no rule may list a
  // :focus-visible selector beside others outside the @supports fallback.
  it('every :has() highlight rule has a class twin, and no mixed :focus-visible lists', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');
    const js = fs.readFileSync(path.join(__dirname, '../../public/js/app.js'), 'utf8');

    const rules = [...css.matchAll(/^(\.[\w-]+):has\(> summary > \.step-card-icon\.(current|locked)\)([^{]*)\{([^}]*)\}/gm)];
    expect(rules.length).toBeGreaterThan(0);
    for (const [, box, state, rest, body] of rules) {
      expect(css).toContain(`${box}.is-${state}${rest}{${body}}`);
    }
    expect(js).toContain('function initStepStatusClasses()');
    expect(js).toContain('initStepStatusClasses();');

    const mixed = css.split(/\r?\n/).filter((line) => line.includes(':focus-visible') && line.includes(',') && line.includes('{') && !line.startsWith('a:focus-visible, button:focus-visible'));
    expect(mixed).toEqual([]);
  });
});
