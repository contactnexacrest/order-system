'use strict';
// QA SUI-004 (13 Nov 2026): key/value tables wrap long values so a phone page never scrolls sideways (twin of KvTableWrapSui004Test).
const fs = require('fs');
const path = require('path');

describe('SUI-004 key/value table wrapping', () => {
  it('table.kv cells wrap long values', () => {
    const css = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'css', 'app.css'), 'utf8');
    expect(css).toMatch(/table\.kv td, table\.kv th \{ overflow-wrap: anywhere; \}/);
  });
});
