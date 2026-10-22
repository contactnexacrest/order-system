'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Port of PHP's SelfHostedFontTest. External QA report U-13: Poppins was
 * loaded live from Google Fonts on every page — if that request is
 * blocked or slow, the whole UI silently fell back to a different font
 * with no warning. Self-hosted instead, so a page that needs the font
 * never depends on a third-party request succeeding.
 */
describe('Self-hosted Poppins font (External QA report U-13)', () => {
  const LAYOUTS = ['layout/base.njk', 'layout/bare.njk', 'layout/client.njk'];

  test('no layout loads fonts from Google', () => {
    for (const layout of LAYOUTS) {
      const html = fs.readFileSync(path.join(__dirname, '../../views', layout), 'utf8');
      expect(html).not.toContain('fonts.googleapis.com');
      expect(html).not.toContain('fonts.gstatic.com');
    }
  });

  test('app.css declares the self-hosted font faces', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');

    for (const weight of ['400', '500', '600', '700']) {
      expect(css).toContain(`font-weight: ${weight}`);
      expect(css).toContain(`url('/fonts/poppins-${weight}.woff2')`);
    }
  });

  test('every declared font file actually exists on disk', () => {
    for (const weight of ['400', '500', '600', '700']) {
      const filePath = path.join(__dirname, `../../public/fonts/poppins-${weight}.woff2`);
      expect(fs.existsSync(filePath)).toBe(true);
      expect(fs.statSync(filePath).size).toBeGreaterThan(0);
    }
  });
});
