'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Owner rule (2026-10-27): every input form, existing or future, is checked
 * in the browser before it is sent. That check lives once, sitewide, in
 * public/js/app.js, so every layout must load it; and a "Re-enter ..." field
 * is matched to the field it confirms by name ("<name>_confirm" -> "<name>",
 * "confirm_password" -> "new_password"), so that field must be on the same form.
 */
const VIEWS = path.join(__dirname, '../../views');

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((d) => (d.isDirectory() ? walk(path.join(dir, d.name)) : [path.join(dir, d.name)]));
}

describe('form validation conventions', () => {
  test('every layout loads the sitewide validation script', () => {
    for (const layout of fs.readdirSync(path.join(VIEWS, 'layout')).filter((f) => f.endsWith('.njk'))) {
      expect(fs.readFileSync(path.join(VIEWS, 'layout', layout), 'utf8')).toContain('/js/app.js');
    }
  });

  test('every Re-enter field has the field it confirms', () => {
    const missing = [];
    for (const file of walk(VIEWS).filter((f) => f.endsWith('.njk'))) {
      const html = fs.readFileSync(file, 'utf8');
      for (const [tag, name] of html.matchAll(/<input[^>]*\bname="([a-z_]+_confirm|confirm_password)"[^>]*>/g)) {
        const dm = tag.match(/data-match="([a-z_]+)"/);
        const targets = dm ? [dm[1]] : (name === 'confirm_password' ? ['new_password', 'password'] : [name.slice(0, -'_confirm'.length)]);
        if (!targets.some((t) => html.includes(`name="${t}"`))) missing.push(`${path.basename(file)}: ${name}`);
      }
    }
    expect(missing).toEqual([]);
  });
});
