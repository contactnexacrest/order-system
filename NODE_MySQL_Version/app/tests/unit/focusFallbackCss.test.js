'use strict';

const fs = require('fs');
const path = require('path');

/**
 * External QA report U-22: "Keyboard focus ring uses :focus-visible only
 * — not supported by Safari 14-15.3 (iOS 14-15.3), so keyboard users
 * there see no focus outline." Fix direction: add a :focus fallback.
 * Port of FocusFallbackCssTest.php.
 */
function hasMixedFocusVisibleList(line) {
  return line.split('}').some((segment) => {
    if (!segment.includes('{')) return false;
    const selector = segment.slice(0, segment.indexOf('{')).trim();
    return selector.includes(':focus-visible') && selector.includes(',') && !selector.startsWith('a:focus-visible, button:focus-visible');
  });
}

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

    // Stale-test fix (QA 13 Nov 2026, D-360): only the selector part of a rule
    // (the text before '{') is checked — a comma inside a declaration such as
    // var(--navy,#1f3a68) is not a selector list.
    const mixed = css.split(/\r?\n/).filter(hasMixedFocusVisibleList);
    expect(mixed).toEqual([]);
    // The check itself still catches a mixed list.
    expect(hasMixedFocusVisibleList('.x:focus-visible, .y:hover{outline:0}')).toBe(true);
    expect(hasMixedFocusVisibleList('.offer-switch:focus-visible{outline:2px solid var(--navy,#1f3a68)}')).toBe(false);
  });
});
