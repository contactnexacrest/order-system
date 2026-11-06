'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Port of PHP's AssociateStatRowCssTest: the associate home money boxes and
 * the Associate › Track record boxes (.stat-row) are styled like the
 * dashboard tiles instead of showing as plain stacked text.
 */
describe('Associate .stat-row boxes are styled', () => {
  it('styles the boxes like the dashboard tiles', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');

    expect(css).toMatch(/\.stat-row\{display:grid;/);
    expect(css).toMatch(/\.stat-row \.stat\{[^}]*border-top:3px solid var\(--gold\)/);
    expect(css).toMatch(/\.stat-row \.stat-label\{[^}]*font-size:0\.78rem/);
    expect(css).toMatch(/\.stat-row \.stat-value\{[^}]*font-weight:800/);
  });

  it('both views that use the boxes still use the styled class names', () => {
    for (const v of ['associate_portal/home.njk', 'associates/_portal_access.njk']) {
      const html = fs.readFileSync(path.join(__dirname, '../../views', v), 'utf8');
      expect(html).toContain('class="stat-row"');
      expect(html).toContain('class="stat-label"');
      expect(html).toContain('class="stat-value"');
    }
  });
});
