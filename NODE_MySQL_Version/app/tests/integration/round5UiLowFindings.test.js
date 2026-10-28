'use strict';

const fs = require('fs');
const path = require('path');
const sopController = require('../../src/controllers/sopController');

/**
 * Port of Round5UiLowFindingsTest.php. QA Round 5, low findings on the UI:
 *  - R5-05: SOP links to ./README.md opened /sop/README (404) — they now
 *    open /sop, the chapter list.
 *  - R5-06: every form field gets an accessible name — one sitewide
 *    fallback in app.js for fields with no label.
 *  - R5-11: phones — tap targets at least 24px, no text below 11px,
 *    16px form fields (no iOS zoom on focus).
 */
describe('QA Round 5 low UI findings (R5-05 / R5-06 / R5-11)', () => {
  it('SOP README links open the chapter list', async () => {
    const res = { renderView: (view, data) => { res.data = data; }, status() { return res; }, send() {} };
    await sopController.show({ params: { chapter: '00-getting-started' }, permissions: { ca_module_view: true } }, res);
    expect(res.data.contentHtml).not.toContain('href="/sop/README');
    expect(res.data.contentHtml).toContain('href="/sop"');
  });

  it('app.js names every unlabelled field', () => {
    const js = fs.readFileSync(path.join(__dirname, '../../public/js/app.js'), 'utf8');
    expect(js).toContain('function initFieldLabels()');
    expect(js).toMatch(/function init\(\) \{[\s\S]*initFieldLabels\(\);/);
  });

  it('phone CSS raises tap targets, text and input sizes', () => {
    const css = fs.readFileSync(path.join(__dirname, '../../public/css/app.css'), 'utf8');
    expect(css).toContain('@media (max-width:640px){\n  .tag,.tag-hint,.step-group-num,.gs-pill{font-size:11px;}');
    expect(css).toContain('input[type=checkbox],input[type=radio]{min-width:24px;min-height:24px;}');
    expect(css).toContain('input:not([type=checkbox]):not([type=radio]),select,textarea{font-size:16px !important;}');
  });
});
